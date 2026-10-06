<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\BudgetOverrun;
use App\Models\Department;
use App\Models\FinanceCategory;
use App\Models\Organization;
use App\Models\Payee;
use App\Models\PayItem;
use App\Models\PaySchedule;
use App\Models\User;
use App\Services\BudgetControl;
use App\Services\Budgets;
use App\Services\ExchangeRateService;
use App\Services\Payroll;
use App\Services\PayRuns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class PayrollBudgetTest extends TestCase
{
    use RefreshDatabase;

    private Organization $eglise;

    private User $tresorier;

    private User $pasteur;

    private PaySchedule $monthly;

    private int $general;

    private int $salaries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-10-20 10:00');
        $this->eglise = $this->createCommunity('Église');
        foreach (['tresorier' => 'tresorier', 'pasteur' => 'pasteur'] as $property => $role) {
            $this->{$property} = User::factory()->create();
            $this->assign($this->{$property}, $this->role($this->eglise, $role), $this->eglise);
        }
        $this->inOrganization($this->eglise);
        $this->actingAs($this->tresorier);
        app(ExchangeRateService::class)->setRate($this->eglise, 'CDF', '2800', now()->subMonth());
        $this->monthly = app(Payroll::class)->schedules($this->eglise)->first();
        $this->general = Department::where('is_system', true)->value('id');
        $this->salaries = FinanceCategory::where('type', 'expense')->where('name', 'Rémunérations et motivations')->value('id');
        Payee::create(['name' => 'Pasteur', 'position' => 'Pasteur titulaire', 'department_id' => $this->general, 'pay_schedule_id' => $this->monthly->id, 'currency' => 'USD', 'base_amount' => 250]);
        Payee::create(['name' => 'Sentinelle', 'department_id' => $this->general, 'pay_schedule_id' => $this->monthly->id, 'currency' => 'CDF', 'base_amount' => 168000]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function adopt(callable $lines): Budget
    {
        $budgets = app(Budgets::class);
        $budget = $budgets->prepare($this->eglise, 2026);
        $lines($budget);
        $budgets->submit($budget);
        $budgets->approve($budget->fresh(), $this->pasteur);

        return $budget->fresh();
    }

    public function test_the_budget_takes_the_payroll_mass(): void
    {
        PayItem::create(['name' => 'Logement', 'kind' => 'earning', 'calculation' => 'percent_base', 'default_value' => 20, 'applies_to_all' => true]);
        $budget = app(Budgets::class)->prepare($this->eglise, 2026);

        LivewireTest::test(Livewire\Budget\Version::class, ['budget' => $budget])->call('importPayroll')->assertHasNoErrors();

        $lines = $budget->lines()->whereNotNull('payee_id')->get();
        $this->assertCount(2, $lines);
        // Pasteur : (250 + 50) × 12 = 3 600 $ ; sentinelle : (168 000 + 33 600) × 12 / 2 800 = 864 $.
        $this->assertSame('3600.00', (string) $lines->firstWhere('label', 'Paie : Pasteur titulaire (Pasteur)')->amount);
        $this->assertSame('864.00', (string) $lines->firstWhere('label', 'Paie : Sentinelle')->amount);
        $this->assertSame($this->salaries, $lines->first()->category_id);

        // Reprendre une deuxième fois met à jour, sans doubler.
        Payee::where('name', 'Pasteur')->update(['base_amount' => 300]);
        app(Budgets::class)->importPayroll($budget);
        $this->assertSame(2, $budget->lines()->whereNotNull('payee_id')->count());
        $this->assertSame('4320.00', (string) $budget->lines()->where('label', 'Paie : Pasteur titulaire (Pasteur)')->value('amount'));
    }

    public function test_payroll_beyond_the_salary_budget_needs_an_authorized_overrun(): void
    {
        // 270 $ au budget des salaires : la paie d'octobre demande 250 $ + 60 $ = 310 $.
        $this->adopt(fn (Budget $b) => BudgetLine::create(['budget_id' => $b->id, 'type' => 'expense', 'department_id' => $this->general,
            'category_id' => $this->salaries, 'label' => 'Salaires', 'amount' => 270]));
        $other = FinanceCategory::where('type', 'expense')->where('name', 'Entretien et réparations')->value('id');
        Budget::sole()->lines()->create(['type' => 'expense', 'department_id' => $this->general, 'category_id' => $other, 'label' => 'Entretien', 'amount' => 500]);

        $runs = app(PayRuns::class);
        $run = $runs->prepare($this->eglise, $this->monthly, Carbon::parse('2026-10-01'));
        $line = app(BudgetControl::class)->payrollLines($run)[BudgetControl::key($this->general, $this->salaries)];
        $this->assertSame(310.0, $line['needed']);
        $this->assertSame(40.0, $line['missing']);

        // La finance ne peut pas présenter la paie.
        LivewireTest::test(Livewire\Payroll\Run::class, ['run' => $run])
            ->assertSee('Budget des salaires')
            ->call('submit')->assertHasErrors('note');
        $this->assertSame('draft', $run->fresh()->status);

        // Elle demande un dépassement, pris sur la ligne de l'entretien.
        LivewireTest::test(Livewire\Payroll\Run::class, ['run' => $run])
            ->call('askOverrun', BudgetControl::key($this->general, $this->salaries))
            ->assertSet('overrun.amount', '40')
            ->set('overrun.source_key', BudgetControl::key($this->general, $other))
            ->set('overrun.reason', 'Prime de fin d’année de la sentinelle')
            ->call('requestOverrun')->assertHasNoErrors();
        $overrun = BudgetOverrun::sole();
        $this->assertSame($run->id, $overrun->pay_run_id);

        $this->actingAs($this->pasteur);
        LivewireTest::test(Livewire\Payroll\Run::class, ['run' => $run])->call('decideOverrun', $overrun->id, true)->assertHasNoErrors();

        // La paie passe, et elle est engagée dans le budget.
        $this->actingAs($this->tresorier);
        $runs->submit($run->fresh());
        $execution = app(BudgetControl::class)->execution($this->eglise, 2026)['expense'];
        $salaries = $execution[BudgetControl::key($this->general, $this->salaries)];
        $this->assertSame(310.0, $salaries['committed']);
        $this->assertSame(0.0, $salaries['available']);
        $this->assertSame(460.0, $execution[BudgetControl::key($this->general, $other)]['available']);
    }

    public function test_payroll_without_a_salary_line_is_out_of_budget(): void
    {
        $other = FinanceCategory::where('type', 'expense')->where('name', 'Entretien et réparations')->value('id');
        $this->adopt(fn (Budget $b) => BudgetLine::create(['budget_id' => $b->id, 'type' => 'expense', 'department_id' => $this->general, 'category_id' => $other, 'label' => 'Entretien', 'amount' => 500]));
        $run = app(PayRuns::class)->prepare($this->eglise, $this->monthly, Carbon::parse('2026-10-01'));

        $line = app(BudgetControl::class)->payrollLines($run)[BudgetControl::key($this->general, $this->salaries)];
        $this->assertTrue($line['unbudgeted']);
        $this->expectException(InvalidArgumentException::class);
        app(PayRuns::class)->submit($run);
    }
}
