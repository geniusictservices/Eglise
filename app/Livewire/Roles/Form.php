<?php

namespace App\Livewire\Roles;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class Form extends Component
{
    use WritesInOrganization;

    public ?Role $role = null;

    public string $name = '';

    public string $description = '';

    /** @var list<string> */
    public array $permissions = [];

    #[Url(as: 'depuis')]
    public ?int $from = null;

    #[Locked]
    public bool $readOnly = false;

    public function mount(?Role $role = null): void
    {
        $this->authorize('roles.manage');
        $organization = $this->organization();

        if ($role?->exists) {
            abort_unless(in_array($role->organization_id, $organization->lineageIds(), true), 404);
            $this->role = $role;
            $this->readOnly = $role->is_locked || $role->organization_id !== $organization->id;
            $this->fill(['name' => $role->name, 'description' => (string) $role->description, 'permissions' => $role->grantedPermissions()]);
        } elseif ($this->from) {
            $source = Role::whereIn('organization_id', $organization->lineageIds())->findOrFail($this->from);
            $this->fill([
                'name' => __(':name (copie)', ['name' => $source->name]),
                'description' => (string) $source->description,
                'permissions' => $source->grantedPermissions(),
            ]);
        }
    }

    public function toggleGroup(string $group): void
    {
        $keys = array_keys(Permissions::groups()[$group]['items'] ?? []);
        $all = array_diff($keys, $this->permissions) === [];
        $this->permissions = $all
            ? array_values(array_diff($this->permissions, $keys))
            : array_values(array_unique(array_merge($this->permissions, $keys)));
    }

    public function save()
    {
        abort_if($this->readOnly || $this->notOurs(), 403);
        $this->authorizeWrite('roles.manage');
        $organization = $this->organization();

        $this->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('roles')->where('organization_id', $organization->id)->ignore($this->role?->id)],
            'description' => 'nullable|string|max:500',
            'permissions' => 'array|min:1',
            'permissions.*' => [Rule::in(Permissions::all())],
        ], [
            'permissions.min' => __('Cochez au moins une permission.'),
        ], ['name' => __('nom')]);

        $data = ['name' => $this->name, 'description' => $this->description ?: null, 'permissions' => array_values($this->permissions)];

        if ($this->role) {
            $this->role->update($data);
        } else {
            $this->role = Role::create($data + ['organization_id' => $organization->id]);
        }

        session()->flash('status', __('Rôle « :name » enregistré.', ['name' => $this->name]));

        return $this->redirectRoute('roles.index');
    }

    /** Un rôle verrouillé ou défini par un niveau supérieur se consulte, il ne se modifie pas ici (vérifié côté serveur). */
    private function notOurs(): bool
    {
        return $this->role && ($this->role->is_locked || $this->role->organization_id !== $this->organization()->id);
    }

    public function delete()
    {
        abort_if($this->readOnly || ! $this->role || $this->notOurs(), 403);
        $this->authorizeWrite('roles.manage');

        if ($this->role->assignments()->exists()) {
            $this->notify(__('Ce rôle est encore attribué. Retirez-le d’abord aux utilisateurs concernés.'), 'error');

            return null;
        }

        $this->role->delete();
        session()->flash('status', __('Rôle supprimé.'));

        return $this->redirectRoute('roles.index');
    }

    public function render()
    {
        return view('livewire.roles.form', [
            'groups' => Permissions::groups(),
        ])->title($this->role ? $this->role->name : __('Nouveau rôle'));
    }
}
