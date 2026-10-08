<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Services\BudgetSources;
use App\Support\FiscalYear;
use Illuminate\Support\Facades\Gate;

/** Le budget adopté, prêt à imprimer pour le conseil ou l'assemblée. */
class BudgetPrintController extends Controller
{
    public function __invoke(Budget $budget, BudgetSources $sources)
    {
        abort_unless(Gate::any(['planning.view', 'budget.arbitrate', 'budget.approve']), 403);
        $budget->load(['lines.department', 'lines.category', 'submitter', 'approver', 'organization']);
        $organization = $budget->organization;

        return view('budget.print', [
            'b' => $budget,
            'summary' => $sources->summary($budget),
            'organization' => $organization,
            'identity' => $organization->documentIdentity(),
            'yearLabel' => FiscalYear::label($organization, $budget->fiscal_year),
            'bounds' => FiscalYear::bounds($organization, $budget->fiscal_year),
            'groups' => collect(['income', 'expense'])->mapWithKeys(fn ($type) => [$type => $budget->lines->where('type', $type)
                ->groupBy(fn ($l) => $l->department?->name ?? __('Recettes générales'))->sortKeys()])->all(),
        ]);
    }
}
