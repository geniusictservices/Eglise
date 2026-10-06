<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use InvalidArgumentException;

/**
 * L'accès du support Genius ICT à une communauté, avec son accord : la
 * communauté l'ouvre pour quelques jours (Paramètres › Support) et peut le
 * retirer à tout moment. Un agent qui l'utilise voit la communauté en
 * lecture seule ; chaque ouverture et chaque fermeture sont au journal.
 */
class SupportAccess
{
    public const SESSION = 'support_organization_id';

    /** Ce que l'agent peut voir ; il ne peut rien modifier. Jamais le suivi pastoral ni les données sensibles. */
    public const PERMISSIONS = [
        'organization.view', 'users.view', 'audit.view', 'members.view',
        'finance.view', 'finance.contributions.view', 'finance.reports',
        'planning.view', 'payroll.view', 'consolidation.view',
    ];

    public const DURATIONS = [1, 3, 7];

    private ?Organization $resolved = null;

    private bool $loaded = false;

    /** La communauté ouverte par l'agent connecté, si l'accord tient toujours. */
    public function organization(?User $user = null): ?Organization
    {
        $user ??= auth()->user();
        if (! $user?->hasPlatformPermission('admin.support') || ! session()->has(self::SESSION)) {
            return null;
        }
        if (! $this->loaded) {
            $this->resolved = Organization::find(session(self::SESSION));
            $this->loaded = true;
        }

        return $this->resolved && self::granted($this->resolved) ? $this->resolved : null;
    }

    /** L'agent est-il dans cette communauté (ou l'un de ses niveaux inférieurs) ? */
    public function covers(User $user, Organization $organization): bool
    {
        $root = $this->organization($user);

        return $root !== null && str_starts_with($organization->path, $root->path);
    }

    public static function granted(Organization $organization): bool
    {
        return (bool) $organization->support_access_until?->isFuture();
    }

    public function start(User $user, Organization $organization): void
    {
        if (! $user->hasPlatformPermission('admin.support')) {
            throw new InvalidArgumentException(__('Votre rôle ne permet pas d’ouvrir une communauté.'));
        }
        if (! self::granted($organization)) {
            throw new InvalidArgumentException(__('Cette communauté n’a pas autorisé l’accès du support.'));
        }
        session()->put(self::SESSION, $organization->id);
        $this->resolved = $organization;
        $this->loaded = true;
        app(AuditLogger::class)->record('support_opened', $organization, description: __('a ouvert la communauté avec l’accès du support Genius ICT, en lecture seule'));
    }

    public function stop(): ?Organization
    {
        $organization = Organization::find(session()->pull(self::SESSION));
        $this->resolved = null;
        $this->loaded = false;
        if ($organization) {
            app(AuditLogger::class)->record('support_closed', $organization, description: __('a refermé l’accès du support Genius ICT'));
        }

        return $organization;
    }

    /** L'agent avait ouvert une communauté dont l'accès a été retiré ou a expiré. */
    public function revoked(?User $user = null): bool
    {
        return session()->has(self::SESSION) && $this->organization($user) === null;
    }
}
