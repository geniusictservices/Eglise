<?php

namespace App\Livewire\Roles;

use App\Services\OrganizationProvisioner;
use App\Support\Permissions;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Rôles et permissions')]
class Index extends Component
{
    public function mount(): void
    {
        $this->authorize('roles.manage');
    }

    public function render(OrganizationProvisioner $provisioner)
    {
        $organization = current_organization();
        $roles = $provisioner->availableRoles($organization)->load('organization')->loadCount('assignments');

        return view('livewire.roles.index', [
            'roles' => $roles,
            'organization' => $organization,
            'total' => count(Permissions::all()),
        ]);
    }
}
