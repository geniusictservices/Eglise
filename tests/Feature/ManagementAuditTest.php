<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\DocumentType;
use App\Models\GroupMeeting;
use App\Models\Household;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Services\Documents;
use App\Services\DocumentTypes;
use App\Services\Groups;
use App\Services\MemberRegistry;
use App\Services\OrganizationProvisioner;
use App\Services\Pastoral;
use App\Services\SupportTickets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

/**
 * Les erreurs relevées par l'audit de gestion d'octobre 2026 (modèles, ménages, groupes,
 * anniversaires, rattachement, support), pour qu'elles ne reviennent pas.
 */
class ManagementAuditTest extends TestCase
{
    use RefreshDatabase;

    private Organization $siege;

    private Organization $paroisse;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::factory()->create();
        $this->siege = $this->createCommunity('Communauté de la Paix', $this->admin);
        $this->paroisse = $this->createChild($this->siege, 'Paroisse de Himbi');
        $this->admin->forceFill(['current_organization_id' => $this->paroisse->id])->save();
        $this->actingAs($this->admin);
        $this->inOrganization($this->paroisse);
    }

    private function type(string $key): DocumentType
    {
        return app(DocumentTypes::class)->available($this->paroisse)->firstWhere('key', $key);
    }

    public function test_provided_templates_keep_their_field_keys_when_saved(): void
    {
        $types = app(DocumentTypes::class);
        $reco = $types->adapt($this->type('recommendation'), $this->paroisse);
        $saved = $types->save($this->paroisse, ['name' => $reco->name, 'title' => $reco->title, 'code' => $reco->code, 'subject' => $reco->subject,
            'body' => $reco->body, 'number_format' => $reco->number_format, 'fields' => $reco->customFields()], $reco);
        $this->assertSame('destinataire', $saved->fresh()->customFields()[0]['key']);

        $m = Member::create(['last_name' => 'Kambale', 'first_name' => 'Jean', 'gender' => 'M']);
        $doc = app(Documents::class)->issue($this->paroisse, $saved->fresh(), ['member_id' => $m->id, 'fields' => ['destinataire' => 'CBCA Bukavu']]);
        $this->assertStringContainsString('CBCA Bukavu', $doc->body);

        // La convocation, dont un champ s'appelle « Date », s'enregistre aussi.
        $conv = $types->adapt($this->type('summons'), $this->paroisse);
        $types->save($this->paroisse, ['name' => $conv->name, 'title' => $conv->title, 'code' => $conv->code, 'subject' => $conv->subject,
            'body' => $conv->body, 'number_format' => $conv->number_format, 'fields' => $conv->customFields()], $conv);
        $this->assertSame(collect($conv->customFields())->pluck('key')->all(), collect($conv->fresh()->customFields())->pluck('key')->all());
    }

    public function test_a_child_has_no_spouse_and_a_free_recipient_is_named(): void
    {
        $household = Household::create(['name' => 'Famille Kambale']);
        Member::create(['last_name' => 'Kambale', 'first_name' => 'Paul', 'gender' => 'M', 'household_id' => $household->id, 'household_role' => 'head']);
        $mother = Member::create(['last_name' => 'Kavugho', 'first_name' => 'Marie', 'gender' => 'F', 'household_id' => $household->id, 'household_role' => 'spouse']);
        $son = Member::create(['last_name' => 'Kambale', 'first_name' => 'David', 'gender' => 'M', 'household_id' => $household->id, 'household_role' => 'child']);
        $types = app(DocumentTypes::class);
        $this->assertNull($types->values($this->type('marriage'), $this->paroisse, ['member' => $son])['conjoint']);
        $this->assertNotNull($types->values($this->type('marriage'), $this->paroisse, ['member' => $mother])['conjoint']);

        $free = $types->save($this->paroisse, ['name' => 'Lettre nominative', 'title' => 'Lettre', 'code' => 'LNO', 'subject' => 'free',
            'body' => 'Cher {nom_complet},', 'number_format' => '{CODE}/{NUMERO}', 'fields' => []]);
        $this->assertStringContainsString('Mairie de Goma', app(Documents::class)->issue($this->paroisse, $free, ['beneficiary' => 'Mairie de Goma'])->body);
    }

    public function test_redating_a_meeting_corrects_it_and_a_leap_birthday_has_the_right_age(): void
    {
        $leader = Member::create(['last_name' => 'Kambale', 'first_name' => 'Paul']);
        $groups = app(Groups::class);
        $group = $groups->create($this->paroisse, ['name' => 'Cellule', 'leader_member_id' => $leader->id]);
        $groups->recordMeeting($group, ['held_on' => today()->subDays(3)->toDateString()], []);
        LivewireTest::test(Livewire\Groups\Show::class, ['group' => $group])
            ->call('openMeeting', GroupMeeting::first()->id)->set('meeting.held_on', today()->subDays(2)->toDateString())->call('saveMeeting')->assertHasNoErrors();
        $this->assertSame([today()->subDays(2)->toDateString()], GroupMeeting::where('group_id', $group->id)->get()->map(fn ($m) => $m->held_on->toDateString())->all());

        // Le pasteur ne peut pas supprimer la fiche du responsable sans lui donner un successeur.
        LivewireTest::test(Livewire\Members\Show::class, ['id' => $leader->id])->call('archive');
        $this->assertNotNull($leader->fresh());

        Carbon::setTestNow('2027-02-20');
        Member::create(['last_name' => 'Bissextile', 'first_name' => 'Ana', 'birth_date' => '2000-02-29']);
        $b = app(Pastoral::class)->birthdays($this->paroisse, today(), today()->addDays(30))->first(fn ($x) => $x['member']->first_name === 'Ana');
        $this->assertSame(['2027-02-28', 27], [$b['date']->toDateString(), $b['age']]);
        Carbon::setTestNow();
    }

    public function test_joining_a_denomination_leaves_one_template_of_each_kind(): void
    {
        $other = $this->createCommunity('Église indépendante', $this->admin);
        $types = app(DocumentTypes::class);
        $types->available($other);
        $types->available($this->siege);
        $other->moveUnder($this->siege);
        app(MemberRegistry::class)->harmonize($other->fresh());
        $list = $types->available($other->fresh());
        $this->assertSame([], $list->pluck('key')->countBy()->filter(fn ($c) => $c > 1)->keys()->all());
        $this->assertSame([], app(OrganizationProvisioner::class)->availableRoles($other->fresh())->pluck('key')->filter()->countBy()->filter(fn ($c) => $c > 1)->keys()->all());
    }

    public function test_ticket_numbers_never_collide_after_a_demo_is_deleted(): void
    {
        $t = app(SupportTickets::class);
        $demo = $this->createCommunity('Démo', User::factory()->create());
        $t->open($this->paroisse, $this->admin, 'A', 'question', 'a');
        $t->open($demo, $this->admin, 'B', 'question', 'b');
        $t->open($this->paroisse, $this->admin, 'C', 'question', 'c');
        DB::table('support_tickets')->where('organization_id', $demo->id)->delete();
        $this->assertStringEndsWith('-0004', $t->open($this->paroisse, $this->admin, 'D', 'question', 'd')->number);
    }
}
