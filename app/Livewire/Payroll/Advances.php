<?php

namespace App\Livewire\Payroll;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\CashAccount;
use App\Models\Payee;
use App\Models\SalaryAdvance;
use App\Services\Payroll;
use App\Services\SalaryAdvances;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Les avances sur salaire : proposées, approuvées, payées, retenues sur les paies. */
#[Title('Avances sur salaire')]
class Advances extends Component
{
    use WritesInOrganization;

    public array $form = ['payee_id' => '', 'amount' => '', 'installments' => '1', 'reason' => ''];

    public ?int $advanceId = null;

    public string $note = '';

    public string $accountId = '';

    public string $payCurrency = '';

    public function mount(): void
    {
        abort_unless(Gate::any(['payroll.view', 'payroll.manage', 'payroll.approve']), 403);
    }

    public function create(): void
    {
        $this->authorizeWrite('payroll.manage');
        $this->form = ['payee_id' => (string) Payee::where('is_active', true)->orderBy('id')->value('id'), 'amount' => '', 'installments' => '2', 'reason' => ''];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'advance');
    }

    public function save(SalaryAdvances $advances): void
    {
        $this->authorizeWrite('payroll.manage');
        $data = $this->validate([
            'form.payee_id' => ['required', Rule::exists('payees', 'id')->where('organization_id', $this->organization()->id)],
            'form.amount' => 'required|numeric|gt:0',
            'form.installments' => 'required|integer|between:1,12',
            'form.reason' => 'required|string|min:5|max:500',
        ], attributes: ['form.amount' => __('montant'), 'form.installments' => __('nombre de retenues'), 'form.reason' => __('motif')])['form'];
        try {
            $advances->request(Payee::findOrFail($data['payee_id']), $data['amount'], (int) $data['installments'], trim($data['reason']));
        } catch (InvalidArgumentException $e) {
            $this->addError('form.payee_id', $e->getMessage());

            return;
        }
        $this->dispatch('close-modal', name: 'advance');
        $this->notify(__('Avance proposée : elle attend l’approbation du pasteur.'));
    }

    public function decide(SalaryAdvances $advances, int $id, bool $approve): void
    {
        $this->authorizeWrite('payroll.approve');
        try {
            $advances->decide(SalaryAdvance::findOrFail($id), auth()->user(), $approve, trim($this->note) ?: null);
        } catch (InvalidArgumentException $e) {
            $this->addError("note-$id", $e->getMessage());

            return;
        }
        $this->note = '';
        $this->notify($approve ? __('Avance approuvée : la finance peut la payer.') : __('Avance refusée.'));
    }

    public function askPay(int $id): void
    {
        $this->authorizeWrite('payroll.manage');
        $advance = SalaryAdvance::findOrFail($id);
        $this->advanceId = $advance->id;
        $account = CashAccount::where('is_active', true)->whereHas('currencies', fn ($q) => $q->where('currency', $advance->currency)->where('is_active', true))->orderBy('position')->first()
            ?? CashAccount::where('is_active', true)->orderBy('position')->first();
        $this->accountId = (string) $account?->id;
        $this->payCurrency = $advance->currency;
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'pay');
    }

    public function pay(SalaryAdvances $advances): void
    {
        $this->authorizeWrite('payroll.manage');
        $this->validate(['accountId' => ['required', Rule::exists('cash_accounts', 'id')->where('organization_id', $this->organization()->id)->where('is_active', true)], 'payCurrency' => 'required|size:3'],
            attributes: ['accountId' => __('compte')]);
        try {
            $advances->pay(SalaryAdvance::findOrFail($this->advanceId), CashAccount::findOrFail($this->accountId), $this->payCurrency);
        } catch (InvalidArgumentException $e) {
            $this->addError('accountId', $e->getMessage());

            return;
        }
        $this->dispatch('close-modal', name: 'pay');
        $this->notify(__('Avance payée : elle sera retenue sur les prochaines paies.'));
    }

    public function cancel(SalaryAdvances $advances, int $id): void
    {
        $this->authorizeWrite('payroll.manage');
        try {
            $advances->cancel(SalaryAdvance::findOrFail($id));
        } catch (InvalidArgumentException $e) {
            $this->notify($e->getMessage(), 'error');
        }
    }

    public function render(Payroll $payroll)
    {
        $writable = ! $this->organization()->isReadOnly();
        $payees = Payee::with(['member', 'schedule'])->where('is_active', true)->orderBy('id')->get();
        $chosen = $payees->firstWhere('id', (int) ($this->form['payee_id'] ?? 0));
        $usual = $chosen && ! $chosen->schedule?->isPerService() ? $payroll->compute($chosen)['net'] : null;
        $each = $chosen && is_numeric($this->form['amount'] ?? null) ? (float) $this->form['amount'] / max(1, (int) $this->form['installments']) : null;

        return view('livewire.payroll.advances', [
            'advances' => SalaryAdvance::with(['payee.member', 'repayments', 'requester', 'approver'])->latest()->get(),
            'payees' => $payees,
            'chosen' => $chosen,
            'usual' => $usual,
            'each' => $each,
            'accounts' => CashAccount::with('currencies')->where('is_active', true)->orderBy('position')->get(),
            'paying' => $this->advanceId ? SalaryAdvance::with('payee')->find($this->advanceId) : null,
            'canManage' => $writable && Gate::allows('payroll.manage'),
            'canApprove' => $writable && Gate::allows('payroll.approve'),
        ]);
    }
}
