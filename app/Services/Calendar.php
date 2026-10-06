<?php

namespace App\Services;

use App\Models\AttendanceCheckin;
use App\Models\AttendanceRecord;
use App\Models\AttendanceVisitor;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Member;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Le calendrier, les inscriptions et les présences. */
class Calendar
{
    /**
     * Les activités entre deux dates, dans l'ordre : une ligne par date.
     *
     * @return Collection<int, array{event: Event, date: Carbon}>
     */
    public function agenda(Organization $organization, Carbon $from, Carbon $to): Collection
    {
        $events = Event::withoutOrganizationScope()->with(['department', 'group'])->where('organization_id', $organization->id)
            ->whereDate('starts_on', '<=', $to)
            ->where(fn ($q) => $q->where('repeats', '!=', 'none')->orWhereDate('starts_on', '>=', $from->copy()->subDays(31)))
            ->where(fn ($q) => $q->whereNull('repeat_until')->orWhereDate('repeat_until', '>=', $from))
            ->get();

        return $events->flatMap(fn (Event $e) => collect($e->occurrences($from, $to))->map(fn (Carbon $d) => ['event' => $e, 'date' => $d]))
            ->sortBy(fn ($o) => $o['date']->toDateString().' '.($o['event']->start_time ?? '00:00'))->values();
    }

    public function save(Organization $organization, array $data, ?Event $event = null): Event
    {
        $values = [
            'title' => trim($data['title']), 'kind' => array_key_exists($data['kind'] ?? '', Event::KINDS) ? $data['kind'] : 'other',
            'starts_on' => $data['starts_on'], 'ends_on' => ($data['ends_on'] ?? null) ?: null,
            'start_time' => ($data['start_time'] ?? null) ?: null, 'end_time' => ($data['end_time'] ?? null) ?: null,
            'place' => trim((string) ($data['place'] ?? '')) ?: null, 'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'audience' => $data['audience'] ?? 'all',
            'is_public' => (bool) ($data['is_public'] ?? ($data['audience'] ?? 'all') === 'all'),
            'department_id' => ($data['audience'] ?? '') === 'department' ? (int) $data['department_id'] : null,
            'group_id' => ($data['audience'] ?? '') === 'group' ? (int) $data['group_id'] : null,
            'repeats' => array_key_exists($data['repeats'] ?? '', Event::REPEATS) ? $data['repeats'] : 'none',
            'repeat_until' => ($data['repeats'] ?? 'none') !== 'none' ? (($data['repeat_until'] ?? null) ?: null) : null,
            'registration' => (bool) ($data['registration'] ?? false),
            'capacity' => ($data['registration'] ?? false) && ($data['capacity'] ?? null) ? (int) $data['capacity'] : null,
            'tracks_attendance' => (bool) ($data['tracks_attendance'] ?? false),
        ];
        if ($values['ends_on'] && $values['ends_on'] < $values['starts_on']) {
            throw new InvalidArgumentException(__('La fin ne peut pas précéder le début.'));
        }

        if ($event) {
            $event->update($values);

            return $event;
        }

        return Event::create($values + ['organization_id' => $organization->id, 'created_by' => auth()->id()]);
    }

    /** Annule une seule date d'une activité qui se répète. */
    public function skip(Event $event, Carbon $date): void
    {
        $event->update(['skipped_dates' => collect($event->skipped_dates ?? [])->push($date->toDateString())->unique()->sort()->values()->all()]);
    }

    public function restore(Event $event, Carbon $date): void
    {
        $event->update(['skipped_dates' => collect($event->skipped_dates ?? [])->reject(fn ($d) => $d === $date->toDateString())->values()->all() ?: null]);
    }

    // Inscriptions ----------------------------------------------------------

    public function register(Event $event, Carbon $date, ?int $memberId, ?string $name = null, ?string $phone = null): EventRegistration
    {
        if (! $event->registration) {
            throw new InvalidArgumentException(__('Cette activité ne prend pas d’inscriptions.'));
        }
        $taken = EventRegistration::where('event_id', $event->id)->whereDate('occurs_on', $date);
        if ($event->capacity && (clone $taken)->count() >= $event->capacity) {
            throw new InvalidArgumentException(__('Complet : les :n places sont prises.', ['n' => $event->capacity]));
        }
        if ($memberId && (clone $taken)->where('member_id', $memberId)->exists()) {
            throw new InvalidArgumentException(__('Cette personne est déjà inscrite.'));
        }
        if (! $memberId && trim((string) $name) === '') {
            throw new InvalidArgumentException(__('Choisissez le membre, ou écrivez le nom de la personne.'));
        }

        return EventRegistration::create(['event_id' => $event->id, 'occurs_on' => $date->toDateString(), 'member_id' => $memberId,
            'name' => $memberId ? null : trim($name), 'phone' => $memberId ? null : (trim((string) $phone) ?: null), 'registered_by' => auth()->id()]);
    }

