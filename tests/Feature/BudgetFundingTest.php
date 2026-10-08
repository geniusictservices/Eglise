<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\CashAccount;
use App\Models\CashAccountCurrency;
use App\Models\Department;
use App\Models\FinanceCategory;
use App\Models\Organization;
use App\Models\User;
use App\Services\BudgetControl;
use App\Services\BudgetFundings;
use App\Services\Budgets;
use App\Services\Ledger;
use App\Services\Projects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class BudgetFundingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $eglise;

    private User $tresorier;

    private User $pasteur;

    private Department $jeunesse;

    private Department $general;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-20 10:00');
        $this->eglise = $this->createCommunity('Église');
        foreach (['tresorier' => 'tresorier', 'pasteur' => 'pasteur'] as $property => $role) {
            $this->{$property} = User::factory()->create();
            $this->assign($this->{$property}, $this->role($this->eglise, $role), $this->eglise);
        }
        $this->inOrganization($this->eglise);
        $this->jeunesse = Department::create(['name' => 'Jeunesse']);
        $this->general = Department::where('is_system', true)->firstOrFail();
        $this->actingAs($this->tresorier);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function category(string $type, string $name): int
    {
        return FinanceCategory::where('type', $type)->where('name', $name)->value('id');
    }

    private function line(Budget $budget, string $type, string $label, float $amount, ?int $department = null, ?int $project = null): BudgetLine
    {
        $category = $type === 'income' ? $this->category('income', 'Offrande du culte') : $this->category('expense', 'Fournitures et matériel');

        return BudgetLine::create(['budget_id' => $budget->id, 'type' => $type, 'department_id' => $department, 'category_id' => $category,
            'label' => $label, 'amount' => $amount, 'project_id' => $project]);
    }

    public function test_each_planned_expense_says_which_planned_income_pays_it(): void
    {
        $budgets = app(Budgets::class);
        $fundings = app(BudgetFundings::class);
        $budget = $budgets->prepare($this->eglise, 2027);
        $chaises = $this->line($budget, 'expense', 'Chaises', 450, $this->jeunesse->id);
        $courant = $this->line($budget, 'expense', 'Électricité', 1000, $this->general->id);
        $dimes = $this->line($budget, 'income', 'Dîmes', 1200);
        $cotisations = $this->line($budget, 'income', 'Cotisations des jeunes', 300, $this->jeunesse->id);

        // Sans financement, le budget ne se présente pas.
        try {
            $budgets->submit($budget);
            $this->fail('Le budget non financé a été présenté.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('2 dépenses prévues', $e->getMessage());
        }

        // Une recette ne finance pas plus que ce qu'elle prévoit, ni une dépense au-delà de son montant.
        foreach ([[$chaises, [$cotisations->id => 350]], [$chaises, [$dimes->id => 300, $cotisations->id => 200]]] as [$expense, $amounts]) {
            try {
                $fundings->set($expense, $amounts);
                $this->fail('Financement accepté à tort.');
            } catch (InvalidArgumentException) {
            }
        }
        $fundings->set($chaises, [$cotisations->id => 300, $dimes->id => 100]);
        $this->assertSame(['funded' => 400.0, 'missing' => 50.0], array_intersect_key($fundings->state($budget->fresh())['expense'][$chaises->id], ['missing' => 1, 'funded' => 1]));

        // La répartition automatique complète ce qui manque, sans toucher au reste.
        $this->assertSame(2, $fundings->auto($budget->fresh()));
        $state = $fundings->state($budget->fresh());
        $this->assertSame(0, $state['unfunded']);
        $this->assertSame(150.0, $state['expense'][$chaises->id]['sources']->firstWhere('line.id', $dimes->id)['amount']);
        $this->assertSame(50.0, $state['income'][$dimes->id]['free']);

        // Les dîmes baissent : le financement qui dépasse est retiré, la dépense redevient non financée.
        $dimes->update(['amount' => 1000]);
        $fundings->trim($dimes);
        $state = $fundings->state($budget->fresh());
        $this->assertSame(0.0, $state['income'][$dimes->id]['free']);
        $this->assertSame(1, $state['unfunded']);
        $this->assertSame(150.0, $state['missing']);

        $courant->update(['amount' => 850]);
        $fundings->trim($courant);
        $budgets->submit($budget->fresh());
        $this->assertSame('submitted', $budget->fresh()->status);
    }

    public function test_the_budget_takes_the_projects_of_the_year_with_their_carried_balance(): void
    {
        $projects = app(Projects::class);
        $caisse = CashAccount::create(['name' => 'Caisse', 'kind' => 'cash']);
        CashAccountCurrency::create(['cash_account_id' => $caisse->id, 'currency' => 'USD', 'opening_balance' => 0, 'opened_on' => '2025-01-01']);
        $temple = $projects->save($this->eglise, ['name' => 'Temple', 'department_id' => $this->general->id], [
            ['fiscal_year' => 2026, 'income_planned' => 5000, 'expense_planned' => 1000],
            ['fiscal_year' => 2027, 'income_planned' => 3000, 'expense_planned' => 6000],
        ]);
        $toiture = $projects->save($this->eglise, ['name' => 'Toiture'], [['fiscal_year' => 2027, 'expense_planned' => 400]]);
        // 2026 : 3 500 $ reçus, 800 $ dépensés : 2 700 $ passent sur 2027.
        app(Ledger::class)->record($caisse, 'USD', 'income', ['amount' => '3500', 'category_id' => $temple->category_id, 'project_id' => $temple->id, 'occurred_on' => '2026-06-01']);
        app(Ledger::class)->record($caisse, 'USD', 'expense', ['amount' => '800', 'category_id' => $this->category('expense', 'Fournitures et matériel'), 'project_id' => $temple->id, 'occurred_on' => '2026-07-01']);
        $this->assertSame(2700.0, $projects->carriedInto($temple, 2027));

        $budgets = app(Budgets::class);
        $budget = $budgets->prepare($this->eglise, 2027);
        $lines = $budget->lines()->get();
        $this->assertSame(4, $lines->count());
        $this->assertSame('2700.00', (string) $lines->firstWhere('source', 'carryover')->amount);
        $this->assertSame('3000.00', (string) $lines->where('project_id', $temple->id)->where('source', 'project')->firstWhere('type', 'income')->amount);
        $this->assertSame('6000.00', (string) $lines->where('project_id', $temple->id)->firstWhere('type', 'expense')->amount);

        // Reprendre deux fois ne double rien.
        $budgets->importProjects($budget);
        $this->assertSame(4, $budget->lines()->count());

        // L'argent du temple ne finance que le temple ; les dîmes financent la toiture.
        $dimes = $this->line($budget, 'income', 'Dîmes', 1000);
        $fundings = app(BudgetFundings::class);
        $fundings->auto($budget->fresh());
        $state = $fundings->state($budget->fresh());
        $templeExpense = $lines->where('project_id', $temple->id)->firstWhere('type', 'expense');
        $toitureExpense = $lines->firstWhere('project_id', $toiture->id);
        $this->assertSame(0, $state['unfunded']);
        $this->assertSame(['Temple : solde reporté', 'Temple : collecte prévue', 'Dîmes'], $state['expense'][$templeExpense->id]['sources']->pluck('line.label')->all());
        $this->assertSame(['Dîmes'], $state['expense'][$toitureExpense->id]['sources']->pluck('line.label')->all());
        try {
            $fundings->set($toitureExpense, [$lines->firstWhere('source', 'carryover')->id => 400]);
            $this->fail('L’argent du temple a financé la toiture.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('ne finance que ce projet', $e->getMessage());
        }

        $budgets->submit($budget->fresh());
        $budgets->approve($budget->fresh(), $this->pasteur);

        // Adopté : la toiture a 400 $ des recettes ordinaires ; le solde reporté n'est pas une recette de 2027.
        $this->assertSame(400.0, $projects->totals($toiture->fresh())['available']);
        $this->assertSame(400.0, $projects->years($toiture->fresh())->firstWhere('year', 2027)['budgeted']);
        $execution = app(BudgetControl::class)->execution($this->eglise, 2027);
        $this->assertSame(4000.0, $execution['totals']['income_budgeted']);
    }

    public function test_finance_sets_the_funding_of_a_line_on_screen(): void
    {
        $budget = app(Budgets::class)->prepare($this->eglise, 2027);
        $chaises = $this->line($budget, 'expense', 'Chaises', 450, $this->jeunesse->id);
        $dimes = $this->line($budget, 'income', 'Dîmes', 1200);

        LivewireTest::test(Livewire\Budget\Version::class, ['budget' => $budget])
            ->assertSee('1 dépense prévue n’a pas encore de financement complet')
            ->call('editFunding', $chaises->id)
            ->set("funding.{$dimes->id}", '2000')->call('saveFunding')->assertHasErrors('funding')
            ->call('fundFrom', $dimes->id)->assertSet("funding.{$dimes->id}", '450')
            ->call('saveFunding')->assertHasNoErrors()
            ->assertSee('Chaque dépense prévue est financée par des recettes prévues.')
            ->set('tab', 'recettes')->assertSee('libre')
            ->set('tab', 'projets')->assertSee('Aucun projet dans ce budget.');

        // Le pasteur voit le financement, sans le modifier.
        $budget->update(['status' => 'submitted', 'submitted_by' => $this->tresorier->id, 'submitted_at' => now()]);
        $this->actingAs($this->pasteur);
        LivewireTest::test(Livewire\Budget\Version::class, ['budget' => $budget])->assertSee('Financée par Dîmes')->call('editFunding', $chaises->id)->assertForbidden();
    }
}
