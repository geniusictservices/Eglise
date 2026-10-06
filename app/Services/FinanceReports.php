<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\Department;
use App\Models\FinanceCategory;
use App\Models\FinanceClosing;
use App\Models\FinanceTransaction;
use App\Models\Organization;
use App\Support\FiscalYear;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rapport financier d'une période : soldes de début et de fin par compte et
 * par devise, recettes et dépenses par catégorie, dépenses par département.
 * Les montants restent dans leur devise ; l'équivalent en dollars est celui
 * du taux du jour de chaque opération.
 */
class FinanceReports
{
    public function __construct(private Ledger $ledger) {}

    public function period(Organization $organization, Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        $base = fn () => FinanceTransaction::withoutOrganizationScope()->valid()->where('organization_id', $organization->id)
            ->whereBetween('occurred_on', [$from->toDateString(), $to->toDateString()]);

        // Mouvements par compte, devise et type.
        $moves = $base()->select('cash_account_id', 'currency', 'type', DB::raw('sum(amount) as total'))
            ->groupBy('cash_account_id', 'currency', 'type')->get()
            ->groupBy(fn ($r) => $r->cash_account_id.'|'.$r->currency);

        $accounts = [];
        $currencies = [];
        $list = CashAccount::withoutOrganizationScope()->where('organization_id', $organization->id)->with('currencies')->orderBy('position')->orderBy('name')->get();
        foreach ($list as $account) {
            foreach ($account->currencies as $c) {
                $rows = $moves->get($account->id.'|'.$c->currency, collect())->pluck('total', 'type');
                $sum = fn (array $types) => BigDecimal::of((string) collect($types)->sum(fn ($t) => (float) ($rows[$t] ?? 0)));
                $row = [
                    'account' => $account,
                    'currency' => $c->currency,
                    'opening' => $this->ledger->balance($account, $c->currency, $from->copy()->subDay()),
                    'income' => $sum(['income']),
                    'expense' => $sum(['expense']),
                    'transfers' => $sum(['transfer_in', 'exchange_in'])->minus($sum(['transfer_out', 'exchange_out'])),
                    'closing' => $this->ledger->balance($account, $c->currency, $to),
                ];
                // Un compte fermé et vide n'encombre pas le rapport.
                if (! $account->is_active && $row['opening']->isZero() && $row['closing']->isZero() && $rows->isEmpty()) {
                    continue;
                }
                $accounts[] = $row;
                $total = $currencies[$c->currency] ?? array_fill_keys(['opening', 'income', 'expense', 'transfers', 'closing'], BigDecimal::zero());
                foreach (array_keys($total) as $key) {
                    $total[$key] = $total[$key]->plus($row[$key]);
                }
                $currencies[$c->currency] = $total;
            }
        }
        uksort($currencies, fn ($a, $b) => ($a === config('waumini.base_currency') ? -1 : 0) <=> ($b === config('waumini.base_currency') ? -1 : 0) ?: strcmp($a, $b));

        // Recettes et dépenses par catégorie, dans chaque devise et en dollars.
        $categories = FinanceCategory::withoutOrganizationScope()->where('organization_id', $organization->id)->pluck('name', 'id');
        $byCategory = fn (string $type) => $base()->where('type', $type)
            ->select('category_id', 'currency', DB::raw('sum(amount) as total'), DB::raw('sum(usd_amount) as usd'))
            ->groupBy('category_id', 'currency')->get()->groupBy('category_id')
            ->map(fn ($rows, $id) => [
                'name' => $categories[$id] ?? __('Sans catégorie'),
                'amounts' => $rows->mapWithKeys(fn ($r) => [$r->currency => (float) $r->total])->all(),
                'usd' => round((float) $rows->sum('usd'), 2),
            ])->sortByDesc('usd')->values()->all();

        $departments = Department::withoutGlobalScope('organization')->withTrashed()->where('organization_id', $organization->id)->pluck('name', 'id');
        $byDepartment = $base()->where('type', 'expense')->select('department_id', DB::raw('sum(usd_amount) as usd'))->groupBy('department_id')->get()
            ->map(fn ($r) => ['name' => $departments[$r->department_id] ?? __('Sans département'), 'usd' => round((float) $r->usd, 2)])
            ->sortByDesc('usd')->values()->all();

        $income = $byCategory('income');
        $expense = $byCategory('expense');
        $incomeUsd = round(array_sum(array_column($income, 'usd')), 2);
        $expenseUsd = round(array_sum(array_column($expense, 'usd')), 2);

        // Sur plusieurs mois : la ligne de chaque mois.
        $months = [];
        if ($from->format('Y-m') !== $to->format('Y-m')) {
            $rows = $base()->whereIn('type', ['income', 'expense'])
                ->select(DB::raw("date_format(occurred_on, '%Y-%m') as ym"), 'type', DB::raw('sum(usd_amount) as usd'))
                ->groupBy('ym', 'type')->get()->groupBy('ym');
            // Ni les mois vides d'avant la première opération, ni les mois à venir.
            $last = $to->copy()->min(today());
            for ($m = $from->copy()->startOfMonth(); $m->lte($last); $m->addMonth()) {
                $r = $rows->get($m->format('Y-m'), collect())->pluck('usd', 'type');
                if (! $months && $r->isEmpty()) {
                    continue;
                }
                $months[] = ['month' => $m->copy(), 'income' => round((float) ($r['income'] ?? 0), 2), 'expense' => round((float) ($r['expense'] ?? 0), 2)];
            }
        }

        return [
            'from' => $from,
            'to' => $to,
            'accounts' => $accounts,
            'currencies' => $currencies,
            'income' => $income,
            'expense' => $expense,
            'departments' => $byDepartment,
            'months' => $months,
            'totals' => ['income' => $incomeUsd, 'expense' => $expenseUsd, 'result' => round($incomeUsd - $expenseUsd, 2)],
            'count' => $base()->count(),
        ];
    }

    /** Un mois civil (année, 1 à 12) ou l'exercice qui commence cette année-là (mois 0). */
    public function bounds(Organization $organization, int $year, int $month): array
    {
        if (! $month) {
            return FiscalYear::bounds($organization, $year);
        }
        $from = Carbon::create($year, $month, 1);

        return [$from, $from->copy()->endOfMonth()->startOfDay()];
    }

    /**
     * La période choisie dans les écrans : un exercice (mois 0) ou un de ses mois.
     *
     * @return array{0: Carbon, 1: Carbon, 2: ?FinanceClosing, 3: string} début, fin, clôture, intitulé
     */
    public function selection(Organization $organization, int $fiscalYear, int $month): array
    {
        $year = $month ? FiscalYear::calendarYear($organization, $fiscalYear, $month) : $fiscalYear;
        [$from, $to] = $this->bounds($organization, $year, $month);

        return [$from, $to, $this->closing($organization, $year, $month),
            $month ? ucfirst($from->translatedFormat('F Y')) : __('Exercice :y', ['y' => FiscalYear::label($organization, $fiscalYear)])];
    }

    public function closing(Organization $organization, int $year, int $month): ?FinanceClosing
    {
        return FinanceClosing::withoutOrganizationScope()->where('organization_id', $organization->id)->where('year', $year)->where('month', $month)->first();
    }
}
