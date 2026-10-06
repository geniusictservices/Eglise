<?php

namespace App\Livewire\Households;

use App\Models\Household;
use App\Models\Organization;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Liste des ménages de la communauté. */
#[Title('Ménages')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public array $form = ['name' => '', 'district' => '', 'street' => '', 'house_number' => '', 'city' => '', 'phone' => ''];

    public function mount(): void
    {
        $this->authorize('members.view');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $organization = current_organization();
        abort_unless(Gate::allows('members.manage') && ! $organization->isReadOnly(), 403);
        $this->form = ['name' => '', 'district' => '', 'street' => '', 'house_number' => '', 'city' => (string) $organization->city, 'phone' => ''];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'household');
    }

    public function save()
    {
        abort_unless(Gate::allows('members.manage') && ! current_organization()->isReadOnly(), 403);
        $data = $this->validate(Show::rules(), attributes: Show::attributes())['form'];

        $household = Household::create(collect($data)->map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v)->all());

        return $this->redirectRoute('households.show', $household);
    }

    public function render()
    {
        $organization = current_organization();
        $user = auth()->user();
        $ids = Organization::query()->subtreeOf($organization)->get()
            ->filter(fn (Organization $o) => $o->is($organization) || $user->hasPermission('members.view', $o))
            ->pluck('id');

        $households = Household::withoutOrganizationScope()->whereIn('organization_id', $ids)
            ->when(trim($this->search) !== '', function ($q) {
                $term = '%'.trim($this->search).'%';
                $q->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('district', 'like', $term)->orWhere('street', 'like', $term));
            })
            ->with(['head', 'members', 'organization'])
            ->withCount('members')
            ->orderBy('name')
            ->paginate(24);

        return view('livewire.households.index', [
            'households' => $households,
            'canManage' => Gate::allows('members.manage') && ! $organization->isReadOnly(),
            'organization' => $organization,
            'multiLevel' => $ids->count() > 1,
        ]);
    }
}
