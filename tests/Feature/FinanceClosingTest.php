<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\CashAccount;
use App\Models\CashAccountCurrency;
use App\Models\FinanceCategory;
use App\Models\FinanceClosing;
use App\Models\FinanceTransaction;
use App\Models\Organization;
use App\Models\User;
use App\Services\Closings;
use App\Services\ExchangeRateService;
use App\Services\FinanceReports;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class FinanceClosingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $eglise;

    private User $admin;

    private User $tresorier;

    private CashAccount $caisse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-10-06 10:00');
        $this->admin = User::factory()->create();
        $this->eglise = $this->createCommunity('Église', $this->admin);
        $this->tresorier = User::factory()->create();
        $this->assign($this->tresorier, $this->role($this->eglise, 'tresorier'), $this->eglise);
        $this->inOrganization($this->eglise);
        $this->actingAs($this->tresorier);
        app(ExchangeRateService::class)->setRate($this->eglise, 'CDF', '2800', now()->subYear());
        $this->caisse = CashAccount::create(['name' => 'Caisse', 'kind' => 'cash']);
        foreach (['USD' => 100, 'CDF' => 0] as $code => $opening) {
            CashAccountCurrency::create(['cash_account_id' => $this->caisse->id, 'currency' => $code, 'opening_balance' => $opening, 'opened_on' => '2026-08-01']);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function record(string $type, string $currency, string $amount, string $on, string $category): FinanceTransaction
    {
        return app(Ledger::class)->record($this->caisse, $currency, $type, ['amount' => $amount, 'occurred_on' => $on,
            'category_id' => FinanceCategory::where('type', $type)->where('name', $category)->value('id')]);
    }

    public function test_the_report_gives_balances_and_categories(): void
    {
        $this->record('income', 'USD', '50', '2026-08-03', 'Dîme');
        $this->record('income', 'CDF', '28000', '2026-08-10', 'Offrande du culte');
        $this->record('expense', 'USD', '30', '2026-08-20', 'Transport et déplacements');
        $this->record('income', 'USD', '999', '2026-09-02', 'Dîme');

        $r = app(FinanceReports::class)->period($this->eglise, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

        $this->assertSame(['income' => 60.0, 'expense' => 30.0, 'result' => 30.0], $r['totals']);
        $usd = collect($r['accounts'])->firstWhere('currency', 'USD');
        $this->assertTrue($usd['opening']->isEqualTo(100));
        $this->assertTrue($usd['closing']->isEqualTo(120));
        $this->assertSame('Dîme', $r['income'][0]['name']);
        $this->assertSame(['USD' => 50.0], $r['income'][0]['amounts']);
        $this->assertSame(['CDF' => 28000.0], $r['income'][1]['amounts']);
    }

    public function test_months_are_closed_in_order_and_lock_their_dates(): void
    {
        $t = $this->record('income', 'USD', '50', '2026-08-03', 'Dîme');
        $closings = app(Closings::class);

        $this->assertSame('Un mois se clôture une fois terminé.', $closings->blocker($this->eglise, 2026, 10));
        $this->assertStringContainsString('août', $closings->blocker($this->eglise, 2026, 9));

        LivewireTest::test(Livewire\Finances\Closings::class)
            ->call('askClose', 8)->call('close')->assertHasNoErrors()
            ->call('askClose', 9)->call('close')->assertHasNoErrors();

        $august = FinanceClosing::where('month', 8)->sole();
        $this->assertTrue($august->isClosed());
        $this->assertSame(50, (int) $august->snapshot['totals']['income']);

        // Plus d'opération ni d'annulation à ces dates.
        $this->expectExceptionThrown(fn () => $this->record('income', 'USD', '10', '2026-08-15', 'Dîme'));
        $this->expectExceptionThrown(fn () => app(Ledger::class)->transfer($this->caisse, 'USD', $this->caisse, 'CDF', '10', '28000', null, Carbon::parse('2026-09-30')));
        $this->expectExceptionThrown(fn () => app(Ledger::class)->cancel($t, 'Erreur de saisie'));
        LivewireTest::test(Livewire\Finances\Journal::class)->set('month', '2026-08')->call('askCancel', $t->id)->assertForbidden();

        // Octobre reste ouvert.
        $this->record('income', 'USD', '10', '2026-10-01', 'Dîme');
        $this->assertSame(2, FinanceTransaction::count());
    }

    public function test_only_the_administrator_reopens_with_a_reason(): void
    {
        $closings = app(Closings::class);
        $closings->close($this->eglise, 2026, 8);
        $closings->close($this->eglise, 2026, 9);

        // Le trésorier clôture mais ne rouvre pas.
        LivewireTest::test(Livewire\Finances\Closings::class)->call('askReopen', 8)->assertForbidden();

        $this->actingAs($this->admin);
        LivewireTest::test(Livewire\Finances\Closings::class)
            ->call('askReopen', 8)
            ->set('reason', 'Erreur')->call('reopen')->assertHasErrors('reason')
            ->set('reason', 'Facture SNEL d’août oubliée')->call('reopen')->assertHasNoErrors();

        // Rouvrir août rouvre aussi septembre, avec le même motif.
        $this->assertSame(['reopened', 'reopened'], FinanceClosing::orderBy('month')->pluck('status')->all());
        $this->assertSame('Facture SNEL d’août oubliée', FinanceClosing::where('month', 9)->value('reopen_reason'));
        $this->record('expense', 'USD', '20', '2026-08-25', 'Électricité et eau');

        // On clôture de nouveau : l'instantané est mis à jour.
        $this->actingAs($this->tresorier);
        $closings->close($this->eglise, 2026, 8);
        $this->assertSame(20, (int) FinanceClosing::where('month', 8)->value('snapshot')['totals']['expense']);
        $this->assertSame('closed', FinanceClosing::where('month', 8)->value('status'));
    }

    public function test_the_year_closes_once_all_its_months_are_closed(): void
    {
        Carbon::setTestNow('2027-01-10 10:00');
        $closings = app(Closings::class);
        $this->assertStringContainsString('août', $closings->yearBlocker($this->eglise, 2026));

        foreach (range(8, 12) as $m) {
            $closings->close($this->eglise, 2026, $m);
        }
        $this->assertNull($closings->yearBlocker($this->eglise, 2026));
        $closings->closeYear($this->eglise, 2026);
        $this->assertTrue($closings->isClosed($this->eglise, 2026, 0));

        $this->actingAs($this->admin);
        $this->assertSame(2, $closings->reopen($this->eglise, 2026, 12, 'Écriture de fin d’année à corriger'));
        $this->assertFalse($closings->isClosed($this->eglise, 2026, 0));
    }

    public function test_reports_print_and_export(): void
    {
        $this->record('income', 'USD', '50', '2026-09-03', 'Dîme');

        $this->get(route('finances.reports', ['annee' => 2026, 'mois' => 9]))->assertOk()->assertSee('Provisoire');
        $this->get(route('finances.reports.print', ['annee' => 2026, 'mois' => 9]))->assertOk()->assertSee('Rapport financier de septembre 2026')->assertSee('Dîme');
        $this->get(route('finances.reports.print', ['annee' => 2026]))->assertOk()->assertSee('Mois par mois');

        $response = $this->get(route('finances.reports.excel', ['annee' => 2026, 'mois' => 9]));
        $response->assertOk()->assertDownload();
        $this->assertStringContainsString('2026-09.xlsx', $response->headers->get('content-disposition'));

        // Le secrétaire n'a pas accès aux rapports financiers.
        $secretaire = User::factory()->create();
        $this->assign($secretaire, $this->role($this->eglise, 'secretaire'), $this->eglise);
        $this->actingAs($secretaire)->get(route('finances.reports.print', ['annee' => 2026]))->assertForbidden();
    }

    private function expectExceptionThrown(callable $action): void
    {
        try {
            $action();
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('clôturé', $e->getMessage());

            return;
        }
        $this->fail('Une écriture dans une période clôturée aurait dû être refusée.');
    }
}
