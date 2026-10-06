<?php

namespace App\Livewire\Meetings;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\Meeting;
use App\Models\MeetingDecision;
use App\Models\MeetingParticipant;
use App\Models\Member;
use App\Models\PlanAction;
use App\Support\FiscalYear;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

/** Une réunion : présents, ordre du jour, procès-verbal, décisions. */
class Show extends Component
{
    use WritesInOrganization;

    public Meeting $meeting;

    public array $form = [];

    public string $participantSearch = '';

    public string $participantName = '';

    public array $decision = [];

    public function mount(Meeting $meeting): void
    {
        abort_unless(Gate::any(['meetings.manage', 'planning.view']), 403);
        $this->meeting = $meeting;
        $this->form = [
            'title' => $meeting->title, 'held_at' => $meeting->held_at->format('Y-m-d\TH:i'), 'place' => (string) $meeting->place,
            'chair' => (string) $meeting->chair, 'secretary' => (string) $meeting->secretary,
            'agenda' => (string) $meeting->agenda, 'minutes' => (string) $meeting->minutes,
        ];
        $this->resetDecision();
    }

    private function canManage(): bool
    {
        return Gate::allows('meetings.manage') && ! $this->organization()->isReadOnly();
    }

    /** Chaque champ s'enregistre dès qu'on le quitte. */
    public function updatedForm($value, string $field): void
    {
        abort_unless($this->canManage(), 403);
        $rules = ['title' => 'required|string|max:200', 'held_at' => 'required|date', 'place' => 'nullable|string|max:150', 'chair' => 'nullable|string|max:150',
            'secretary' => 'nullable|string|max:150', 'agenda' => 'nullable|string|max:5000', 'minutes' => 'nullable|string|max:60000'];
        abort_unless(isset($rules[$field]), 422);
        $this->validate(["form.$field" => $rules[$field]], attributes: ["form.$field" => __('champ')]);
        $this->meeting->update([$field => $value === '' ? null : $value]);
    }

    public function addParticipant(?int $memberId = null): void
    {
        abort_unless($this->canManage(), 403);
        if ($memberId) {
            $member = Member::findOrFail($memberId);
            MeetingParticipant::firstOrCreate(['meeting_id' => $this->meeting->id, 'member_id' => $member->id]);
        } else {
            $this->validate(['participantName' => 'required|string|max:150'], attributes: ['participantName' => __('nom')]);
            MeetingParticipant::create(['meeting_id' => $this->meeting->id, 'name' => trim($this->participantName)]);
        }
        $this->participantSearch = '';
        $this->participantName = '';
    }

    /** Pour une réunion de département : tous ses membres en une fois. */
    public function addDepartmentMembers(): void
    {
        abort_unless($this->canManage() && $this->meeting->department_id, 403);
        $this->meeting->loadMissing('department');
        foreach ($this->meeting->department->members()->pluck('members.id') as $id) {
            MeetingParticipant::firstOrCreate(['meeting_id' => $this->meeting->id, 'member_id' => $id]);
        }
    }

    public function setAttendance(int $id, string $attendance): void
    {
        abort_unless($this->canManage() && array_key_exists($attendance, Meeting::ATTENDANCE), 403);
        $this->meeting->participants()->findOrFail($id)->update(['attendance' => $attendance]);
    }

    public function removeParticipant(int $id): void
    {
        abort_unless($this->canManage(), 403);
        $this->meeting->participants()->findOrFail($id)->delete();
    }

    private function resetDecision(): void
    {
        $this->decision = ['text' => '', 'responsible' => '', 'due_on' => '', 'plan_action_id' => ''];
    }

    public function addDecision(): void
    {
        abort_unless($this->canManage(), 403);
        $data = $this->validate([
            'decision.text' => 'required|string|max:2000',
            'decision.responsible' => 'nullable|string|max:150',
            'decision.due_on' => 'nullable|date',
            'decision.plan_action_id' => ['nullable', Rule::exists('plan_actions', 'id')->where('organization_id', $this->organization()->id)],
        ], attributes: ['decision.text' => __('décision')])['decision'];
        MeetingDecision::create(array_map(fn ($v) => $v === '' ? null : $v, $data) + ['meeting_id' => $this->meeting->id]);
        $this->resetDecision();
    }

    public function toggleDecision(int $id): void
    {
        abort_unless($this->canManage(), 403);
        $decision = $this->meeting->decisions()->findOrFail($id);
        $decision->update(['is_done' => ! $decision->is_done]);
    }

    public function removeDecision(int $id): void
    {
        abort_unless($this->canManage(), 403);
        $this->meeting->decisions()->findOrFail($id)->delete();
    }

    public function markHeld(): void
    {
        abort_unless($this->canManage(), 403);
        $this->meeting->update(['status' => $this->meeting->status === 'held' ? 'planned' : 'held']);
    }

    public function render()
    {
        $this->meeting->refresh()->load(['department', 'participants.member', 'decisions.action', 'author']);
        $year = FiscalYear::of($this->organization(), $this->meeting->held_at);
        $taken = $this->meeting->participants->pluck('member_id')->filter()->all();

        return view('livewire.meetings.show', [
            'canManage' => $this->canManage(),
            'candidates' => trim($this->participantSearch) !== '' ? Member::search($this->participantSearch)->whereNotIn('id', $taken)->orderBy('last_name')->limit(5)->get() : collect(),
            'actions' => PlanAction::whereHas('objective', fn ($q) => $q->whereIn('fiscal_year', [$year, $year + 1]))->orderBy('title')->get(),
            'counts' => $this->meeting->participants->countBy('attendance'),
        ])->title($this->meeting->title);
    }
}
