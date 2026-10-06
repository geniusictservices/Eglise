<?php

namespace App\Livewire\Events;

use App\Livewire\Concerns\WritesInOrganization;
use App\Livewire\Events\Concerns\EditsEvents;
use App\Models\AttendanceRecord;
use App\Models\EventRegistration;
use App\Services\Calendar;
use App\Support\EventAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Le calendrier : cultes, prières, événements, mois par mois. */
#[Title('Calendrier')]
class Index extends Component
{
    use EditsEvents, WritesInOrganization;

    #[Url(as: 'mois')]
    public string $month = '';

    public function mount(): void
    {
        abort_unless(Gate::any(['organization.view', 'member.space']), 403);
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = today()->format('Y-m');
        }
    }

    public function shift(int $months): void
    {
        $this->month = Carbon::createFromFormat('Y-m-d', $this->month.'-01')->addMonthsNoOverflow($months)->format('Y-m');
    }

    public function render(Calendar $calendar)
    {
        $organization = $this->organization();
        $start = Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();
        $agenda = $calendar->agenda($organization, $start, $end);
        $eventIds = $agenda->pluck('event.id')->unique();

        $records = AttendanceRecord::whereIn('event_id', $eventIds)->whereBetween('occurs_on', [$start, $end])->withCount('checkins')->get()
            ->keyBy(fn ($r) => $r->event_id.'|'.$r->occurs_on->toDateString());
        $registrations = EventRegistration::whereIn('event_id', $eventIds)->whereBetween('occurs_on', [$start, $end])
            ->selectRaw('event_id, occurs_on, count(*) as n')->groupBy('event_id', 'occurs_on')->get()
            ->mapWithKeys(fn ($r) => [$r->event_id.'|'.Carbon::parse($r->occurs_on)->toDateString() => $r->n]);

        return view('livewire.events.index', [
            'days' => $agenda->groupBy(fn ($o) => $o['date']->toDateString()),
            'start' => $start,
            'records' => $records,
            'registrations' => $registrations,
            'canCreate' => EventAccess::canCreate(auth()->user(), $organization) && ! $organization->isReadOnly(),
        ] + $this->eventFormOptions());
    }
}
