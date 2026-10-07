<?php

namespace App\Livewire\Finances\Expenses;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\Department;
use App\Models\ExpenseAttachment;
use App\Models\FinanceCategory;
use App\Models\Member;
use App\Services\BudgetControl;
use App\Services\Budgets;
use App\Services\Expenses;
use App\Services\Ledger;
use App\Support\FiscalYear;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Demander une dépense ou une avance. */
#[Title('Demander une dépense')]
class Form extends Component
{
    use WithFileUploads, WritesInOrganization;

    /** « budget » : une ligne du budget adopté ; « imprevu » : une dépense qui n'était pas prévue. */
    public string $budgetMode = 'budget';

    public string $budgetLineId = '';

    public string $unforeseenReason = '';

    public string $departmentId = '';

    public string $categoryId = '';

    public string $title = '';

    public string $description = '';

    public string $amount = '';

    public string $currency = 'USD';

    public bool $isAdvance = false;

    public ?int $beneficiaryId = null;

    public string $beneficiarySearch = '';

    public string $beneficiaryName = '';

    public string $neededOn = '';

    public array $files = [];

    public function mount(): void
    {
        $this->authorize('finance.expenses.request');
        $this->departmentId = (string) Department::where('is_system', true)->value('id');
        $this->categoryId = (string) FinanceCategory::where('type', 'expense')->where('is_active', true)->orderBy('position')->value('id');
    }

    public function chooseBeneficiary(int $id): void
    {
        $this->beneficiaryId = Member::findOrFail($id)->id;
        $this->beneficiarySearch = '';
    }

