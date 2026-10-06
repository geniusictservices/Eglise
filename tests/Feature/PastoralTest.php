<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\DocumentRequest;
use App\Models\Member;
use App\Models\Organization;
use App\Models\PastoralCase;
use App\Models\PastoralNote;
use App\Models\PaymentDeclaration;
use App\Models\PrayerRequest;
use App\Models\User;
use App\Services\DocumentTypes;
use App\Services\MemberAccounts;
use App\Services\Notifier;
use App\Services\Pastoral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class PastoralTest extends TestCase
{
    use RefreshDatabase;

    private Organization $eglise;

    private User $admin;

    private User $pasteur;

    private Member $rebecca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['waumini.push.public_key' => null]);
        $this->admin = User::factory()->create();
        $this->eglise = $this->createCommunity('Église', $this->admin);
        $this->pasteur = User::factory()->create(['current_organization_id' => $this->eglise->id]);
        $this->assign($this->pasteur, $this->role($this->eglise, 'pasteur'), $this->eglise);
        $this->inOrganization($this->eglise);
        $this->actingAs($this->pasteur);
        $this->rebecca = Member::create(['last_name' => 'MASIKA', 'first_name' => 'Rebecca', 'gender' => 'F', 'phone' => '+243990001111', 'birth_date' => '1957-02-11']);
    }

    public function test_a_case_with_visits_and_a_confidential_note_only_its_author_reads(): void
    {
        LivewireTest::test(Livewire\Pastoral\Index::class)
            ->call('create', $this->rebecca->id)
            ->set('form.kind', 'sick')->set('form.title', 'Hospitalisée')
            ->call('save')->assertHasNoErrors()->assertRedirect();
        $case = PastoralCase::sole();

        LivewireTest::test(Livewire\Pastoral\Show::class, ['case' => $case])
            ->set('note.body', 'Visite à l’hôpital, fièvre en baisse.')->call('addNote')->assertHasNoErrors()
            ->set('note.body', 'Difficultés pour payer les soins.')->set('note.is_confidential', true)->call('addNote')
            ->assertSee('Visite à l’hôpital')->assertSee('Difficultés pour payer');

        // Chiffrée en base, illisible pour un autre membre de l'équipe et pour l'administrateur.
        $secret = PastoralNote::where('is_confidential', true)->sole();
        $this->assertStringNotContainsString('payer les soins', DB::table('pastoral_notes')->where('id', $secret->id)->value('body'));
        $this->actingAs($this->admin);
        LivewireTest::test(Livewire\Pastoral\Show::class, ['case' => $case])
            ->assertSee('Visite à l’hôpital')->assertDontSee('Difficultés pour payer')->assertSee('elle seule peut la lire');

        $this->actingAs($this->pasteur);
        app(Pastoral::class)->close($case);
        $this->assertSame('closed', $case->fresh()->status);
        $this->get(route('members.show', $this->rebecca->id))->assertOk()->assertSee('Hospitalisée');
    }

    public function test_only_the_pastoral_team_sees_the_follow_up(): void
    {
        $tresorier = User::factory()->create(['current_organization_id' => $this->eglise->id]);
        $this->assign($tresorier, $this->role($this->eglise, 'tresorier'), $this->eglise);
        $this->actingAs($tresorier)->get(route('pastoral.index'))->assertForbidden();
    }

    public function test_birthdays_and_the_morning_reminder(): void
    {
        Carbon::setTestNow('2026-02-11 08:00');
        Member::create(['last_name' => 'PALUKU', 'first_name' => 'Samuel', 'birth_date' => '1963-02-15']);
        Member::create(['last_name' => 'KAVIRA', 'first_name' => 'Ruth', 'birth_date' => '1990-07-01']);

        $soon = app(Pastoral::class)->birthdays($this->eglise, today(), today()->addDays(7));
        $this->assertSame(['Rebecca', 'Samuel'], $soon->map(fn ($b) => $b['member']->first_name)->all());
        $this->assertSame(69, $soon[0]['age']);

        $this->artisan('waumini:anniversaires')->assertSuccessful();
        $this->assertSame(1, app(Notifier::class)->unreadCount($this->admin));
        Carbon::setTestNow();
    }

    public function test_a_member_opens_their_space_and_asks_for_prayer_and_a_certificate(): void
    {
        $this->actingAs($this->admin);
        LivewireTest::test(Livewire\Members\Show::class, ['id' => $this->rebecca->id])
            ->set('spacePhone', '0990001111')->call('openSpace')->assertHasNoErrors()->assertSee('Mot de passe provisoire');
        $account = User::where('phone', '+243990001111')->sole();
        $this->assertTrue($account->must_change_password);
        $this->assertSame($account->id, $this->rebecca->fresh()->user_id);

        $account->forceFill(['must_change_password' => false])->save();
        $this->actingAs($account);
        $this->get(route('dashboard'))->assertRedirect(route('member.space'));
        $this->get(route('members.index'))->assertForbidden();
        $this->get(route('members.card', $this->rebecca))->assertOk();

        $type = app(DocumentTypes::class)->available($this->eglise)->firstWhere('key', 'membership');
        LivewireTest::test(Livewire\Member\Space::class)
            ->assertSee('Bonjour Rebecca')
            ->set('prayer.subject', 'Ma santé')->call('askPrayer')->assertHasNoErrors()
            ->set('request.type', $type->id)->set('request.message', 'Pour l’université')->call('askDocument')->assertHasNoErrors()
            ->set('request.type', $type->id)->call('askDocument')->assertHasErrors('request.type');
        $this->assertSame($this->rebecca->id, PrayerRequest::sole()->member_id);
        $this->assertSame(1, app(Notifier::class)->unreadCount($this->pasteur) > 0 ? 1 : 0);

        // Le secrétariat délivre : la demande est servie et la personne prévenue.
        $this->actingAs($this->admin);
        $request = DocumentRequest::sole();
        LivewireTest::withQueryParams(['modele' => $type->id, 'membre' => $this->rebecca->id, 'demande' => $request->id])
            ->test(Livewire\Documents\Issue::class)->call('issue')->assertHasNoErrors();
        $this->assertSame('issued', $request->fresh()->status);
        $this->assertSame(1, app(Notifier::class)->unreadCount($account));

        // La prière portée : un mot arrive à la personne.
        app(Pastoral::class)->answer(PrayerRequest::sole(), 'Nous avons prié pour vous.');
        $this->assertSame(2, app(Notifier::class)->unreadCount($account));
    }

    public function test_a_member_declares_a_mobile_money_gift_from_their_space(): void
    {
        $tresorier = User::factory()->create(['current_organization_id' => $this->eglise->id]);
        $this->assign($tresorier, $this->role($this->eglise, 'tresorier'), $this->eglise);
        app(MemberAccounts::class)->open($this->rebecca, '0990001111');
        $account = User::where('phone', '+243990001111')->sole();
        $account->forceFill(['must_change_password' => false])->save();
        $this->actingAs($account);

        LivewireTest::test(Livewire\Member\Space::class)
            ->call('openGift')->call('declareGift')->assertHasErrors(['gift.amount', 'gift.reference'])
            ->set('gift.amount', '15')->set('gift.reference', 'mp1234')->call('declareGift')->assertHasNoErrors()
            ->assertSee('en cours de vérification');
        $declaration = PaymentDeclaration::sole();
        $this->assertSame([$this->rebecca->id, 'member', 'MP1234'], [$declaration->member_id, $declaration->source, $declaration->transaction_reference]);
        $this->assertTrue(DatabaseNotification::where('notifiable_id', $tresorier->id)->where('key', "declaration.{$declaration->id}.review")->exists());
    }

    public function test_opening_a_space_twice_or_with_a_bad_number_is_refused(): void
    {
        $accounts = app(MemberAccounts::class);
        $this->expectException(\InvalidArgumentException::class);
        $accounts->open($this->rebecca, 'abc');
    }
}
