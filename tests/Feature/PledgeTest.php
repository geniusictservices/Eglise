<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\Campaign;
use App\Models\CashAccount;
use App\Models\CashAccountCurrency;
use App\Models\Household;
use App\Models\Member;
use App\Models\Pledge;
use App\Models\PledgeReminder;
use App\Models\User;
use App\Services\ExchangeRateService;
use App\Services\Pledges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class PledgeTest extends TestCase
{
    use RefreshDatabase;

    private CashAccount $caisse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $admin = User::factory()->create();
        $eglise = $this->createCommunity('Église de la Paix', $admin);
        $this->actingAs($admin);
        $this->inOrganization($eglise);
        app(ExchangeRateService::class)->setRate($eglise, 'CDF', '2800', now()->subYear());
        $this->caisse = CashAccount::create(['name' => 'Caisse', 'kind' => 'cash']);
        foreach (['USD', 'CDF'] as $c) {
            CashAccountCurrency::create(['cash_account_id' => $this->caisse->id, 'currency' => $c, 'opened_on' => now()->subYear()]);
        }
    }

    public function test_a_campaign_collects_pledges_paid_in_installments(): void
    {
        LivewireTest::test(Livewire\Finances\Pledges\Index::class)
            ->call('editCampaign')
            ->set('form.name', 'Construction du temple')
            ->set('form.goal_amount', '10000')
            ->call('saveCampaign')
            ->assertHasNoErrors();
        $campaign = Campaign::sole();
        $this->assertNotNull($campaign->category_id);

        $member = Member::create(['last_name' => 'BAHATI', 'first_name' => 'Isaac', 'phone' => '+243812345678']);
        LivewireTest::test(Livewire\Finances\Pledges\Form::class)
            ->set('campaignId', (string) $campaign->id)
            ->call('choose', 'member', $member->id)
            ->set('amount', '600')
            ->set('frequency', 'monthly')
            ->set('installments', 6)
            ->set('firstDueOn', today()->subMonths(2)->toDateString())
            ->call('save')
            ->assertHasNoErrors();

        $pledge = Pledge::sole();
        $service = app(Pledges::class);
        // Trois échéances sont passées (il y a deux mois, il y a un mois, aujourd'hui) : 300 $ attendus.
        $this->assertSame('300.00', (string) $service->progress($pledge)['expected']);
        $this->assertSame('300.00', (string) $service->progress($pledge)['late']);

        LivewireTest::test(Livewire\Finances\Pledges\Show::class, ['pledge' => $pledge])
            ->call('openPayment')
            ->set('currency', 'USD')
            ->set('amount', '200')
            ->call('pay')
            ->assertHasNoErrors();

        // Un versement en francs est compté en dollars sur la promesse.
        $service->pay($pledge, $this->caisse, 'CDF', '280000');
        $p = $service->progress($pledge->fresh());
        $this->assertSame('300.00', (string) $p['paid']);
        $this->assertSame('0.00', (string) $p['late']);
        $this->assertSame(50, $p['percent']);
        $this->assertSame('300.00', (string) $service->campaignTotals($campaign)['received']);

        $this->get(route('finances.pledges'))->assertOk()->assertSee('Construction du temple');
        $this->get(route('finances.pledges.show', $pledge))->assertOk()->assertSee('Isaac BAHATI');
    }

    public function test_a_pledge_is_fulfilled_and_can_be_in_kind(): void
    {
        $household = Household::create(['name' => 'Famille MUMBERE', 'phone' => '+243991112233']);
        $pledge = Pledge::create(['household_id' => $household->id, 'kind' => 'in_kind', 'in_kind_description' => '20 sacs de ciment',
            'amount' => '300', 'currency' => 'USD', 'pledged_on' => today()]);

        LivewireTest::test(Livewire\Finances\Pledges\Show::class, ['pledge' => $pledge])
            ->call('openDelivery')
            ->set('deliveryDescription', '20 sacs de ciment')
            ->set('deliveryValue', '300')
            ->call('deliver')
            ->assertHasNoErrors();

        $this->assertSame('fulfilled', $pledge->fresh()->status);
    }

    public function test_the_whatsapp_reminder_is_prefilled_and_logged(): void
    {
        $member = Member::create(['last_name' => 'MASIKA', 'first_name' => 'Rebecca', 'phone' => '+243812000111']);
        $pledge = Pledge::create(['member_id' => $member->id, 'amount' => '100', 'currency' => 'USD', 'pledged_on' => today()->subMonth(), 'first_due_on' => today()->subWeek()]);

        $url = app(Pledges::class)->whatsappUrl($pledge);
        $this->assertStringStartsWith('https://wa.me/243812000111?text=', $url);
        $this->assertStringContainsString(rawurlencode('Bonjour Rebecca'), $url);
        $this->assertStringContainsString(rawurlencode('il reste 100,00'), $url);

        LivewireTest::test(Livewire\Finances\Pledges\Show::class, ['pledge' => $pledge])->call('logReminder');
        $this->assertSame(1, PledgeReminder::count());

        LivewireTest::test(Livewire\Finances\Pledges\Index::class)->set('state', 'retard')->assertSee('Rebecca MASIKA');
    }

    public function test_pledgers_are_hidden_without_the_permission(): void
    {
        $eglise = current_organization();
        $secretaire = User::factory()->create();
        $this->assign($secretaire, $this->role($eglise, 'secretaire'), $eglise);
        $this->actingAs($secretaire);
        $this->get(route('finances.pledges'))->assertForbidden();
    }
}
