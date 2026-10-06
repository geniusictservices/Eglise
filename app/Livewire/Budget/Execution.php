<?php

namespace App\Livewire\Budget;

use App\Models\BudgetOverrun;
use App\Models\Department;
use App\Models\FinanceCategory;
use App\Services\BudgetControl;
use App\Support\FiscalYear;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Le suivi du budget : prévu, réalisé, engagé et disponible, ligne par ligne. */
#[Title('Suivi du budget')]
class Execution extends Component
{
    #[Url(as: 'exercice')]
    public int $year = 0;

    public function mount(): void
    {
        abort_unless(Gate::any(['planning.view', 'budget.arbitrate', 'budget.approve', 'budget.authorize', 'finance.reports']), 403);
        $this->year = $this->year ?: FiscalYear::current(current_organization());
    }

    public function render(BudgetControl $control)
    {
        $organization = current_organization();
        $execution = $control->execution($organization, $this->year);
        [$from, $to] = FiscalYear::bounds($organization, $this->year);
        // Part de l'exercice écoulée, pour juger le rythme des dépenses.
        $elapsed = max(0, min(100, (int) round($from->diffInDays(min(today(), $to)) / max(1, $from->diffInDays($to)) * 100)));
        $current = FiscalYear::current($organization);
        $names = ['departments' => Department::withTrashed()->pluck('name', 'id'), 'categories' => FinanceCategory::pluck('name', 'id')];

        return view('livewire.budget.execution', [
            'x' => $execution,
            'elapsed' => $elapsed,
            'yearLabel' => FiscalYear::label($organization, $this->year),
            'years' => collect(range($current + 1, $current - 2))->mapWithKeys(fn ($y) => [$y => FiscalYear::label($organization, $y)])->all(),
            'overruns' => BudgetOverrun::with(['department', 'category', 'expense', 'decider', 'sourceDepartment', 'sourceCategory'])->where('fiscal_year', $this->year)->latest()->get(),
            'names' => $names,
        ]);
    }
}
