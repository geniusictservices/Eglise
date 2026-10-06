<?php

namespace App\Livewire\Finances;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\CashAccount;
use App\Models\CashAccountCurrency;
use App\Services\ExchangeRateService;
use App\Services\Ledger;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Virement entre deux comptes, ou opération de change (dans un même compte
 * ou entre deux comptes) : on indique le montant donné et le montant reçu.
 */
#[Title('Virement ou change')]
class TransferForm extends Component
{
    use WritesInOrganization;

    public string $from = '';   // "compte:devise"

    public string $to = '';

    public string $amountOut = '';

    public string $amountIn = '';

    public string $occurredOn = '';

    public string $description = '';

    public function mount(): void
    {
        $this->authorize('finance.exchange');
        $this->occurredOn = today()->toDateString();
        $options = array_keys($this->options());
        $this->from = $options[0] ?? '';
        $this->to = $options[1] ?? '';
    }

    /** Chaque devise de chaque compte ouvert : « 3:USD » => « Caisse principale · USD ». */
    private function options(): array
    {
        return CashAccountCurrency::query()
            ->join('cash_accounts', 'cash_accounts.id', '=', 'cash_account_currencies.cash_account_id')
            ->where('cash_accounts.organization_id', $this->organization()->id)
            ->where('cash_accounts.is_active', true)->where('cash_account_currencies.is_active', true)
            ->orderBy('cash_accounts.position')->orderBy('cash_accounts.name')->orderByRaw("currency = 'USD' DESC")
            ->get(['cash_accounts.id as account_id', 'cash_accounts.name', 'cash_account_currencies.currency'])
            ->mapWithKeys(fn ($r) => [$r->account_id.':'.$r->currency => $r->name.' · '.$r->currency])->all();
    }

    private function split(string $value): array
    {
        [$id, $currency] = array_pad(explode(':', $value), 2, null);

        return [CashAccount::find($id), $currency];
    }

    public function save(Ledger $ledger): void
    {
        $this->authorizeWrite('finance.exchange');
        [$fromAccount, $fromCurrency] = $this->split($this->from);
        [$toAccount, $toCurrency] = $this->split($this->to);
        $exchange = $fromCurrency !== $toCurrency;

        $this->validate([
            'from' => ['required', 'in:'.implode(',', array_keys($this->options()))],
            'to' => ['required', 'in:'.implode(',', array_keys($this->options())), 'different:from'],
            'amountOut' => 'required|numeric|gt:0',
            'amountIn' => [$exchange ? 'required' : 'nullable', 'numeric', 'gt:0'],
            'occurredOn' => 'required|date|before_or_equal:today',
            'description' => 'nullable|string|max:255',
        ], ['to.different' => __('Choisissez un autre compte ou une autre devise.')], ['amountOut' => __('montant'), 'amountIn' => __('montant reçu'), 'to' => __('destination')]);

        try {
            $ledger->transfer($fromAccount, $fromCurrency, $toAccount, $toCurrency, $this->amountOut, $exchange ? $this->amountIn : null,
                trim($this->description) ?: null, Carbon::parse($this->occurredOn));
        } catch (\InvalidArgumentException $e) {
            $this->addError('amountOut', $e->getMessage());

            return;
        }

        session()->flash('status', $exchange ? __('Opération de change enregistrée.') : __('Virement enregistré.'));
        $this->redirectRoute('finances.journal');
    }

    public function render(Ledger $ledger, ExchangeRateService $rates)
    {
        [$fromAccount, $fromCurrency] = $this->split($this->from);
        [$toAccount, $toCurrency] = $this->split($this->to);
        $exchange = $fromCurrency && $toCurrency && $fromCurrency !== $toCurrency;

        // Taux pratiqué, comparé au taux du jour de la communauté.
        $applied = null;
        $official = null;
        if ($exchange && is_numeric($this->amountOut) && is_numeric($this->amountIn) && $this->amountOut > 0 && $this->amountIn > 0) {
            $usdSide = $fromCurrency === 'USD' ? $this->amountOut : ($toCurrency === 'USD' ? $this->amountIn : null);
            $otherSide = $fromCurrency === 'USD' ? $this->amountIn : $this->amountOut;
            $otherCurrency = $fromCurrency === 'USD' ? $toCurrency : $fromCurrency;
            if ($usdSide) {
                $applied = Money::rate(BigDecimal::of((string) $otherSide)->dividedBy((string) $usdSide, 4, RoundingMode::HalfUp), $otherCurrency);
                $rate = $rates->rate($this->organization(), $otherCurrency);
                $official = $rate ? Money::rate($rate, $otherCurrency) : null;
            }
        }

        return view('livewire.finances.transfer-form', [
            'options' => $this->options(),
            'exchange' => $exchange,
            'fromBalance' => $fromAccount && $fromCurrency ? Money::format($ledger->balance($fromAccount, $fromCurrency), $fromCurrency) : null,
            'fromCurrency' => $fromCurrency,
            'toCurrency' => $toCurrency,
            'applied' => $applied,
            'official' => $official,
        ]);
    }
}
