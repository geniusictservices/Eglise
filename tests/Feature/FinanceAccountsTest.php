<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\CashAccount;
use App\Models\CashAccountCurrency;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\Member;
use App\Models\OrganizationCurrency;
use App\Models\User;
use App\Services\ExchangeRateService;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class FinanceAccountsTest extends TestCase
{
    use RefreshDatabase;

    private $eglise;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $admin = User::factory()->create();
        $this->eglise = $this->createCommunity('Église', $admin);
        $this->actingAs($admin);
        $this->inOrganization($this->eglise);
        app(ExchangeRateService::class)->setRate($this->eglise, 'CDF', '2800');
    }

    private function account(string $name = 'Caisse', string $kind = 'cash', array $currencies = ['USD' => 0, 'CDF' => 0]): CashAccount
    {
        $account = CashAccount::create(['name' => $name, 'kind' => $kind]);
        foreach ($currencies as $code => $opening) {
            CashAccountCurrency::create(['cash_account_id' => $account->id, 'currency' => $code, 'opening_balance' => $opening, 'opened_on' => today()]);
        }

        return $account;
    }

    public function test_finance_screens_open(): void
    {
        $this->account();
        foreach (['finances.index', 'finances.journal', 'finances.income', 'finances.transfer', 'finances.settings'] as $route) {
            $this->get(route($route))->assertOk();
        }
    }

    public function test_an_account_holds_several_currencies_with_their_own_balance(): void
    {
        LivewireTest::test(Livewire\Finances\Settings::class)
            ->call('editAccount')
            ->set('account.name', 'M-Pesa de la paroisse')
            ->set('account.kind', 'mobile')
            ->set('account.provider', 'M-Pesa (Vodacom)')
            ->set('account.account_number', '0812345678')
            ->set('account.currencies.USD.opening', '120')
            ->set('account.currencies.CDF.opening', '350000')
            ->call('saveAccount')
            ->assertHasNoErrors();

        $account = CashAccount::sole();
        $ledger = app(Ledger::class);
        $this->assertSame(['CDF', 'USD'], $account->currencies()->pluck('currency')->sort()->values()->all());
        $this->assertSame('120.00', (string) $ledger->balance($account, 'USD'));
        $this->assertSame('350000.00', (string) $ledger->balance($account, 'CDF'));
    }

    public function test_income_gets_a_receipt_number_and_a_dollar_equivalent(): void
    {
        $account = $this->account();
        $dime = FinanceCategory::where('name', 'Dîme')->sole();
        $member = Member::create(['last_name' => 'KAHINDO', 'first_name' => 'Esther']);

        LivewireTest::test(Livewire\Finances\IncomeForm::class)
            ->set('accountId', (string) $account->id)
            ->set('currency', 'CDF')
            ->set('categoryId', (string) $dime->id)
            ->call('save')
            ->assertHasErrors(['amount', 'memberId'])
            ->call('chooseMember', $member->id)
            ->set('amount', '56000')
            ->call('save')
            ->assertHasNoErrors();

        $t = FinanceTransaction::sole();
        $this->assertSame('R-'.now()->year.'-000001', $t->receipt_number);
        $this->assertSame('20.00', $t->usd_amount);
        $this->assertSame($member->id, $t->member_id);
        $this->get(route('finances.receipt', $t))->assertOk()->assertSee('KAHINDO')->assertSee('56');
    }

    public function test_a_currency_not_held_by_the_account_is_refused(): void
    {
        $account = $this->account('Caisse francs', 'cash', ['CDF' => 0]);
        $this->expectException(\InvalidArgumentException::class);
        app(Ledger::class)->record($account, 'USD', 'income', ['amount' => '10', 'category_id' => FinanceCategory::where('type', 'income')->value('id')]);
    }

    public function test_exchange_within_an_account_and_transfer_between_accounts(): void
    {
        $caisse = $this->account('Caisse', 'cash', ['USD' => 100, 'CDF' => 0]);
        $banque = $this->account('Rawbank', 'bank', ['USD' => 0]);
        $ledger = app(Ledger::class);

        // Change de 50 $ en francs dans la même caisse.
        LivewireTest::test(Livewire\Finances\TransferForm::class)
            ->set('from', $caisse->id.':USD')
            ->set('to', $caisse->id.':CDF')
            ->set('amountOut', '50')
            ->set('amountIn', '142500')
            ->assertSee('Taux pratiqué')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('50.00', (string) $ledger->balance($caisse, 'USD'));
        $this->assertSame('142500.00', (string) $ledger->balance($caisse, 'CDF'));
        $this->assertSame(2, FinanceTransaction::whereIn('type', ['exchange_in', 'exchange_out'])->count());

        // Dépôt de 40 $ à la banque.
        [$out] = $ledger->transfer($caisse, 'USD', $banque, 'USD', '40');
        $this->assertSame('10.00', (string) $ledger->balance($caisse, 'USD'));
        $this->assertSame('40.00', (string) $ledger->balance($banque, 'USD'));

        // Pas plus que le solde.
        LivewireTest::test(Livewire\Finances\TransferForm::class)
            ->set('from', $caisse->id.':USD')->set('to', $banque->id.':USD')->set('amountOut', '500')
            ->call('save')->assertHasErrors('amountOut');

        // Annuler un virement annule ses deux côtés.
        LivewireTest::test(Livewire\Finances\Journal::class)
            ->call('askCancel', $out->id)
            ->set('cancelReason', 'Dépôt pas encore fait')
            ->call('cancel')
            ->assertHasNoErrors();
        $this->assertSame('50.00', (string) $ledger->balance($caisse, 'USD'));
        $this->assertSame('0.00', (string) $ledger->balance($banque, 'USD'));
    }

    public function test_names_of_personal_contributions_are_hidden_without_the_permission(): void
    {
        $account = $this->account();
        $member = Member::create(['last_name' => 'MUMBERE', 'first_name' => 'Moïse']);
        $t = app(Ledger::class)->record($account, 'USD', 'income', ['amount' => '10', 'category_id' => FinanceCategory::where('name', 'Dîme')->value('id'), 'member_id' => $member->id]);

        $this->get(route('finances.journal'))->assertSee('Moïse MUMBERE');

        $secretaire = User::factory()->create();
        $this->assign($secretaire, $this->role($this->eglise, 'secretaire'), $this->eglise);
        $conseil = User::factory()->create();
        $this->assign($conseil, $this->role($this->eglise, 'conseil'), $this->eglise);

        $this->actingAs($conseil);
        $this->get(route('finances.journal'))->assertOk()->assertDontSee('MUMBERE')->assertSee('Contribution nominative');
        $this->get(route('finances.receipt', $t))->assertForbidden();

        $this->actingAs($secretaire);
        $this->get(route('finances.index'))->assertForbidden();
    }

    public function test_a_missing_exchange_rate_is_reported(): void
    {
        OrganizationCurrency::firstOrCreate(['currency' => 'EUR'], ['is_active' => true]);
        $account = $this->account('Caisse euros', 'cash', ['EUR' => 0]);

        LivewireTest::test(Livewire\Finances\IncomeForm::class)
            ->set('accountId', (string) $account->id)
            ->set('currency', 'EUR')
            ->set('amount', '20')
            ->call('save')
            ->assertHasErrors('amount')
            ->assertSee('Saisissez d’abord le taux du jour pour EUR');
    }
}
