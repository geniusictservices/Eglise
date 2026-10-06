<?php

namespace App\Livewire\Users;

use App\Models\Organization;
use App\Models\User;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Utilisateurs')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('users.view');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $organization = current_organization();
        $subtreeIds = Organization::query()->subtreeOf($organization)->pluck('id');

        $users = User::query()
            ->whereHas('roleAssignments', fn ($q) => $q->whereIn('organization_id', $subtreeIds))
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $digits = preg_replace('/\D+/', '', $this->search);
                $q->where(fn ($q) => $q->where('name', 'like', $term)
                    ->when($digits !== '', fn ($q) => $q->orWhere('phone', 'like', '%'.ltrim($digits, '0').'%')));
            })
            ->with(['roleAssignments' => fn ($q) => $q->whereIn('organization_id', $subtreeIds)->with(['role', 'organization'])])
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.users.index', [
            'users' => $users,
            'organization' => $organization,
        ]);
    }
}
