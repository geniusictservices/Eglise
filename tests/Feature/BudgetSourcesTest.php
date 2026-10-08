<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\CashAccount;
use App\Models\CashAccountCurrency;
use App\Models\Department;
use App\Models\FinanceCategory;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Pledge;
use App\Models\User;
use App\Services\BudgetControl;
use App\Services\Budgets;
use App\Services\BudgetSources;
use App\Services\Ledger;
use App\Services\Projects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class BudgetSourcesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $eglise;

    private User $tresorier;

    private User $pasteur;

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
        $this->general = Department::where('is_system', true)->firstOrFail();
        $this->actingAs($this->tresorier);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function line(Budget $budget, string $type, string $category, string $label, float $amount): BudgetLine
    {
        return BudgetLine::create(['budget_id' => $budget->id, 'type' => $type, 'department_id' => $type === 'expense' ? $this->general->id : null,
            'category_id' => FinanceCategory::where('type', $type)->where('name', $category)->value('id'), 'label' => $label, 'amount' => $amount]);
    }

    public function test_the_budget_says_where_the_money_will_come_from(): void
    {
        $budgets = app(Budgets::class);
        $budget = $budgets->prepare($this->eglise, 2027);
        $this->line($budget, 'expense', 'Électricité et eau', 'SNEL', 12000);
        $this->line($budget, 'expense', 'Rémunérations et motivations', 'Paie', 8000);
        $this->line($budget, 'income', 'Dîme', 'Dîmes', 9000);
        $this->line($budget, 'income', 'Offrande du culte', 'Offrandes', 7000);

        // 20 000 $ de dépenses, 16 000 $ de recettes : il faut dire d'où viendront les 4 000 $.
        try {
            $budgets->submit($budget);
            $this->fail('Un budget sans source pour toutes ses dépenses a été présenté.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('000,00', $e->getMessage());
        }
        LivewireTest::test(Livewire\Budget\Version::class, ['budget' => $budget])->assertSee('Équilibre du budget')->assertSee('Il manque');

        $this->line($budget, 'income', 'Promesses et projets', 'Promesses des familles', 4500);
        $summary = app(BudgetSources::class)->summary($budget->fresh());
        $this->assertSame([20000.0, 0.0, 500.0], [$summary['expense'], $summary['gap'], $summary['surplus']]);
        // Chaque recette dit ce qu'elle apporte, chaque dépense ce qu'elle consomme, en pour cent des 20 500 $ de recettes.
        LivewireTest::test(Livewire\Budget\Version::class, ['budget' => $budget])->set('tab', 'recettes')
            ->assertSee('apporte 44 %')->assertSee('apporte 34 %')->assertSee('apporte 22 %')
            ->set('tab', 'depenses')->assertSee('consomme 59 %')->assertSee('consomme 39 %')->assertSee('Équilibre du budget');

        $budgets->submit($budget->fresh());
        $this->assertSame('submitted', $budget->fresh()->status);
    }

    public function test_project_money_pays_only_its_project_and_what_it_lacks_comes_from_ordinary_income(): void
    {
        $projects = app(Projects::class);
        $caisse = CashAccount::create(['name' => 'Caisse', 'kind' => 'cash']);
        CashAccountCurrency::create(['cash_account_id' => $caisse->id, 'currency' => 'USD', 'opening_balance' => 0, 'opened_on' => '2025-01-01']);
        $temple = $projects->save($this->eglise, ['name' => 'Temple', 'department_id' => $this->general->id], [
            ['fiscal_year' => 2026, 'income_planned' => 5000, 'expense_planned' => 1000],
            ['fiscal_year' => 2027, 'income_planned' => 3000, 'expense_planned' => 4000],
        ]);
        $toiture = $projects->save($this->eglise, ['name' => 'Toiture'], [['fiscal_year' => 2027, 'expense_planned' => 400]]);
        // 2026 : 3 500 $ reçus, 800 $ dépensés : 2 700 $ passent sur 2027.
        app(Ledger::class)->record($caisse, 'USD', 'income', ['amount' => '3500', 'category_id' => $temple->category_id, 'project_id' => $temple->id, 'occurred_on' => '2026-06-01']);
        app(Ledger::class)->record($caisse, 'USD', 'expense', ['amount' => '800', 'category_id' => FinanceCategory::where('type', 'expense')->where('name', 'Fournitures et matériel')->value('id'),
            'project_id' => $temple->id, 'occurred_on' => '2026-07-01']);
        $this->assertSame(2700.0, $projects->carriedInto($temple, 2027));

        $budgets = app(Budgets::class);
        $budget = $budgets->prepare($this->eglise, 2027);
        $lines = $budget->lines()->get();
        $this->assertSame(4, $lines->count());
        $this->assertSame('2700.00', (string) $lines->firstWhere('source', 'carryover')->amount);
        $budgets->importProjects($budget);
        $this->assertSame(4, $budget->lines()->count()); // reprendre deux fois ne double rien

        // Le temple a 5 700 $ pour 4 000 $ de dépenses : 1 700 $ lui restent réservés et ne paient pas la toiture.
        $this->line($budget, 'income', 'Dîme', 'Dîmes', 300);
        $summary = app(BudgetSources::class)->summary($budget->fresh());
        $this->assertSame([400.0, 100.0, 1700.0], [$summary['expense'] - 4000, $summary['gap'], $summary['reserved']]);
        $this->assertSame(400.0, $summary['projects']->firstWhere('project.id', $toiture->id)['ordinary']);

        $this->line($budget, 'income', 'Offrande du culte', 'Offrandes', 100);
        $budgets->submit($budget->fresh());
        $budgets->approve($budget->fresh(), $this->pasteur);

        // Adopté : la toiture a 400 $ des recettes ordinaires, débloqués au rythme des rentrées.
        $this->assertSame([400.0, 0.0], [$projects->totals($toiture->fresh())['budgeted_planned'], $projects->totals($toiture->fresh())['available']]);
        Carbon::setTestNow('2027-03-10 10:00');
        app(Ledger::class)->record($caisse, 'USD', 'income', ['amount' => '200', 'category_id' => FinanceCategory::where('type', 'income')->where('name', 'Offrande du culte')->value('id')]);
        $this->assertSame(200.0, app(Projects::class)->totals($toiture->fresh())['available']); // 200 $ rentrés sur 400 $ prévus : la moitié
        // Le solde reporté n'est pas une recette de 2027.
        $this->assertSame(0.0, app(Projects::class)->totals($temple->fresh())['budgeted']);

        // L'argent du temple dans les caisses lui est réservé : 3 500 $ reçus, 800 $ dépensés.
        $this->assertSame(2700.0, app(Projects::class)->reserved($this->eglise)['total']);
        $this->get(route('finances.index'))->assertOk()->assertSee('réservés aux projets');
        $this->assertSame(3400.0, app(BudgetControl::class)->execution($this->eglise, 2027)['totals']['income_budgeted']);

        LivewireTest::test(Livewire\Budget\Version::class, ['budget' => $budget])->set('tab', 'projets')
            ->assertSee('pris sur les recettes ordinaires')->assertSee('Payé par son argent');
    }

    public function test_active_pledges_are_offered_as_planned_income(): void
    {
        $member = Member::create(['last_name' => 'KAHINDO', 'first_name' => 'Esther']);
        Pledge::create(['member_id' => $member->id, 'amount' => '600', 'currency' => 'USD', 'pledged_on' => today()]);
        $budget = app(Budgets::class)->prepare($this->eglise, 2027);

        LivewireTest::test(Livewire\Budget\Version::class, ['budget' => $budget])->set('tab', 'recettes')
            ->assertSee('Les promesses en cours (hors projets) attendent encore')
            ->call('addPledges')->call('addPledges');
        $this->assertSame('600.00', (string) $budget->lines()->where('label', 'Promesses des membres en cours')->sole()->amount);
    }
}
