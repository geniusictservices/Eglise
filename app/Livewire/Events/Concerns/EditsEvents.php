<?php

namespace App\Livewire\Events\Concerns;

use App\Models\Department;
use App\Models\Event;
use App\Models\Group;
use App\Services\Calendar;
use App\Support\EventAccess;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** Le formulaire d'une activité, commun au calendrier et à la page d'une date. */
trait EditsEvents
{
    public array $eventForm = [];

    public ?int $editingEventId = null;

    public function openEventForm(?int $id = null, ?string $date = null): void
    {
        $event = $id ? Event::findOrFail($id) : null;
        abort_if($this->organization()->isReadOnly(), 403);
        abort_unless($event ? EventAccess::canEdit(auth()->user(), $this->organization(), $event) : EventAccess::canCreate(auth()->user(), $this->organization()), 403);
        $this->editingEventId = $event?->id;
        $full = EventAccess::full(auth()->user(), $this->organization());
        $scopes = EventAccess::scopes(auth()->user(), $this->organization());
        $this->eventForm = $event ? [
            'title' => $event->title, 'kind' => $event->kind, 'starts_on' => $event->starts_on->toDateString(), 'ends_on' => $event->ends_on?->toDateString() ?? '',
            'start_time' => $event->start_time ? substr($event->start_time, 0, 5) : '', 'end_time' => $event->end_time ? substr($event->end_time, 0, 5) : '',
            'place' => (string) $event->place, 'description' => (string) $event->description, 'audience' => $event->audience,
            'department_id' => $event->department_id ?? '', 'group_id' => $event->group_id ?? '', 'repeats' => $event->repeats,
            'repeat_until' => $event->repeat_until?->toDateString() ?? '', 'registration' => $event->registration, 'capacity' => $event->capacity ?? '',
            'tracks_attendance' => $event->tracks_attendance,
        ] : [
            'title' => '', 'kind' => 'service', 'starts_on' => $date ?? today()->toDateString(), 'ends_on' => '', 'start_time' => '09:00', 'end_time' => '',
            'place' => '', 'description' => '', 'audience' => $full ? 'all' : ($scopes['departments'] ? 'department' : 'group'),
            'department_id' => $scopes['departments'][0] ?? '', 'group_id' => $scopes['groups'][0] ?? '', 'repeats' => 'none', 'repeat_until' => '',
            'registration' => false, 'capacity' => '', 'tracks_attendance' => true,
        ];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'event');
    }

    public function saveEvent(Calendar $calendar)
    {
        $organization = $this->organization();
        $this->validate([
            'eventForm.title' => 'required|string|max:150',
            'eventForm.kind' => ['required', Rule::in(array_keys(Event::KINDS))],
            'eventForm.starts_on' => 'required|date',
            'eventForm.ends_on' => 'nullable|date|after_or_equal:eventForm.starts_on',
            'eventForm.start_time' => 'nullable|date_format:H:i',
            'eventForm.end_time' => 'nullable|date_format:H:i',
            'eventForm.place' => 'nullable|string|max:160',
            'eventForm.description' => 'nullable|string|max:3000',
            'eventForm.audience' => ['required', Rule::in(array_keys(Event::AUDIENCES))],
            'eventForm.department_id' => ['required_if:eventForm.audience,department', 'nullable', Rule::exists('departments', 'id')->where('organization_id', $organization->id)],
            'eventForm.group_id' => ['required_if:eventForm.audience,group', 'nullable', Rule::exists('groups', 'id')->where('organization_id', $organization->id)],
            'eventForm.repeats' => ['required', Rule::in(array_keys(Event::REPEATS))],
            'eventForm.repeat_until' => 'nullable|date|after:eventForm.starts_on',
            'eventForm.capacity' => 'nullable|integer|min:1|max:100000',
        ], attributes: ['eventForm.title' => __('titre'), 'eventForm.starts_on' => __('date'), 'eventForm.ends_on' => __('fin'),
            'eventForm.department_id' => __('département'), 'eventForm.group_id' => __('groupe'), 'eventForm.repeat_until' => __('jusqu’au')]);

        $form = $this->eventForm;
        $event = $this->editingEventId ? Event::findOrFail($this->editingEventId) : null;
        abort_if($organization->isReadOnly(), 403);
        abort_unless($event ? EventAccess::canEdit(auth()->user(), $organization, $event) : EventAccess::canCreate(auth()->user(), $organization), 403);
        if (! EventAccess::allowsAudience(auth()->user(), $organization, $form['audience'],
            $form['audience'] === 'department' ? (int) $form['department_id'] : null, $form['audience'] === 'group' ? (int) $form['group_id'] : null)) {
            $this->addError('eventForm.audience', __('Programmez pour l’un de vos départements ou de vos groupes.'));

            return null;
        }

        try {
            $event = $calendar->save($organization, $form, $event);
        } catch (InvalidArgumentException $e) {
            $this->addError('eventForm.ends_on', $e->getMessage());

            return null;
        }
        $this->dispatch('close-modal', name: 'event');
        session()->flash('status', __('Activité enregistrée.'));
        $back = $this->eventReturnDate();
        if (! $back || ! $event->occursOn($back)) {
            $from = $event->starts_on->isPast() ? today() : $event->starts_on;
            $back = ($event->occurrences($from, $from->copy()->addYears(2))[0] ?? $event->starts_on)->toDateString();
        }

        return $this->redirectRoute('events.show', ['event' => $event, 'date' => $back]);
    }

    /** La date à rouvrir après l'enregistrement, si elle existe encore. */
    protected function eventReturnDate(): ?string
    {
        return null;
    }

    /** Les listes du formulaire, limitées à ce que la personne peut programmer. */
    protected function eventFormOptions(): array
    {
        $organization = $this->organization();
        $full = EventAccess::full(auth()->user(), $organization);
        $scopes = EventAccess::scopes(auth()->user(), $organization);

        return [
            'formDepartments' => Department::where('is_active', true)->when(! $full, fn ($q) => $q->whereIn('id', $scopes['departments']))->orderBy('name')->get(),
            'formGroups' => Group::where('is_active', true)->when(! $full, fn ($q) => $q->whereIn('id', $scopes['groups']))->orderBy('name')->get(),
            'formAudiences' => collect(Event::AUDIENCES)->filter(fn ($l, $k) => $full || ($k === 'department' && $scopes['departments']) || ($k === 'group' && $scopes['groups']))->all(),
        ];
    }
}
