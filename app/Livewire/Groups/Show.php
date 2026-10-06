<?php

namespace App\Livewire\Groups;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\Department;
use App\Models\Group;
use App\Models\GroupMeeting;
use App\Models\Member;
use App\Services\Groups;
use App\Support\GroupAccess;
use InvalidArgumentException;
use Livewire\Component;
use Livewire\WithPagination;

/** Un groupe : responsable, adjoints, membres, rencontres et présences. */
class Show extends Component
{
    use WithPagination, WritesInOrganization;

    public Group $group;

    public array $form = [];

    public string $memberSearch = '';

    public string $newRole = 'member';

    public string $leaderSearch = '';

    public array $meeting = [];

    public array $attendance = [];

    public function mount(Group $group): void
    {
        $visible = GroupAccess::visible(auth()->user(), $this->organization());
        abort_unless($visible === null || in_array($group->id, $visible, true), 403);
        $this->group = $group;
    }

    // Réglages et responsable ------------------------------------------------

    public function edit(): void
    {
        $this->authorizeSetUp();
        $this->form = $this->group->only(['name', 'kind', 'place']) + [
            'department_id' => $this->group->department_id ?? '', 'meeting_day' => $this->group->meeting_day ?? '',
            'meeting_time' => $this->group->meeting_time ? substr($this->group->meeting_time, 0, 5) : '', 'description' => (string) $this->group->description,
        ];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'group');
    }

    public function save(Groups $groups): void
    {
        $this->authorizeSetUp();
        $this->validate(Settings::rules($this->organization()), attributes: Settings::attributes());
        if (! Settings::departmentAllowed($this->organization(), $this->form['department_id'] ?? null)) {
            $this->addError('form.department_id', __('Choisissez l’un de vos départements.'));

            return;
        }
        $groups->update($this->group, $this->form);
        $this->dispatch('close-modal', name: 'group');
        $this->notify(__('Groupe enregistré.'));
    }

    public function changeLeader(Groups $groups, int $memberId): void
    {
        $this->authorizeSetUp();
        $groups->changeLeader($this->group, $memberId);
        $this->group->refresh();
        $this->leaderSearch = '';
        $this->dispatch('close-modal', name: 'leader');
        $this->notify(__(':name est le nouveau responsable du groupe.', ['name' => $this->group->leader->fullName()]));
    }

    public function toggleActive(): void
    {
        $this->authorizeSetUp();
        $this->group->update(['is_active' => ! $this->group->is_active]);
        $this->notify($this->group->is_active ? __('Groupe réactivé.') : __('Groupe mis en sommeil. Son historique est conservé.'));
    }

    public function delete()
    {
        $this->authorizeSetUp();
        $this->group->delete();
        session()->flash('status', __('Groupe supprimé.'));

        return $this->redirectRoute('groups.index');
    }

    // Membres ------------------------------------------------------------------

    public function addMember(Groups $groups, int $id): void
    {
        $this->authorizeManage();
        try {
            $member = $groups->addMember($this->group, $id, $this->newRole);
        } catch (InvalidArgumentException $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        }
        $this->memberSearch = '';
        $this->notify(__(':name ajouté(e) au groupe.', ['name' => $member->fullName()]));
    }

    public function setRole(Groups $groups, int $memberId, string $role): void
    {
        $this->authorizeManage();
        $groups->setRole($this->group, $memberId, $role);
    }

    public function removeMember(Groups $groups, int $memberId): void
    {
        $this->authorizeManage();
        try {
            $groups->removeMember($this->group, $memberId);
        } catch (InvalidArgumentException $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        }
        $this->notify(__('Retiré(e) du groupe.'));
    }

    // Rencontres et présences ----------------------------------------------------

    public function openMeeting(Groups $groups, ?int $id = null): void
    {
        $this->authorizeManage();
        $existing = $id ? GroupMeeting::where('group_id', $this->group->id)->with('attendances')->findOrFail($id) : null;
        $this->meeting = [
            'held_on' => ($existing?->held_on ?? today())->toDateString(), 'topic' => $existing->topic ?? '',
            'visitors' => $existing->visitors ?? 0, 'notes' => $existing->notes ?? '',
        ];
        $this->attendance = $groups->people($this->group)->mapWithKeys(fn (Member $m) => [
            $m->id => $existing?->attendances->firstWhere('member_id', $m->id)->status ?? 'absent',
        ])->all();
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'meeting');
    }

    public function allPresent(): void
    {
        $this->attendance = array_map(fn () => 'present', $this->attendance);
    }

    public function saveMeeting(Groups $groups): void
    {
        $this->authorizeManage();
        $this->validate([
            'meeting.held_on' => 'required|date|before_or_equal:today',
            'meeting.topic' => 'nullable|string|max:200',
            'meeting.visitors' => 'nullable|integer|min:0|max:5000',
            'meeting.notes' => 'nullable|string|max:3000',
        ], attributes: ['meeting.held_on' => __('date'), 'meeting.visitors' => __('visiteurs')]);
        $meeting = $groups->recordMeeting($this->group, $this->meeting, $this->attendance);
        $meeting->load('attendances');
        $this->dispatch('close-modal', name: 'meeting');
        $this->notify(__('Rencontre notée : :p présents sur :t.', ['p' => $meeting->presentCount(), 't' => $meeting->attendances->count()]));
    }

    public function deleteMeeting(int $id): void
    {
        $this->authorizeManage();
        GroupMeeting::where('group_id', $this->group->id)->findOrFail($id)->delete();
        $this->dispatch('close-modal', name: 'meeting');
        $this->notify(__('Rencontre supprimée.'));
    }

    private function authorizeManage(): void
    {
        abort_if($this->organization()->isReadOnly(), 403, __('Cette communauté est en lecture seule.'));
        abort_unless(GroupAccess::canManage(auth()->user(), $this->organization(), $this->group), 403);
    }

    private function authorizeSetUp(): void
    {
        $this->authorizeWrite('groups.manage');
        abort_unless(GroupAccess::canSetUp(auth()->user(), $this->organization(), $this->group), 403);
    }

    public function render(Groups $groups)
    {
        $organization = $this->organization();
        $user = auth()->user();
        $writable = ! $organization->isReadOnly();
        $people = $groups->people($this->group);
        $search = fn (string $term) => trim($term) !== ''
            ? Member::search($term)->whereNotIn('id', $people->pluck('id'))->orderBy('last_name')->limit(6)->get() : collect();
        $full = GroupAccess::full($user, $organization);

        return view('livewire.groups.show', [
            'people' => $people,
            'candidates' => $search($this->memberSearch),
            'leaderCandidates' => trim($this->leaderSearch) !== '' ? Member::search($this->leaderSearch)->where('id', '!=', $this->group->leader_member_id)->orderBy('last_name')->limit(6)->get() : collect(),
            'meetings' => $this->group->meetings()->with('attendances')->paginate(10),
            'absentees' => $groups->absentees($this->group),
            'rate' => $groups->rate($this->group),
            'canManage' => $writable && GroupAccess::canManage($user, $organization, $this->group),
            'canSetUp' => $writable && GroupAccess::canSetUp($user, $organization, $this->group) && $user->can('groups.manage'),
            'departments' => Department::where('is_active', true)->when(! $full, fn ($q) => $q->whereIn('id', GroupAccess::departments($user, $organization)))->orderBy('name')->get(),
            'full' => $full,
        ])->title($this->group->name);
    }
}
