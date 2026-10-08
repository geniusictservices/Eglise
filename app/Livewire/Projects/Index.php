<?php

namespace App\Livewire\Projects;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\CashAccount;
use App\Models\Department;
use App\Models\Project;
use App\Models\Vision;
use App\Services\Ledger;
use App\Services\Projects;
use App\Support\FiscalYear;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Les projets de la communauté, sous sa vision : ce qui est prévu, collecté, dépensé, et où en est chacun. */
#[Title('Projets')]
class Index extends Component
{
    use EditsProject, WritesInOrganization;

    #[Url(as: 'exercice')]
    public int $year = 0;

    #[Url(as: 'departement', except: '')]
    public string $department = '';

    #[Url(as: 'etat', except: 'ouverts')]
    public string $state = 'ouverts';

    public array $vision = [];

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

    public function render(Projects $projects, Ledger $ledger)
    {
        $organization = $this->organization();
        $list = Project::with(['years', 'department', 'responsible', 'account', 'indicators'])
            ->when($this->department !== '', fn ($q) => $q->where('department_id', $this->department))
            ->when($this->state === 'ouverts', fn ($q) => $q->whereIn('status', ['planned', 'ongoing']))
            ->when($this->state === 'annee', fn ($q) => $q->where(fn ($q) => $q->whereHas('years', fn ($q) => $q->where('fiscal_year', $this->year))
                ->orWhere(fn ($q) => $q->whereIn('status', ['planned', 'ongoing'])->whereDoesntHave('years'))))
            ->when($this->state === 'finis', fn ($q) => $q->whereIn('status', ['done', 'cancelled']))
            ->orderByRaw("FIELD(status, 'ongoing', 'planned', 'done', 'cancelled')")->orderBy('theme')->orderBy('name')->get()
            ->map(function (Project $p) use ($projects) {
                $totals = $projects->totals($p);

                return ['project' => $p, 'totals' => $totals, 'progress' => $projects->progressOf($p, $totals)['percent']];
            });
        $open = $list->filter(fn ($r) => $r['project']->isActive());
        $current = FiscalYear::current($organization);

        return view('livewire.projects.index', $this->projectFormData() + [
            'rows' => $list,
            'themes' => $list->groupBy(fn ($r) => $r['project']->theme ?: ''),
            'currentVision' => $this->currentVision(),
            // L'avancement d'ensemble : la moyenne des projets ouverts qui ont des indicateurs.
            'overall' => (int) round($open->whereNotNull('progress')->avg('progress') ?? 0),
            'late' => $open->filter(fn ($r) => $r['project']->isLate())->count(),
            'yearLabel' => FiscalYear::label($organization, $this->year),
            'years' => collect(range($current + 2, $current - 3))->mapWithKeys(fn ($y) => [$y => FiscalYear::label($organization, $y)])->all(),
            'departments' => Department::where('is_active', true)->orderByDesc('is_system')->orderBy('name')->get(),
            'accounts' => CashAccount::where('is_active', true)->orderBy('position')->get(),
            'currencies' => $ledger->currencies($organization),
            'canManage' => Gate::allows('planning.manage') && ! $organization->isReadOnly(),
        ]);
    }
}
