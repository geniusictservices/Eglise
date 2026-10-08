<?php

namespace App\Livewire\Budget;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\Department;
use App\Models\FinanceCategory;
use App\Models\Project;
use App\Services\Budgets;
use App\Services\BudgetSources;
use App\Services\Projects;
use App\Support\FiscalYear;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Une version du budget : la finance l'arbitre et la présente, le pasteur l'approuve. */
class Version extends Component
{
    use WritesInOrganization;

    public Budget $budget;

    /** Montants arrêtés, ligne par ligne, pendant l'arbitrage. */
    public array $amounts = [];

    /** L'onglet affiché : les dépenses prévues, les recettes prévues (jamais mélangées), ou les projets de l'exercice. */
    #[Url(as: 'onglet', except: 'depenses')]
    public string $tab = 'depenses';

    public ?int $lineId = null;

    public array $line = [];

    public string $note = '';

    public function mount(Budget $budget): void
    {
        abort_unless(Gate::any(['planning.view', 'budget.arbitrate', 'budget.approve']), 403);
        $this->budget = $budget;
        $this->fillAmounts();
    }

    private function fillAmounts(): void
    {
        $this->amounts = $this->budget->lines()->pluck('amount', 'id')->map(fn ($a) => (string) (float) $a)->all();
    }

    private function canArbitrate(): bool
    {
        return $this->budget->isEditable() && Gate::allows('budget.arbitrate') && ! $this->organization()->isReadOnly();
    }

    public function updatedAmounts($value, $key): void
    {
        abort_unless($this->canArbitrate(), 403);
        $this->validate(["amounts.$key" => 'required|numeric|min:0'], attributes: ["amounts.$key" => __('montant')]);
        $this->budget->lines()->findOrFail((int) $key)->update(['amount' => $value]);
    }

