<?php

namespace App\Livewire\Pastoral;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\PastoralCase;
use App\Models\PastoralNote;
use App\Services\Notifier;
use App\Services\Pastoral;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Component;

/** Un suivi : le fil des visites, appels et notes ; les notes confidentielles ne se lisent que par leur auteur. */
class Show extends Component
{
    use WritesInOrganization;

    public PastoralCase $case;

    public array $note = [];

    public function mount(PastoralCase $case): void
    {
        $this->authorize('pastoral.view');
        $this->case = $case;
        $this->resetNote();
    }

    private function resetNote(): void
    {
        $this->note = ['kind' => 'visit', 'happened_on' => today()->toDateString(), 'body' => '', 'is_confidential' => false,
            'next_on' => $this->case->next_on?->isFuture() ? $this->case->next_on->toDateString() : today()->addWeek()->toDateString()];
    }

    public function addNote(Pastoral $pastoral): void
    {
        $this->authorizeWrite('pastoral.view');
        $this->validate([
            'note.kind' => ['required', Rule::in(array_keys(PastoralNote::KINDS))], 'note.happened_on' => 'required|date|before_or_equal:today',
            'note.body' => 'required|string|max:5000', 'note.next_on' => 'nullable|date',
        ], attributes: ['note.body' => __('note'), 'note.happened_on' => __('date')]);
        try {
            $pastoral->addNote($this->case, auth()->user(), $this->note, Gate::allows('pastoral.confidential'));
        } catch (InvalidArgumentException $e) {
            $this->addError('note.body', $e->getMessage());

            return;
        }
        $this->case->refresh();
        $this->resetNote();
        $this->notify(__('Note ajoutée au suivi.'));
    }

    public function assign(Pastoral $pastoral, ?int $userId = null): void
    {
        $this->authorizeWrite('pastoral.view');
        abort_unless($userId === null || $this->team()->contains('id', $userId), 422);
        $pastoral->assign($this->case, $userId);
        $this->notify(__('Suivi confié.'));
    }

    public function close(Pastoral $pastoral): void
    {
        $this->authorizeWrite('pastoral.view');
        $pastoral->close($this->case);
        $this->notify(__('Suivi clos. Il reste consultable.'));
    }

    public function reopen(Pastoral $pastoral): void
    {
        $this->authorizeWrite('pastoral.view');
        $pastoral->reopen($this->case);
    }

    private function team()
    {
        return collect(app(Notifier::class)->withPermission($this->organization(), 'pastoral.view'))->push(auth()->user())->unique('id')->values();
    }

    public function render()
    {
        return view('livewire.pastoral.show', [
            'notes' => $this->case->notes()->with('author')->get(),
            'team' => $this->team(),
            'canWrite' => ! $this->organization()->isReadOnly(),
            'canConfidential' => Gate::allows('pastoral.confidential'),
            'others' => PastoralCase::where('member_id', $this->case->member_id)->whereNotNull('member_id')->whereKeyNot($this->case->id)->latest('opened_on')->get(),
        ])->title($this->case->personName());
    }
}
