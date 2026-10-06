<?php

namespace App\Livewire\Finances\Expenses;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\CashAccount;
use App\Models\ExpenseAttachment;
use App\Models\ExpenseRequest;
use App\Services\Expenses;
use App\Services\Ledger;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Une demande de dépense : où elle en est dans le circuit, et l'action qui l'attend. */
class Show extends Component
{
    use WithFileUploads, WritesInOrganization;

    public ExpenseRequest $expense;

    public string $note = '';

    public string $reason = '';

    public string $accountId = '';

    public string $spent = '';

    public array $files = [];

    public string $fileKind = 'invoice';

    public function mount(ExpenseRequest $expense): void
    {
        abort_unless(Gate::any(['finance.view', 'finance.expenses.approve', 'finance.disburse']) || $expense->requested_by === auth()->id(), 403);
        $this->expense = $expense;
        $this->spent = (string) (float) $expense->amount;
    }

    private function run(callable $action, string $message, string $field = 'note'): void
    {
        try {
            $action();
        } catch (InvalidArgumentException $e) {
            $this->addError($field, $e->getMessage());

            return;
        }
        $this->reset('note', 'reason', 'files');
        $this->dispatch('close-modal', name: 'reject');
        $this->dispatch('close-modal', name: 'cancel');
        $this->notify($message);
    }

    public function check(Expenses $expenses): void
    {
        $this->authorizeWrite('finance.disburse');
        $this->validate(['note' => 'nullable|string|max:500']);
        $this->run(fn () => $expenses->check($this->expense, trim($this->note) ?: null), __('Demande contrôlée : elle passe à l’approbation.'));
    }

    public function approve(Expenses $expenses): void
    {
        $this->authorizeWrite('finance.expenses.approve');
        $this->validate(['note' => 'nullable|string|max:500']);
        $last = $this->expense->approvals()->where('decision', 'approved')->count() + 1 >= $this->expense->approvals_required;
        $this->run(fn () => $expenses->approve($this->expense, auth()->user(), trim($this->note) ?: null),
            $last ? __('Signature enregistrée : la dépense peut être décaissée.') : __('Signature enregistrée : il faut encore une autre signature.'));
    }

    public function reject(Expenses $expenses): void
    {
        abort_unless(Gate::any(['finance.expenses.approve', 'finance.disburse']), 403);
        abort_if($this->organization()->isReadOnly(), 403, __('Cette communauté est en lecture seule.'));
        $this->validate(['reason' => 'required|string|min:5|max:500'], attributes: ['reason' => __('motif')]);
        $this->run(fn () => $expenses->reject($this->expense, auth()->user(), trim($this->reason)), __('Demande refusée.'), 'reason');
    }

    public function disburse(Expenses $expenses): void
    {
        $this->authorizeWrite('finance.disburse');
        $this->validate([
            'accountId' => ['required', Rule::exists('cash_accounts', 'id')->where('organization_id', $this->organization()->id)->where('is_active', true)],
        ], attributes: ['accountId' => __('compte')]);
        $this->run(fn () => $expenses->disburse($this->expense, CashAccount::findOrFail($this->accountId)),
            $this->expense->is_advance ? __('Avance remise : elle devra être justifiée.') : __('Dépense décaissée.'), 'accountId');
    }

    public function justify(Expenses $expenses): void
    {
        $this->authorizeWrite('finance.disburse');
        $this->validate([
            'spent' => 'required|numeric|min:0',
            'note' => 'nullable|string|max:500',
            'files.*' => 'file|max:8192|mimes:jpg,jpeg,png,webp,pdf',
        ], attributes: ['spent' => __('montant dépensé'), 'files.*' => __('pièce jointe')]);
        $this->storeFiles('invoice');
        $this->run(fn () => $expenses->justify($this->expense, $this->spent, trim($this->note) ?: null), __('Dépense justifiée.'), 'spent');
    }

    /** Ajouter une pièce à tout moment (devis, facture, reçu, photo). */
    public function attach(): void
    {
        abort_unless(Gate::any(['finance.disburse', 'finance.expenses.approve']) || $this->expense->requested_by === auth()->id(), 403);
        abort_if($this->organization()->isReadOnly(), 403, __('Cette communauté est en lecture seule.'));
        $this->validate([
            'files' => 'required|array|min:1',
            'files.*' => 'file|max:8192|mimes:jpg,jpeg,png,webp,pdf',
            'fileKind' => ['required', Rule::in(array_keys(ExpenseAttachment::KINDS))],
        ], attributes: ['files' => __('fichier'), 'files.*' => __('pièce jointe')]);
        $this->storeFiles($this->fileKind);
        $this->reset('files');
        $this->notify(__('Pièce ajoutée.'));
    }

    private function storeFiles(string $kind): void
    {
        foreach ($this->files as $file) {
            ExpenseAttachment::create(['expense_request_id' => $this->expense->id, 'kind' => $kind, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'path' => $file->storeAs('expenses/'.$this->expense->organization_id, $this->expense->id.'-'.Str::random(8).'.'.$file->extension(), 'local'), 'uploaded_by' => auth()->id()]);
        }
    }

    public function cancel(Expenses $expenses): void
    {
        abort_unless(Gate::allows('finance.disburse') || $this->expense->requested_by === auth()->id(), 403);
        abort_if($this->organization()->isReadOnly(), 403, __('Cette communauté est en lecture seule.'));
        $this->run(fn () => $expenses->cancel($this->expense), __('Demande annulée.'));
    }

    public function render(Ledger $ledger, Expenses $expenses)
    {
        $this->expense->refresh()->load(['department', 'category', 'beneficiary', 'requester', 'checker', 'disburser', 'justifier', 'account', 'transaction', 'approvals.user', 'attachments']);
        $e = $this->expense;
        $writable = ! $this->organization()->isReadOnly();
        $me = auth()->id();

        // Comptes qui tiennent la devise de la demande, avec leur solde.
        $accounts = collect();
        if ($e->status === 'approved') {
            $accounts = $ledger->balances($this->organization())->where('currency', $e->currency)->filter(fn ($b) => $b['account']->is_active)->values();
            if ($this->accountId === '' && $accounts->isNotEmpty()) {
                $this->accountId = (string) ($accounts->first(fn ($b) => (float) (string) $b['balance'] >= (float) $e->amount) ?? $accounts->first())['account']->id;
            }
        }

        return view('livewire.finances.expenses.show', [
            'accounts' => $accounts,
            'canCheck' => $writable && $e->status === 'submitted' && Gate::allows('finance.disburse'),
            'canSign' => $writable && $e->status === 'checked' && Gate::allows('finance.expenses.approve') && $e->requested_by !== $me && ! $e->approvals->contains('user_id', $me),
            'canReject' => $writable && in_array($e->status, ['submitted', 'checked'], true) && Gate::any(['finance.expenses.approve', 'finance.disburse']),
            'canDisburse' => $writable && $e->status === 'approved' && Gate::allows('finance.disburse'),
            'canJustify' => $writable && $e->status === 'disbursed' && Gate::allows('finance.disburse'),
            'canCancel' => $writable && in_array($e->status, ['submitted', 'checked', 'approved'], true) && (Gate::allows('finance.disburse') || $e->requested_by === $me),
            'canAttach' => $writable && ! in_array($e->status, ['rejected', 'cancelled'], true) && (Gate::any(['finance.disburse', 'finance.expenses.approve']) || $e->requested_by === $me),
            'ownRequest' => $e->requested_by === $me,
            'settings' => $expenses->settings($this->organization()),
        ])->title(__('Dépense :n', ['n' => $e->number]));
    }
}