    /** Une ligne s'ajoute dans l'onglet ouvert : une dépense prévue ou une recette prévue. */
    public function editLine(?int $id = null): void
    {
        abort_unless($this->canArbitrate(), 403);
        $l = $id ? $this->budget->lines()->findOrFail($id) : null;
        $this->lineId = $l?->id;
        $type = $l->type ?? ($this->tab === 'recettes' ? 'income' : 'expense');
        $this->line = [
            'type' => $type,
            'department_id' => (string) ($l->department_id ?? ($type === 'expense' ? Department::where('is_system', true)->value('id') : '')),
            'category_id' => (string) ($l->category_id ?? FinanceCategory::where('type', $type)->where('is_active', true)->orderBy('position')->value('id')),
            'label' => $l->label ?? '',
            'amount' => $l ? (string) (float) $l->amount : '',
            'note' => $l->note ?? '',
            'project_id' => (string) ($l->project_id ?? ''),
        ];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'line');
    }

    public function saveLine(): void
    {
        abort_unless($this->canArbitrate(), 403);
        $organization = $this->organization()->id;
        $data = $this->validate([
            'line.type' => 'required|in:income,expense',
            'line.department_id' => [Rule::requiredIf(($this->line['type'] ?? '') === 'expense'), 'nullable', Rule::exists('departments', 'id')->where('organization_id', $organization)],
            'line.category_id' => ['required', Rule::exists('finance_categories', 'id')->where('organization_id', $organization)->where('type', $this->line['type'] ?? '')],
            'line.label' => 'required|string|max:150',
            'line.amount' => 'required|numeric|min:0',
            'line.note' => 'nullable|string|max:255',
            'line.project_id' => ['nullable', Rule::exists('projects', 'id')->where('organization_id', $organization)],
        ], attributes: ['line.label' => __('objet'), 'line.amount' => __('montant'), 'line.department_id' => __('département')])['line'];

        $values = ['type' => $data['type'], 'department_id' => $data['department_id'] ?: null, 'category_id' => (int) $data['category_id'],
            'label' => trim($data['label']), 'amount' => $data['amount'], 'note' => trim((string) $data['note']) ?: null, 'project_id' => ($data['project_id'] ?? null) ?: null];
        $this->lineId ? $this->budget->lines()->findOrFail($this->lineId)->update($values) : BudgetLine::create($values + ['budget_id' => $this->budget->id]);

        $this->fillAmounts();
        $this->dispatch('close-modal', name: 'line');
        $this->notify(__('Ligne enregistrée.'));
    }

    public function deleteLine(int $id): void
    {
        abort_unless($this->canArbitrate(), 403);
        $this->budget->lines()->findOrFail($id)->delete();
        $this->fillAmounts();
    }

    public function importProjects(Budgets $budgets): void
    {
        abort_unless($this->canArbitrate(), 403);
        $count = $budgets->importProjects($this->budget);
        $this->fillAmounts();
        $this->notify($count ? trans_choice(':count ligne reprise des projets de l’exercice.|:count lignes reprises des projets de l’exercice.', $count) : __('Aucun projet n’a de tranche prévue pour cet exercice.'));
    }

    public function importProposals(Budgets $budgets): void
    {
        abort_unless($this->canArbitrate(), 403);
        $count = $budgets->importProposals($this->budget);
        $this->fillAmounts();
        $this->notify($count ? trans_choice(':count ligne reprise des propositions.|:count lignes reprises des propositions.', $count) : __('Aucune nouvelle proposition à reprendre.'));
    }

    public function importPayroll(Budgets $budgets): void
    {
        abort_unless($this->canArbitrate(), 403);
        try {
            $count = $budgets->importPayroll($this->budget);
        } catch (InvalidArgumentException $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        }
        $this->fillAmounts();
        $this->notify($count ? trans_choice(':count ligne de salaires reprise de la paie.|:count lignes de salaires reprises de la paie.', $count) : __('Personne n’est payé par la paie pour le moment.'));
    }

    public function submit(Budgets $budgets): void
    {
        $this->authorizeWrite('budget.arbitrate');
        $this->run(fn () => $budgets->submit($this->budget), __('Budget présenté au pasteur pour approbation.'));
    }

    public function approve(Budgets $budgets): void
    {
        $this->authorizeWrite('budget.approve');
        $this->validate(['note' => 'nullable|string|max:255']);
        $this->run(fn () => $budgets->approve($this->budget, auth()->user(), trim($this->note) ?: null), __('Budget approuvé : il est adopté.'));
    }

    public function sendBack(Budgets $budgets): void
    {
        $this->authorizeWrite('budget.approve');
        $this->validate(['note' => 'required|string|min:5|max:255'], attributes: ['note' => __('remarque')]);
        $this->run(fn () => $budgets->returnBudget($this->budget, trim($this->note)), __('Budget renvoyé à la finance.'));
        $this->dispatch('close-modal', name: 'send-back');
    }

    public function discard(Budgets $budgets)
    {
        $this->authorizeWrite('budget.arbitrate');
        $year = $this->budget->fiscal_year;
        $budgets->discard($this->budget);

        return $this->redirectRoute('budget.index', ['exercice' => $year]);
    }

    private function run(callable $action, string $message): void
    {
        $this->resetErrorBag('note');
        try {
            $action();
        } catch (InvalidArgumentException $e) {
            $this->addError('note', $e->getMessage());

            return;
        }
        $this->note = '';
        $this->notify($message);
    }

    public function render(Budgets $budgets, BudgetSources $sources, Projects $projects)
    {
        $this->budget->refresh()->load(['lines.department', 'lines.category', 'lines.project', 'preparer', 'submitter', 'approver']);
        $organization = $this->organization();
        $lines = $this->budget->lines;
        $summary = $sources->summary($this->budget);

        // Les lignes de l'onglet ouvert, département par département.
        $type = $this->tab === 'recettes' ? 'income' : 'expense';

        return view('livewire.budget.version', [
            'type' => $type,
            'summary' => $summary,
            'missingProjects' => $this->tab === 'projets' ? $projects->forYear($this->budget->fiscal_year)
                ->filter(fn ($p) => $p->years->contains('fiscal_year', $this->budget->fiscal_year) && ! $lines->contains('project_id', $p->id))->values() : collect(),
            'openProjects' => Project::whereIn('status', ['planned', 'ongoing'])->orderBy('name')->get(['id', 'name']),
            // Dans chaque groupe, les lignes de la plus grande à la plus petite.
            'groups' => $lines->where('type', $type)->groupBy(fn ($l) => $l->department?->name ?? __('Recettes générales'))->sortKeys()
                ->map(fn ($group) => $group->sortByDesc(fn ($l) => (float) $l->amount)->values()),
            'yearLabel' => FiscalYear::label($organization, $this->budget->fiscal_year),
            'canArbitrate' => $this->canArbitrate(),
            'canSubmit' => $this->canArbitrate() && $lines->isNotEmpty(),
            'canApprove' => $this->budget->status === 'submitted' && Gate::allows('budget.approve') && ! $organization->isReadOnly(),
            'ownSubmission' => $this->budget->submitted_by === auth()->id(),
            'departments' => Department::where('is_active', true)->orderByDesc('is_system')->orderBy('name')->get(),
            'categories' => FinanceCategory::where('type', $this->line['type'] ?? 'expense')->where('is_active', true)->orderBy('position')->get(),
            'adopted' => $this->budget->status !== 'adopted' ? $budgets->adopted($organization, $this->budget->fiscal_year)?->load('lines') : null,
        ])->title(__('Budget :y, version :v', ['y' => FiscalYear::label($organization, $this->budget->fiscal_year), 'v' => $this->budget->version]));
    }
}
