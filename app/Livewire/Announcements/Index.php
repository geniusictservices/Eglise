<?php

namespace App\Livewire\Announcements;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\Announcement;
use App\Models\Department;
use App\Models\Event;
use App\Models\Group;
use App\Services\Announcements;
use App\Services\Notifier;
use App\Support\AnnouncementAccess;
use App\Support\EventAccess;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Les annonces de la communauté, à lire ici et à partager sur WhatsApp. */
#[Title('Annonces')]
class Index extends Component
{
    use WithPagination, WritesInOrganization;

    #[Url(as: 'anciennes')]
    public bool $archived = false;

    public array $form = [];

    public ?int $editingId = null;

    public function mount(): void
    {
        $this->authorize('organization.view');
        // Annoncer une activité depuis le calendrier : ?activite=12&date=2026-10-25
        $eventId = (int) request()->query('activite');
        $date = (string) request()->query('date');
        if ($eventId && AnnouncementAccess::canCreate(auth()->user(), $this->organization())) {
            $event = Event::find($eventId);
            if ($event && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && $event->occursOn($date)) {
                $this->create($event, Carbon::parse($date));
            }
        }
    }

    public function create(?Event $event = null, ?Carbon $date = null): void
    {
        $this->authorizeCreate();
        $scopes = EventAccess::scopes(auth()->user(), $this->organization());
        $full = AnnouncementAccess::full(auth()->user(), $this->organization());
        $this->editingId = null;
        $this->form = [
            'title' => $event ? $event->title : '', 'body' => $event ? (string) $event->description : '',
            'audience' => $event && ($full || $event->audience !== 'all') ? $event->audience : ($full ? 'all' : ($scopes['departments'] ? 'department' : 'group')),
            'department_id' => $event?->department_id ?? ($scopes['departments'][0] ?? ''), 'group_id' => $event?->group_id ?? ($scopes['groups'][0] ?? ''),
            'event_id' => $event?->id, 'event_date' => $date?->toDateString(), 'pinned' => false,
            'expires_on' => $date ? $date->copy()->addDay()->toDateString() : today()->addWeeks(2)->toDateString(),
        ];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'announcement');
    }

    public function edit(int $id): void
    {
        $announcement = Announcement::findOrFail($id);
        $this->authorizeEdit($announcement);
        $this->editingId = $announcement->id;
        $this->form = $announcement->only(['title', 'body', 'audience', 'event_id', 'pinned']) + [
            'department_id' => $announcement->department_id ?? '', 'group_id' => $announcement->group_id ?? '',
            'event_date' => $announcement->event_date?->toDateString(), 'expires_on' => $announcement->expires_on?->toDateString() ?? '',
        ];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'announcement');
    }

    public function save(Announcements $announcements): void
    {
        $organization = $this->organization();
        $this->validate([
            'form.title' => 'required|string|max:150',
            'form.body' => 'required|string|max:4000',
            'form.audience' => ['required', Rule::in(['all', 'department', 'group'])],
            'form.department_id' => ['required_if:form.audience,department', 'nullable', Rule::exists('departments', 'id')->where('organization_id', $organization->id)],
            'form.group_id' => ['required_if:form.audience,group', 'nullable', Rule::exists('groups', 'id')->where('organization_id', $organization->id)],
            'form.expires_on' => 'nullable|date|after_or_equal:today',
        ], attributes: ['form.title' => __('titre'), 'form.body' => __('message'), 'form.expires_on' => __('visible jusqu’au'),
            'form.department_id' => __('département'), 'form.group_id' => __('groupe')]);
        $existing = $this->editingId ? Announcement::findOrFail($this->editingId) : null;
        $existing ? $this->authorizeEdit($existing) : $this->authorizeCreate();
        if (! AnnouncementAccess::allowsAudience(auth()->user(), $organization, $this->form['audience'],
            $this->form['audience'] === 'department' ? (int) $this->form['department_id'] : null, $this->form['audience'] === 'group' ? (int) $this->form['group_id'] : null)) {
            $this->addError('form.audience', __('Annoncez à l’un de vos départements ou de vos groupes.'));

            return;
        }
        $form = $this->form;
        if (! AnnouncementAccess::full(auth()->user(), $organization)) {
            $form['pinned'] = false;
        }
        $announcement = $announcements->save($organization, $form, $existing);
        $this->dispatch('close-modal', name: 'announcement');
        $this->notify($existing ? __('Annonce modifiée.') : trans_choice('Annonce publiée : :count personne prévenue dans Waumini. Partagez-la aussi sur WhatsApp.|Annonce publiée : :count personnes prévenues dans Waumini. Partagez-la aussi sur WhatsApp.', $announcement->recipients));
    }

    public function delete(int $id): void
    {
        $announcement = Announcement::findOrFail($id);
        $this->authorizeEdit($announcement);
        $announcement->delete();
        app(Notifier::class)->settle("announcement.{$id}");
        $this->notify(__('Annonce supprimée.'));
    }

    private function authorizeCreate(): void
    {
        abort_if($this->organization()->isReadOnly(), 403);
        abort_unless(AnnouncementAccess::canCreate(auth()->user(), $this->organization()), 403);
    }

    private function authorizeEdit(Announcement $announcement): void
    {
        abort_if($this->organization()->isReadOnly(), 403);
        abort_unless(AnnouncementAccess::canEdit(auth()->user(), $this->organization(), $announcement), 403);
    }

    public function render()
    {
        $organization = $this->organization();
        $user = auth()->user();
        $full = AnnouncementAccess::full($user, $organization);
        $scopes = EventAccess::scopes($user, $organization);
        $items = Announcement::with(['department', 'group', 'event', 'author'])->whereNotNull('published_at')
            ->where(AnnouncementAccess::visibleQuery($user, $organization))
            ->when($this->archived, fn ($q) => $q->whereDate('expires_on', '<', today()), fn ($q) => $q->current())
            ->orderByDesc('pinned')->latest('published_at')->paginate(15);

        return view('livewire.announcements.index', [
            'items' => $items,
            'canCreate' => ! $organization->isReadOnly() && AnnouncementAccess::canCreate($user, $organization),
            'full' => $full,
            'departments' => Department::where('is_active', true)->when(! $full, fn ($q) => $q->whereIn('id', $scopes['departments']))->orderBy('name')->get(),
            'groups' => Group::where('is_active', true)->when(! $full, fn ($q) => $q->whereIn('id', $scopes['groups']))->orderBy('name')->get(),
            'audiences' => collect(['all' => __('Toute la communauté'), 'department' => __('Un département'), 'group' => __('Un groupe')])
                ->filter(fn ($l, $k) => $full || ($k === 'department' && $scopes['departments']) || ($k === 'group' && $scopes['groups']))->all(),
            'linkedEvent' => ($this->form['event_id'] ?? null) ? Event::find($this->form['event_id']) : null,
        ]);
    }
}
