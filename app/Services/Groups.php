<?php

namespace App\Services;

use App\Models\Group;
use App\Models\GroupAttendance;
use App\Models\GroupDue;
use App\Models\GroupMeeting;
use App\Models\Member;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Les groupes : un responsable obligatoire, des adjoints, des membres, des rencontres et leurs présences. */
class Groups
{
    public function __construct(private Notifier $notifier) {}

    public function create(Organization $organization, array $data): Group
    {
        $leader = $this->member($organization, $data['leader_member_id'] ?? null);
        $group = Group::create(['organization_id' => $organization->id, 'leader_member_id' => $leader->id] + $this->settings($data));
        $this->announceLeader($group, $leader);

        return $group;
    }

    public function update(Group $group, array $data): void
    {
        $group->update($this->settings($data));
    }

    /** Le nouveau responsable quitte la liste des membres ; l'ancien y reste comme simple membre. */
    public function changeLeader(Group $group, int $memberId): void
    {
        $organization = $group->loadMissing('organization')->organization;
        $leader = $this->member($organization, $memberId);
        if ($leader->id === $group->leader_member_id) {
            return;
        }

        DB::transaction(function () use ($group, $leader) {
            $group->members()->detach($leader->id);
            $group->members()->syncWithoutDetaching([$group->leader_member_id => ['role' => 'member', 'joined_on' => today()]]);
            $group->update(['leader_member_id' => $leader->id]);
        });
        $this->announceLeader($group, $leader);
    }

    public function addMember(Group $group, int $memberId, string $role = 'member'): Member
    {
        $member = $this->member($group->loadMissing('organization')->organization, $memberId);
        if ($member->id === $group->leader_member_id) {
            throw new InvalidArgumentException(__(':name est déjà le responsable du groupe.', ['name' => $member->fullName()]));
        }
        $group->members()->syncWithoutDetaching([$member->id => ['role' => array_key_exists($role, Group::ROLES) ? $role : 'member', 'joined_on' => today()]]);
        if ($role === 'deputy') {
            $this->announceDeputy($group, $member);
        }

        return $member;
    }

    public function setRole(Group $group, int $memberId, string $role): void
    {
        if (! array_key_exists($role, Group::ROLES) || ! $group->members()->whereKey($memberId)->exists()) {
            throw new InvalidArgumentException(__('Ce membre n’est pas dans le groupe.'));
        }
        $group->members()->updateExistingPivot($memberId, ['role' => $role]);
        if ($role === 'deputy') {
            $this->announceDeputy($group, Member::withoutOrganizationScope()->findOrFail($memberId));
        }
    }

    public function removeMember(Group $group, int $memberId): void
    {
        if ($memberId === $group->leader_member_id) {
            throw new InvalidArgumentException(__('Le groupe garde toujours un responsable : désignez-en un autre d’abord.'));
        }
        $group->members()->detach($memberId);
    }

    /** Le responsable d'abord, puis les adjoints et les membres. */
    public function people(Group $group): Collection
    {
        $group->loadMissing('leader');
        $others = $group->members()->orderByRaw("FIELD(group_members.role, 'deputy', 'member')")->orderBy('last_name')->get();

        return collect([$group->leader])->filter()->concat($others)->values();
    }

    /**
     * Note une rencontre et ses présences. Une seule rencontre par jour : la
     * noter de nouveau la corrige.
     *
     * @param  array<int, string>  $attendance  member_id => present|excused|absent
     */
    public function recordMeeting(Group $group, array $data, array $attendance): GroupMeeting
    {
        $heldOn = Carbon::parse($data['held_on'])->startOfDay();
        if ($heldOn->isFuture()) {
            throw new InvalidArgumentException(__('On note les présences d’une rencontre déjà tenue.'));
        }
        $people = $this->people($group)->pluck('id')->all();

        return DB::transaction(function () use ($group, $data, $attendance, $heldOn, $people) {
            $values = ['organization_id' => $group->organization_id, 'topic' => trim((string) ($data['topic'] ?? '')) ?: null,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null, 'visitors' => max(0, (int) ($data['visitors'] ?? 0)), 'recorded_by' => auth()->id()];
            $sameDay = GroupMeeting::withoutOrganizationScope()->where('group_id', $group->id)->whereDate('held_on', $heldOn)->first();
            // Une rencontre rouverte et redatée se corrige elle-même : elle ne crée pas un doublon.
            $edited = ($data['id'] ?? null) ? GroupMeeting::withoutOrganizationScope()->where('group_id', $group->id)->find($data['id']) : null;
            if ($edited && $sameDay && ! $sameDay->is($edited)) {
                throw new InvalidArgumentException(__('Une rencontre est déjà notée le :d : ouvrez-la pour la corriger.', ['d' => $heldOn->translatedFormat('j F Y')]));
            }
            $meeting = $edited ?? $sameDay ?? new GroupMeeting(['group_id' => $group->id]);
            $meeting->fill($values + ['held_on' => $heldOn->toDateString()])->save();
            $meeting->attendances()->delete();
            foreach ($people as $memberId) {
                $status = $attendance[$memberId] ?? 'absent';
                GroupAttendance::create(['group_meeting_id' => $meeting->id, 'member_id' => $memberId,
                    'status' => array_key_exists($status, GroupMeeting::STATUSES) ? $status : 'absent']);
            }

            return $meeting;
        });
    }

