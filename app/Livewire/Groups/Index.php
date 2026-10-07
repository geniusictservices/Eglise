<?php

namespace App\Livewire\Groups;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\Department;
use App\Models\Group;
use App\Models\Member;
use App\Services\Groups;
use App\Support\GroupAccess;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Les groupes de la communauté : cellules, chorales, groupes de prière… */
#[Title('Groupes')]
class Index extends Component
{
    use EditsSchedule, WritesInOrganization;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'type')]
    public string $kind = '';

    public array $form = [];

    public string $leaderSearch = '';

    public function mount(): void
    {
        abort_unless(Gate::any(['groups.manage', 'members.view', 'attendance.record']) || GroupAccess::led(auth()->user(), $this->organization()), 403);
    }

    public function create(): void
    {
        $this->authorizeSetUp();
        $this->form = ['name' => '', 'kind' => 'cell', 'department_id' => '', 'leader_member_id' => null, 'schedule' => self::scheduleRows(null), 'place' => '', 'description' => '', 'dues_amount' => '', 'dues_currency' => 'USD'];
        $this->leaderSearch = '';
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'group');
    }

    public function chooseLeader(int $id): void
    {
        $this->form['leader_member_id'] = Member::findOrFail($id)->id;
        $this->leaderSearch = '';
    }

    public function save(Groups $groups)
    {
        $this->authorizeSetUp();
        $this->validate(Settings::rules($this->organization(), true), ['form.leader_member_id.required' => __('Un groupe a toujours un responsable : choisissez-le parmi les membres.')], Settings::attributes());
        if (! Settings::departmentAllowed($this->organization(), $this->form['department_id'] ?? null)) {
            $this->addError('form.department_id', __('Choisissez l’un de vos départements.'));

            return null;
        }

        try {
            $group = $groups->create($this->organization(), $this->form);
        } catch (InvalidArgumentException $e) {
            $this->addError('form.leader_member_id', $e->getMessage());

            return null;
        }
        session()->flash('status', __('Groupe créé. Ajoutez maintenant ses membres.'));

        return $this->redirectRoute('groups.show', $group);
    }

    private function authorizeSetUp(): void
    {
        $this->authorizeWrite('groups.manage');
        abort_unless(GroupAccess::canSetUp(auth()->user(), $this->organization()), 403);
    }

    public function render(Groups $groups)
    {
        $organization = $this->organization();
        $visible = GroupAccess::visible(auth()->user(), $organization);
        $list = Group::with(['leader', 'department', 'latestMeeting.attendances'])->withCount('members')
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->when($this->kind !== '', fn ($q) => $q->where('kind', $this->kind))
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.trim($this->search).'%')
                ->orWhereHas('leader', fn ($q) => $q->search($this->search))))
            ->orderByDesc('is_active')->orderBy('name')->get();
        $full = GroupAccess::full(auth()->user(), $organization);

        return view('livewire.groups.index', [
            'groups' => $list,
            'canSetUp' => GroupAccess::canSetUp(auth()->user(), $organization) && ! $organization->isReadOnly(),
            'departments' => Department::where('is_active', true)->when(! $full, fn ($q) => $q->whereIn('id', GroupAccess::departments(auth()->user(), $organization)))->orderBy('name')->get(),
            'leader' => ($this->form['leader_member_id'] ?? null) ? Member::find($this->form['leader_member_id']) : null,
            'candidates' => trim($this->leaderSearch) !== '' ? Member::search($this->leaderSearch)->orderBy('last_name')->limit(5)->get() : collect(),
            'full' => $full,
        ]);
    }
}
