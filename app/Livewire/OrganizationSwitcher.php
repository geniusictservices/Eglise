<?php

namespace App\Livewire;

use Livewire\Component;

/** Sélecteur de communauté, dans la barre latérale. */
class OrganizationSwitcher extends Component
{
    public function render()
    {
        $user = auth()->user();
        $organizations = $user->accessibleOrganizations()->orderBy('path')->limit(200)->get();

        // Ordonne en arbre : le chemin matérialisé trié par nom à chaque niveau.
        $byParent = $organizations->groupBy('parent_id');
        $ids = $organizations->pluck('id')->all();
        $ordered = collect();
        $walk = function ($items) use (&$walk, $byParent, $ordered) {
            foreach ($items->sortBy('name') as $organization) {
                $ordered->push($organization);
                $walk($byParent->get($organization->id, collect()));
            }
        };
        $walk($organizations->filter(fn ($o) => ! in_array($o->parent_id, $ids, true)));

        $minDepth = $ordered->min('depth') ?? 0;

        return view('livewire.organization-switcher', [
            'organizations' => $ordered,
            'minDepth' => $minDepth,
            'current' => current_organization(),
        ]);
    }
}
