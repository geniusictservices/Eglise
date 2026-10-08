<?php

namespace App\Services;

use App\Models\Budget;
use Illuminate\Support\Collection;

/**
 * L'équilibre du budget. On ne relie pas chaque dépense à une recette : les recettes prévues
 * (dîmes, offrandes, promesses, collectes des projets…) doivent couvrir le total des dépenses
 * prévues ; chaque ligne dit, en pour cent des recettes prévues, ce qu'elle apporte ou consomme.
 *
 * L'argent d'un projet (sa collecte, son solde reporté) ne paie que ce projet : ce qui en
 * dépasse ses dépenses de l'année lui reste réservé. Ce qui manque à un projet est pris sur
 * les recettes ordinaires.
 */
class BudgetSources
{
    /**
     * @return array{expense: float, ordinary_income: float, usable: float, gap: float, surplus: float, reserved: float, projects: Collection}
     */
    public function summary(Budget $budget): array
    {
        $lines = $budget->relationLoaded('lines') ? $budget->lines : $budget->lines()->with(['category', 'project'])->get();
        $lines->loadMissing(['category', 'project']);

        // Les projets : leurs ressources propres, leurs dépenses, ce qu'ils prennent aux recettes ordinaires.
        $projects = $lines->whereNotNull('project_id')->groupBy('project_id')->map(function (Collection $group) {
            $income = (float) $group->where('type', 'income')->where('source', '!=', 'carryover')->sum('amount');
            $carried = (float) $group->where('source', 'carryover')->sum('amount');
            $expense = (float) $group->where('type', 'expense')->sum('amount');
            $own = min($income + $carried, $expense);

            return ['project' => $group->first()->project, 'income' => $income, 'carried' => $carried, 'expense' => $expense,
                'own' => round($own, 2), 'ordinary' => round(max(0, $expense - $income - $carried), 2), 'reserved' => round(max(0, $income + $carried - $expense), 2)];
        })->filter(fn ($p) => $p['project'])->sortBy(fn ($p) => $p['project']->name)->values();

        $ordinaryLines = $lines->where('type', 'income')->whereNull('project_id');
        $ordinaryIncome = round((float) $ordinaryLines->sum('amount'), 2);
        $expense = round((float) $lines->where('type', 'expense')->sum('amount'), 2);
        $usable = round($ordinaryIncome + $projects->sum('own'), 2);

        return [
            'expense' => $expense,
            'ordinary_income' => $ordinaryIncome,
            'usable' => $usable,
            'gap' => round(max(0, $expense - $usable), 2),
            'surplus' => round(max(0, $usable - $expense), 2),
            'reserved' => round((float) $projects->sum('reserved'), 2),
            'projects' => $projects,
        ];
    }
}
