<?php

namespace App\Support;

use App\Models\Group;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Qui gère quels groupes : tous pour ceux qui gèrent les groupes sans être
 * limités à un département ; les groupes de ses départements pour un
 * responsable de département ; son propre groupe pour son responsable et
 * ses adjoints (par leur fiche de membre).
 */
class GroupAccess
{
    public static function full(User $user, Organization $organization): bool
    {
        return Gate::forUser($user)->allows('groups.manage', $organization) && DepartmentScope::ids($user, $organization) === null;
    }

    /** @return int[] groupes dont la personne est responsable ou adjointe */
    public static function led(User $user, Organization $organization): array
    {
        $member = DepartmentScope::member($user, $organization);
        if (! $member) {
            return [];
        }

        return Group::withoutOrganizationScope()->where('organization_id', $organization->id)
            ->where(fn ($q) => $q->where('leader_member_id', $member->id)
                ->orWhereHas('members', fn ($q) => $q->where('members.id', $member->id)->where('group_members.role', 'deputy')))
            ->pluck('id')->all();
    }

    /** @return int[] départements dont les groupes sont gérés par la personne */
    public static function departments(User $user, Organization $organization): array
    {
        return Gate::forUser($user)->allows('groups.manage', $organization) ? DepartmentScope::led($user, $organization) : [];
    }

    /** @return int[]|null null : tous les groupes */
    public static function manageable(User $user, Organization $organization): ?array
    {
        if (self::full($user, $organization)) {
            return null;
        }
        $departments = self::departments($user, $organization);
        $inDepartments = $departments ? Group::withoutOrganizationScope()->where('organization_id', $organization->id)->whereIn('department_id', $departments)->pluck('id')->all() : [];

        return array_values(array_unique([...$inDepartments, ...self::led($user, $organization)]));
    }

    /** @return int[]|null null : tous les groupes */
    public static function visible(User $user, Organization $organization): ?array
    {
        return Gate::forUser($user)->any(['members.view', 'attendance.record'], $organization) ? null : self::manageable($user, $organization);
    }

    /** Membres, rencontres et présences du groupe. */
    public static function canManage(User $user, Organization $organization, Group $group): bool
    {
        $ids = self::manageable($user, $organization);

        return $ids === null || in_array($group->id, $ids, true);
    }

    /** Créer un groupe, le renommer, changer son responsable, le supprimer. */
    public static function canSetUp(User $user, Organization $organization, ?Group $group = null): bool
    {
        if (self::full($user, $organization)) {
            return true;
        }
        $departments = self::departments($user, $organization);

        return $group ? in_array($group->department_id, $departments, true) : $departments !== [];
    }
}
