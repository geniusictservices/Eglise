<?php

namespace App\Livewire\Departments;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\Department;
use App\Models\Member;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

/** Un département : responsables et membres. */
class Show extends Component
{
    use WritesInOrganization;

    public Department $department;

    public array $form = [];

    public string $memberSearch = '';

    public string $newRole = 'member';

    public function mount(Department $department): void
    {
        $this->authorize('members.view');
        $this->department = $department;
    }

    public function edit(): void
    {
        $this->authorizeWrite('departments.manage');
        $this->form = $this->department->only(['name', 'kind', 'color']) + ['description' => (string) $this->department->description];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'department');
    }

    public function save(): void
    {
        $this->authorizeWrite('departments.manage');
        $data = $this->validate(Index::rules($this->department->id), attributes: ['form.name' => __('nom')])['form'];

        $this->department->update(['name' => trim($data['name']), 'kind' => $this->department->is_system ? 'administrative' : $data['kind'],
            'description' => trim((string) $data['description']) ?: null, 'color' => $data['color']]);

        $this->dispatch('close-modal', name: 'department');
        $this->notify(__('Département enregistré.'));
    }

    public function toggleActive(): void
    {
        $this->authorizeWrite('departments.manage');
        abort_if($this->department->is_system, 403);
        $this->department->update(['is_active' => ! $this->department->is_active]);
        $this->notify($this->department->is_active ? __('Département réactivé.') : __('Département mis en sommeil. Son historique est conservé.'));
    }

    public function delete()
    {
        $this->authorizeWrite('departments.manage');
        abort_if($this->department->is_system, 403);

        if ($this->department->members()->exists()) {
            $this->notify(__('Retirez d’abord les membres, ou mettez le département en sommeil.'), 'error');

            return null;
        }

        $this->department->delete();
        session()->flash('status', __('Département supprimé.'));

        return $this->redirectRoute('departments.index');
    }

    public function addMember(int $id): void
    {
        $this->authorizeWrite('departments.manage');
        $this->validate(['newRole' => ['required', Rule::in(array_keys(Department::ROLES))]]);
        $member = Member::findOrFail($id);

        $this->department->members()->syncWithoutDetaching([$member->id => ['role' => $this->newRole, 'joined_on' => now()]]);
        $this->reset('memberSearch');
        $this->notify(__(':name ajouté(e) au département.', ['name' => $member->fullName()]));
    }

    public function setRole(int $memberId, string $role): void
    {
        $this->authorizeWrite('departments.manage');
        abort_unless(array_key_exists($role, Department::ROLES), 422);
        abort_unless($this->department->members()->whereKey($memberId)->exists(), 404);
        $this->department->members()->updateExistingPivot($memberId, ['role' => $role]);
    }

    public function removeMember(int $memberId): void
    {
        $this->authorizeWrite('departments.manage');
        $this->department->members()->detach($memberId);
        $this->notify(__('Retiré(e) du département.'));
    }

    public function render()
    {
        $candidates = collect();
        if (trim($this->memberSearch) !== '') {
            $candidates = Member::search($this->memberSearch)
                ->whereDoesntHave('departments', fn ($q) => $q->where('departments.id', $this->department->id))
                ->orderBy('last_name')->limit(6)->get();
        }

        $members = $this->department->members()->with('status')
            ->orderByRaw("FIELD(department_members.role, 'leader', 'deputy', 'member')")
            ->orderBy('last_name')->get();

        return view('livewire.departments.show', [
            'members' => $members,
            'candidates' => $candidates,
            'canManage' => Gate::allows('departments.manage') && ! $this->organization()->isReadOnly(),
            'colors' => config('waumini.registry.colors'),
        ])->title($this->department->name);
    }
}
