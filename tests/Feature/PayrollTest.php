<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Payee;
use App\Models\PayeeItem;
use App\Models\PayItem;
use App\Models\PaySchedule;
use App\Models\User;
use App\Services\ExchangeRateService;
use App\Services\Payroll;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    use RefreshDatabase;

    private Organization $eglise;

    private User $tresorier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->eglise = $this->createCommunity('Église');
        $this->tresorier = User::factory()->create();
        $this->assign($this->tresorier, $this->role($this->eglise, 'tresorier'), $this->eglise);
        $this->inOrganization($this->eglise);
        $this->actingAs($this->tresorier);
        app(ExchangeRateService::class)->setRate($this->eglise, 'CDF', '2800', now()->subMonth());
    }

    public function test_the_payslip_adds_earnings_then_deducts_and_never_goes_negative(): void
    {
        $monthly = app(Payroll::class)->schedules($this->eglise)->first();
        $this->assertSame('Mensuel', $monthly->name);
        $logement = PayItem::create(['name' => 'Logement', 'kind' => 'earning', 'calculation' => 'percent_base', 'default_value' => 20]);
        $transport = PayItem::create(['name' => 'Transport', 'kind' => 'earning', 'calculation' => 'fixed', 'default_value' => 15, 'applies_to_all' => true]);
        PayItem::create(['name' => 'Cotisation', 'kind' => 'deduction', 'calculation' => 'percent_gross', 'default_value' => 5, 'applies_to_all' => true, 'is_statutory' => true]);

        $pasteur = Payee::create(['name' => 'Pasteur Paluku', 'pay_schedule_id' => $monthly->id, 'currency' => 'USD', 'base_amount' => 300]);
        PayeeItem::create(['payee_id' => $pasteur->id, 'pay_item_id' => $logement->id]);
        $sentinelle = Payee::create(['name' => 'Sentinelle', 'pay_schedule_id' => $monthly->id, 'currency' => 'USD', 'base_amount' => 60]);
        PayeeItem::create(['payee_id' => $sentinelle->id, 'pay_item_id' => $transport->id, 'is_excluded' => true]);

        // 300 + 60 (logement) + 15 (transport) = 375 brut ; 5 % = 18,75 ; net 356,25.
        $c = app(Payroll::class)->compute($pasteur);
        $this->assertSame(375.0, $c['gross']);
        $this->assertSame(18.75, $c['deductions']);
        $this->assertSame(356.25, $c['net']);
        $this->assertTrue(collect($c['lines'])->firstWhere('label', 'Cotisation')['statutory']);

        // La sentinelle n'a pas le transport ; une avance ne rend jamais le net négatif.
        $c = app(Payroll::class)->compute($sentinelle, 1, [['label' => 'Prime de Noël', 'kind' => 'earning', 'amount' => 20]], [['id' => 1, 'label' => 'Avance', 'amount' => 500]]);
        $this->assertSame(80.0, $c['gross']);
        $this->assertSame(76.0, $c['advance_total']);
        $this->assertSame(0.0, $c['net']);
    }

    public function test_a_per_service_payee_is_paid_by_the_number_of_services(): void
    {
        $sermons = PaySchedule::create(['name' => 'Prédicateurs invités', 'unit' => 'service', 'service_label' => 'prédication']);
        $this->assertSame('À la prestation (prédication)', $sermons->describe());
        $kasongo = Payee::create(['name' => 'Frère Kasongo', 'pay_schedule_id' => $sermons->id, 'currency' => 'CDF', 'base_amount' => 30000]);

        $this->assertSame(90000.0, app(Payroll::class)->compute($kasongo, 3)['net']);
    }

    public function test_finance_registers_a_member_as_payee_with_their_items(): void
    {
        $member = Member::create(['last_name' => 'PALUKU', 'first_name' => 'Daniel']);
        $logement = PayItem::create(['name' => 'Logement', 'kind' => 'earning', 'calculation' => 'fixed', 'default_value' => 50]);

        LivewireTest::test(Livewire\Payroll\Payees::class)
            ->call('edit')
            ->call('chooseMember', $member->id)
            ->set('form.position', 'Pasteur titulaire')
            ->set('form.base_amount', '300')
            ->set("items.{$logement->id}.on", true)
            ->set("items.{$logement->id}.value", '80')
            ->call('save')->assertHasNoErrors()
            ->assertSee('PALUKU');

        $payee = Payee::sole();
        $this->assertSame($member->id, $payee->member_id);
        $this->assertSame(380.0, app(Payroll::class)->compute($payee)['net']);

        // Le secrétaire ne voit pas la paie.
        $secretaire = User::factory()->create();
        $this->assign($secretaire, $this->role($this->eglise, 'secretaire'), $this->eglise);
        $this->actingAs($secretaire)->get(route('payroll.payees'))->assertForbidden();
    }

    public function test_the_church_creates_its_own_pay_items_and_rhythms(): void
    {
        LivewireTest::test(Livewire\Payroll\Settings::class)
            ->call('editItem')
            ->set('item.name', 'Prime de transport')->set('item.kind', 'earning')->set('item.calculation', 'percent_gross')
            ->set('item.default_value', '10')->call('saveItem')->assertHasErrors('item.calculation')
            ->set('item.calculation', 'fixed')->call('saveItem')->assertHasNoErrors()
            ->set('tab', 'rythmes')
            ->call('editSchedule')
            ->set('schedule.name', 'Quinzaine')->set('schedule.unit', 'week')->set('schedule.every', '2')
            ->call('saveSchedule')->assertHasNoErrors();

        $this->assertSame('Toutes les 2 semaines', PaySchedule::where('name', 'Quinzaine')->sole()->describe());
        $this->get(route('payroll.index'))->assertOk()->assertSee('Quinzaine');
    }
}
