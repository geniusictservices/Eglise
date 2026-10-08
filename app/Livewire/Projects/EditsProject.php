<?php

namespace App\Livewire\Projects;

use App\Models\Member;
use App\Models\Project;
use App\Services\Ledger;
use App\Services\Projects;
use App\Support\FiscalYear;
use Illuminate\Validation\Rule;

/** Le formulaire d'un projet, commun à la liste (créer) et à la fiche (modifier). */
trait EditsProject
{
    public ?int $projectId = null;

    public array $project = [];

    /** Les tranches annuelles : exercice, collecte prévue, dépenses prévues (en dollars). */
    public array $tranches = [];

    public string $responsibleSearch = '';

    public function editProject(?int $id = null): void
    {
        $this->authorizeWrite('planning.manage');
        $p = $id ? Project::with('years')->findOrFail($id) : null;
        $year = FiscalYear::current($this->organization());
        $this->projectId = $p?->id;
        $this->project = [
            'name' => $p->name ?? '', 'kind' => $p->kind ?? 'project', 'description' => (string) ($p->description ?? ''), 'theme' => (string) ($p->theme ?? ''),
            'department_id' => (string) ($p->department_id ?? ''), 'responsible_member_id' => $p?->responsible_member_id, 'responsible_name' => (string) ($p->responsible_name ?? ''),
            'goal_amount' => $p?->goal_amount !== null ? (string) (float) $p->goal_amount : '', 'goal_currency' => $p->goal_currency ?? 'USD',
            'starts_on' => $p?->starts_on?->toDateString() ?? '', 'ends_on' => $p?->ends_on?->toDateString() ?? '',
            'cash_account_id' => (string) ($p->cash_account_id ?? ''), 'status' => $p->status ?? 'planned',
        ];
        $this->tranches = $p && $p->years->isNotEmpty()
            ? $p->years->map(fn ($y) => ['fiscal_year' => (string) $y->fiscal_year, 'income_planned' => (string) (float) $y->income_planned, 'expense_planned' => (string) (float) $y->expense_planned, 'note' => (string) $y->note])->all()
            : [['fiscal_year' => (string) $year, 'income_planned' => '', 'expense_planned' => '', 'note' => '']];
        $this->responsibleSearch = '';
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'project');
    }

    public function addTranche(): void
    {
        $last = (int) (collect($this->tranches)->max('fiscal_year') ?: FiscalYear::current($this->organization()) - 1);
        $this->tranches[] = ['fiscal_year' => (string) ($last + 1), 'income_planned' => '', 'expense_planned' => '', 'note' => ''];
    }

    public function removeTranche(int $index): void
    {
        unset($this->tranches[$index]);
        $this->tranches = array_values($this->tranches);
    }

    public function chooseResponsible(int $id): void
    {
        $this->project['responsible_member_id'] = Member::findOrFail($id)->id;
        $this->project['responsible_name'] = '';
        $this->responsibleSearch = '';
    }

    public function saveProject(Projects $projects, Ledger $ledger)
    {
        $this->authorizeWrite('planning.manage');
        $organization = $this->organization()->id;
        $this->validate([
            'project.name' => 'required|string|max:150',
            'project.kind' => ['required', Rule::in(array_keys(Project::KINDS))],
            'project.description' => 'nullable|string|max:3000',
            'project.theme' => 'nullable|string|max:150',
            'project.department_id' => ['nullable', Rule::exists('departments', 'id')->where('organization_id', $organization)],
            'project.responsible_member_id' => ['nullable', Rule::exists('members', 'id')->where('organization_id', $organization)],
            'project.responsible_name' => 'nullable|string|max:150',
            'project.goal_amount' => 'nullable|numeric|min:0',
            'project.goal_currency' => ['required', Rule::in($ledger->currencies($this->organization()))],
            'project.starts_on' => 'nullable|date',
            'project.ends_on' => 'nullable|date|after_or_equal:project.starts_on',
            'project.cash_account_id' => ['nullable', Rule::exists('cash_accounts', 'id')->where('organization_id', $organization)],
            'project.status' => ['required', Rule::in(array_keys(Project::STATUSES))],
            'tranches.*.fiscal_year' => 'required|integer|min:2000|max:2100|distinct',
            'tranches.*.income_planned' => 'nullable|numeric|min:0',
            'tranches.*.expense_planned' => 'nullable|numeric|min:0',
            'tranches.*.note' => 'nullable|string|max:255',
        ], ['tranches.*.fiscal_year.distinct' => __('Une année ne peut avoir qu’une tranche.')], [
            'project.name' => __('nom'), 'project.ends_on' => __('date de fin'), 'tranches.*.fiscal_year' => __('année'),
        ]);

        $project = $projects->save($this->organization(), $this->project, $this->tranches, $this->projectId ? Project::findOrFail($this->projectId) : null);
        $this->dispatch('close-modal', name: 'project');
        $this->notify(__('Projet enregistré.'));

        return $this->projectId ? null : $this->redirectRoute('projects.show', $project);
    }

    private function projectFormData(): array
    {
        return [
            'responsible' => ($this->project['responsible_member_id'] ?? null) ? Member::find($this->project['responsible_member_id']) : null,
            'candidates' => trim($this->responsibleSearch) !== '' ? Member::search($this->responsibleSearch)->orderBy('last_name')->limit(5)->get() : collect(),
        ];
    }
}
