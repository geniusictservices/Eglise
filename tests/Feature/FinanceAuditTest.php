<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\CashAccount;
use App\Models\CashAccountCurrency;
use App\Models\Department;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\Payee;
use App\Models\User;
use App\Services\BudgetControl;
use App\Services\Budgets;
use App\Services\ExchangeRateService;
use App\Services\Expenses;
use App\Services\Ledger;
use App\Services\Payroll;
use App\Services\PayRuns;
use App\Services\ProjectNetwork;
use App\Services\Projects;
use App\Services\SalaryAdvances;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Les erreurs relevées par l'audit financier d'octobre 2026, pour qu'elles ne reviennent pas.
 */
class FinanceAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['waumini.push.public_key' => null]);
        Carbon::setTestNow('2026-10-06 10:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function church(): array
    {
        $admin = User::factory()->create();
        $org = $this->createCommunity('Eglise', $admin);
        $tres = User::factory()->create();
        $past = User::factory()->create();
        $this->assign($tres, $this->role($org, 'tresorier'), $org);
        $this->assign($past, $this->role($org, 'pasteur'), $org);
        $this->inOrganization($org);
        app(ExchangeRateService::class)->setRate($org, 'CDF', '2800', now()->subYear());
        $caisse = CashAccount::create(['name' => 'Caisse', 'kind' => 'cash']);
        CashAccountCurrency::create(['cash_account_id' => $caisse->id, 'currency' => 'USD', 'opening_balance' => 1000, 'opened_on' => now()->subYear()]);

        return [$org, $admin, $tres, $past, $caisse];
    }

    public function test_an_advance_that_takes_the_whole_salary_is_repaid_once_and_two_runs_never_take_it_twice(): void
    {
        [$org, $admin, $tres, $past, $caisse] = $this->church();
        $this->actingAs($tres);
        $monthly = app(Payroll::class)->schedules($org)->first();
        $sentinelle = Payee::create(['name' => 'Sentinelle', 'pay_schedule_id' => $monthly->id, 'currency' => 'USD', 'base_amount' => 100]);
        $adv = app(SalaryAdvances::class)->request($sentinelle, '100', 1, 'urgence');
        $this->actingAs($past);
        app(SalaryAdvances::class)->decide($adv, $past, true);
        $this->actingAs($tres);
        app(SalaryAdvances::class)->pay($adv->fresh(), $caisse, 'USD');

        // Octobre et novembre préparés d'avance : les deux prévoient la retenue.
        $runs = app(PayRuns::class);
        $oct = $runs->prepare($org, $monthly, Carbon::parse('2026-10-01'));
        $nov = $runs->prepare($org, $monthly);
        $this->assertSame('0.00', (string) $oct->slips()->sole()->net);
        foreach ([$oct, $nov] as $run) {
            $this->actingAs($tres);
            $runs->submit($run->fresh());
            $this->actingAs($past);
            $runs->approve($run->fresh(), $past);
            $this->actingAs($tres);
            $runs->pay($run->fresh(), 'USD', $caisse, 'USD');
            $this->assertSame('paid', $run->fresh()->status);
        }
        // La retenue n'est faite qu'une fois ; novembre rend les 100 $ au salaire.
        $this->assertSame(['repaid', 100.0], [$adv->fresh()->status, $adv->fresh()->load('repayments')->repaid()]);
        $this->assertSame('100.00', (string) $nov->slips()->sole()->net);
        $this->assertSame('800.00', (string) app(Ledger::class)->balance($caisse, 'USD')); // 1 000 - 100 d'avance - 100 de salaire en novembre

        // Annuler la paie de novembre rend l'argent ; annuler octobre rend l'avance à rembourser.
        $runs->cancel($nov->fresh());
        $runs->cancel($oct->fresh());
        $this->assertSame(['paid', 0.0], [$adv->fresh()->status, $adv->fresh()->load('repayments')->repaid()]);
        $this->assertSame('900.00', (string) app(Ledger::class)->balance($caisse, 'USD'));
    }

    public function test_a_disbursed_expense_is_cancelled_from_its_request_never_from_the_journal(): void
    {
        [$org, $admin, $tres, $past, $caisse] = $this->church();
        $this->actingAs($tres);
        $exp = app(Expenses::class);
        $req = $exp->submit($org, ['department_id' => Department::where('is_system', true)->value('id'), 'category_id' => FinanceCategory::where('type', 'expense')->value('id'),
            'title' => 'Avance', 'amount' => 200, 'currency' => 'USD', 'is_advance' => true, 'is_unforeseen' => true, 'unforeseen_reason' => 'x']);
        $exp->check($req);
        $req->update(['status' => 'approved']);
        $exp->disburse($req->fresh(), $caisse);
        $tx = FinanceTransaction::where('expense_request_id', $req->id)->where('type', 'expense')->sole();

        try {
            app(Ledger::class)->cancel($tx, 'erreur de saisie');
            $this->fail('Une opération de dépense a été annulée dans le journal.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('demande de dépense', $e->getMessage());
        }

        // Justifiée avec 50 $ rendus, puis annulée : tout revient, rien n'est créé.
        $exp->justify($req->fresh(), '150');
        $this->assertSame('850.00', (string) app(Ledger::class)->balance($caisse, 'USD'));
        $exp->cancel($req->fresh());
        $this->assertSame(['cancelled', '1000.00'], [$req->fresh()->status, (string) app(Ledger::class)->balance($caisse, 'USD')]);
    }

    public function test_a_back_dated_expense_must_be_covered_on_its_date(): void
    {
        [$org, $admin, $tres, $past, $caisse] = $this->church();
        $this->actingAs($tres);
        $ledger = app(Ledger::class);
        $income = FinanceCategory::where('type', 'income')->value('id');
        $expense = FinanceCategory::where('type', 'expense')->value('id');
        CashAccountCurrency::where('cash_account_id', $caisse->id)->update(['opening_balance' => 0]);
        $ledger->record($caisse, 'USD', 'income', ['amount' => '500', 'category_id' => $income, 'occurred_on' => '2026-10-01']);

        $this->expectException(\InvalidArgumentException::class);
        $ledger->record($caisse, 'USD', 'expense', ['amount' => '300', 'category_id' => $expense, 'occurred_on' => '2026-09-15']);
    }

    public function test_parish_shares_of_a_franc_goal_and_project_income_in_the_budget(): void
    {
        $admin = User::factory()->create();
        $siege = $this->createCommunity('Siege', $admin);
        $himbi = $this->createChild($siege, 'Himbi');
        $this->actingAs($admin);
        app(ExchangeRateService::class)->setRate($siege, 'CDF', '2800', now()->subYear());
        $project = app(CurrentOrganization::class)->within($siege, fn () => app(Projects::class)->save($siege,
            ['name' => 'Temple', 'goal_amount' => 28000000, 'goal_currency' => 'CDF'],
            [['fiscal_year' => 2026, 'income_planned' => 10000, 'expense_planned' => 10000]]));
        $relay = app(CurrentOrganization::class)->within($siege, fn () => app(ProjectNetwork::class)->setShare($project, $himbi, 4000));
        $this->assertSame('4000.00', (string) $relay->years()->sole()->income_planned);
    }

    public function test_project_income_reaches_its_department_line_and_commitments_stay_in_their_year(): void
    {
        [$org, $admin, $tres, $past, $caisse] = $this->church();
        $this->actingAs($admin);
        $dept = Department::create(['name' => 'Jeunesse', 'is_active' => true]);
        $project = app(Projects::class)->save($org, ['name' => 'Convention', 'department_id' => $dept->id],
            [['fiscal_year' => 2026, 'income_planned' => 1000, 'expense_planned' => 1000]]);
        $budget = Budget::create(['organization_id' => $org->id, 'fiscal_year' => 2026, 'version' => 1, 'status' => 'draft']);
        app(Budgets::class)->importProjects($budget);
        $budget->update(['status' => 'adopted']);
        app(Ledger::class)->record($caisse, 'USD', 'income', ['amount' => '300', 'category_id' => $project->fresh()->category_id, 'project_id' => $project->id]);
        $ex = app(BudgetControl::class)->execution($org, 2026);
        $this->assertSame(300.0, (float) collect($ex['income'])->firstWhere('category_id', $project->fresh()->category_id)['actual']);

        $this->actingAs($tres);
        $general = Department::where('is_system', true)->value('id');
        $cat = FinanceCategory::where('type', 'expense')->value('id');
        BudgetLine::create(['budget_id' => $budget->id, 'type' => 'expense', 'department_id' => $general, 'category_id' => $cat, 'label' => 'x', 'amount' => 500]);
        $exp = app(Expenses::class);
        $req = $exp->submit($org, ['department_id' => $general, 'category_id' => $cat, 'title' => 'Futur', 'amount' => 400, 'currency' => 'USD', 'needed_on' => '2027-03-01', 'is_unforeseen' => true, 'unforeseen_reason' => 'x']);
        $exp->check($req);
        $this->assertSame(500.0, (float) app(BudgetControl::class)->execution($org, 2026)['expense'][BudgetControl::key($general, $cat)]['available']);
    }
}
