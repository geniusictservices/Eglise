<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Crée les communautés et leurs niveaux, avec les rôles modèles et
 * l'administrateur, en une seule transaction.
 */
class OrganizationProvisioner
{
    /**
     * Inscrit une nouvelle communauté (église indépendante ou siège d'une
     * dénomination). Son créateur en devient l'administrateur.
     */
    public function createRoot(array $attributes, User $administrator): Organization
    {
        return DB::transaction(function () use ($attributes, $administrator) {
            $organization = Organization::create($attributes + [
                'level_label' => 'Église',
                'status' => 'trial',
                'trial_ends_at' => now()->addDays(config('waumini.trial_days')),
                'timezone' => config('waumini.default_timezone'),
                'created_by' => $administrator->id,
            ]);

            $this->installRoleTemplates($organization);
            $this->installDefaultCurrencies($organization);
            app(MemberRegistry::class)->installDefaults($organization);
            $this->installDefaultDepartments($organization);
            $this->assign($administrator, $organization->roles()->where('key', 'administrateur')->firstOrFail(), $organization, includesDescendants: true);

            $administrator->forceFill(['current_organization_id' => $organization->id])->save();

            return $organization;
        });
    }

    /** Ajoute un niveau inférieur (région, secteur, paroisse…). */
    public function createChild(Organization $parent, array $attributes): Organization
    {
        return DB::transaction(function () use ($parent, $attributes) {
            $organization = $parent->children()->create($attributes + [
                'status' => $parent->status,
                'trial_ends_at' => $parent->trial_ends_at,
                'timezone' => $parent->timezone,
                'locale' => $parent->locale,
                'is_demo' => $parent->is_demo,
                'demo_expires_at' => $parent->demo_expires_at,
                'created_by' => auth()->id(),
            ]);

            $this->installDefaultCurrencies($organization);
            $this->installDefaultDepartments($organization);

            return $organization;
        });
    }

    /** Chaque niveau a ses départements ; l'Administration générale existe d'office. */
    public function installDefaultDepartments(Organization $organization): void
    {
        Department::withoutOrganizationScope()->firstOrCreate(
            ['organization_id' => $organization->id, 'is_system' => true],
            ['name' => __('Administration générale'), 'kind' => 'administrative', 'color' => 'ink',
                'description' => __('Dépenses et besoins communs : loyer, électricité, secrétariat, entretien…')],
        );
    }

    /** Copie les rôles modèles de config/waumini.php dans l'organisation. */
    public function installRoleTemplates(Organization $organization): void
    {
        foreach (config('waumini.role_templates') as $key => $template) {
            Role::firstOrCreate(
                ['organization_id' => $organization->id, 'key' => $key],
                [
                    'name' => $template['name'],
                    'description' => $template['description'],
                    'permissions' => $template['permissions'],
                    'is_locked' => $template['locked'] ?? false,
                ],
            );
        }
    }

    public function installDefaultCurrencies(Organization $organization): void
    {
        $organization->currencies()->withoutGlobalScope('organization')->firstOrCreate(['currency' => 'CDF'], ['is_active' => true]);
    }

    public function assign(User $user, Role $role, Organization $organization, bool $includesDescendants = false): RoleAssignment
    {
        $assignment = RoleAssignment::firstOrCreate(
            ['user_id' => $user->id, 'role_id' => $role->id, 'organization_id' => $organization->id],
            ['includes_descendants' => $includesDescendants, 'granted_by' => auth()->id()],
        );

        if ($assignment->includes_descendants !== $includesDescendants) {
            $assignment->update(['includes_descendants' => $includesDescendants]);
        }

        $user->forgetAssignments();

        return $assignment;
    }

    /** Rôles utilisables dans une organisation : les siens et ceux de ses ancêtres. */
    public function availableRoles(Organization $organization)
    {
        return Role::whereIn('organization_id', $organization->lineageIds())
            ->orderByDesc('is_locked')->orderBy('name')->get();
    }
}
