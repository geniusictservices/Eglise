<?php

namespace App\Livewire\Budget;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\Budget;
use App\Models\BudgetOverrun;
use App\Models\BudgetProposal;
use App\Models\Department;
use App\Services\Budgets;
use App\Support\DepartmentScope;
use App\Support\FiscalYear;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Le budget d'un exercice : propositions des départements, versions, adoption. */
#[Title('Budget')]
class Index extends Component
{
    use WritesInOrganization;

    #[Url(as: 'exercice')]
    public int $year = 0;

    public string $reason = '';

    public function mount(): void
    {
        abort_unless(Gate::any(['planning.view', 'budget.propose', 'budget.arbitrate', 'budget.approve']), 403);
        $this->year = $this->year ?: FiscalYear::current($this->organization());
    }

    public function prepare(Budgets $budgets)
    {
        $this->authorizeWrite('budget.arbitrate');
        try {
            $budget = $budgets->prepare($this->organization(), $this->year);
        } catch (InvalidArgumentException $e) {
            $this->notify($e->getMessage(), 'error');

            return null;
        }

        return $this->redirectRoute('budget.version', $budget);
    }

    public function revise(Budgets $budgets)
    {
        $this->authorizeWrite('budget.arbitrate');
        $this->validate(['reason' => 'required|string|min:10|max:255'], attributes: ['reason' => __('motif')]);
        try {
            $budget = $budgets->revise($this->organization(), $this->year, trim($this->reason));
        } catch (InvalidArgumentException $e) {
            $this->addError('reason', $e->getMessage());

            return null;
        }

        return $this->redirectRoute('budget.version', $budget);
    }

    public function render(Budgets $budgets)
    {
        $organization = $this->organization();
        $adopted = $budgets->adopted($organization, $this->year)?->load(['lines', 'approver']);
        $pending = $budgets->pending($organization, $this->year);
        // La finance, le pasteur et ceux qui gèrent le plan voient toutes les propositions.
        $scope = Gate::any(['budget.arbitrate', 'budget.approve', 'planning.manage']) ? null : DepartmentScope::ids(auth()->user(), $organization);
        $proposals = BudgetProposal::with('lines')->where('fiscal_year', $this->year)->get()->keyBy('department_id');
        $current = FiscalYear::current($organization);

        return view('livewire.budget.index', [
            'yearLabel' => FiscalYear::label($organization, $this->year),
            'years' => collect(range($current + 1, $current - 2))->mapWithKeys(fn ($y) => [$y => FiscalYear::label($organization, $y)])->all(),
            'adopted' => $adopted,
            'pending' => $pending,
            'byDepartment' => $adopted ? $budgets->byDepartment($adopted) : [],
            'departments' => Department::where('is_active', true)->orderByDesc('is_system')->orderBy('name')->get(),
            'proposals' => $proposals,
            'mine' => $scope,
            'versions' => Budget::with('approver')->where('fiscal_year', $this->year)->orderByDesc('version')->get(),
            'canArbitrate' => Gate::allows('budget.arbitrate') && ! $organization->isReadOnly(),
            'canPropose' => Gate::any(['budget.propose', 'budget.arbitrate']) && ! $organization->isReadOnly(),
            'linked' => (bool) DepartmentScope::member(auth()->user(), $organization),
            'pendingOverruns' => Gate::allows('budget.authorize') ? BudgetOverrun::where('status', 'pending')->with(['expense', 'payRun'])->get() : collect(),
        ]);
    }
}
