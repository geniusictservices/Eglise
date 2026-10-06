<?php

namespace App\Livewire\Finances\Expenses;

use App\Models\ExpenseRequest;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Les demandes de dépense, rangées par étape du circuit. */
#[Title('Dépenses')]
class Index extends Component
{
    #[Url(as: 'etape', except: '')]
    public string $step = '';

    public function mount(): void
    {
        abort_unless(Gate::any(['finance.view', 'finance.expenses.request', 'finance.expenses.approve']), 403);
        if ($this->step === '') {
            // On ouvre sur ce qui attend la personne.
            $this->step = match (true) {
                Gate::allows('finance.expenses.approve') && ExpenseRequest::where('status', 'checked')->exists() => 'checked',
                Gate::allows('finance.disburse') && ExpenseRequest::where('status', 'submitted')->exists() => 'submitted',
                default => 'all',
            };
        }
    }

    public function render()
    {
        $seeAll = Gate::any(['finance.view', 'finance.expenses.approve']);
        $base = ExpenseRequest::query()->when(! $seeAll, fn ($q) => $q->where('requested_by', auth()->id()));

        return view('livewire.finances.expenses.index', [
            'counts' => (clone $base)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'overdue' => (clone $base)->where('status', 'disbursed')->where('is_advance', true)->whereDate('justify_by', '<', today())->count(),
            'requests' => (clone $base)->with(['department', 'category', 'requester', 'approvals'])
                ->when($this->step !== 'all', fn ($q) => $q->where('status', $this->step))
                ->latest()->limit(100)->get(),
            'canRequest' => Gate::allows('finance.expenses.request') && ! current_organization()->isReadOnly(),
        ]);
    }
}
