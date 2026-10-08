<?php

namespace App\Livewire\Finances;

use App\Models\ExpenseRequest;
use App\Models\FinanceTransaction;
use App\Models\PaymentDeclaration;
use App\Services\Ledger;
use App\Services\Projects;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Tableau des finances : soldes des comptes, mois en cours, dernières opérations. */
#[Title('Finances')]
class Index extends Component
{
    public function mount(): void
    {
        $this->authorize('finance.view');
    }

    public function render(Ledger $ledger, Projects $projects)
    {
        $organization = current_organization();
        $balances = $ledger->balances($organization);
        $totalUsd = $balances->every(fn ($b) => $b['usd'] !== null) ? $balances->reduce(fn ($sum, $r) => $sum->plus($r['usd']), BigDecimal::zero()) : null;
        $month = FinanceTransaction::valid()->whereBetween('occurred_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);

        return view('livewire.finances.index', [
            'balances' => $balances->groupBy(fn ($b) => $b['account']->id),
            'byCurrency' => $balances->groupBy('currency')->map(fn ($rows) => $rows->reduce(fn ($sum, $r) => $sum->plus($r['balance']), BigDecimal::zero())),
            'totalUsd' => $totalUsd,
            // L'argent des projets qui est dans les caisses : il ne sert pas aux dépenses ordinaires.
            'reserved' => $projects->reserved($organization),
            'income' => (clone $month)->where('type', 'income')->sum('usd_amount'),
            'expense' => (clone $month)->where('type', 'expense')->sum('usd_amount'),
            'byCategory' => (clone $month)->where('type', 'income')->with('category')
                ->selectRaw('category_id, sum(usd_amount) as total')->groupBy('category_id')->orderByDesc('total')->get(),
            'recent' => FinanceTransaction::with(['account', 'category', 'member'])->latest('occurred_on')->latest('id')->limit(8)->get(),
            'canSeeNames' => Gate::allows('finance.contributions.view'),
            'hasAccounts' => $balances->isNotEmpty(),
            'pendingDeclarations' => Gate::allows('finance.payments.validate') ? PaymentDeclaration::where('status', 'pending')->count() : 0,
            // Ce qui attend la personne dans le circuit des dépenses.
            'pendingExpenses' => ExpenseRequest::whereIn('status', array_merge(
                Gate::allows('finance.disburse') ? ['submitted', 'approved'] : [],
                Gate::allows('finance.expenses.approve') ? ['checked'] : [],
            ))->count(),
            'overdueAdvances' => ExpenseRequest::where('status', 'disbursed')->where('is_advance', true)->whereDate('justify_by', '<', today())->count(),
        ]);
    }
}