    // Présences ---------------------------------------------------------------

    public function record(Event $event, Carbon $date): AttendanceRecord
    {
        if ($date->isAfter(today())) {
            throw new InvalidArgumentException(__('On note les présences d’une activité déjà tenue.'));
        }

        return AttendanceRecord::withoutOrganizationScope()->firstOrCreate(['event_id' => $event->id, 'occurs_on' => $date->toDateString()],
            ['organization_id' => $event->organization_id, 'recorded_by' => auth()->id()]);
    }

    /**
     * Les effectifs : hommes, femmes, enfants, dont visiteurs. Sans le détail,
     * on peut donner seulement le total. Tout est facultatif.
     */
    public function saveCounts(AttendanceRecord $record, array $counts): void
    {
        $value = fn (string $key) => ($counts[$key] ?? '') === '' || ($counts[$key] ?? null) === null ? null : max(0, (int) $counts[$key]);
        [$men, $women, $children, $visitors, $total] = [$value('men'), $value('women'), $value('children'), $value('visitors'), $value('total')];
        $detail = $men !== null || $women !== null || $children !== null;
        if ($detail) {
            $total = (int) $men + (int) $women + (int) $children;
        }
        if ($visitors !== null && $total !== null && $visitors > $total) {
            throw new InvalidArgumentException(__('Les visiteurs font partie du total : ils ne peuvent pas être plus nombreux.'));
        }
        $record->update(['men' => $men, 'women' => $women, 'children' => $children, 'visitors' => $visitors, 'total' => $total,
            'notes' => trim((string) ($counts['notes'] ?? '')) ?: null, 'recorded_by' => auth()->id()]);
    }

    /** Pointe ou retire un membre ; renvoie true s'il est maintenant présent. */
    public function toggleCheckin(AttendanceRecord $record, int $memberId): bool
    {
        $member = Member::withoutOrganizationScope()->where('organization_id', $record->organization_id)->findOrFail($memberId);
        $existing = AttendanceCheckin::where('attendance_record_id', $record->id)->where('member_id', $member->id)->first();
        if ($existing) {
            $existing->delete();

            return false;
        }
        AttendanceCheckin::create(['attendance_record_id' => $record->id, 'member_id' => $member->id]);

        return true;
    }

    public function addVisitor(AttendanceRecord $record, array $data): AttendanceVisitor
    {
        if (trim((string) ($data['name'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Indiquez le nom du visiteur.'));
        }

        return AttendanceVisitor::create(['attendance_record_id' => $record->id, 'name' => trim($data['name']),
            'phone' => trim((string) ($data['phone'] ?? '')) ?: null, 'invited_by' => trim((string) ($data['invited_by'] ?? '')) ?: null,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null]);
    }

    /** Le nombre de présents : le total compté, sinon les pointés et les visiteurs nommés. */
    public function headcount(AttendanceRecord $record): ?int
    {
        if ($record->total !== null) {
            return $record->total;
        }
        $named = $record->checkins()->count() + $record->namedVisitors()->count();

        return $named ?: null;
    }

    /**
     * Les membres pointés auparavant mais absents des dernières dates pointées :
     * à appeler. Ne concerne que les églises qui pointent.
     *
     * @return Collection<int, Member>
     */
    public function missing(Organization $organization, int $recent = 4, int $before = 8): Collection
    {
        $records = AttendanceRecord::withoutOrganizationScope()->where('organization_id', $organization->id)
            ->whereHas('checkins')->orderByDesc('occurs_on')->limit($recent + $before)->pluck('id');
        if ($records->count() <= $recent) {
            return collect();
        }
        [$latest, $earlier] = [$records->take($recent), $records->slice($recent)];
        $seenLately = AttendanceCheckin::whereIn('attendance_record_id', $latest)->pluck('member_id')->unique();
        $regulars = AttendanceCheckin::whereIn('attendance_record_id', $earlier)->select('member_id', DB::raw('count(*) as times'))
            ->groupBy('member_id')->having('times', '>=', 2)->pluck('member_id')->diff($seenLately);

        return Member::withoutOrganizationScope()->whereIn('id', $regulars)->orderBy('last_name')->get();
    }
}
