<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\CashAccount;
use App\Models\CashAccountCurrency;
use App\Models\FinanceTransaction;
use App\Models\Organization;
use App\Models\Payee;
use App\Models\PayRun;
use App\Models\PaySchedule;
use App\Models\SalaryAdvance;
use App\Models\User;
use App\Services\ExchangeRateService;
use App\Services\Ledger;
use App\Services\Payroll;
use App\Services\PayRuns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class PayRunTest extends TestCase
{
    use RefreshDatabase;

    private Organization $eglise;

    private User $tresorier;

    private User $pasteur;

    private CashAccount $caisse;

    private PaySchedule $monthly;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-10-28 10:00');
        $this->eglise = $this->createCommunity('Église');
        foreach (['tresorier' => 'tresorier', 'pasteur' => 'pasteur'] as $property => $role) {
            $this->{$property} = User::factory()->create();
            $this->assign($this->{$property}, $this->role($this->eglise, $role), $this->eglise);
        }
        $this->inOrganization($this->eglise);
        $this->actingAs($this->tresorier);
        app(ExchangeRateService::class)->setRate($this->eglise, 'CDF', '2800', now()->subMonth());
        $this->caisse = CashAccount::create(['name' => 'Caisse', 'kind' => 'cash']);
        foreach (['USD' => 1000, 'CDF' => 1000000] as $code => $opening) {
            CashAccountCurrency::create(['cash_account_id' => $this->caisse->id, 'currency' => $code, 'opening_balance' => $opening, 'opened_on' => now()->subYear()]);
        }
        $this->monthly = app(Payroll::class)->schedules($this->eglise)->first();
        Payee::create(['name' => 'Pasteur', 'pay_schedule_id' => $this->monthly->id, 'currency' => 'USD', 'base_amount' => 250]);
        Payee::create(['name' => 'Sentinelle', 'pay_schedule_id' => $this->monthly->id, 'currency' => 'CDF', 'base_amount' => 168000]);
        Payee::create(['name' => 'Ancien', 'pay_schedule_id' => $this->monthly->id, 'currency' => 'USD', 'base_amount' => 50, 'is_active' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_finance_prepares_the_pastor_approves_and_finance_pays(): void
    {
        LivewireTest::test(Livewire\Payroll\Index::class)
            ->call('askPrepare')
            ->assertSet('start', '2026-10-01')
            ->call('prepare')->assertRedirect();
        $run = PayRun::sole();
        $this->assertSame('Octobre 2026', $run->label());
        $this->assertSame(2, $run->slips()->count()); // pas la personne qui n'est plus payée

        // Une prime ponctuelle pour la sentinelle.
        $slip = $run->slips()->where('currency', 'CDF')->sole();
        LivewireTest::test(Livewire\Payroll\Run::class, ['run' => $run])
            ->call('editSlip', $slip->id)
            ->set('adjustment.label', 'Prime de Noël')->set('adjustment.amount', '28000')
            ->call('addAdjustment')->assertHasNoErrors()
            ->call('submit')->assertHasNoErrors();
        $this->assertSame('196000.00', (string) $slip->fresh()->net);
        $this->assertSame('submitted', $run->fresh()->status);

        // Le trésorier n'approuve pas.
        LivewireTest::test(Livewire\Payroll\Run::class, ['run' => $run])->call('approve')->assertForbidden();

        $this->actingAs($this->pasteur);
        LivewireTest::test(Livewire\Payroll\Run::class, ['run' => $run])->call('approve')->assertHasNoErrors();
        $this->assertSame('approved', $run->fresh()->status);

        // La finance paie : les dollars en dollars, les francs… en dollars, au taux du jour.
        $this->actingAs($this->tresorier);
        LivewireTest::test(Livewire\Payroll\Run::class, ['run' => $run])
            ->set('payment.USD', ['account' => (string) $this->caisse->id, 'currency' => 'USD'])
            ->call('pay', 'USD')->assertHasNoErrors()
            ->set('payment.CDF', ['account' => (string) $this->caisse->id, 'currency' => 'USD'])
            ->call('pay', 'CDF')->assertHasNoErrors();

        $run->refresh();
        $this->assertSame('paid', $run->status);
        $this->assertSame(2, FinanceTransaction::where('type', 'expense')->count());
        $slip->refresh();
        $this->assertSame('USD', $slip->paid_currency);
        $this->assertSame('70.00', (string) $slip->paid_amount); // 196 000 FC à 2 800
        $this->assertTrue(app(Ledger::class)->balance($this->caisse, 'USD')->isEqualTo(680)); // 1000 − 250 − 70
        $this->assertSame('Rémunérations et motivations', FinanceTransaction::where('pay_slip_id', $slip->id)->sole()->category->name);

        $this->get(route('payroll.print', $run))->assertOk()->assertSee('État de paie');
        $this->get(route('payroll.slip', $slip))->assertOk()->assertSee('Prime de Noël')->assertSee('Net à payer');

        // La période suivante commence le 1er novembre ; on ne refait pas octobre.
        $this->assertSame('2026-11-01', app(PayRuns::class)->nextPeriod($this->monthly)[0]->toDateString());
        $this->expectException(\InvalidArgumentException::class);
        app(PayRuns::class)->prepare($this->eglise, $this->monthly, Carbon::parse('2026-10-01'));
    }

    public function test_per_service_pay_uses_the_number_of_services(): void
    {
        $sermons = PaySchedule::create(['name' => 'Prédicateurs', 'unit' => 'service', 'service_label' => 'prédication']);
        Payee::create(['name' => 'Frère Kasongo', 'pay_schedule_id' => $sermons->id, 'currency' => 'CDF', 'base_amount' => 30000]);
        $run = app(PayRuns::class)->prepare($this->eglise, $sermons, Carbon::parse('2026-10-01'));
        $slip = $run->slips()->sole();
        $this->assertSame('0.00', (string) $slip->net);

        LivewireTest::test(Livewire\Payroll\Run::class, ['run' => $run])->set("quantities.{$slip->id}", '3');
        $this->assertSame('90000.00', (string) $slip->fresh()->net);
    }

    public function test_the_pastor_can_send_the_payroll_back(): void
    {
        $runs = app(PayRuns::class);
        $run = $runs->prepare($this->eglise, $this->monthly);
        $runs->submit($run);

        $this->actingAs($this->pasteur);
        LivewireTest::test(Livewire\Payroll\Run::class, ['run' => $run])
            ->call('sendBack')->assertHasErrors('note')
            ->set('note', 'Ajoutez la prime de la secrétaire')->call('sendBack')->assertHasNoErrors();
        $this->assertSame('draft', $run->fresh()->status);
        $this->assertSame('Ajoutez la prime de la secrétaire', $run->fresh()->return_note);
    }

    public function test_a_salary_advance_is_approved_paid_and_withheld_on_the_next_payrolls(): void
    {
        $sentinelle = Payee::where('name', 'Sentinelle')->sole();

        LivewireTest::test(Livewire\Payroll\Advances::class)
            ->call('create')
            ->set('form.payee_id', (string) $sentinelle->id)
            ->set('form.amount', '84000')
            ->set('form.installments', '2')
            ->set('form.reason', 'Soins à l’hôpital')
            ->call('save')->assertHasNoErrors();
        $advance = SalaryAdvance::sole();
        $this->assertSame('requested', $advance->status);
        $this->assertSame('CDF', $advance->currency);

        // Le trésorier ne s'approuve pas ; le pasteur approuve.
        LivewireTest::test(Livewire\Payroll\Advances::class)->call('decide', $advance->id, true)->assertForbidden();
        $this->actingAs($this->pasteur);
        LivewireTest::test(Livewire\Payroll\Advances::class)->call('decide', $advance->id, true)->assertHasNoErrors();
        $this->assertSame('approved', $advance->fresh()->status);

        $this->actingAs($this->tresorier);
        LivewireTest::test(Livewire\Payroll\Advances::class)
            ->call('askPay', $advance->id)
            ->assertSet('payCurrency', 'CDF')
            ->call('pay')->assertHasNoErrors();
        $this->assertSame('paid', $advance->fresh()->status);
        $this->assertTrue(app(Ledger::class)->balance($this->caisse, 'CDF')->isEqualTo(916000));

        // Deux paies : 42 000 FC retenus à chaque fois, puis l'avance est remboursée.
        $runs = app(PayRuns::class);
        foreach (['2026-10-01', '2026-11-01'] as $start) {
            $run = $runs->prepare($this->eglise, $this->monthly, Carbon::parse($start));
            $slip = $run->slips()->where('currency', 'CDF')->sole();
            $this->assertSame('42000.00', (string) $slip->advance_total);
            $this->assertSame('126000.00', (string) $slip->net);
            $runs->submit($run);
            $runs->approve($run->fresh(), $this->pasteur);
            $runs->pay($run->fresh(), 'CDF', $this->caisse, 'CDF');
            $runs->pay($run->fresh(), 'USD', $this->caisse, 'USD');
        }
        $advance->refresh();
        $this->assertSame('repaid', $advance->status);
        $this->assertSame(0.0, $advance->load('repayments')->remaining());

        // Décembre : plus rien à retenir.
        $run = $runs->prepare($this->eglise, $this->monthly, Carbon::parse('2026-12-01'));
        $this->assertSame('0.00', (string) $run->slips()->where('currency', 'CDF')->sole()->advance_total);
    }
}
