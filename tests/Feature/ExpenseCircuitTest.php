<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\CashAccount;
use App\Models\CashAccountCurrency;
use App\Models\Department;
use App\Models\ExpenseAttachment;
use App\Models\ExpenseRequest;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Services\ExchangeRateService;
use App\Services\Expenses;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class ExpenseCircuitTest extends TestCase
{
    use RefreshDatabase;

    private Organization $eglise;

    private User $admin;

    private User $responsable;

    private User $tresorier;

    private User $pasteur;

    private CashAccount $caisse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->admin = User::factory()->create();
        $this->eglise = $this->createCommunity('Église', $this->admin);
        foreach (['responsable' => 'responsable_departement', 'tresorier' => 'tresorier', 'pasteur' => 'pasteur'] as $property => $role) {
            $this->{$property} = User::factory()->create();
            $this->assign($this->{$property}, $this->role($this->eglise, $role), $this->eglise);
        }
        $this->inOrganization($this->eglise);
        app(ExchangeRateService::class)->setRate($this->eglise, 'CDF', '2800', now()->subYear());
        $this->caisse = CashAccount::create(['name' => 'Caisse', 'kind' => 'cash']);
        CashAccountCurrency::create(['cash_account_id' => $this->caisse->id, 'currency' => 'USD', 'opening_balance' => 500, 'opened_on' => now()->subYear()]);
    }

    private function request(array $data = []): ExpenseRequest
    {
        $this->actingAs($this->responsable);

        return app(Expenses::class)->submit($this->eglise, $data + [
            'department_id' => Department::where('is_system', true)->value('id'),
            'category_id' => FinanceCategory::where('type', 'expense')->value('id'),
            'title' => 'Chaises', 'amount' => 200, 'currency' => 'USD', 'is_advance' => false,
        ]);
    }

    public function test_a_department_head_requests_an_expense_with_a_quote(): void
    {
        $this->actingAs($this->responsable);

        LivewireTest::test(Livewire\Finances\Expenses\Form::class)
            ->set('title', '50 chaises pour la salle')
            ->set('amount', '450')
            ->set('files', [UploadedFile::fake()->create('devis.pdf', 100, 'application/pdf')])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $request = ExpenseRequest::sole();
        $this->assertSame('submitted', $request->status);
        $this->assertSame('D-'.now()->year.'-0001', $request->number);
        $this->assertSame(2, $request->approvals_required);
        $this->assertSame($this->responsable->id, $request->requested_by);
        $attachment = ExpenseAttachment::sole();
        $this->assertSame('quote', $attachment->kind);
        $this->get(route('finances.expenses.attachment', $attachment))->assertOk();
        $this->get(route('finances.expenses.show', $request))->assertOk()->assertSee('50 chaises pour la salle');
    }

    public function test_the_full_circuit_with_two_signatures(): void
    {
        $request = $this->request();

        $this->actingAs($this->tresorier);
        LivewireTest::test(Livewire\Finances\Expenses\Show::class, ['expense' => $request])
            ->assertSee('Contrôlée, à approuver')
            ->call('check')->assertHasNoErrors();
        $this->assertSame('checked', $request->fresh()->status);

        // Le trésorier ne signe pas : ce n'est pas un approbateur.
        LivewireTest::test(Livewire\Finances\Expenses\Show::class, ['expense' => $request])->call('approve')->assertForbidden();

        $this->actingAs($this->pasteur);
        LivewireTest::test(Livewire\Finances\Expenses\Show::class, ['expense' => $request])
            ->call('approve')->assertHasNoErrors()
            ->assertDispatched('notify', fn ($name, $params) => str_contains($params['message'], 'encore une autre'));
        $this->assertSame('checked', $request->fresh()->status);

        // Une deuxième signature de la même personne ne compte pas.
        LivewireTest::test(Livewire\Finances\Expenses\Show::class, ['expense' => $request])->call('approve')->assertHasErrors('note');

        $this->actingAs($this->admin);
        LivewireTest::test(Livewire\Finances\Expenses\Show::class, ['expense' => $request])->call('approve')->assertHasNoErrors();
        $this->assertSame('approved', $request->fresh()->status);

        $this->actingAs($this->tresorier);
        LivewireTest::test(Livewire\Finances\Expenses\Show::class, ['expense' => $request])
            ->assertSet('accountId', (string) $this->caisse->id)
            ->call('disburse')->assertHasNoErrors();
        $request->refresh();
        $this->assertSame('disbursed', $request->status);
        $t = FinanceTransaction::sole();
        $this->assertSame('expense', $t->type);
        $this->assertSame($request->id, $t->expense_request_id);
        $this->assertTrue(app(Ledger::class)->balance($this->caisse, 'USD')->isEqualTo(300));

        LivewireTest::test(Livewire\Finances\Expenses\Show::class, ['expense' => $request])
            ->set('spent', '200')
            ->set('files', [UploadedFile::fake()->image('facture.jpg')])
            ->call('justify')->assertHasNoErrors();
        $this->assertSame('justified', $request->fresh()->status);
        $this->assertSame('invoice', ExpenseAttachment::sole()->kind);
        $this->assertSame(1, FinanceTransaction::count());
    }

    public function test_the_requester_never_approves_their_own_request(): void
    {
        $this->actingAs($this->admin);
        $request = app(Expenses::class)->submit($this->eglise, [
            'department_id' => Department::where('is_system', true)->value('id'), 'category_id' => FinanceCategory::where('type', 'expense')->value('id'),
            'title' => 'Carburant', 'amount' => 40, 'currency' => 'USD', 'is_advance' => false,
        ]);
        app(Expenses::class)->check($request);

        LivewireTest::test(Livewire\Finances\Expenses\Show::class, ['expense' => $request->fresh()])
            ->assertDontSee('Signer et approuver')
            ->call('approve')
            ->assertHasErrors('note');
        $this->assertSame(0, $request->approvals()->count());
    }

    public function test_the_church_chooses_one_two_or_three_signatures(): void
    {
        $this->actingAs($this->admin);
        LivewireTest::test(Livewire\Finances\Settings::class)
            ->set('tab', 'circuit')
            ->set('approvalsRequired', 4)->call('saveCircuit')->assertHasErrors('approvalsRequired')
            ->set('approvalsRequired', 1)->set('advanceDays', 7)->call('saveCircuit')->assertHasNoErrors();

        $request = $this->request();
        $this->assertSame(1, $request->approvals_required);
        app(Expenses::class)->check($request);
        app(Expenses::class)->approve($request->fresh(), $this->pasteur);
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_an_advance_returns_what_was_not_spent(): void
    {
        $member = Member::create(['last_name' => 'KAKULE', 'first_name' => 'Josué']);
        $request = $this->request(['title' => 'Évangélisation', 'amount' => 150, 'is_advance' => true, 'beneficiary_member_id' => $member->id]);
        $expenses = app(Expenses::class);
        $expenses->check($request);
        $expenses->approve($request->fresh(), $this->pasteur);
        $expenses->approve($request->fresh(), $this->admin);
        $this->actingAs($this->tresorier);
        $expenses->disburse($request->fresh(), $this->caisse);
        $request->refresh();
        $this->assertTrue($request->justify_by->isSameDay(today()->addDays(14)));

        // On ne justifie pas plus que l'avance.
        LivewireTest::test(Livewire\Finances\Expenses\Show::class, ['expense' => $request])
            ->set('spent', '160')->call('justify')->assertHasErrors('spent')
            ->set('spent', '128')->assertSee('Reste rendu dans Caisse')
            ->call('justify')->assertHasNoErrors();

        $request->refresh();
        $this->assertSame('justified', $request->status);
        $return = FinanceTransaction::findOrFail($request->return_transaction_id);
        $this->assertSame('income', $return->type);
        $this->assertSame('22.00', (string) $return->amount);
        $this->assertSame('Retour sur avance', $return->category->name);
        $this->assertTrue(app(Ledger::class)->balance($this->caisse, 'USD')->isEqualTo(372));
    }

    public function test_an_overdue_advance_can_block_the_next_one(): void
    {
        $member = Member::create(['last_name' => 'MASIKA', 'first_name' => 'Grâce']);
        $expenses = app(Expenses::class);
        $first = $this->request(['amount' => 80, 'is_advance' => true, 'beneficiary_member_id' => $member->id]);
        $expenses->check($first);
        $expenses->approve($first->fresh(), $this->pasteur);
        $expenses->approve($first->fresh(), $this->admin);
        $expenses->disburse($first->fresh(), $this->caisse);
        $first->update(['justify_by' => today()->subDay()]);
        $this->assertTrue($first->fresh()->isOverdue());

        // Sans blocage, une nouvelle avance reste possible.
        $this->request(['amount' => 30, 'is_advance' => true, 'beneficiary_member_id' => $member->id]);

        $settings = $this->eglise->settings ?? [];
        $settings['finance']['expenses'] = ['approvals_required' => 2, 'advance_days' => 14, 'block_unjustified_advances' => true];
        $this->eglise->update(['settings' => $settings]);

        $this->actingAs($this->responsable);
        LivewireTest::test(Livewire\Finances\Expenses\Form::class)
            ->set('title', 'Autre avance')->set('amount', '20')->set('isAdvance', true)
            ->call('chooseBeneficiary', $member->id)
            ->call('save')
            ->assertHasErrors('beneficiaryId');
        $this->assertSame(2, ExpenseRequest::count());
    }

    public function test_a_refused_request_keeps_its_reason_and_nothing_is_paid(): void
    {
        $request = $this->request();
        $this->actingAs($this->pasteur);
        LivewireTest::test(Livewire\Finances\Expenses\Show::class, ['expense' => $request])
            ->set('reason', 'Non')->call('reject')->assertHasErrors('reason')
            ->set('reason', 'Pas prévu au budget')->call('reject')->assertHasNoErrors();

        $request->refresh();
        $this->assertSame('rejected', $request->status);
        $this->assertSame('Pas prévu au budget', $request->reject_reason);
        $this->assertSame(0, FinanceTransaction::count());
    }

    public function test_a_department_head_only_sees_their_own_requests(): void
    {
        $mine = $this->request(['title' => 'Ma demande']);
        $this->actingAs($this->admin);
        $other = app(Expenses::class)->submit($this->eglise, [
            'department_id' => Department::where('is_system', true)->value('id'), 'category_id' => FinanceCategory::where('type', 'expense')->value('id'),
            'title' => 'Demande du siège', 'amount' => 10, 'currency' => 'USD', 'is_advance' => false,
        ]);

        $this->actingAs($this->responsable);
        $this->get(route('finances.expenses', ['etape' => 'all']))->assertOk()->assertSee('Ma demande')->assertDontSee('Demande du siège');
        $this->get(route('finances.expenses.show', $mine))->assertOk();
        $this->get(route('finances.expenses.show', $other))->assertForbidden();
    }
}