    public function save(Expenses $expenses, Ledger $ledger)
    {
        $this->authorizeWrite('finance.expenses.request');
        $fromBudget = $this->adoptedBudget() && $this->budgetMode === 'budget';
        $this->validate([
            'budgetLineId' => [Rule::requiredIf($fromBudget)],
            'unforeseenReason' => [Rule::requiredIf($this->adoptedBudget() && $this->budgetMode === 'imprevu'), 'nullable', 'string', 'min:5', 'max:255'],
            'departmentId' => ['required', Rule::exists('departments', 'id')->where('organization_id', $this->organization()->id)],
            'categoryId' => ['required', Rule::exists('finance_categories', 'id')->where('organization_id', $this->organization()->id)->where('type', 'expense')],
            'title' => 'required|string|max:150',
            'description' => 'nullable|string|max:2000',
            'amount' => 'required|numeric|gt:0',
            'currency' => ['required', Rule::in($ledger->currencies($this->organization()))],
            'beneficiaryId' => [Rule::requiredIf($this->isAdvance && trim($this->beneficiaryName) === '')],
            'beneficiaryName' => 'nullable|string|max:150',
            'neededOn' => 'nullable|date',
            'files.*' => 'file|max:8192|mimes:jpg,jpeg,png,webp,pdf',
        ], ['beneficiaryId.required' => __('Une avance est remise à quelqu’un : choisissez la personne qui justifiera.'),
            'budgetLineId.required' => __('Choisissez la ligne du budget, ou cochez « Imprévu » si la dépense n’était pas prévue.')],
            ['unforeseenReason' => __('raison'), 'title' => __('objet'), 'amount' => __('montant'), 'departmentId' => __('département'), 'files.*' => __('pièce jointe')]);

        try {
            $request = $expenses->submit($this->organization(), [
                'department_id' => (int) $this->departmentId, 'category_id' => (int) $this->categoryId,
                'budget_line_id' => $fromBudget ? (int) $this->budgetLineId : null,
                'is_unforeseen' => $this->adoptedBudget() !== null && $this->budgetMode === 'imprevu', 'unforeseen_reason' => trim($this->unforeseenReason) ?: null,
                'title' => trim($this->title), 'description' => trim($this->description) ?: null,
                'amount' => $this->amount, 'currency' => $this->currency, 'is_advance' => $this->isAdvance,
                'beneficiary_member_id' => $this->beneficiaryId, 'beneficiary_name' => $this->beneficiaryId ? null : (trim($this->beneficiaryName) ?: null),
                'needed_on' => $this->neededOn ?: null,
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->addError($this->isAdvance ? 'beneficiaryId' : 'submit', $e->getMessage());

            return null;
        }

        foreach ($this->files as $file) {
            ExpenseAttachment::create(['expense_request_id' => $request->id, 'kind' => 'quote', 'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'path' => $file->storeAs('expenses/'.$request->organization_id, $request->id.'-'.Str::random(8).'.'.$file->extension(), 'local'), 'uploaded_by' => auth()->id()]);
        }

        session()->flash('status', __('Demande :n envoyée : elle passe au contrôle de la finance.', ['n' => $request->number]));

        return $this->redirectRoute('finances.expenses.show', $request);
    }

    /** Le budget adopté de l'exercice de la dépense, s'il y en a un. */
    private function adoptedBudget(): ?Budget
    {
        return app(Budgets::class)->adopted($this->organization(), FiscalYear::of($this->organization(), $this->neededOn ?: today()));
    }

    /** Une ligne du budget choisie : son département et sa catégorie deviennent ceux de la dépense. */
    public function updatedBudgetLineId(): void
    {
        $line = $this->budgetLineId ? BudgetLine::where('type', 'expense')->find((int) $this->budgetLineId) : null;
        if ($line) {
            $this->departmentId = (string) $line->department_id;
            $this->categoryId = (string) $line->category_id;
        }
    }

    /**
     * Les lignes de dépenses du budget adopté, avec ce qui reste sur chacune (par département et catégorie).
     *
     * @return Collection<string, Collection<int, array{line: BudgetLine, available: float}>>
     */
    private function budgetChoices(): Collection
    {
        $budget = $this->adoptedBudget();
        if (! $budget) {
            return collect();
        }
        $execution = app(BudgetControl::class)->execution($this->organization(), $budget->fiscal_year);

        return $budget->lines()->with(['department', 'category'])->where('type', 'expense')->get()
            ->sortBy(fn ($l) => [$l->department?->name, $l->label])
            ->map(fn (BudgetLine $l) => ['line' => $l, 'available' => (float) ($execution['expense'][BudgetControl::key($l->department_id, (int) $l->category_id)]['available'] ?? 0)])
            ->groupBy(fn ($c) => $c['line']->department?->name ?? __('Sans département'));
    }

    /** Le disponible de la ligne du budget choisie (null : pas de budget adopté). */
    private function budgetLine(): ?array
    {
        if (! $this->departmentId || ! $this->categoryId) {
            return null;
        }
        $control = app(BudgetControl::class);
        $year = FiscalYear::of($this->organization(), $this->neededOn ?: today());
        $execution = $control->execution($this->organization(), $year);

        return $execution['budget'] ? ($execution['expense'][BudgetControl::key((int) $this->departmentId, (int) $this->categoryId)] ?? ['available' => null]) : null;
    }

    public function render(Ledger $ledger)
    {
        return view('livewire.finances.expenses.form', [
            'departments' => Department::where('is_active', true)->orderByDesc('is_system')->orderBy('name')->get(),
            'categories' => FinanceCategory::where('type', 'expense')->where('is_active', true)->orderBy('position')->get(),
            'currencies' => $ledger->currencies($this->organization()),
            'beneficiary' => $this->beneficiaryId ? Member::find($this->beneficiaryId) : null,
            'candidates' => ! $this->beneficiaryId && trim($this->beneficiarySearch) !== '' ? Member::search($this->beneficiarySearch)->orderBy('last_name')->limit(5)->get() : collect(),
            'settings' => app(Expenses::class)->settings($this->organization()),
            'budgetLine' => $this->budgetLine(),
            'adopted' => $this->adoptedBudget(),
            'choices' => $this->budgetChoices(),
        ]);
    }
}
