<?php

namespace App\Livewire\Concerns;

use App\Models\Organization;
use Illuminate\Support\Facades\Gate;

/** Outils communs aux écrans qui modifient les données de la communauté. */
trait WritesInOrganization
{
    protected function organization(): Organization
    {
        return current_organization();
    }

    /** Vérifie la permission et que la communauté n'est pas en lecture seule. */
    protected function authorizeWrite(string $permission, ?Organization $organization = null): void
    {
        $organization ??= $this->organization();

        abort_unless(Gate::allows($permission, $organization), 403);
        abort_if($organization->isReadOnly(), 403, __('Cette communauté est en lecture seule.'));
    }

    protected function notify(string $message, string $type = 'success'): void
    {
        $this->dispatch('notify', message: $message, type: $type);
    }
}
