<?php

namespace App\Support;

use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Qui programme quoi : ceux qui gèrent le calendrier, toutes les activités ;
 * un responsable de département ou de groupe, celles de son département ou
 * de son groupe.
 */
class EventAccess
{
    public static function full(User $user, Organization $organization): bool
    {
        return Gate::forUser($user)->allows('activities.manage', $organization);
    }

    /** @return array{departments: int[], groups: int[]} */
    public static function scopes(User $user, Organization $organization): array
    {
        return ['departments' => DepartmentScope::led($user, $organization), 'groups' => GroupAccess::manageable($user, $organization) ?? []];
    }

    public static function canCreate(User $user, Organization $organization): bool
    {
        if (self::full($user, $organization)) {
            return true;
        }
        $scopes = self::scopes($user, $organization);

        return $scopes['departments'] !== [] || $scopes['groups'] !== [];
    }

    public static function canEdit(User $user, Organization $organization, Event $event): bool
    {
        return self::full($user, $organization) || self::allowsAudience($user, $organization, $event->audience, $event->department_id, $event->group_id);
    }

    public static function allowsAudience(User $user, Organization $organization, string $audience, ?int $departmentId, ?int $groupId): bool
    {
        if (self::full($user, $organization)) {
            return true;
        }
        $scopes = self::scopes($user, $organization);

        return match ($audience) {
            'department' => in_array($departmentId, $scopes['departments'], true),
            'group' => in_array($groupId, $scopes['groups'], true),
            default => false,
        };
    }

    /** Effectifs, pointage et visiteurs. */
    public static function canRecord(User $user, Organization $organization, Event $event): bool
    {
        return Gate::forUser($user)->allows('attendance.record', $organization) || self::canEdit($user, $organization, $event);
    }
}
