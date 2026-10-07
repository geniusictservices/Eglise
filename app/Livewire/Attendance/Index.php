<?php

namespace App\Livewire\Attendance;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\AttendanceRecord;
use App\Models\AttendanceVisitor;
use App\Models\Event;
use App\Services\Calendar;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Les présences : la fréquentation au fil des semaines, les visiteurs à revoir, les absents à appeler. */
#[Title('Présences')]
class Index extends Component
{
    use WritesInOrganization;

    #[Url(as: 'activite')]
    public string $eventId = '';

    #[Url(as: 'periode')]
    public int $weeks = 12;

    public function mount(): void
    {
        abort_unless(Gate::any(['attendance.record', 'activities.manage', 'members.view']), 403);
        $this->weeks = in_array($this->weeks, [4, 12, 26, 52], true) ? $this->weeks : 12;
    }

    public function render(Calendar $calendar)
    {
        $organization = $this->organization();
        $records = AttendanceRecord::with(['event'])->withCount(['checkins', 'namedVisitors'])
            ->when($this->eventId !== '', fn ($q) => $q->where('event_id', (int) $this->eventId))
            ->whereDate('occurs_on', '>=', today()->subWeeks($this->weeks))->whereDate('occurs_on', '<=', today())
            ->orderByDesc('occurs_on')->get()
            ->each(fn (AttendanceRecord $r) => $r->setAttribute('headcount', $r->total ?? (($r->checkins_count + $r->named_visitors_count) ?: null)));

        $counted = $records->whereNotNull('headcount');
        $averages = $counted->groupBy('event_id')->map(fn ($rs) => [
            'event' => $rs->first()->event, 'average' => (int) round($rs->avg('headcount')), 'dates' => $rs->count(),
            'max' => $rs->max('headcount'), 'visitors' => (int) $rs->sum(fn ($r) => $r->visitors ?? $r->named_visitors_count),
        ])->sortByDesc('average')->values();

        // Le graphique suit une seule activité : celle choisie, sinon la plus fréquentée.
        $charted = $averages->first();
        $chart = $charted ? $counted->where('event_id', $charted['event']?->id)->sortBy('occurs_on')->values() : collect();

        return view('livewire.attendance.index', [
            'records' => $records,
            'chart' => $chart,
            'chartTitle' => $charted ? $charted['event']?->title : null,
            'peak' => max(1, (int) $chart->max('headcount')),
            'averages' => $averages,
            'events' => Event::where('tracks_attendance', true)->orderBy('title')->get(),
            'toFollow' => AttendanceVisitor::with('record.event')->whereNull('followed_up_at')
                ->whereHas('record', fn ($q) => $q->whereDate('occurs_on', '>=', today()->subDays(30)))->latest()->get(),
            'missing' => Gate::allows('members.view') ? $calendar->missing($organization) : collect(),
        ]);
    }
}
