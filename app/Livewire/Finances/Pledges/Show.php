<?php

namespace App\Livewire\Finances\Pledges;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\CashAccount;
use App\Models\CashAccountCurrency;
use App\Models\FinanceTransaction;
use App\Models\Pledge;
use App\Models\PledgeReminder;
use App\Services\Pledges;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

/** Une promesse : échéances, versements, dons en nature, relances. */
class Show extends Component
{
    use WritesInOrganization;

    public Pledge $pledge;

    // Versement
    public string $accountId = '';

    public string $currency = '';

    public string $amount = '';

    public string $paidOn = '';

    public string $paymentMethod = 'cash';

    public string $reference = '';

    // Don en nature
    public string $deliveryDescription = '';

    public string $deliveryValue = '';

    public string $deliveryOn = '';

    public string $cancelReason = '';

    public function mount(Pledge $pledge): void
    {
        $this->authorize('finance.view');
        abort_unless(Gate::any(['finance.pledges', 'finance.contributions.view']), 403);
        $this->pledge = $pledge;
    }

    private function accountCurrencies(): array
    {
        return $this->accountId ? CashAccountCurrency::where('cash_account_id', $this->accountId)->where('is_active', true)->pluck('currency')->all() : [];
    }

    public function openPayment(): void
    {
        $this->authorizeWrite('finance.income');
        $this->accountId = (string) CashAccount::where('is_active', true)->orderBy('position')->value('id');
        $this->currency = in_array($this->pledge->currency, $this->accountCurrencies(), true) ? $this->pledge->currency : ($this->accountCurrencies()[0] ?? '');
        $this->fill(['amount' => '', 'paidOn' => today()->toDateString(), 'reference' => '', 'paymentMethod' => CashAccount::find($this->accountId)?->kind ?? 'cash']);
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'payment');
    }

    public function updatedAccountId(): void
    {
        $currencies = $this->accountCurrencies();
        $this->currency = in_array($this->pledge->currency, $currencies, true) ? $this->pledge->currency : ($currencies[0] ?? '');
        $this->paymentMethod = CashAccount::find($this->accountId)?->kind ?? 'cash';
    }

    public function pay(Pledges $pledges): void
    {
        $this->authorizeWrite('finance.income');
        $this->validate([
            'accountId' => ['required', Rule::exists('cash_accounts', 'id')->where('organization_id', $this->organization()->id)->where('is_active', true)],
            'currency' => ['required', Rule::in($this->accountCurrencies())],
            'amount' => 'required|numeric|gt:0',
            'paidOn' => 'required|date|before_or_equal:today',
            'paymentMethod' => ['required', Rule::in(array_keys(FinanceTransaction::PAYMENT_METHODS))],
            'reference' => [Rule::requiredIf($this->paymentMethod === 'mobile'), 'nullable', 'string', 'max:100'],
        ], ['reference.required' => __('Indiquez l’ID de la transaction mobile money.')], ['amount' => __('montant')]);

        try {
            $t = $pledges->pay($this->pledge, CashAccount::findOrFail($this->accountId), $this->currency, $this->amount, [
                'occurred_on' => $this->paidOn, 'payment_method' => $this->paymentMethod, 'external_reference' => trim($this->reference) ?: null,
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        $this->dispatch('close-modal', name: 'payment');
        $this->notify(__('Versement enregistré : reçu :n.', ['n' => $t->receipt_number]));
    }

    public function openDelivery(): void
    {
        $this->authorizeWrite('finance.pledges');
        $this->fill(['deliveryDescription' => (string) $this->pledge->in_kind_description, 'deliveryValue' => '', 'deliveryOn' => today()->toDateString()]);
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'delivery');
    }

    public function deliver(Pledges $pledges): void
    {
        $this->authorizeWrite('finance.pledges');
        $this->validate([
            'deliveryDescription' => 'required|string|max:255',
            'deliveryValue' => 'required|numeric|min:0',
            'deliveryOn' => 'required|date|before_or_equal:today',
        ], attributes: ['deliveryDescription' => __('description'), 'deliveryValue' => __('valeur')]);

        $pledges->deliver($this->pledge, trim($this->deliveryDescription), $this->deliveryValue, Carbon::parse($this->deliveryOn));
        $this->dispatch('close-modal', name: 'delivery');
        $this->notify(__('Don en nature enregistré.'));
    }

    /** Trace la relance : le message part du téléphone de l'utilisateur, sur WhatsApp. */
    public function logReminder(): void
    {
        abort_unless(Gate::allows('finance.pledges'), 403);
        PledgeReminder::create(['pledge_id' => $this->pledge->id, 'user_id' => auth()->id()]);
    }

    public function cancel(): void
    {
        $this->authorizeWrite('finance.pledges');
        $this->validate(['cancelReason' => 'required|string|min:5|max:255'], attributes: ['cancelReason' => __('motif')]);
        $this->pledge->update(['status' => 'cancelled', 'notes' => trim(($this->pledge->notes ? $this->pledge->notes."\n" : '').__('Annulée : :r', ['r' => $this->cancelReason]))]);
        $this->dispatch('close-modal', name: 'cancel');
        $this->notify(__('Promesse annulée. Les versements déjà reçus restent dans les recettes.'));
    }

    public function render(Pledges $pledges)
    {
        $this->pledge->refresh()->load(['project', 'member', 'household', 'department']);
        $progress = $pledges->progress($this->pledge);

        // Échéancier : date, montant attendu, cumul, honorée ou non.
        $schedule = [];
        if ($this->pledge->frequency !== 'once') {
            $each = (float) $this->pledge->amount / max(1, $this->pledge->installments);
            $first = $this->pledge->first_due_on ?? $this->pledge->pledged_on;
            $received = (float) (string) $progress['received'];
            for ($i = 0; $i < $this->pledge->installments; $i++) {
                $date = $this->pledge->frequency === 'weekly' ? $first->copy()->addWeeks($i) : $first->copy()->addMonthsNoOverflow($i);
                $cumul = $each * ($i + 1);
                $schedule[] = ['date' => $date, 'amount' => $each, 'paid' => $received + 0.009 >= $cumul, 'late' => $date->lt(today()) && $received + 0.009 < $cumul];
            }
        }

        return view('livewire.finances.pledges.show', [
            'progress' => $progress,
            'schedule' => $schedule,
            'payments' => $this->pledge->payments()->with('account')->latest('occurred_on')->get(),
            'deliveries' => $this->pledge->deliveries()->latest('received_on')->get(),
            'reminders' => $this->pledge->reminders()->with('user')->limit(5)->get(),
            'whatsapp' => $pledges->whatsappUrl($this->pledge),
            'message' => $pledges->reminderMessage($this->pledge),
            'canManage' => Gate::allows('finance.pledges') && ! $this->organization()->isReadOnly(),
            'canPay' => Gate::allows('finance.income') && ! $this->organization()->isReadOnly() && $this->pledge->status !== 'cancelled',
            'accounts' => CashAccount::where('is_active', true)->orderBy('position')->get(),
            'currencies' => $this->accountCurrencies(),
        ])->title(__('Promesse de :n', ['n' => $this->pledge->pledgerName()]));
    }
}
