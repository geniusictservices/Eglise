<?php

namespace App\Livewire;

use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * État de l'abonnement et offres. Les prix ne sont montrés qu'ici, dans
 * l'application (jamais sur le site public).
 */
#[Title('Abonnement')]
class Subscription extends Component
{
    public string $tier = 'small';

    public bool $annual = false;

    public function mount(): void
    {
        $this->authorize('organization.settings');
    }

    public function render()
    {
        $organization = current_organization();
        $root = $organization->root();
        $contact = config('waumini.contact');

        $daysLeft = $root->trial_ends_at ? (int) now()->diffInDays($root->trial_ends_at, false) : null;

        return view('livewire.subscription', [
            'organization' => $organization,
            'root' => $root,
            'daysLeft' => $daysLeft,
            'packs' => config('waumini.packs'),
            'tiers' => config('waumini.size_tiers'),
            'freeMonths' => config('waumini.annual_discount_months'),
            'contact' => $contact,
            'whatsapp' => $contact['phone']
                ? 'https://wa.me/'.ltrim($contact['phone'], '+').'?text='.rawurlencode(__('Bonjour Genius ICT, je souhaite souscrire à Waumini pour :name.', ['name' => $root->name]))
                : null,
        ]);
    }
}
