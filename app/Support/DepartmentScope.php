<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Les départements qu'une personne peut gérer pour le budget : tous pour la
 * finance et ceux qui gèrent les départements ; sinon ceux dont elle est
 * responsable ou adjointe, par sa fiche de membre.
 */
class DepartmentScope
{
    /** @return int[]|null null : tous les départements */
    public static function ids(User $user, Organization $organization): ?array
    {
        if (Gate::forUser($user)->any(['budget.arbitrate', 'departments.manage'], $organization)) {
            return null;
        }

        return self::led($user, $organization);
    }

    /** @return int[] départements dont la personne est responsable ou adjointe */
    public static function led(User $user, Organization $organization): array
    {
        $member = self::member($user, $organization);

        return $member ? $member->departments()->wherePivotIn('role', ['leader', 'deputy'])->pluck('departments.id')->all() : [];
    }

    public static function member(User $user, Organization $organization): ?Member
    {
        return Member::withoutOrganizationScope()->where('organization_id', $organization->id)->where('user_id', $user->id)->first();
    }

    public static function allows(User $user, Organization $organization, Department|int $department): bool
    {
        $ids = self::ids($user, $organization);

        return $ids === null || in_array(is_int($department) ? $department : $department->id, $ids, true);
    }
}
