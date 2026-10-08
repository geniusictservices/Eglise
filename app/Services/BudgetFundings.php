<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\BudgetFunding;
use App\Models\BudgetLine;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Le financement du budget : chaque dépense prévue dit quelles recettes prévues la paient
 * (dîmes, offrandes, promesses, collecte d'un projet, solde reporté…). Une recette ne finance
 * pas plus que ce qu'elle prévoit ; l'argent d'un projet ne finance que ce projet.
 * Un budget dont une dépense n'est pas entièrement financée ne peut pas être présenté.
 */
class BudgetFundings
{
    /**
     * Où en est le financement, ligne par ligne.
     *
     * @return array{expense: array<int, array{funded: float, missing: float, sources: Collection}>, income: array<int, array{allocated: float, free: float}>, unfunded: int, missing: float}
     */
    public function state(Budget $budget): array
    {
        $budget->loadMissing(['lines', 'fundings']);
        $byExpense = $budget->fundings->groupBy('expense_line_id');
        $byIncome = $budget->fundings->groupBy('income_line_id');
        $lines = $budget->lines->keyBy('id');

        $state = ['expense' => [], 'income' => [], 'unfunded' => 0, 'missing' => 0.0];
        foreach ($budget->lines as $line) {
            if ($line->type === 'expense') {
                $sources = ($byExpense[$line->id] ?? collect())->map(fn (BudgetFunding $f) => ['line' => $lines[$f->income_line_id] ?? null, 'amount' => (float) $f->amount])
                    ->filter(fn ($s) => $s['line'])->values();
                $funded = round((float) $sources->sum('amount'), 2);
                $missing = max(0, round((float) $line->amount - $funded, 2));
                $state['expense'][$line->id] = ['funded' => $funded, 'missing' => $missing, 'sources' => $sources];
                if ($missing > 0.004) {
                    $state['unfunded']++;
                    $state['missing'] += $missing;
                }
            } else {
                $allocated = round((float) ($byIncome[$line->id] ?? collect())->sum('amount'), 2);
                $state['income'][$line->id] = ['allocated' => $allocated, 'free' => round((float) $line->amount - $allocated, 2)];
            }
        }
        $state['missing'] = round($state['missing'], 2);

        return $state;
    }

    /** Les dépenses prévues qui ne sont pas entièrement financées. */
    public function unfunded(Budget $budget): Collection
    {
        $state = $this->state($budget);

        return $budget->lines->where('type', 'expense')->filter(fn (BudgetLine $l) => $state['expense'][$l->id]['missing'] > 0.004)->values();
    }

    /** Une recette peut-elle financer cette dépense ? L'argent d'un projet ne va qu'à ce projet. */
    public function canFund(BudgetLine $income, BudgetLine $expense): bool
    {
        return $income->type === 'income' && $expense->type === 'expense' && $income->budget_id === $expense->budget_id
            && (! $income->project_id || $income->project_id === $expense->project_id);
    }

    /**
     * La finance dit quelles recettes financent une dépense, et combien.
     *
     * @param  array<int|string, mixed>  $amounts  ligne de recette => montant en dollars
     */
    public function set(BudgetLine $expense, array $amounts): void
    {
        $budget = $expense->budget()->firstOrFail();
        $this->expectDraft($budget);
        if ($expense->type !== 'expense') {
            throw new InvalidArgumentException(__('Seule une dépense prévue se finance.'));
        }

        $state = $this->state($budget);
        $incomes = $budget->lines->where('type', 'income')->keyBy('id');
        $current = $budget->fundings->where('expense_line_id', $expense->id)->pluck('amount', 'income_line_id');
        $wanted = [];
        foreach ($amounts as $id => $amount) {
            $amount = round(max(0, (float) $amount), 2);
            if ($amount < 0.005) {
                continue;
            }
            $income = $incomes[(int) $id] ?? throw new InvalidArgumentException(__('Cette recette n’est pas dans ce budget.'));
            if (! $this->canFund($income, $expense)) {
                throw new InvalidArgumentException(__('« :r » est l’argent d’un projet : elle ne finance que ce projet.', ['r' => $income->label]));
            }
            $free = $state['income'][$income->id]['free'] + (float) ($current[$income->id] ?? 0);
            if ($amount > $free + 0.004) {
                throw new InvalidArgumentException(__('« :r » n’a plus que :m de libre.', ['r' => $income->label, 'm' => Money::format(max(0, $free), 'USD')]));
            }
            $wanted[$income->id] = $amount;
        }
        if (array_sum($wanted) > (float) $expense->amount + 0.004) {
            throw new InvalidArgumentException(__('Le financement dépasse la dépense (:m).', ['m' => Money::format($expense->amount, 'USD')]));
        }

        DB::transaction(function () use ($budget, $expense, $wanted) {
            BudgetFunding::where('expense_line_id', $expense->id)->delete();
            foreach ($wanted as $income => $amount) {
                BudgetFunding::create(['budget_id' => $budget->id, 'expense_line_id' => $expense->id, 'income_line_id' => $income, 'amount' => $amount]);
            }
        });
        $budget->unsetRelation('fundings');
    }

    /**
     * Répartition automatique : complète ce qui manque à chaque dépense, sans toucher à ce qui
     * est déjà réparti. Une dépense de projet prend d'abord l'argent de son projet (le solde
     * reporté, puis la collecte) ; ensuite, chaque dépense prend les recettes de son département,
     * puis les recettes générales, puis les autres.
     *
     * @return int le nombre de dépenses complétées
     */
    public function auto(Budget $budget): int
    {
        $this->expectDraft($budget);
        $budget->unsetRelation('lines')->unsetRelation('fundings');
        $state = $this->state($budget);
        $free = collect($state['income'])->map(fn ($s) => $s['free'])->all();
        $incomes = $budget->lines->where('type', 'income')->filter(fn (BudgetLine $l) => (float) $l->amount > 0);
        $count = 0;

        $expenses = $budget->lines->where('type', 'expense')->sortBy(fn (BudgetLine $l) => [$l->project_id ? 0 : 1, $l->id]);
        DB::transaction(function () use ($budget, $expenses, $incomes, $state, &$free, &$count) {
            foreach ($expenses as $expense) {
                $missing = $state['expense'][$expense->id]['missing'];
                if ($missing < 0.005) {
                    continue;
                }
                $candidates = $incomes->filter(fn (BudgetLine $i) => $this->canFund($i, $expense))
                    ->sortBy(fn (BudgetLine $i) => [match (true) {
                        $expense->project_id && $i->project_id === $expense->project_id => $i->isCarryover() ? 0 : 1,
                        $i->department_id && $i->department_id === $expense->department_id => 2,
                        ! $i->department_id => 3,
                        default => 4,
                    }, $i->id]);
                foreach ($candidates as $income) {
                    $take = round(min($missing, $free[$income->id] ?? 0), 2);
                    if ($take < 0.005) {
                        continue;
                    }
                    $funding = BudgetFunding::firstOrNew(['expense_line_id' => $expense->id, 'income_line_id' => $income->id], ['budget_id' => $budget->id, 'amount' => 0]);
                    $funding->amount = round((float) $funding->amount + $take, 2);
                    $funding->save();
                    $free[$income->id] -= $take;
                    $missing = round($missing - $take, 2);
                    if ($missing < 0.005) {
                        $count++;
                        break;
                    }
                }
            }
        });
        $budget->unsetRelation('fundings');

        return $count;
    }

    /**
     * Après un changement de montant : une dépense n'est pas financée au-delà de son montant,
     * une recette ne finance pas au-delà du sien. On retire d'abord les dernières parts.
     */
    public function trim(BudgetLine $line): void
    {
        $column = $line->type === 'expense' ? 'expense_line_id' : 'income_line_id';
        $parts = BudgetFunding::where($column, $line->id)->orderByDesc('id')->get();
        $excess = round((float) $parts->sum('amount') - (float) $line->amount, 2);
        foreach ($parts as $part) {
            if ($excess < 0.005) {
                break;
            }
            $cut = min($excess, (float) $part->amount);
            $left = round((float) $part->amount - $cut, 2);
            $left < 0.005 ? $part->delete() : $part->update(['amount' => $left]);
            $excess = round($excess - $cut, 2);
        }
        $line->budget?->unsetRelation('fundings');
    }

    /**
     * Recopie le financement dans une nouvelle version du budget.
     *
     * @param  array<int, int>  $map  ancienne ligne => nouvelle ligne
     */
    public function copy(Budget $from, Budget $to, array $map): void
    {
        foreach ($from->fundings()->get() as $f) {
            if (isset($map[$f->expense_line_id], $map[$f->income_line_id])) {
                BudgetFunding::create(['budget_id' => $to->id, 'expense_line_id' => $map[$f->expense_line_id], 'income_line_id' => $map[$f->income_line_id], 'amount' => $f->amount]);
            }
        }
    }

    private function expectDraft(Budget $budget): void
    {
        if (! $budget->isEditable()) {
            throw new InvalidArgumentException(__('Cette version n’est plus modifiable.'));
        }
    }
}
