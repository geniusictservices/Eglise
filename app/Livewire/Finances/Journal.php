<?php

namespace App\Livewire\Finances;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\CashAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Services\Closings;
use App\Services\Ledger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Toutes les opérations, par mois, avec filtres et annulation tracée. */
#[Title('Opérations')]
class Journal extends Component
{
    use WithPagination, WritesInOrganization;

    #[Url(as: 'mois')]
    public string $month = '';

    #[Url(as: 'compte', except: '')]
    public string $account = '';

    #[Url(as: 'type', except: '')]
    public string $kind = '';

    #[Url(as: 'categorie', except: '')]
    public string $category = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public ?int $cancelId = null;

    public string $cancelReason = '';

    public function mount(): void
    {
        $this->authorize('finance.view');
        $this->month = preg_match('/^\d{4}-\d{2}$/', $this->month) ? $this->month : now()->format('Y-m');
    }

    public function updated(string $property): void
    {
        if ($property !== 'cancelReason') {
            $this->resetPage();
        }
    }

    public function shiftMonth(int $delta): void
    {
        $this->month = Carbon::createFromFormat('Y-m-d', $this->month.'-01')->addMonths($delta)->format('Y-m');
        $this->resetPage();
    }

    private function canCancel(FinanceTransaction $t): bool
    {
        $permission = match (true) {
            $t->type === 'income' => 'finance.income',
            $t->type === 'expense' => 'finance.disburse',
            default => 'finance.exchange',
        };

        return Gate::allows($permission) && ! $this->organization()->isReadOnly() && ! $t->cancelled_at && ! $this->monthClosed();
    }

    /** Le mois affiché est-il clôturé ? On n'y annule plus rien. */
    private function monthClosed(): bool
    {
        $start = Carbon::createFromFormat('Y-m-d', $this->month.'-01');

        return once(fn () => app(Closings::class)->isClosed($this->organization(), $start->year, $start->month)
            || app(Closings::class)->isClosed($this->organization(), $start->year, 0));
    }

    public function askCancel(int $id): void
    {
        $t = FinanceTransaction::findOrFail($id);
        abort_unless($this->canCancel($t), 403);
        $this->cancelId = $t->id;
        $this->cancelReason = '';
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'cancel');
    }

    public function cancel(Ledger $ledger): void
    {
        $t = FinanceTransaction::findOrFail($this->cancelId);
        abort_unless($this->canCancel($t), 403);
        $this->validate(['cancelReason' => 'required|string|min:5|max:255'], attributes: ['cancelReason' => __('motif')]);
        try {
            $ledger->cancel($t, trim($this->cancelReason));
        } catch (\InvalidArgumentException $e) {
            $this->addError('cancelReason', $e->getMessage());

            return;
        }

        $this->dispatch('close-modal', name: 'cancel');
        $this->notify(__('Opération annulée. Elle reste visible, barrée, dans le journal.'));
    }

    private function query(): Builder
    {
        $start = Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfMonth();

        return FinanceTransaction::query()
            ->whereBetween('occurred_on', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->when($this->account !== '', fn ($q) => $q->where('cash_account_id', $this->account))
            ->when($this->kind !== '', fn ($q) => match ($this->kind) {
                'recettes' => $q->where('type', 'income'),
                'depenses' => $q->where('type', 'expense'),
                default => $q->whereIn('type', ['transfer_in', 'transfer_out', 'exchange_in', 'exchange_out']),
            })
            ->when($this->category !== '', fn ($q) => $q->where('category_id', $this->category))
            ->when(trim($this->search) !== '', function ($q) {
                $term = '%'.trim($this->search).'%';
                $q->where(fn ($q) => $q->where('description', 'like', $term)->orWhere('receipt_number', 'like', $term)
                    ->orWhere('external_reference', 'like', $term)
                    ->when(Gate::allows('finance.contributions.view'), fn ($q) => $q->orWhere('payer_name', 'like', $term)
                        ->orWhereHas('member', fn ($q) => $q->search(trim($this->search)))));
            });
    }

    public function render()
    {
        $totals = (clone $this->query())->valid()
            ->selectRaw("sum(case when type = 'income' then usd_amount else 0 end) as income")
            ->selectRaw("sum(case when type = 'expense' then usd_amount else 0 end) as expense")
            ->first();

        $transactions = $this->query()->with(['account', 'category', 'member', 'department', 'author'])
            ->orderByDesc('occurred_on')->orderByDesc('id')->paginate(30);

        return view('livewire.finances.journal', [
            'transactions' => $transactions,
            'accounts' => CashAccount::orderBy('position')->get(),
            'categories' => FinanceCategory::orderBy('type')->orderBy('position')->get(),
            'totals' => $totals,
            'monthLabel' => Carbon::createFromFormat('Y-m-d', $this->month.'-01')->translatedFormat('F Y'),
            'canSeeNames' => Gate::allows('finance.contributions.view'),
            'closed' => $this->monthClosed(),
            'cancellable' => $transactions->getCollection()->mapWithKeys(fn ($t) => [$t->id => $this->canCancel($t)]),
            'pending' => $this->cancelId ? FinanceTransaction::find($this->cancelId) : null,
        ]);
    }
}
