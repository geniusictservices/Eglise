<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Group;
use App\Models\Member;
use App\Models\Organization;
use App\Models\RoleAssignment;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Publier une annonce : elle arrive dans les nouveautés de ceux qu'elle concerne. */
class Announcements
{
    public function __construct(private Notifier $notifier) {}

    public function save(Organization $organization, array $data, ?Announcement $announcement = null): Announcement
    {
        $values = [
            'title' => trim($data['title']), 'body' => trim($data['body']), 'audience' => $data['audience'] ?? 'all',
            'is_public' => (bool) ($data['is_public'] ?? false),
            'department_id' => ($data['audience'] ?? '') === 'department' ? (int) $data['department_id'] : null,
            'group_id' => ($data['audience'] ?? '') === 'group' ? (int) $data['group_id'] : null,
            'event_id' => ($data['event_id'] ?? null) ?: null, 'event_date' => ($data['event_id'] ?? null) ? (($data['event_date'] ?? null) ?: null) : null,
            'pinned' => (bool) ($data['pinned'] ?? false), 'expires_on' => ($data['expires_on'] ?? null) ?: null,
        ];
        if ($announcement) {
            $announcement->update($values);

            return $announcement;
        }

        $announcement = Announcement::create($values + ['organization_id' => $organization->id, 'created_by' => auth()->id(), 'published_at' => now()]);
        $announcement->update(['recipients' => $this->notify($announcement)]);

        return $announcement;
    }

    /** Les comptes concernés : ceux de la communauté, ou ceux liés aux membres du département ou du groupe. */
    public function recipients(Announcement $announcement): Collection
    {
        $organization = $announcement->organization_id;

        return match ($announcement->audience) {
            'department' => Member::withoutOrganizationScope()->whereNotNull('user_id')
                ->whereHas('departments', fn ($q) => $q->where('departments.id', $announcement->department_id))->pluck('user_id'),
            'group' => $this->groupUsers(Group::withoutOrganizationScope()->find($announcement->group_id)),
            default => RoleAssignment::with('user')->where('organization_id', $organization)->get()
                ->filter(fn ($a) => $a->user?->is_active)->pluck('user_id')
                ->merge(Member::withoutOrganizationScope()->where('organization_id', $organization)->whereNotNull('user_id')->pluck('user_id')),
        };
    }

    private function groupUsers(?Group $group): Collection
    {
        if (! $group) {
            return collect();
        }
        $ids = $group->members()->pluck('members.id')->push($group->leader_member_id);

        return Member::withoutOrganizationScope()->whereIn('id', $ids)->whereNotNull('user_id')->pluck('user_id');
    }

    private function notify(Announcement $announcement): int
    {
        return $this->notifier->send($announcement->organization()->firstOrFail(), $this->recipients($announcement)->unique(), "announcement.{$announcement->id}", [
            'title' => $announcement->title, 'body' => Str::limit($announcement->body, 140),
            'url' => route('announcements.show', $announcement), 'icon' => 'megaphone']);
    }
}
