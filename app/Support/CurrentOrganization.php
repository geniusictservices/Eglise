<?php

namespace App\Support;

use App\Models\Organization;

/**
 * L'organisation dans laquelle l'utilisateur travaille pendant cette requête.
 *
 * Fixée par le middleware SetCurrentOrganization ; les modèles qui utilisent
 * BelongsToOrganization s'en servent pour filtrer automatiquement leurs données.
 */
class CurrentOrganization
{
    private ?Organization $organization = null;

    public function set(?Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function get(): ?Organization
    {
        return $this->organization;
    }

    public function id(): ?int
    {
        return $this->organization?->id;
    }

    public function has(): bool
    {
        return $this->organization !== null;
    }

    /** Exécute un traitement dans le contexte d'une autre organisation. */
    public function within(Organization $organization, callable $callback): mixed
    {
        $previous = $this->organization;
        $this->organization = $organization;

        try {
            return $callback($organization);
        } finally {
            $this->organization = $previous;
        }
    }
}
