<?php

namespace App\Livewire\Plan;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\Department;
use App\Models\Member;
use App\Models\PlanAction;
use App\Models\PlanActionUpdate;
use App\Models\PlanObjective;
use App\Models\Vision;
use App\Support\DepartmentScope;
use App\Support\FiscalYear;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Le plan d'action : la vision, les objectifs de l'exercice et leurs actions. */
#[Title('Plan d’action')]
class Index extends Component
{
    use WritesInOrganization;

    #[Url(as: 'exercice')]
    public int $year = 0;

    #[Url(as: 'departement', except: '')]
    public string $department = '';

    public array $vision = [];

    public ?int $objectiveId = null;

    public array $objective = [];

    public ?int $actionId = null;

    public array $action = [];

    public string $responsibleSearch = '';

    public ?int $progressActionId = null;

    public int $progress = 0;

    public string $progressNote = '';

    public function mount(): void
    {
        abort_unless(Gate::any(['planning.view', 'planning.manage']), 403);
        $this->year = $this->year ?: FiscalYear::current($this->organization());
    }

    private function currentVision(): ?Vision
    {
        return Vision::where('starts_year', '<=', $this->year)->where('ends_year', '>=', $this->year)->latest('starts_year')->first();
    }

    public function editVision(): void
    {
        $this->authorizeWrite('planning.manage');
        $v = $this->currentVision();
        $this->vision = ['id' => $v?->id, 'title' => $v->title ?? '', 'statement' => $v->statement ?? '',
            'starts_year' => (string) ($v->starts_year ?? $this->year), 'ends_year' => (string) ($v->ends_year ?? $this->year + 4)];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'vision');
    }

    public function saveVision(): void
    {
        $this->authorizeWrite('planning.manage');
        $data = $this->validate([
            'vision.title' => 'required|string|max:200',
            'vision.statement' => 'nullable|string|max:3000',
            'vision.starts_year' => 'required|integer|min:2000|max:2100',
            'vision.ends_year' => 'required|integer|gte:vision.starts_year|max:2100',
        ], attributes: ['vision.title' => __('vision'), 'vision.ends_year' => __('dernière année')])['vision'];

        $vision = ($this->vision['id'] ?? null) ? Vision::findOrFail($this->vision['id']) : new Vision;
        $vision->fill(collect($data)->only(['title', 'statement', 'starts_year', 'ends_year'])->all())->save();
        $this->dispatch('close-modal', name: 'vision');
        $this->notify(__('Vision enregistrée.'));
    }

    public function editObjective(?int $id = null): void
    {
        $this->authorizeWrite('planning.manage');
        $o = $id ? PlanObjective::findOrFail($id) : null;
        $this->objectiveId = $o?->id;
        $this->objective = ['title' => $o->title ?? '', 'description' => $o->description ?? '', 'indicator' => $o->indicator ?? '',
            'department_id' => (string) ($o->department_id ?? $this->department)];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'objective');
    }

    public function saveObjective(): void
    {
        $this->authorizeWrite('planning.manage');
        $data = $this->validate([
            'objective.title' => 'required|string|max:200',
            'objective.description' => 'nullable|string|max:2000',
            'objective.indicator' => 'nullable|string|max:200',
            'objective.department_id' => ['nullable', Rule::exists('departments', 'id')->where('organization_id', $this->organization()->id)],
        ], attributes: ['objective.title' => __('objectif')])['objective'];

        $values = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $this->objectiveId
            ? PlanObjective::findOrFail($this->objectiveId)->update($values)
            : PlanObjective::create($values + ['fiscal_year' => $this->year, 'vision_id' => $this->currentVision()?->id, 'position' => (int) PlanObjective::where('fiscal_year', $this->year)->max('position') + 1]);
        $this->dispatch('close-modal', name: 'objective');
        $this->notify(__('Objectif enregistré.'));
    }

    public function deleteObjective(int $id): void
    {
        $this->authorizeWrite('planning.manage');
        PlanObjective::findOrFail($id)->delete();
        $this->dispatch('close-modal', name: 'objective');
    }

    public function editAction(?int $id = null, ?int $objectiveId = null): void
    {
        $this->authorizeWrite('planning.manage');
        $a = $id ? PlanAction::findOrFail($id) : null;
        $objective = PlanObjective::findOrFail($a->plan_objective_id ?? $objectiveId);
        $this->actionId = $a?->id;
        $this->action = [
            'plan_objective_id' => $objective->id, 'title' => $a->title ?? '', 'description' => $a->description ?? '',
            'department_id' => (string) ($a->department_id ?? $objective->department_id ?? ''),
            'responsible_member_id' => $a?->responsible_member_id, 'responsible_name' => $a->responsible_name ?? '',
            'starts_on' => $a?->starts_on?->toDateString() ?? '', 'due_on' => $a?->due_on?->toDateString() ?? '',
            'estimated_cost' => $a?->estimated_cost !== null ? (string) (float) $a->estimated_cost : '', 'status' => $a->status ?? 'planned',
        ];
        $this->responsibleSearch = '';
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'action');
    }

    public function chooseResponsible(int $id): void
    {
        $this->action['responsible_member_id'] = Member::findOrFail($id)->id;
        $this->action['responsible_name'] = '';
        $this->responsibleSearch = '';
    }

    public function saveAction(): void
    {
        $this->authorizeWrite('planning.manage');
        $organization = $this->organization()->id;
        $data = $this->validate([
            'action.title' => 'required|string|max:200',
            'action.description' => 'nullable|string|max:2000',
            'action.department_id' => ['nullable', Rule::exists('departments', 'id')->where('organization_id', $organization)],
            'action.responsible_member_id' => ['nullable', Rule::exists('members', 'id')->where('organization_id', $organization)],
            'action.responsible_name' => 'nullable|string|max:150',
            'action.starts_on' => 'nullable|date',
            'action.due_on' => 'nullable|date|after_or_equal:action.starts_on',
            'action.estimated_cost' => 'nullable|numeric|min:0',
            'action.status' => ['required', Rule::in(array_keys(PlanAction::STATUSES))],
        ], attributes: ['action.title' => __('action'), 'action.due_on' => __('échéance')])['action'];

        $values = array_map(fn ($v) => $v === '' ? null : $v, $data);
        if ($values['status'] === 'done') {
            $values['progress'] = 100;
        }
        $this->actionId
            ? PlanAction::findOrFail($this->actionId)->update($values)
            : PlanAction::create($values + ['plan_objective_id' => $this->action['plan_objective_id']]);
        $this->dispatch('close-modal', name: 'action');
        $this->notify(__('Action enregistrée.'));
    }

    /** Le responsable d'un département met à jour l'avancement des actions de son département. */
    private function canUpdate(PlanAction $action): bool
    {
        return ! $this->organization()->isReadOnly() && (Gate::allows('planning.manage')
            || ($action->department_id && DepartmentScope::allows(auth()->user(), $this->organization(), $action->department_id)));
    }

    public function editProgress(int $id): void
    {
        $action = PlanAction::findOrFail($id);
        abort_unless($this->canUpdate($action), 403);
        $this->progressActionId = $action->id;
        $this->progress = $action->progress;
        $this->progressNote = '';
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'progress');
    }

    public function saveProgress(): void
    {
        $action = PlanAction::findOrFail($this->progressActionId);
        abort_unless($this->canUpdate($action), 403);
        $this->validate(['progress' => 'required|integer|between:0,100', 'progressNote' => 'nullable|string|max:1000'], attributes: ['progress' => __('avancement')]);

        DB::transaction(function () use ($action) {
            PlanActionUpdate::create(['plan_action_id' => $action->id, 'user_id' => auth()->id(), 'progress' => $this->progress, 'note' => trim($this->progressNote) ?: null]);
            $action->update(['progress' => $this->progress, 'status' => match (true) {
                $action->status === 'cancelled' => 'cancelled',
                $this->progress >= 100 => 'done',
                $this->progress > 0 => 'ongoing',
                default => 'planned',
            }]);
        });
        $this->dispatch('close-modal', name: 'progress');
        $this->notify(__('Avancement enregistré.'));
    }

    public function render()
    {
        $organization = $this->organization();
        $objectives = PlanObjective::with(['department', 'actions.department', 'actions.responsible', 'actions.updates.user'])
            ->where('fiscal_year', $this->year)
            ->when($this->department !== '', fn ($q) => $q->where(fn ($q) => $q->where('department_id', $this->department)
                ->orWhereHas('actions', fn ($q) => $q->where('department_id', $this->department))))
            ->orderBy('position')->get();
        $current = FiscalYear::current($organization);
        $actions = $objectives->flatMap->actions->where('status', '!=', 'cancelled');

        return view('livewire.plan.index', [
            'currentVision' => $this->currentVision(),
            'objectives' => $objectives,
            'overall' => $actions->isEmpty() ? 0 : (int) round($actions->avg('progress')),
            'late' => $actions->filter->isLate()->count(),
            'yearLabel' => FiscalYear::label($organization, $this->year),
            'years' => collect(range($current + 1, $current - 2))->mapWithKeys(fn ($y) => [$y => FiscalYear::label($organization, $y)])->all(),
            'departments' => Department::where('is_active', true)->orderByDesc('is_system')->orderBy('name')->get(),
            'canManage' => Gate::allows('planning.manage') && ! $organization->isReadOnly(),
            'updatable' => $objectives->flatMap->actions->mapWithKeys(fn ($a) => [$a->id => $this->canUpdate($a)]),
            'responsible' => ($this->action['responsible_member_id'] ?? null) ? Member::find($this->action['responsible_member_id']) : null,
            'candidates' => trim($this->responsibleSearch) !== '' ? Member::search($this->responsibleSearch)->orderBy('last_name')->limit(5)->get() : collect(),
            'progressAction' => $this->progressActionId ? PlanAction::find($this->progressActionId) : null,
        ]);
    }
}
