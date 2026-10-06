<?php

namespace App\Livewire\Admin\Communities;

use App\Models\Organization;
use App\Models\Subscription;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Les communautés inscrites (racines : églises indépendantes et sièges). */
#[Layout('layouts::admin')]
#[Title('Communautés')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'etat', except: '')]
    public string $status = '';

    public bool $withDemos = false;

    public function mount(): void
    {
        $this->authorize('admin.communities');
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $communities = Organization::whereNull('parent_id')
            ->when(! $this->withDemos, fn ($q) => $q->where('is_demo', false))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when(trim($this->search) !== '', function ($q) {
                $term = '%'.trim($this->search).'%';
                $q->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('short_name', 'like', $term)->orWhere('city', 'like', $term)->orWhere('phone', 'like', $term));
            })
            ->select('organizations.*')
            ->selectSub(fn ($q) => $q->from('organizations as d')->selectRaw('count(*)')
                ->whereRaw("d.path like concat(organizations.path, '%') and d.id <> organizations.id"), 'levels_count')
            ->latest()
            ->paginate(25);

        $latest = Subscription::with('plan')->whereIn('organization_id', $communities->pluck('id'))
            ->orderBy('ends_on')->get()->keyBy('organization_id');

        return view('livewire.admin.communities.index', ['communities' => $communities, 'latest' => $latest]);
    }
}
