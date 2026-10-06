<?php

namespace App\Livewire\Meetings;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\Department;
use App\Models\Meeting;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Les réunions : à venir et passées, avec leurs décisions. */
#[Title('Réunions')]
class Index extends Component
{
    use WritesInOrganization;

    public array $form = [];

    public function mount(): void
    {
        abort_unless(Gate::any(['meetings.manage', 'planning.view']), 403);
    }

    public function create(): void
    {
        $this->authorizeWrite('meetings.manage');
        $this->form = ['title' => '', 'kind' => 'council', 'department_id' => '', 'held_at' => now()->addWeek()->setTime(15, 0)->format('Y-m-d\TH:i'), 'place' => ''];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'meeting');
    }

    public function save()
    {
        $this->authorizeWrite('meetings.manage');
        $data = $this->validate([
            'form.title' => 'required|string|max:200',
            'form.kind' => ['required', Rule::in(array_keys(Meeting::KINDS))],
            'form.department_id' => [Rule::requiredIf(($this->form['kind'] ?? '') === 'department'), 'nullable', Rule::exists('departments', 'id')->where('organization_id', $this->organization()->id)],
            'form.held_at' => 'required|date',
            'form.place' => 'nullable|string|max:150',
        ], attributes: ['form.title' => __('objet'), 'form.held_at' => __('date'), 'form.department_id' => __('département')])['form'];

        $meeting = Meeting::create(array_map(fn ($v) => $v === '' ? null : $v, $data) + ['created_by' => auth()->id()]);

        return $this->redirectRoute('meetings.show', $meeting);
    }

    public function render()
    {
        $meetings = Meeting::with(['department', 'decisions'])->withCount('participants')->orderByDesc('held_at')->limit(100)->get();

        return view('livewire.meetings.index', [
            'upcoming' => $meetings->filter(fn ($m) => $m->status === 'planned' && $m->held_at->isFuture())->sortBy('held_at'),
            'past' => $meetings->reject(fn ($m) => $m->status === 'planned' && $m->held_at->isFuture()),
            'openDecisions' => $meetings->flatMap->decisions->where('is_done', false)->count(),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(),
            'canManage' => Gate::allows('meetings.manage') && ! $this->organization()->isReadOnly(),
        ]);
    }
}
