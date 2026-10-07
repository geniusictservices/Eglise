<?php

namespace Tests\Feature;

use App\Livewire;
use App\Livewire\Finances\Expenses\Form;
use App\Models\BudgetLine;
use App\Models\BudgetOverrun;
use App\Models\CashAccount;
use App\Models\CashAccountCurrency;
use App\Models\Department;
use App\Models\ExpenseRequest;
use App\Models\FinanceCategory;
use App\Models\Organization;
use App\Models\User;
use App\Services\BudgetControl;
use App\Services\Budgets;
use App\Services\ExchangeRateService;
use App\Services\Expenses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class BudgetControlTest extends TestCase
{
    use RefreshDatabase;

    private Organization $eglise;

    private User $admin;

    private User $tresorier;

    private User $pasteur;

    private Department $jeunesse;

    private CashAccount $caisse;

    private int $missions;

    private int $fournitures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-10-06 10:00');
        $this->admin = User::factory()->create();
        $this->eglise = $this->createCommunity('Église', $this->admin);
        foreach (['tresorier' => 'tresorier', 'pasteur' => 'pasteur'] as $property => $role) {
            $this->{$property} = User::factory()->create();
            $this->assign($this->{$property}, $this->role($this->eglise, $role), $this->eglise);
        }
        $this->inOrganization($this->eglise);
        app(ExchangeRateService::class)->setRate($this->eglise, 'CDF', '2800', now()->subYear());
        $this->caisse = CashAccount::create(['name' => 'Caisse', 'kind' => 'cash']);
        CashAccountCurrency::create(['cash_account_id' => $this->caisse->id, 'currency' => 'USD', 'opening_balance' => 5000, 'opened_on' => now()->subYear()]);

        $this->jeunesse = Department::create(['name' => 'Jeunesse']);
        $this->missions = FinanceCategory::where('type', 'expense')->where('name', 'Évangélisation et missions')->value('id');
        $this->fournitures = FinanceCategory::where('type', 'expense')->where('name', 'Fournitures et matériel')->value('id');

        // Budget 2026 adopté : 1 500 $ pour les missions, 400 $ pour les fournitures de la Jeunesse.
        $this->actingAs($this->tresorier);
        $budgets = app(Budgets::class);
        $budget = $budgets->prepare($this->eglise, 2026);
        foreach ([[$this->missions, 1500], [$this->fournitures, 400]] as [$category, $amount]) {
            BudgetLine::create(['budget_id' => $budget->id, 'type' => 'expense', 'department_id' => $this->jeunesse->id, 'category_id' => $category, 'label' => 'Ligne', 'amount' => $amount]);
        }
        $budgets->submit($budget);
        $budgets->approve($budget->fresh(), $this->pasteur);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function request(int $category, string $amount, string $currency = 'USD'): ExpenseRequest
    {
        return app(Expenses::class)->submit($this->eglise, ['department_id' => $this->jeunesse->id, 'category_id' => $category,
            'title' => 'Dépense', 'amount' => $amount, 'currency' => $currency, 'is_advance' => false]);
    }

    public function test_an_expense_within_the_budget_passes_and_is_committed(): void
    {
        $request = $this->request($this->fournitures, '840000', 'CDF'); // 300 $
        $control = app(BudgetControl::class);
        $this->assertNull($control->shortfall($request));
        app(Expenses::class)->check($request);

        $line = $control->execution($this->eglise, 2026)['expense'][BudgetControl::key($this->jeunesse->id, $this->fournitures)];
        $this->assertSame(300.0, $line['committed']);
        $this->assertSame(100.0, $line['available']);

        // Une fois décaissée, elle passe d'engagée à dépensée.
        $expenses = app(Expenses::class);
        $expenses->approve($request->fresh(), $this->pasteur);
        $expenses->approve($request->fresh(), $this->admin);
        CashAccountCurrency::create(['cash_account_id' => $this->caisse->id, 'currency' => 'CDF', 'opening_balance' => 1000000, 'opened_on' => now()->subYear()]);
        $expenses->disburse($request->fresh(), $this->caisse);
        $line = $control->execution($this->eglise, 2026)['expense'][BudgetControl::key($this->jeunesse->id, $this->fournitures)];
        $this->assertSame(0.0, $line['committed']);
        $this->assertSame(300.0, $line['actual']);
    }

    public function test_an_overrun_needs_the_pastor_and_says_where_the_money_comes_from(): void
    {
        $request = $this->request($this->fournitures, '650');

        // La finance ne peut pas contrôler une dépense au-delà du budget.
        LivewireTest::test(Livewire\Finances\Expenses\Show::class, ['expense' => $request])
            ->assertSee('dépasse le disponible')
            ->assertDontSee('Contrôlée, à approuver')
            ->call('check')->assertHasErrors('note');

        // La demande dit d'où vient l'argent : ici, la ligne des missions.
        LivewireTest::test(Livewire\Finances\Expenses\Show::class, ['expense' => $request])
            ->call('askOverrun')
            ->assertSet('overrun.amount', '250')
            ->set('overrun.reason', 'La convention ne peut pas attendre.')
            ->call('requestOverrun')->assertHasErrors('overrun.source_key')
            ->set('overrun.source_key', $this->jeunesse->id.'-'.$this->missions)
            ->call('requestOverrun')->assertHasNoErrors();
        $overrun = BudgetOverrun::sole();
        $this->assertSame('pending', $overrun->status);

        // Le trésorier ne s'autorise pas lui-même.
        LivewireTest::test(Livewire\Finances\Expenses\Show::class, ['expense' => $request])->call('decideOverrun', true)->assertForbidden();

        $this->actingAs($this->pasteur);
        LivewireTest::test(Livewire\Finances\Expenses\Show::class, ['expense' => $request])
            ->assertSee('Prise sur Jeunesse')
            ->call('decideOverrun', true)->assertHasNoErrors();
        $this->assertSame('authorized', $overrun->fresh()->status);

        // La dépense passe ; la ligne des missions a cédé 250 $.
        $this->actingAs($this->tresorier);
        app(Expenses::class)->check($request->fresh());
        $execution = app(BudgetControl::class)->execution($this->eglise, 2026)['expense'];
        $this->assertSame(1250.0, $execution[BudgetControl::key($this->jeunesse->id, $this->missions)]['available']);
        $this->assertSame(0.0, $execution[BudgetControl::key($this->jeunesse->id, $this->fournitures)]['available']);
    }

    public function test_an_expense_without_a_budget_line_needs_an_authorization(): void
    {
        $accueil = FinanceCategory::where('type', 'expense')->where('name', 'Accueil et réceptions')->value('id');
        $request = $this->request($accueil, '60');
        $this->assertSame(60.0, app(BudgetControl::class)->shortfall($request));

        $control = app(BudgetControl::class);
        $overrun = $control->requestOverrun($request, ['amount' => 60, 'source' => 'new_income', 'source_detail' => 'Don de la famille Mbuyi', 'reason' => 'Réception des invités de la convention']);
        $this->assertSame('Recette nouvelle : Don de la famille Mbuyi', $overrun->sourceLabel());

        // Refuser demande un motif.
        try {
            $control->decide($overrun, $this->pasteur, false);
            $this->fail('Un refus sans motif aurait dû être refusé.');
        } catch (InvalidArgumentException) {
        }
        $control->decide($overrun, $this->pasteur, false, 'Pas cette année');
        $this->assertSame('refused', $overrun->fresh()->status);
        $this->assertSame(60.0, $control->shortfall($request->fresh()));
    }

    public function test_a_transfer_cannot_take_more_than_the_line_has(): void
    {
        $request = $this->request($this->fournitures, '2400');
        $this->expectException(InvalidArgumentException::class);
        app(BudgetControl::class)->requestOverrun($request, ['amount' => 2000, 'source' => 'transfer',
            'source_department_id' => $this->jeunesse->id, 'source_category_id' => $this->missions, 'reason' => 'Trop gros']);
    }

    public function test_without_an_adopted_budget_there_is_no_control(): void
    {
        $request = app(Expenses::class)->submit($this->eglise, ['department_id' => $this->jeunesse->id, 'category_id' => $this->fournitures,
            'title' => 'Chaises 2027', 'amount' => 9000, 'currency' => 'USD', 'is_advance' => false, 'needed_on' => '2027-02-01']);
        $this->assertNull(app(BudgetControl::class)->shortfall($request));
        $this->get(route('budget.execution', ['exercice' => 2026]))->assertOk()->assertSee('Jeunesse');
    }

    public function test_an_expense_is_linked_to_a_budget_line_unless_it_is_unforeseen(): void
    {
        $line = BudgetLine::where('category_id', $this->fournitures)->whereHas('budget', fn ($q) => $q->where('status', 'adopted'))->sole();
        $this->actingAs($this->admin);

        // Par défaut, la dépense se rattache à une ligne du budget : son département et sa catégorie suivent.
        LivewireTest::test(Form::class)
            ->assertSee('Prévue au budget')->assertSee('reste 400,00')
            ->set('title', 'Ballons pour le tournoi')->set('amount', '120')
            ->call('save')->assertHasErrors('budgetLineId')
            ->set('budgetLineId', (string) $line->id)->assertSet('departmentId', (string) $this->jeunesse->id)
            ->call('save')->assertHasNoErrors()->assertRedirect();
        $request = ExpenseRequest::latest('id')->first();
        $this->assertSame([$line->id, false, $this->jeunesse->id, $this->fournitures], [$request->budget_line_id, $request->is_unforeseen, $request->department_id, $request->category_id]);
        $this->get(route('finances.expenses.show', $request))->assertOk()->assertSee('Ligne du budget : Ligne');

        // Un imprévu dit pourquoi il n'était pas prévu ; sans ligne au budget, il demandera un dépassement.
        $travaux = FinanceCategory::where('type', 'expense')->where('name', 'Entretien et réparations')->value('id');
        LivewireTest::test(Form::class)
            ->set('budgetMode', 'imprevu')->set('title', 'Tôles après l’orage')->set('amount', '300')
            ->set('departmentId', (string) $this->jeunesse->id)->set('categoryId', (string) $travaux)
            ->call('save')->assertHasErrors('unforeseenReason')
            ->set('unforeseenReason', 'La toiture a cédé pendant l’orage')->call('save')->assertHasNoErrors();
        $imprevu = ExpenseRequest::latest('id')->first();
        $this->assertSame([null, true, 'La toiture a cédé pendant l’orage'], [$imprevu->budget_line_id, $imprevu->is_unforeseen, $imprevu->unforeseen_reason]);
        $this->assertSame(300.0, app(BudgetControl::class)->shortfall($imprevu));
        $this->actingAs($this->admin)->get(route('finances.expenses.show', $imprevu))->assertOk()->assertSee('Imprévu')->assertSee('La toiture a cédé pendant l’orage');
        $this->get(route('finances.expenses'))->assertOk()->assertSee('imprévu');

        // Une ligne qui n'est pas celle du budget adopté est refusée.
        $this->expectException(InvalidArgumentException::class);
        app(Expenses::class)->submit($this->eglise, ['title' => 'X', 'amount' => 10, 'currency' => 'USD', 'budget_line_id' => 999999]);
    }
}
