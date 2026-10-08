<?php

namespace App\Services;

use App\Models\Budget;
use Illuminate\Support\Collection;

/**
 * D'où viendra l'argent du budget. On ne relie pas chaque dépense à une recette : on dit,
 * pour le total des dépenses prévues, quelles recettes le couvriront (dîmes, offrandes,
 * promesses, collectes des projets…) et quelle part chacune apporte.
 *
 * L'argent d'un projet (sa collecte, son solde reporté) ne paie que ce projet : ce qui en
 * dépasse ses dépenses de l'année lui reste réservé. Ce qui manque à un projet est pris sur
 * les recettes ordinaires.
 */
class BudgetSources
{
    /**
     * @return array{expense: float, ordinary_income: float, usable: float, gap: float, surplus: float, reserved: float, sources: Collection, projects: Collection}
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

        // Les sources, de la plus grande à la plus petite : chaque catégorie de recettes ordinaires,
        // puis l'argent des projets qui paie leurs propres dépenses.
        $sources = $ordinaryLines->groupBy('category_id')->map(fn (Collection $group) => [
            'label' => $group->first()->category?->name ?? $group->first()->label,
            'amount' => round((float) $group->sum('amount'), 2),
            'lines' => $group->pluck('label')->unique()->values(),
        ])->values();
        foreach ($projects->where('own', '>', 0) as $p) {
            $sources->push(['label' => __('Projet : :p', ['p' => $p['project']->name]), 'amount' => $p['own'],
                'lines' => collect([$p['carried'] > 0 ? __('collecte et solde reporté') : __('collecte du projet')])]);
        }
        $sources = $sources->sortByDesc('amount')->values()
            ->map(fn ($s) => $s + ['share' => $expense > 0 ? (int) round($s['amount'] / $expense * 100) : 0]);

        return [
            'expense' => $expense,
            'ordinary_income' => $ordinaryIncome,
            'usable' => $usable,
            'gap' => round(max(0, $expense - $usable), 2),
            'surplus' => round(max(0, $usable - $expense), 2),
            'reserved' => round((float) $projects->sum('reserved'), 2),
            'sources' => $sources,
            'projects' => $projects,
        ];
    }
}
