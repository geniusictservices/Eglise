<?php

namespace App\Livewire\Finances;

use App\Models\FinanceTransaction;
use App\Models\PaymentDeclaration;
use App\Services\Ledger;
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

    public function render(Ledger $ledger)
    {
        $organization = current_organization();
        $balances = $ledger->balances($organization);
        $month = FinanceTransaction::valid()->whereBetween('occurred_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);

        return view('livewire.finances.index', [
            'balances' => $balances->groupBy(fn ($b) => $b['account']->id),
            'byCurrency' => $balances->groupBy('currency')->map(fn ($rows) => $rows->reduce(fn ($sum, $r) => $sum->plus($r['balance']), BigDecimal::zero())),
            'totalUsd' => $balances->every(fn ($b) => $b['usd'] !== null) ? $balances->reduce(fn ($sum, $r) => $sum->plus($r['usd']), BigDecimal::zero()) : null,
            'income' => (clone $month)->where('type', 'income')->sum('usd_amount'),
            'expense' => (clone $month)->where('type', 'expense')->sum('usd_amount'),
            'byCategory' => (clone $month)->where('type', 'income')->with('category')
                ->selectRaw('category_id, sum(usd_amount) as total')->groupBy('category_id')->orderByDesc('total')->get(),
            'recent' => FinanceTransaction::with(['account', 'category', 'member'])->latest('occurred_on')->latest('id')->limit(8)->get(),
            'canSeeNames' => Gate::allows('finance.contributions.view'),
            'hasAccounts' => $balances->isNotEmpty(),
            'pendingDeclarations' => Gate::allows('finance.payments.validate') ? PaymentDeclaration::where('status', 'pending')->count() : 0,
        ]);
    }
}
