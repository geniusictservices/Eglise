<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionDeclaration;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Pricing;
use App\Services\SubscriptionDeclarations;
use App\Services\SupportTickets;
use App\Support\SupportAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class SupportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Organization $eglise;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['waumini.push.public_key' => null]);
        $this->admin = User::factory()->create();
        $this->eglise = $this->createCommunity('Église de la Paix', $this->admin);
        $this->inOrganization($this->eglise);
    }

    private function staff(string $role): User
    {
        return User::factory()->create(['is_platform_staff' => true, 'platform_role' => $role, 'current_organization_id' => null]);
    }

    private function notified(User $user, string $key): bool
    {
        return DatabaseNotification::where('notifiable_id', $user->id)->where('key', $key)->whereNull('read_at')->exists();
    }

    public function test_the_community_declares_its_subscription_payment_and_genius_ict_validates_it(): void
    {
        app(Pricing::class)->ensureCatalogue();
        $plan = Plan::where('key', 'kawaida')->sole();
        $commercial = $this->staff('commercial');

        $this->actingAs($this->admin);
        LivewireTest::test(Livewire\Subscription::class)
            ->call('openDeclare')->set('payment.plan_id', (string) $plan->id)->set('tier', 'small')
            ->assertSee('À payer : 25 $')
            ->set('payment.amount', '25')->set('payment.reference', 'mp 777 001')->call('declare')->assertHasNoErrors()
            ->assertSee('en cours de vérification')
            ->call('openDeclare')->set('payment.plan_id', (string) $plan->id)->set('payment.amount', '25')->set('payment.reference', 'MP777001')
            ->call('declare')->assertHasErrors('payment.reference');

        $declaration = SubscriptionDeclaration::sole();
        $this->assertSame(['MP777001', '25.00'], [$declaration->reference, $declaration->expected_usd]);
        $this->assertTrue($this->notified($commercial, "subscription-declaration.{$declaration->id}"));

        $this->actingAs($commercial);
        LivewireTest::test(Livewire\Admin\Communities\Show::class, ['organization' => $this->eglise])
            ->assertSee('Paiements déclarés à vérifier')->call('validateDeclaration', $declaration->id);

        $subscription = Subscription::sole();
        $this->assertSame(['validated', $subscription->id], [$declaration->fresh()->status, $declaration->fresh()->subscription_id]);
        $this->assertSame('MP777001', $subscription->payment_reference);
        $this->assertSame('active', $this->eglise->fresh()->status);
        $this->assertTrue($this->notified($this->admin, "subscription-declaration.{$declaration->id}.result"));
        $this->assertFalse($this->notified($commercial, "subscription-declaration.{$declaration->id}"));
    }

    public function test_an_unfound_payment_is_rejected_with_a_reason(): void
    {
        app(Pricing::class)->ensureCatalogue();
        $this->actingAs($this->admin);
        $declaration = app(SubscriptionDeclarations::class)->declare($this->eglise, Plan::where('key', 'msingi')->sole(), 'small', 'monthly',
            ['amount' => '15', 'method' => 'M-Pesa', 'reference' => 'FAUX', 'paid_on' => today()->toDateString()]);

        $this->actingAs($this->staff('commercial'));
        LivewireTest::test(Livewire\Admin\Communities\Show::class, ['organization' => $this->eglise])
            ->call('askReject', $declaration->id)->call('rejectDeclaration')->assertHasErrors('rejectReason')
            ->set('rejectReason', 'Aucun paiement reçu avec cet ID')->call('rejectDeclaration')->assertHasNoErrors();
        $this->assertSame('rejected', $declaration->fresh()->status);
        $this->assertSame(0, Subscription::count());

        // Le support (sans droit de facturation) ne valide rien.
        $this->actingAs($this->staff('support'));
        LivewireTest::test(Livewire\Admin\Communities\Show::class, ['organization' => $this->eglise])->call('validateDeclaration', $declaration->id)->assertForbidden();
    }

    public function test_the_support_sees_the_community_read_only_with_its_consent(): void
    {
        Member::create(['last_name' => 'MASIKA', 'first_name' => 'Rebecca', 'gender' => 'F']);
        $agent = $this->staff('support');
        $this->actingAs($agent);

        // Sans accord, rien.
        LivewireTest::test(Livewire\Admin\Communities\Show::class, ['organization' => $this->eglise])->call('openSupport', $this->eglise->id)->assertNoRedirect();
        $this->assertNull(app(SupportAccess::class)->organization());

        // La communauté ouvre l'accès pour trois jours.
        $this->actingAs($this->admin);
        LivewireTest::withQueryParams(['onglet' => 'support'])->test(Livewire\Settings\Edit::class)->set('supportDays', 3)->set('supportAccess', true);
        $this->assertTrue($this->eglise->fresh()->support_access_until->between(now()->addDays(3)->subMinute(), now()->addDays(3)->addMinute()));

        $this->actingAs($agent);
        LivewireTest::test(Livewire\Admin\Communities\Show::class, ['organization' => $this->eglise->fresh()])->call('openSupport', $this->eglise->id)->assertRedirect(route('dashboard'));
        $this->assertSame(1, AuditLog::where('event', 'support_opened')->where('organization_id', $this->eglise->id)->count());

        $this->get(route('members.index'))->assertOk()->assertSee('MASIKA')->assertSee('en lecture seule');
        $this->get(route('pastoral.index'))->assertForbidden();
        $this->get(route('users.create'))->assertForbidden();
        $this->post(route('organizations.switch', $this->eglise))->assertForbidden();
        LivewireTest::test(Livewire\Members\Form::class)->assertForbidden();

        // La communauté retire son accord : l'agent est renvoyé à son espace.
        $this->eglise->update(['support_access_until' => null]);
        app()->forgetScopedInstances();
        $this->get(route('members.index'))->assertRedirect(route('admin.dashboard'));
        $this->assertSame(1, AuditLog::where('event', 'support_closed')->count());
        $this->get(route('members.index'))->assertRedirect(route('admin.dashboard'));
    }

    public function test_the_support_leaves_the_community(): void
    {
        $this->eglise->update(['support_access_until' => now()->addDay()]);
        $agent = $this->staff('support');
        $this->actingAs($agent);
        app(SupportAccess::class)->start($agent, $this->eglise->fresh());
        $this->get(route('dashboard'))->assertOk()->assertSee('Quitter');
        $this->post(route('support.leave'))->assertRedirect(route('admin.communities.show', $this->eglise));
        app()->forgetScopedInstances();
        $this->get(route('dashboard'))->assertRedirect(route('admin.dashboard'));

        // Une agente « contenus » n'ouvre aucune communauté.
        $this->expectException(\InvalidArgumentException::class);
        app(SupportAccess::class)->start($this->staff('contenu'), $this->eglise->fresh());
    }

    public function test_a_support_ticket_goes_back_and_forth(): void
    {
        $agent = $this->staff('support');
        $secretaire = User::factory()->create(['current_organization_id' => $this->eglise->id]);
        $this->assign($secretaire, $this->role($this->eglise, 'secretaire'), $this->eglise);

        $this->actingAs($secretaire);
        LivewireTest::test(Livewire\Support\Index::class)
            ->set('form.subject', 'Le reçu est coupé')->set('form.body', 'En 58 mm, le texte sort coupé.')->call('create')->assertRedirect();
        $ticket = SupportTicket::sole();
        $this->assertSame(['open', 'T'.now()->year.'-0001'], [$ticket->status, $ticket->number]);
        $this->assertTrue($this->notified($agent, "ticket.{$ticket->id}.staff"));

        $this->actingAs($agent);
        LivewireTest::test(Livewire\Admin\Tickets\Index::class)->assertSee('Le reçu est coupé');
        LivewireTest::test(Livewire\Admin\Tickets\Show::class, ['ticket' => $ticket])
            ->set('body', 'Choisissez le format 58 mm dans les Paramètres.')->call('reply')->assertHasNoErrors();
        $this->assertSame(['answered', $agent->id], [$ticket->fresh()->status, $ticket->fresh()->assigned_to]);
        $this->assertTrue($this->notified($secretaire, "ticket.{$ticket->id}.community"));

        $this->actingAs($secretaire);
        LivewireTest::test(Livewire\Support\Show::class, ['ticket' => $ticket])
            ->assertSee('Choisissez le format 58 mm')->set('body', 'Merci, cela marche !')->call('reply')->call('close');
        $this->assertSame('closed', $ticket->fresh()->status);
        $this->assertSame(3, $ticket->messages()->count());

        // Un autre utilisateur de la communauté ne voit pas la demande ; l'administrateur, si.
        $tresorier = User::factory()->create(['current_organization_id' => $this->eglise->id]);
        $this->assign($tresorier, $this->role($this->eglise, 'tresorier'), $this->eglise);
        $this->actingAs($tresorier)->get(route('support.show', $ticket))->assertNotFound();
        $this->actingAs($this->admin)->get(route('support.show', $ticket))->assertOk();
        $this->actingAs($this->staff('contenu'))->get(route('admin.tickets'))->assertForbidden();
    }

    public function test_a_closed_ticket_reopens_with_a_new_message(): void
    {
        $this->actingAs($this->admin);
        $tickets = app(SupportTickets::class);
        $ticket = $tickets->open($this->eglise, $this->admin, 'Question', 'question', 'Bonjour');
        $tickets->close($ticket);
        $tickets->reply($ticket->fresh(), $this->admin, 'Encore une question', fromStaff: false);
        $this->assertSame('open', $ticket->fresh()->status);
    }
}
