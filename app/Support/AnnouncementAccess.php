<?php

namespace App\Support;

use App\Models\Announcement;
use App\Models\Group;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Qui annonce à qui : à toute la communauté, ceux qui envoient les
 * notifications et gèrent les activités ; à son département ou à son
 * groupe, son responsable.
 */
class AnnouncementAccess
{
    public static function full(User $user, Organization $organization): bool
    {
        return Gate::forUser($user)->allows('communication.send', $organization) && Gate::forUser($user)->allows('activities.manage', $organization);
    }

    public static function canCreate(User $user, Organization $organization): bool
    {
        if (self::full($user, $organization)) {
            return true;
        }
        $scopes = EventAccess::scopes($user, $organization);

        return $scopes['departments'] !== [] || $scopes['groups'] !== [];
    }

    public static function allowsAudience(User $user, Organization $organization, string $audience, ?int $departmentId, ?int $groupId): bool
    {
        if (self::full($user, $organization)) {
            return true;
        }
        $scopes = EventAccess::scopes($user, $organization);

        return match ($audience) {
            'department' => in_array($departmentId, $scopes['departments'], true),
            'group' => in_array($groupId, $scopes['groups'], true),
            default => false,
        };
    }

    public static function canEdit(User $user, Organization $organization, Announcement $announcement): bool
    {
        return self::full($user, $organization) || $announcement->created_by === $user->id
            || self::allowsAudience($user, $organization, $announcement->audience, $announcement->department_id, $announcement->group_id);
    }

    /** Ce que la personne voit : tout si elle annonce à tous ; sinon les annonces générales, et celles de ses départements et groupes. */
    public static function visibleQuery(User $user, Organization $organization): \Closure
    {
        if (self::full($user, $organization) || Gate::forUser($user)->allows('members.view', $organization)) {
            return fn ($q) => $q;
        }
        $member = DepartmentScope::member($user, $organization);
        $departments = $member ? $member->departments()->pluck('departments.id')->all() : [];
        $groups = $member ? self::groupsOf($member) : [];
        $scopes = EventAccess::scopes($user, $organization);

        return fn ($q) => $q->where(fn ($q) => $q->where('audience', 'all')
            ->orWhereIn('department_id', [...$departments, ...$scopes['departments']])
            ->orWhereIn('group_id', [...$groups, ...$scopes['groups']])
            ->orWhere('created_by', $user->id));
    }

    /** @return int[] */
    public static function groupsOf(Member $member): array
    {
        return Group::where('leader_member_id', $member->id)
            ->orWhereHas('members', fn ($q) => $q->where('members.id', $member->id))->pluck('id')->all();
    }
}
