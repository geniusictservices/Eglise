<?php

namespace App\Livewire\Payroll;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\CashAccount;
use App\Models\PayRun;
use App\Models\PaySlip;
use App\Services\Ledger;
use App\Services\PayRuns;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Component;

/** Une paie : les bulletins, puis présentation, approbation et paiement. */
class Run extends Component
{
    use WritesInOrganization;

    public PayRun $run;

    /** Prestations saisies, bulletin par bulletin. */
    public array $quantities = [];

    public ?int $slipId = null;

    public array $adjustment = ['label' => '', 'kind' => 'earning', 'amount' => ''];

    public string $note = '';

    /** Paiement : [devise du bulletin => ['account' => id, 'currency' => devise payée]] */
    public array $payment = [];

    public function mount(PayRun $run): void
    {
        abort_unless(Gate::any(['payroll.view', 'payroll.manage', 'payroll.approve']), 403);
        $this->run = $run;
        $this->quantities = $run->slips()->pluck('quantity', 'id')->map(fn ($q) => (string) (float) $q)->all();
    }

    private function canEdit(): bool
    {
        return $this->run->status === 'draft' && Gate::allows('payroll.manage') && ! $this->organization()->isReadOnly();
    }

    public function updatedQuantities($value, $key): void
    {
        abort_unless($this->canEdit(), 403);
        $this->validate(["quantities.$key" => 'required|numeric|min:0|max:999'], attributes: ["quantities.$key" => __('prestations')]);
        $slip = $this->run->slips()->findOrFail((int) $key);
        $slip->update(['quantity' => $value]);
        app(PayRuns::class)->recompute($slip);
    }

    public function editSlip(int $id): void
    {
        abort_unless($this->canEdit(), 403);
        $this->slipId = $this->run->slips()->findOrFail($id)->id;
        $this->adjustment = ['label' => '', 'kind' => 'earning', 'amount' => ''];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'slip');
    }

    /** Une prime ou une retenue ponctuelle, pour cette paie seulement. */
    public function addAdjustment(PayRuns $runs): void
    {
        abort_unless($this->canEdit(), 403);
        $data = $this->validate([
            'adjustment.label' => 'required|string|max:100',
            'adjustment.kind' => 'required|in:earning,deduction',
            'adjustment.amount' => 'required|numeric|gt:0',
        ], attributes: ['adjustment.label' => __('libellé'), 'adjustment.amount' => __('montant')])['adjustment'];
        $slip = $this->run->slips()->findOrFail($this->slipId);
        $slip->update(['adjustments' => array_values(array_merge($slip->adjustments ?? [], [$data + ['amount' => (float) $data['amount']]]))]);
        $runs->recompute($slip);
        $this->adjustment = ['label' => '', 'kind' => 'earning', 'amount' => ''];
    }

    public function removeAdjustment(PayRuns $runs, int $index): void
    {
        abort_unless($this->canEdit(), 403);
        $slip = $this->run->slips()->findOrFail($this->slipId);
        $list = $slip->adjustments ?? [];
        unset($list[$index]);
        $slip->update(['adjustments' => array_values($list)]);
        $runs->recompute($slip);
    }

    public function recomputeAll(PayRuns $runs): void
    {
        abort_unless($this->canEdit(), 403);
        $runs->recomputeAll($this->run);
        $this->notify(__('Bulletins mis à jour d’après les fiches des bénéficiaires.'));
    }

    public function submit(PayRuns $runs): void
    {
        $this->authorizeWrite('payroll.manage');
        $this->attempt(fn () => $runs->submit($this->run), __('Paie présentée au pasteur pour approbation.'));
    }

    public function approve(PayRuns $runs): void
    {
        $this->authorizeWrite('payroll.approve');
        $this->validate(['note' => 'nullable|string|max:255']);
        $this->attempt(fn () => $runs->approve($this->run, auth()->user(), trim($this->note) ?: null), __('Paie approuvée : la finance peut payer.'));
    }

    public function sendBack(PayRuns $runs): void
    {
        $this->authorizeWrite('payroll.approve');
        $this->validate(['note' => 'required|string|min:5|max:255'], attributes: ['note' => __('remarque')]);
        $this->attempt(fn () => $runs->sendBack($this->run, trim($this->note)), __('Paie renvoyée à la finance.'));
    }

    public function cancel(PayRuns $runs): void
    {
        $this->authorizeWrite('payroll.manage');
        $this->attempt(fn () => $runs->cancel($this->run), __('Paie annulée.'));
    }

    public function pay(PayRuns $runs, string $currency): void
    {
        $this->authorizeWrite('payroll.manage');
        $choice = $this->payment[$currency] ?? [];
        $this->validate([
            "payment.$currency.account" => ['required', Rule::exists('cash_accounts', 'id')->where('organization_id', $this->organization()->id)->where('is_active', true)],
            "payment.$currency.currency" => 'required|string|size:3',
        ], attributes: ["payment.$currency.account" => __('compte')]);
        $this->attempt(fn () => $runs->pay($this->run, $currency, CashAccount::findOrFail($choice['account']), $choice['currency']), __('Paie payée : les sorties sont enregistrées dans le compte.'), "payment.$currency.account");
    }

    private function attempt(callable $action, string $message, string $field = 'note'): void
    {
        try {
            $action();
        } catch (InvalidArgumentException $e) {
            $this->addError($field, $e->getMessage());

            return;
        }
        $this->note = '';
        $this->notify($message);
    }

    public function render(Ledger $ledger)
    {
        $this->run->refresh()->load(['schedule', 'slips.payee.member', 'slips.payee.department', 'slips.account', 'submitter', 'approver', 'preparer']);
        $run = $this->run;
        $organization = $this->organization();
        $writable = ! $organization->isReadOnly();

        // Le paiement : pour chaque devise de bulletins, un compte et la devise à payer.
        $balances = $run->status === 'approved' ? $ledger->balances($organization) : collect();
        foreach ($run->slips->whereNull('paid_at')->where('net', '>', 0)->pluck('currency')->unique() as $currency) {
            if (! isset($this->payment[$currency])) {
                $best = $balances->firstWhere('currency', $currency) ?? $balances->first();
                $this->payment[$currency] = ['account' => (string) ($best['account']->id ?? ''), 'currency' => $best['currency'] ?? $currency];
            }
        }

        return view('livewire.payroll.run', [
            'totals' => $run->totals(),
            'balances' => $balances,
            'slip' => $this->slipId ? PaySlip::with('payee')->find($this->slipId) : null,
            'canEdit' => $this->canEdit(),
            'canApprove' => $writable && $run->status === 'submitted' && Gate::allows('payroll.approve') && $run->submitted_by !== auth()->id(),
            'canPay' => $writable && $run->status === 'approved' && Gate::allows('payroll.manage'),
            'canCancel' => $writable && in_array($run->status, ['draft', 'submitted', 'approved'], true) && Gate::allows('payroll.manage') && $run->slips->whereNotNull('paid_at')->isEmpty(),
            'accounts' => CashAccount::with('currencies')->where('is_active', true)->orderBy('position')->get(),
        ])->title(__('Paie : :p', ['p' => $run->label()]));
    }
}
