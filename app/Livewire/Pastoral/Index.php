<?php

namespace App\Livewire\Pastoral;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\Member;
use App\Models\PastoralCase;
use App\Models\PrayerRequest;
use App\Services\Notifier;
use App\Services\Pastoral;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Le suivi pastoral : les personnes accompagnées, les demandes de prière, les anniversaires. */
#[Title('Suivi pastoral')]
class Index extends Component
{
    use WritesInOrganization;

    #[Url(as: 'onglet')]
    public string $tab = 'suivis';

    #[Url(as: 'type')]
    public string $kind = '';

    #[Url(as: 'clos')]
    public bool $closed = false;

    public array $form = [];

    public string $memberSearch = '';

    public array $prayer = [];

    public ?int $answeringId = null;

    public string $answer = '';

    public function mount(): void
    {
        $this->authorize('pastoral.view');
        $memberId = (int) request()->query('membre');
        if ($memberId && ! $this->organization()->isReadOnly()) {
            $this->create($memberId);
        }
    }

    public function create(?int $memberId = null): void
    {
        $this->authorizeWrite('pastoral.view');
        $this->form = ['member_id' => $memberId ? Member::find($memberId)?->id : null, 'person_name' => '', 'person_phone' => '', 'kind' => 'visit', 'title' => '',
            'next_on' => today()->addWeek()->toDateString(), 'assigned_to' => auth()->id()];
        $this->memberSearch = '';
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'case');
    }

    public function chooseMember(int $id): void
    {
        $this->form['member_id'] = Member::findOrFail($id)->id;
        $this->memberSearch = '';
    }

    public function save(Pastoral $pastoral)
    {
        $this->authorizeWrite('pastoral.view');
        $this->validate([
            'form.kind' => ['required', Rule::in(array_keys(PastoralCase::KINDS))],
            'form.title' => 'required|string|max:160',
            'form.person_name' => 'nullable|string|max:150', 'form.person_phone' => 'nullable|string|max:30',
            'form.next_on' => 'nullable|date', 'form.assigned_to' => ['nullable', Rule::in($this->team()->pluck('id')->all())],
        ], attributes: ['form.title' => __('motif')]);
        try {
            $case = $pastoral->open($this->organization(), $this->form);
        } catch (InvalidArgumentException $e) {
            $this->addError('form.person_name', $e->getMessage());

            return null;
        }

        return $this->redirectRoute('pastoral.show', $case);
    }

    public function savePrayer(Pastoral $pastoral): void
    {
        $this->authorizeWrite('pastoral.view');
        $this->validate(['prayer.requester_name' => 'required|string|max:150', 'prayer.subject' => 'required|string|max:160', 'prayer.body' => 'nullable|string|max:2000'],
            attributes: ['prayer.requester_name' => __('nom'), 'prayer.subject' => __('sujet')]);
        $pastoral->pray($this->organization(), $this->prayer + ['is_private' => true]);
        $this->prayer = [];
        $this->dispatch('close-modal', name: 'prayer');
        $this->notify(__('Demande de prière enregistrée.'));
    }

    public function askAnswer(int $id): void
    {
        $this->authorizeWrite('pastoral.view');
        $this->answeringId = PrayerRequest::findOrFail($id)->id;
        $this->answer = '';
        $this->dispatch('open-modal', name: 'answer');
    }

    public function answerPrayer(Pastoral $pastoral): void
    {
        $this->authorizeWrite('pastoral.view');
        $this->validate(['answer' => 'nullable|string|max:1000']);
        $pastoral->answer(PrayerRequest::findOrFail($this->answeringId), $this->answer);
        $this->dispatch('close-modal', name: 'answer');
        $this->notify(__('Demande marquée comme portée dans la prière.'));
    }

    private function team()
    {
        return collect(app(Notifier::class)->withPermission($this->organization(), 'pastoral.view'))->push(auth()->user())->unique('id')->values();
    }

    public function render(Pastoral $pastoral)
    {
        $organization = $this->organization();
        $cases = PastoralCase::with(['member', 'assignee'])->withCount('notes')
            ->where('status', $this->closed ? 'closed' : 'open')
            ->when($this->kind !== '', fn ($q) => $q->where('kind', $this->kind))
            ->orderByRaw('next_on IS NULL')->orderBy('next_on')->latest('opened_on')->get();

        return view('livewire.pastoral.index', [
            'cases' => $cases,
            'counts' => PastoralCase::where('status', 'open')->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind'),
            'prayers' => $this->tab === 'priere' ? PrayerRequest::with('member')->orderByRaw("status = 'answered'")->latest()->limit(50)->get() : collect(),
            'openPrayers' => PrayerRequest::where('status', 'open')->count(),
            'birthdays' => $this->tab === 'anniversaires' ? $pastoral->birthdays($organization, today(), today()->addDays(30)) : collect(),
            'team' => $this->team(),
            'chosen' => ($this->form['member_id'] ?? null) ? Member::find($this->form['member_id']) : null,
            'candidates' => trim($this->memberSearch) !== '' ? Member::search($this->memberSearch)->orderBy('last_name')->limit(6)->get() : collect(),
            'canWrite' => ! $organization->isReadOnly(),
        ]);
    }
}