    /**
     * Ceux qui ont manqué les dernières rencontres sans s'excuser : à visiter.
     *
     * @return Collection<int, Member>
     */
    public function absentees(Group $group, int $times = 3): Collection
    {
        $meetings = $group->meetings()->with('attendances')->limit($times)->get();
        if ($meetings->count() < $times) {
            return collect();
        }

        return $this->people($group)->filter(fn (Member $m) => $meetings->every(
            fn (GroupMeeting $meeting) => $meeting->attendances->firstWhere('member_id', $m->id)?->status === 'absent'))->values();
    }

    /** Taux de présence moyen des dernières rencontres, en pour cent. */
    public function rate(Group $group, int $last = 8): ?int
    {
        $meetings = $group->meetings()->withCount(['attendances', 'attendances as present_count' => fn ($q) => $q->where('status', 'present')])->limit($last)->get();
        $expected = $meetings->sum('attendances_count');

        return $expected ? (int) round($meetings->sum('present_count') / $expected * 100) : null;
    }

    /** Marque la cotisation d'un mois payée, ou l'annule si elle l'était. */
    public function toggleDue(Group $group, int $memberId, string $period): bool
    {
        if (! $group->dues_amount || ! preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new InvalidArgumentException(__('Ce groupe n’a pas de cotisation.'));
        }
        if (! $this->people($group)->contains('id', $memberId)) {
            throw new InvalidArgumentException(__('Ce membre n’est pas dans le groupe.'));
        }
        $existing = GroupDue::where('group_id', $group->id)->where('member_id', $memberId)->where('period', $period)->first();
        if ($existing) {
            $existing->delete();

            return false;
        }
        GroupDue::create(['group_id' => $group->id, 'member_id' => $memberId, 'period' => $period, 'amount' => $group->dues_amount,
            'currency' => $group->dues_currency, 'paid_on' => today(), 'recorded_by' => auth()->id()]);

        return true;
    }

    private function settings(array $data): array
    {
        return [
            'name' => trim($data['name']), 'kind' => array_key_exists($data['kind'] ?? '', Group::KINDS) ? $data['kind'] : 'other',
            'department_id' => ($data['department_id'] ?? null) ?: null,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'schedule' => $this->schedule($data['schedule'] ?? []),
            'place' => trim((string) ($data['place'] ?? '')) ?: null,
            'dues_amount' => (float) ($data['dues_amount'] ?? 0) > 0 ? $data['dues_amount'] : null,
            'dues_currency' => (float) ($data['dues_amount'] ?? 0) > 0 ? ($data['dues_currency'] ?? 'USD') : null,
        ];
    }

    /**
     * Les rencontres de la semaine : jour (obligatoire), heure et intitulé, de lundi à dimanche.
     *
     * @return list<array{day: int, time: ?string, label: ?string}>|null
     */
    private function schedule(array $rows): ?array
    {
        $order = array_flip(array_keys(Group::DAYS));
        $schedule = collect($rows)
            ->filter(fn ($m) => is_array($m) && ($m['day'] ?? '') !== '' && ($m['day'] ?? null) !== null && isset(Group::DAYS[(int) $m['day']]))
            ->map(fn ($m) => ['day' => (int) $m['day'], 'time' => ($m['time'] ?? null) ? substr((string) $m['time'], 0, 5) : null,
                'label' => trim((string) ($m['label'] ?? '')) ?: null])
            ->unique(fn ($m) => $m['day'].$m['time'])->take(Group::MAX_MEETINGS)
            ->sortBy(fn ($m) => sprintf('%d %s', $order[$m['day']], $m['time'] ?? ''))->values()->all();

        return $schedule ?: null;
    }

    private function member(Organization $organization, mixed $id): Member
    {
        $member = $id ? Member::withoutOrganizationScope()->where('organization_id', $organization->id)->find($id) : null;

        return $member ?? throw new InvalidArgumentException(__('Choisissez le responsable du groupe parmi les membres.'));
    }

    private function announceLeader(Group $group, Member $leader): void
    {
        $this->notifier->send($group->loadMissing('organization')->organization, $leader->user_id, "group.{$group->id}.leader", [
            'title' => __('Vous êtes responsable du groupe :g', ['g' => $group->name]),
            'body' => __('Notez ses rencontres et ses présences dans Waumini.'),
            'url' => route('groups.show', $group), 'icon' => 'handshake']);
    }

    private function announceDeputy(Group $group, Member $member): void
    {
        $this->notifier->send($group->loadMissing('organization')->organization, $member->user_id, "group.{$group->id}.deputy.{$member->id}", [
            'title' => __('Vous êtes adjoint du groupe :g', ['g' => $group->name]),
            'url' => route('groups.show', $group), 'icon' => 'handshake']);
    }
}
