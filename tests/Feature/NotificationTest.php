<?php

namespace Tests\Feature;

use App\Jobs\SendPushNotification;
use App\Livewire;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\Department;
use App\Models\ExpenseRequest;
use App\Models\FinanceCategory;
use App\Models\Organization;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Budgets;
use App\Services\Expenses;
use App\Services\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $eglise;

    private User $admin;

    private User $responsable;

    private User $tresorier;

    private User $pasteur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['waumini.push.public_key' => null, 'waumini.push.private_key' => null]);
        $this->admin = User::factory()->create();
        $this->eglise = $this->createCommunity('Église', $this->admin);
        foreach (['responsable' => 'responsable_departement', 'tresorier' => 'tresorier', 'pasteur' => 'pasteur'] as $property => $role) {
            $this->{$property} = User::factory()->create(['current_organization_id' => $this->eglise->id]);
            $this->assign($this->{$property}, $this->role($this->eglise, $role), $this->eglise);
        }
        $this->inOrganization($this->eglise);
    }

    private function unread(User $user): array
    {
        return DatabaseNotification::where('notifiable_id', $user->id)->whereNull('read_at')->pluck('key')->sort()->values()->all();
    }

    private function expense(): ExpenseRequest
    {
        $this->actingAs($this->responsable);

        return app(Expenses::class)->submit($this->eglise, [
            'department_id' => Department::where('is_system', true)->value('id'),
            'category_id' => FinanceCategory::where('type', 'expense')->value('id'),
            'title' => 'Chaises', 'amount' => 200, 'currency' => 'USD', 'is_advance' => false,
        ]);
    }

    public function test_each_step_of_an_expense_reaches_those_who_must_act(): void
    {
        $e = $this->expense();
        $check = "expense.{$e->id}.check";

        // La finance doit vérifier ; celui qui demande n'est pas prévenu de sa propre demande.
        $this->assertSame([$check], $this->unread($this->tresorier));
        $this->assertSame([$check], $this->unread($this->admin));
        $this->assertSame([], $this->unread($this->responsable));
        $this->assertSame([], $this->unread($this->pasteur));
        $n = DatabaseNotification::where('notifiable_id', $this->tresorier->id)->sole();
        $this->assertSame('Dépense à vérifier : '.$e->number, $n->data['title']);
        $this->assertSame('/finances/depenses/'.$e->id, $n->path);

        // Le trésorier ouvre la dépense : sa nouveauté est lue, pas celle de l'administrateur.
        $this->actingAs($this->tresorier)->get(route('finances.expenses.show', $e))->assertOk();
        $this->assertSame([], $this->unread($this->tresorier));
        $this->assertSame([$check], $this->unread($this->admin));

        // Vérifiée : la vérification se range chez tous, les signataires sont prévenus.
        app(Expenses::class)->check($e->fresh());
        $this->assertSame(["expense.{$e->id}.approve"], $this->unread($this->admin));
        $this->assertSame(["expense.{$e->id}.approve"], $this->unread($this->pasteur));
        $this->assertSame([], $this->unread($this->tresorier));

        $this->actingAs($this->pasteur);
        app(Expenses::class)->approve($e->fresh(), $this->pasteur);
        $this->assertSame([], $this->unread($this->responsable));
        $this->actingAs($this->admin);
        app(Expenses::class)->approve($e->fresh(), $this->admin);

        // Approuvée : le demandeur l'apprend, la finance doit décaisser.
        $this->assertSame(["expense.{$e->id}.requester"], $this->unread($this->responsable));
        $this->assertSame(["expense.{$e->id}.disburse"], $this->unread($this->tresorier));
        $this->assertSame([], $this->unread($this->pasteur));

        $this->actingAs($this->tresorier);
        app(Expenses::class)->cancel($e->fresh());
        $this->assertSame([], $this->unread($this->tresorier));
    }

    public function test_a_rejected_request_tells_the_requester_why(): void
    {
        $e = $this->expense();
        $this->actingAs($this->tresorier);
        app(Expenses::class)->reject($e, $this->tresorier, 'Pas de devis');

        $this->assertSame([], $this->unread($this->admin));
        $n = DatabaseNotification::where('notifiable_id', $this->responsable->id)->whereNull('read_at')->sole();
        $this->assertSame('Pas de devis', $n->data['body']);
    }

    public function test_the_budget_goes_to_the_pastor_and_comes_back_to_the_treasurer(): void
    {
        $this->actingAs($this->tresorier);
        $budget = Budget::create(['organization_id' => $this->eglise->id, 'fiscal_year' => now()->year, 'version' => 1, 'status' => 'draft']);
        BudgetLine::create(['budget_id' => $budget->id, 'type' => 'income', 'label' => 'Offrandes', 'amount' => 1000,
            'category_id' => FinanceCategory::where('type', 'income')->value('id')]);
        app(Budgets::class)->submit($budget);

        $this->assertSame(["budget.{$budget->id}.approve"], $this->unread($this->pasteur));
        $this->actingAs($this->pasteur);
        app(Budgets::class)->approve($budget->fresh(), $this->pasteur);

        $this->assertSame([], $this->unread($this->pasteur));
        $this->assertSame([], $this->unread($this->admin));
        $this->assertSame(["budget-year.{$budget->organization_id}.{$budget->fiscal_year}.result"], $this->unread($this->tresorier));
    }

    public function test_the_same_news_is_replaced_not_repeated(): void
    {
        $notifier = app(Notifier::class);
        $notifier->send($this->eglise, $this->pasteur, 'x.1', ['title' => 'Un', 'url' => '/plan']);
        $notifier->send($this->eglise, $this->pasteur, 'x.1', ['title' => 'Deux', 'url' => '/plan']);

        $this->assertSame('Deux', DatabaseNotification::where('notifiable_id', $this->pasteur->id)->sole()->data['title']);
        $this->assertSame(1, $notifier->unreadCount($this->pasteur));
    }

    public function test_opening_a_news_item_switches_to_its_community(): void
    {
        $autre = $this->createCommunity('Autre église', User::factory()->create());
        $this->assign($this->pasteur, $this->role($autre, 'pasteur'), $autre);
        app(Notifier::class)->send($autre, $this->pasteur, 'plan.1', ['title' => 'Plan', 'url' => route('plan.index')]);
        $n = DatabaseNotification::sole();

        $this->actingAs($this->pasteur)->get(route('notifications.open', $n->id))->assertRedirect('/plan');
        $this->assertSame($autre->id, $this->pasteur->fresh()->current_organization_id);
        $this->assertNotNull($n->fresh()->read_at);

        // On n'ouvre pas les nouveautés des autres.
        $this->actingAs($this->tresorier)->get(route('notifications.open', $n->id))->assertNotFound();
    }

    public function test_the_bell_and_the_news_page(): void
    {
        app(Notifier::class)->send($this->eglise, $this->pasteur, 'plan.1', ['title' => 'Objectif à suivre', 'body' => 'Construire', 'url' => route('plan.index')]);
        $this->actingAs($this->pasteur);

        LivewireTest::test(Livewire\Notifications\Bell::class)->assertSee('1');
        LivewireTest::test(Livewire\Notifications\Index::class)
            ->assertSee('Objectif à suivre')->assertSee('Construire')
            ->call('markAllRead')
            ->assertSee('Rien de nouveau');
        $this->assertSame(0, app(Notifier::class)->unreadCount($this->pasteur));
        $this->get(route('notifications.index'))->assertOk()->assertSee('Sur cet appareil');
    }

    public function test_a_phone_subscribes_and_receives_the_news(): void
    {
        $this->actingAs($this->pasteur);
        $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123';
        $this->postJson(route('notifications.subscribe'), ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BKey', 'auth' => 'secret'], 'contentEncoding' => 'aes128gcm'])->assertNoContent();
        $this->postJson(route('notifications.subscribe'), ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BKey2', 'auth' => 'secret'], 'contentEncoding' => 'aes128gcm'])->assertNoContent();
        $this->assertSame('BKey2', PushSubscription::sole()->public_key);

        // Sans clés VAPID, rien ne part ; avec les clés, l'envoi passe par la file d'attente.
        Queue::fake();
        $this->actingAs($this->tresorier);
        app(Notifier::class)->send($this->eglise, $this->pasteur, 'a', ['title' => 'A', 'url' => '/plan']);
        Queue::assertNothingPushed();
        config(['waumini.push.public_key' => 'pub', 'waumini.push.private_key' => 'priv']);
        app(Notifier::class)->send($this->eglise, $this->pasteur, 'b', ['title' => 'B', 'url' => '/plan']);
        Queue::assertPushed(SendPushNotification::class, 1);

        $this->actingAs($this->pasteur)->deleteJson(route('notifications.unsubscribe'), ['endpoint' => $endpoint])->assertNoContent();
        $this->assertSame(0, PushSubscription::count());
    }
}
