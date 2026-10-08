<?php

namespace App\Livewire\Finances\Pledges;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\Pledge;
use App\Models\Project;
use App\Services\Pledges;
use App\Services\Projects;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Campagnes et promesses : qui a promis quoi, ce qui est reçu, qui relancer. */
#[Title('Promesses')]
class Index extends Component
{
    use WithPagination, WritesInOrganization;

    #[Url(as: 'projet', except: '')]
    public string $project = '';

    #[Url(as: 'etat', except: '')]
    public string $state = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('finance.view');
        abort_unless(Gate::any(['finance.pledges', 'finance.contributions.view']), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['project', 'state', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function render(Pledges $pledges, Projects $projects)
    {
        $canSeeNames = Gate::any(['finance.pledges', 'finance.contributions.view']);
        $list = Pledge::with(['project', 'member', 'household', 'department'])
            ->when($this->project !== '', fn ($q) => $q->where('project_id', $this->project))
            ->when(in_array($this->state, ['active', 'fulfilled', 'cancelled'], true), fn ($q) => $q->where('status', $this->state))
            ->when($this->state === 'retard', fn ($q) => $q->where('status', 'active'))
            ->when(trim($this->search) !== '', function ($q) {
                $term = '%'.trim($this->search).'%';
                $q->where(fn ($q) => $q->where('pledger_name', 'like', $term)
                    ->orWhereHas('member', fn ($q) => $q->search(trim($this->search)))
                    ->orWhereHas('household', fn ($q) => $q->where('name', 'like', $term)));
            })
            ->latest('pledged_on')->latest('id')->get()
            ->map(fn ($p) => ['pledge' => $p, 'progress' => $pledges->progress($p)])
            ->when($this->state === 'retard', fn ($c) => $c->filter(fn ($r) => $r['progress']['late']->isPositive()))
            ->values();

        $page = $this->getPage();
        $perPage = 25;

        return view('livewire.finances.pledges.index', [
            // Les projets qui ont des promesses, ou qui en attendent (ouverts, avec un objectif).
            'projects' => Project::withCount(['pledges' => fn ($q) => $q->where('status', '!=', 'cancelled')])
                ->where(fn ($q) => $q->whereHas('pledges')->orWhere(fn ($q) => $q->whereIn('status', ['planned', 'ongoing'])->whereNotNull('goal_amount')))
                ->orderByRaw("FIELD(status, 'ongoing', 'planned', 'done', 'cancelled')")->latest()->get()
                ->map(fn ($c) => ['project' => $c, 'totals' => $projects->totals($c), 'count' => $c->pledges_count]),
            'current' => $this->project !== '' ? Project::find($this->project) : null,
            'rows' => $list->forPage($page, $perPage),
            'total' => $list->count(),
            'pages' => (int) ceil($list->count() / $perPage),
            'page' => $page,
            'lateCount' => $list->filter(fn ($r) => $r['progress']['late']->isPositive())->count(),
            'canSeeNames' => $canSeeNames,
            'canManage' => Gate::allows('finance.pledges') && ! $this->organization()->isReadOnly(),
        ]);
    }
}
