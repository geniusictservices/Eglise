<?php

namespace App\Livewire\Events;

use App\Livewire\Concerns\WritesInOrganization;
use App\Livewire\Events\Concerns\EditsEvents;
use App\Models\AttendanceCheckin;
use App\Models\AttendanceRecord;
use App\Models\AttendanceVisitor;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Member;
use App\Services\Calendar;
use App\Support\DepartmentScope;
use App\Support\EventAccess;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Component;

/** Une date d'une activité : ses détails, ses inscriptions et ses présences. */
class Show extends Component
{
    use EditsEvents, WritesInOrganization;

    public Event $event;

    public string $date;

    public array $counts = [];

    public string $checkinSearch = '';

    public array $visitor = ['name' => '', 'phone' => '', 'invited_by' => ''];

    public string $registrationSearch = '';

    public array $guest = ['name' => '', 'phone' => ''];

    public function mount(Event $event, string $date): void
    {
        $this->authorize('organization.view');
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date), 404);
        $this->event = $event;
        $this->date = $date;
        $skipped = in_array($date, $event->skipped_dates ?? [], true);
        abort_unless($skipped || $event->occursOn($date), 404);
        $record = $this->record();
        $this->counts = $record ? $record->only(['men', 'women', 'children', 'visitors', 'total']) + ['notes' => (string) $record->notes]
            : ['men' => null, 'women' => null, 'children' => null, 'visitors' => null, 'total' => null, 'notes' => ''];
    }

    protected function eventReturnDate(): ?string
    {
        return $this->date;
    }

    private function day(): Carbon
    {
        return Carbon::parse($this->date)->startOfDay();
    }

    private function record(): ?AttendanceRecord
    {
        return AttendanceRecord::where('event_id', $this->event->id)->whereDate('occurs_on', $this->date)->first();
    }

    private function authorizeEdit(): void
    {
        abort_if($this->organization()->isReadOnly(), 403);
        abort_unless(EventAccess::canEdit(auth()->user(), $this->organization(), $this->event), 403);
    }

    private function authorizeRecord(): void
    {
        abort_if($this->organization()->isReadOnly(), 403);
        abort_unless($this->event->tracks_attendance && EventAccess::canRecord(auth()->user(), $this->organization(), $this->event), 403);
    }

    private function run(callable $action, ?string $message = null, string $field = 'counts.total'): bool
    {
        try {
            $action();
        } catch (InvalidArgumentException $e) {
            $this->addError($field, $e->getMessage());
            $this->notify($e->getMessage(), 'error');

            return false;
        }
        if ($message) {
            $this->notify($message);
        }

        return true;
    }

    // L'activité ------------------------------------------------------------------

    public function skip(Calendar $calendar): void
    {
        $this->authorizeEdit();
        $calendar->skip($this->event, $this->day());
        $this->notify(__('Cette date est annulée. Les autres restent prévues.'));
    }

    public function restore(Calendar $calendar): void
    {
        $this->authorizeEdit();
        $calendar->restore($this->event, $this->day());
        $this->notify(__('Cette date est rétablie.'));
    }

    public function deleteEvent()
    {
        $this->authorizeEdit();
        $this->event->delete();
        session()->flash('status', __('Activité supprimée du calendrier.'));

        return $this->redirectRoute('events.index', ['mois' => substr($this->date, 0, 7)]);
    }

    // Inscriptions ------------------------------------------------------------------

    private function canRegisterOthers(): bool
    {
        return ! $this->organization()->isReadOnly() && (EventAccess::canRecord(auth()->user(), $this->organization(), $this->event));
    }

    public function registerMember(Calendar $calendar, int $memberId): void
    {
        abort_unless($this->canRegisterOthers(), 403);
        if ($this->run(fn () => $calendar->register($this->event, $this->day(), Member::findOrFail($memberId)->id), __('Inscription enregistrée.'), 'registrationSearch')) {
            $this->registrationSearch = '';
        }
    }

    public function registerGuest(Calendar $calendar): void
    {
        abort_unless($this->canRegisterOthers(), 403);
        $this->validate(['guest.name' => 'required|string|max:150', 'guest.phone' => 'nullable|string|max:30'], attributes: ['guest.name' => __('nom')]);
        if ($this->run(fn () => $calendar->register($this->event, $this->day(), null, $this->guest['name'], $this->guest['phone']), __('Inscription enregistrée.'), 'guest.name')) {
            $this->guest = ['name' => '', 'phone' => ''];
        }
    }

    /** La personne s'inscrit elle-même, par sa fiche de membre. */
    public function registerMe(Calendar $calendar): void
    {
        abort_if($this->organization()->isReadOnly(), 403);
        $member = DepartmentScope::member(auth()->user(), $this->organization()) ?? abort(403);
        $this->run(fn () => $calendar->register($this->event, $this->day(), $member->id), __('Vous êtes inscrit(e).'), 'registrationSearch');
    }

    public function unregister(int $id): void
    {
        $registration = EventRegistration::where('event_id', $this->event->id)->findOrFail($id);
        $mine = $registration->member_id && $registration->member_id === DepartmentScope::member(auth()->user(), $this->organization())?->id;
        abort_unless($mine || $this->canRegisterOthers(), 403);
        $registration->delete();
        $this->notify(__('Inscription retirée.'));
    }

    // Présences -------------------------------------------------------------------------

    public function saveCounts(Calendar $calendar): void
    {
        $this->authorizeRecord();
        $this->validate([
            'counts.men' => 'nullable|integer|min:0|max:100000', 'counts.women' => 'nullable|integer|min:0|max:100000',
            'counts.children' => 'nullable|integer|min:0|max:100000', 'counts.visitors' => 'nullable|integer|min:0|max:100000',
            'counts.total' => 'nullable|integer|min:0|max:300000', 'counts.notes' => 'nullable|string|max:2000',
        ]);
        $this->run(function () use ($calendar) {
            $record = $calendar->record($this->event, $this->day());
            $calendar->saveCounts($record, $this->counts);
            $this->counts['total'] = $record->fresh()->total;
        }, __('Effectifs enregistrés.'));
    }

    public function toggleCheckin(Calendar $calendar, int $memberId): void
    {
        $this->authorizeRecord();
        $this->run(fn () => $calendar->toggleCheckin($calendar->record($this->event, $this->day()), $memberId));
    }

    public function addVisitor(Calendar $calendar): void
    {
        $this->authorizeRecord();
        $this->validate(['visitor.name' => 'required|string|max:150', 'visitor.phone' => 'nullable|string|max:30', 'visitor.invited_by' => 'nullable|string|max:150'],
            attributes: ['visitor.name' => __('nom')]);
        if ($this->run(fn () => $calendar->addVisitor($calendar->record($this->event, $this->day()), $this->visitor), __('Visiteur ajouté. Pensez à le revoir cette semaine.'), 'visitor.name')) {
            $this->visitor = ['name' => '', 'phone' => '', 'invited_by' => ''];
        }
    }

    public function removeVisitor(int $id): void
    {
        $this->authorizeRecord();
        AttendanceVisitor::whereHas('record', fn ($q) => $q->where('event_id', $this->event->id))->findOrFail($id)->delete();
    }

    public function followedUp(int $id): void
    {
        $this->authorizeRecord();
        $visitor = AttendanceVisitor::whereHas('record', fn ($q) => $q->where('event_id', $this->event->id))->findOrFail($id);
        $visitor->update(['followed_up_at' => $visitor->followed_up_at ? null : now()]);
    }

    public function render()
    {
        $organization = $this->organization();
        $user = auth()->user();
        $record = $this->record()?->load(['namedVisitors']);
        $checked = $record ? AttendanceCheckin::where('attendance_record_id', $record->id)->pluck('member_id')->all() : [];
        $term = trim($this->checkinSearch);
        $registrations = $this->event->registration
            ? EventRegistration::with('member')->where('event_id', $this->event->id)->whereDate('occurs_on', $this->date)->orderBy('id')->get() : collect();
        $me = DepartmentScope::member($user, $organization);
        $canRecord = $this->event->tracks_attendance && ! $organization->isReadOnly() && EventAccess::canRecord($user, $organization, $this->event);

        return view('livewire.events.show', [
            'day' => $this->day(),
            'skipped' => in_array($this->date, $this->event->skipped_dates ?? [], true),
            'record' => $record,
            'checked' => $checked,
            'checkedMembers' => $checked ? Member::withoutOrganizationScope()->whereIn('id', $checked)->orderBy('last_name')->get() : collect(),
            'checkinCandidates' => $canRecord && $term !== '' ? Member::search($term)->orderBy('last_name')->limit(12)->get() : collect(),
            'registrations' => $registrations,
            'registrationCandidates' => trim($this->registrationSearch) !== '' ? Member::search($this->registrationSearch)
                ->whereNotIn('id', $registrations->pluck('member_id')->filter())->orderBy('last_name')->limit(6)->get() : collect(),
            'me' => $me,
            'registered' => $me && $registrations->contains('member_id', $me->id),
            'canEdit' => ! $organization->isReadOnly() && EventAccess::canEdit($user, $organization, $this->event),
            'canRecord' => $canRecord,
            'canRegisterOthers' => $this->canRegisterOthers(),
        ] + $this->eventFormOptions())->title($this->event->title);
    }
}
