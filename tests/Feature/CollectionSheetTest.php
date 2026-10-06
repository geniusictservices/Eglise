<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\CashAccount;
use App\Models\CashAccountCurrency;
use App\Models\CollectionSheet;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\Member;
use App\Models\User;
use App\Services\ExchangeRateService;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class CollectionSheetTest extends TestCase
{
    use RefreshDatabase;

    private CashAccount $caisse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $admin = User::factory()->create();
        $eglise = $this->createCommunity('Église', $admin);
        $this->actingAs($admin);
        $this->inOrganization($eglise);
        app(ExchangeRateService::class)->setRate($eglise, 'CDF', '2800', now()->subMonth());
        $this->caisse = CashAccount::create(['name' => 'Caisse', 'kind' => 'cash']);
        foreach (['USD', 'CDF'] as $c) {
            CashAccountCurrency::create(['cash_account_id' => $this->caisse->id, 'currency' => $c, 'opened_on' => now()->subMonth()]);
        }
    }

    public function test_a_service_collection_is_counted_declared_and_validated(): void
    {
        $offrande = FinanceCategory::where('name', 'Offrande du culte')->value('id');
        $dime = FinanceCategory::where('name', 'Dîme')->value('id');
        $member = Member::create(['last_name' => 'KAHINDO', 'first_name' => 'Esther']);

        LivewireTest::test(Livewire\Finances\Collections\Index::class)->call('create')->assertRedirect();
        $sheet = CollectionSheet::sole();
        $this->get(route('finances.collections'))->assertOk();
        $this->get(route('finances.collections.show', $sheet))->assertOk();

        $component = LivewireTest::test(Livewire\Finances\Collections\Sheet::class, ['sheet' => $sheet])
            // 3 billets de 20 $ et 2 de 5 $ = 70 $ ; 10 billets de 5 000 FC = 50 000 FC
            ->set('counts.USD.20', '3')->set('counts.USD.5', '2')
            ->set('counts.CDF.5000', '10')
            ->set('lines.'.$offrande.'.CDF', '50000')
            ->call('chooseMember', $member->id)
            ->set('envelopeCategory', (string) $dime)->set('envelopeCurrency', 'USD')->set('envelopeAmount', '30')
            ->call('addEnvelope')
            ->assertHasNoErrors()
            // L'offrande en dollars reprend le reste du comptage : 70 − 30 = 40 $.
            ->call('useCountFor', 'USD', $offrande)
            ->assertSet("lines.{$offrande}.USD", '40');

        // Sans compteur, la validation est refusée.
        $component->call('validateSheet')->assertDispatched('notify', fn ($n, $p) => str_contains($p['message'], 'Indiquez qui a compté la collecte.'));
        $this->assertTrue($sheet->fresh()->isDraft());

        $component->set('counters.0', 'Marthe Paluku')->set('counters.1', 'Joël Paluku')->call('validateSheet');

        $sheet->refresh();
        $this->assertSame('validated', $sheet->status);
        $this->assertSame(3, FinanceTransaction::where('collection_id', $sheet->id)->count());
        $this->assertSame('70.00', (string) app(Ledger::class)->balance($this->caisse, 'USD'));
        $this->assertSame('50000.00', (string) app(Ledger::class)->balance($this->caisse, 'CDF'));
        $this->assertSame($member->id, FinanceTransaction::whereNotNull('member_id')->sole()->member_id);
        $this->get(route('finances.collections.print', $sheet))->assertOk()->assertSee('Procès-verbal de collecte')->assertSee('Marthe Paluku');
    }

    public function test_a_counting_difference_blocks_validation(): void
    {
        $offrande = FinanceCategory::where('name', 'Offrande du culte')->value('id');
        $sheet = CollectionSheet::create(['service_date' => today(), 'service_label' => 'Culte', 'cash_account_id' => $this->caisse->id, 'counters' => ['A']]);

        LivewireTest::test(Livewire\Finances\Collections\Sheet::class, ['sheet' => $sheet])
            ->set('counts.USD.10', '5')
            ->set('lines.'.$offrande.'.USD', '45')
            ->assertSee('Écart')
            ->call('validateSheet')
            ->assertDispatched('notify', fn ($n, $p) => str_contains($p['message'], 'ne correspond pas au total déclaré'));

        $this->assertTrue($sheet->fresh()->isDraft());
        $this->assertSame(0, FinanceTransaction::count());
    }

    public function test_cancelling_a_validated_sheet_cancels_its_income(): void
    {
        $offrande = FinanceCategory::where('name', 'Offrande du culte')->value('id');
        $sheet = CollectionSheet::create(['service_date' => today(), 'service_label' => 'Culte', 'cash_account_id' => $this->caisse->id, 'counters' => ['A', 'B']]);

        LivewireTest::test(Livewire\Finances\Collections\Sheet::class, ['sheet' => $sheet])
            ->set('lines.'.$offrande.'.USD', '25')
            ->call('validateSheet')
            ->set('cancelReason', 'Feuille saisie deux fois')
            ->call('cancelSheet')
            ->assertHasNoErrors();

        $this->assertSame('cancelled', $sheet->fresh()->status);
        $this->assertSame('0.00', (string) app(Ledger::class)->balance($this->caisse, 'USD'));
    }
}
