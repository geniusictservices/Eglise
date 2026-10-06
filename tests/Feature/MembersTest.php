<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\Household;
use App\Models\Member;
use App\Models\MemberField;
use App\Models\MemberFunction;
use App\Models\MemberStatus;
use App\Models\User;
use App\Services\MemberRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class MembersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function community(?User $admin = null)
    {
        $admin ??= User::factory()->create();
        $eglise = $this->createCommunity('Église de la Paix', $admin);
        $eglise->update(['settings' => ['members_code' => 'EP']]);
        $this->actingAs($admin);
        $this->inOrganization($eglise);

        return $eglise;
    }

    public function test_member_screens_open(): void
    {
        $admin = User::factory()->create();
        $eglise = $this->community($admin);
        $member = Member::create(['last_name' => 'KAHINDO', 'first_name' => 'Esther', 'phone' => '0812345678']);
        $household = Household::create(['name' => 'Famille KAHINDO']);

        foreach (['members.index', 'members.create', 'members.settings', 'households.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('members.show', $member))->assertOk()->assertSee('KAHINDO');
        $this->get(route('members.edit', $member))->assertOk();
        $this->get(route('households.show', $household))->assertOk();
        $this->get(route('members.index', ['q' => '0812 345']))->assertOk()->assertSee('Esther');
    }

    public function test_a_member_is_registered_with_a_number_status_and_household(): void
    {
        $this->community();

        LivewireTest::test(Livewire\Members\Form::class)
            ->set('data.last_name', 'Kahindo')
            ->set('data.first_name', 'Esther')
            ->set('data.gender', 'F')
            ->set('data.phone', '0812 345 678')
            ->set('householdMode', 'new')
            ->set('householdRole', 'head')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $member = Member::firstOrFail();
        $this->assertSame('KAHINDO', $member->last_name);
        $this->assertSame('+243812345678', $member->phone);
        $this->assertSame('EP-'.now()->year.'-0001', $member->number);
        $this->assertSame('Membre', $member->status->name);
        $this->assertSame(1, $member->statusChanges()->count());
        $this->assertSame('Famille KAHINDO', $member->household->name);
        $this->assertSame($member->id, $member->household->head_member_id);
    }

    public function test_possible_duplicates_are_shown_before_saving(): void
    {
        $this->community();
        Member::create(['last_name' => 'KAHINDO', 'first_name' => 'Bénédicte']);

        $form = LivewireTest::test(Livewire\Members\Form::class)
            ->set('data.last_name', 'kahindo')
            ->set('data.first_name', 'Benedicte')
            ->call('save')
            ->assertHasErrors('duplicates')
            ->assertSee('Voir la fiche');
        $this->assertSame(1, Member::count());

        $form->call('save')->assertHasNoErrors();
        $this->assertSame(2, Member::count());
    }

    public function test_an_old_register_number_can_be_kept(): void
    {
        $this->community();

        LivewireTest::test(Livewire\Members\Form::class)
            ->set('data.last_name', 'MUMBERE')
            ->set('existingNumber', 'ANC-0457')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('ANC-0457', Member::firstOrFail()->number);

        LivewireTest::test(Livewire\Members\Form::class)
            ->set('data.last_name', 'PALUKU')
            ->set('existingNumber', 'ANC-0457')
            ->call('save')
            ->assertHasErrors('existingNumber');
    }

    public function test_custom_fields_are_validated_and_sensitive_ones_are_protected(): void
    {
        $eglise = $this->community();
        MemberField::create(['organization_id' => $eglise->id, 'key' => 'groupe', 'label' => 'Groupe', 'type' => 'select', 'options' => ['Lundi', 'Mercredi'], 'required' => true]);
        MemberField::create(['organization_id' => $eglise->id, 'key' => 'sante', 'label' => 'Santé', 'type' => 'text', 'sensitive' => true]);

        LivewireTest::test(Livewire\Members\Form::class)
            ->set('data.last_name', 'BAHATI')
            ->set('custom.groupe', 'Vendredi')
            ->call('save')
            ->assertHasErrors('custom.groupe')
            ->set('custom.groupe', 'Lundi')
            ->set('custom.sante', 'Diabète')
            ->call('save')
            ->assertHasNoErrors();

        $member = Member::firstOrFail();
        $this->assertSame(['groupe' => 'Lundi', 'sante' => 'Diabète'], $member->custom);

        // La trésorière ne voit pas le champ sensible et ne peut pas l'effacer.
        $tresoriere = User::factory()->create();
        $this->assign($tresoriere, $this->role($eglise, 'tresorier'), $eglise);
        $role = $this->role($eglise, 'tresorier');
        $role->update(['permissions' => array_merge($role->permissions, ['members.manage'])]);
        $this->actingAs($tresoriere);

        LivewireTest::test(Livewire\Members\Form::class, ['id' => $member->id])
            ->assertDontSee('Diabète')
            ->set('custom.groupe', 'Mercredi')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['groupe' => 'Mercredi', 'sante' => 'Diabète'], $member->fresh()->custom);
        $this->get(route('members.show', $member))->assertOk()->assertDontSee('Diabète');
    }

    public function test_status_changes_are_kept_in_the_history(): void
    {
        $eglise = $this->community();
        $member = Member::create(['last_name' => 'MASIKA']);
        $transfere = MemberStatus::where('organization_id', $eglise->id)->where('name', 'Transféré')->first();

        LivewireTest::test(Livewire\Members\Show::class, ['id' => $member->id])
            ->call('openStatus')
            ->set('newStatusId', (string) $transfere->id)
            ->set('statusReason', 'Parti à Bukavu')
            ->call('changeStatus')
            ->assertHasNoErrors();

        $this->assertSame($transfere->id, $member->fresh()->status_id);
        $this->assertSame('Parti à Bukavu', $member->statusChanges()->first()->reason);
    }

    public function test_functions_and_life_events_are_recorded(): void
    {
        $eglise = $this->community();
        $member = Member::create(['last_name' => 'MASIKA']);
        $diacre = MemberFunction::where('organization_id', $eglise->id)->where('name', 'Diacre')->first();

        LivewireTest::test(Livewire\Members\Show::class, ['id' => $member->id])
            ->call('openTerm')
            ->set('termFunctionId', (string) $diacre->id)
            ->set('termStart', '2021-01-10')
            ->call('saveTerm')
            ->assertHasNoErrors()
            ->call('openEvent')
            ->set('event.type', 'baptism')
            ->set('event.occurred_on', '2015-04-05')
            ->set('event.register_number', 'B-112')
            ->call('saveEvent')
            ->assertHasNoErrors()
            ->set('tab', 'fonctions')
            ->assertSee('Diacre')
            ->assertSee('En cours');

        $this->assertSame('B-112', $member->lifeEvents()->first()->register_number);
    }

    public function test_a_household_has_a_single_head(): void
    {
        $this->community();
        $household = Household::create(['name' => 'Famille MUMBERE']);
        $pere = Member::create(['last_name' => 'MUMBERE', 'first_name' => 'Jean']);
        $mere = Member::create(['last_name' => 'KAVIRA', 'first_name' => 'Rose']);

        LivewireTest::test(Livewire\Households\Show::class, ['id' => $household->id])
            ->set('newRole', 'head')
            ->call('addMember', $pere->id)
            ->set('newRole', 'spouse')
            ->call('addMember', $mere->id)
            ->call('makeHead', $mere->id);

        $this->assertSame($mere->id, $household->fresh()->head_member_id);
        $this->assertSame('spouse', $pere->fresh()->household_role);
        $this->assertSame('head', $mere->fresh()->household_role);
    }

    public function test_members_of_another_community_are_not_reachable(): void
    {
        $this->community();
        $autre = $this->createCommunity('Autre église');
        $etranger = Member::create(['organization_id' => $autre->id, 'last_name' => 'ETRANGER']);

        $this->get(route('members.show', $etranger->id))->assertNotFound();
        $this->get(route('members.index'))->assertDontSee('ETRANGER');
    }

    public function test_the_headquarters_can_see_parish_members(): void
    {
        $admin = User::factory()->create();
        $siege = $this->community($admin);
        $paroisse = $this->createChild($siege, 'Paroisse Himbi');
        $member = Member::create(['organization_id' => $paroisse->id, 'last_name' => 'PAROISSIEN']);

        $this->get(route('members.index', ['portee' => 'ici']))->assertDontSee('PAROISSIEN');
        $this->get(route('members.index'))->assertSee('PAROISSIEN'); // siège sans fidèles inscrits chez lui
        $this->get(route('members.index', ['portee' => 'tout']))->assertSee('PAROISSIEN');
        $this->get(route('members.show', $member->id))->assertOk();
    }

    public function test_photos_are_resized_and_served_only_to_authorized_users(): void
    {
        Storage::fake('local');
        $this->community();
        $member = Member::create(['last_name' => 'PHOTO']);

        LivewireTest::test(Livewire\Members\Form::class, ['id' => $member->id])
            ->set('photo', UploadedFile::fake()->image('visage.jpg', 1200, 1600))
            ->call('save')
            ->assertHasNoErrors();

        $path = $member->fresh()->photo_path;
        Storage::disk('local')->assertExists($path);
        $this->assertSame([420, 540], array_slice(getimagesizefromstring(Storage::disk('local')->get($path)), 0, 2));
        $this->get(route('members.photo', $member))->assertOk();

        $this->actingAs(User::factory()->create());
        $this->get(route('members.photo', $member))->assertStatus(302);
    }

    public function test_a_member_card_is_printed_and_verified_by_qr_code(): void
    {
        $eglise = $this->community();
        $member = Member::create(['last_name' => 'KAHINDO', 'first_name' => 'Esther',
            'status_id' => app(MemberRegistry::class)->defaultStatus($eglise)->id]);
        app(MemberRegistry::class)->assignNumber($member);

        $this->get(route('members.card', $member))->assertOk()->assertSee('<svg', false)->assertSee($member->fresh()->number);
        $token = $member->fresh()->card_token;
        $this->assertSame(32, strlen($token));

        auth()->logout();
        $this->get(route('cards.verify', $token))->assertOk()->assertSee('Carte valide')->assertSee('KAHINDO')->assertDontSee('+243');

        $member->update(['status_id' => MemberStatus::where('organization_id', $eglise->id)->where('name', 'Décédé')->value('id')]);
        $this->get(route('cards.verify', $token))->assertOk()->assertSee('Carte non valide');

        $member->delete();
        $this->get(route('cards.verify', $token))->assertSee('ne figure plus au registre');
        $this->get(route('cards.verify', str_repeat('a', 32)))->assertNotFound();
    }
}
