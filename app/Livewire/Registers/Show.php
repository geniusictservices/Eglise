<?php

namespace App\Livewire\Registers;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\DocumentType;
use App\Models\Member;
use App\Models\Register;
use App\Models\RegisterEntry;
use App\Services\DocumentTypes;
use App\Services\Registers;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Un registre : la saisie rapide de ses actes, la recherche, le lien aux fiches, la réédition. */
class Show extends Component
{
    use WithPagination, WritesInOrganization;

    public Register $register;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'acte')]
    public ?int $openId = null;

    public array $entry = [];

    public ?int $editingId = null;

    public ?int $linkingId = null;

    public string $memberSearch = '';

    public function mount(Register $register): void
    {
        abort_unless(Gate::any(['registers.manage', 'documents.issue']), 403);
        $this->register = $register;
        $this->blank(app(Registers::class));
        if ($this->openId && Gate::allows('registers.manage')) {
            $this->edit($this->openId);
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /** Un formulaire vide : on garde la date, le lieu, l'officiant et la page, souvent communs à plusieurs actes. */
    private function blank(Registers $registers, array $keep = []): void
    {
        $this->editingId = null;
        $this->entry = array_merge([
            'entry_number' => $registers->nextNumber($this->register), 'page' => '', 'event_date' => '', 'place' => '', 'officiant' => '',
            'last_name' => '', 'middle_name' => '', 'first_name' => '', 'gender' => '', 'birth_date' => '', 'birth_place' => '',
            'father' => '', 'mother' => '', 'partner_name' => '', 'witnesses' => '', 'notes' => '',
        ], $keep);
    }

    public function edit(int $id): void
    {
        $this->authorizeWrite('registers.manage');
        $e = RegisterEntry::where('register_id', $this->register->id)->findOrFail($id);
        $this->editingId = $e->id;
        $this->openId = $e->id;
        $this->entry = collect($e->only(['entry_number', 'page', 'place', 'officiant', 'last_name', 'middle_name', 'first_name', 'gender', 'birth_place', 'father', 'mother', 'partner_name', 'witnesses', 'notes']))
            ->map(fn ($v) => (string) $v)->all() + ['event_date' => $e->event_date?->toDateString() ?? '', 'birth_date' => $e->birth_date?->toDateString() ?? ''];
        $this->resetValidation();
    }

    public function cancelEdit(Registers $registers): void
    {
        $this->openId = null;
        $this->blank($registers);
        $this->resetValidation();
    }

    public function save(Registers $registers): void
    {
        $this->authorizeWrite('registers.manage');
        $this->validate([
            'entry.entry_number' => 'required|string|max:20', 'entry.page' => 'nullable|string|max:20',
            'entry.event_date' => 'nullable|date|before_or_equal:today', 'entry.place' => 'nullable|string|max:150', 'entry.officiant' => 'nullable|string|max:120',
            'entry.last_name' => 'required|string|max:80', 'entry.middle_name' => 'nullable|string|max:80', 'entry.first_name' => 'nullable|string|max:80',
            'entry.birth_date' => 'nullable|date|before_or_equal:today', 'entry.birth_place' => 'nullable|string|max:100',
            'entry.father' => 'nullable|string|max:150', 'entry.mother' => 'nullable|string|max:150', 'entry.partner_name' => 'nullable|string|max:200',
            'entry.witnesses' => 'nullable|string|max:255', 'entry.notes' => 'nullable|string|max:2000',
        ], attributes: ['entry.entry_number' => __('numéro de l’acte'), 'entry.last_name' => __('nom'), 'entry.event_date' => __('date'), 'entry.birth_date' => __('date de naissance')]);

        try {
            $saved = $registers->save($this->register, $this->entry, $this->editingId ? RegisterEntry::findOrFail($this->editingId) : null);
        } catch (InvalidArgumentException $e) {
            $this->addError('entry.entry_number', $e->getMessage());

            return;
        }
        $editing = (bool) $this->editingId;
        $this->openId = null;
        $this->blank($registers, $editing ? [] : collect($this->entry)->only(['page', 'event_date', 'place', 'officiant'])->all());
        $this->notify($editing ? __('Acte n° :n corrigé.', ['n' => $saved->entry_number]) : __('Acte n° :n enregistré. Au suivant !', ['n' => $saved->entry_number]));
        $this->dispatch('entry-saved');
    }

    public function delete(Registers $registers, int $id): void
    {
        $this->authorizeWrite('registers.manage');
        RegisterEntry::where('register_id', $this->register->id)->findOrFail($id)->delete();
        $this->cancelEdit($registers);
        $this->notify(__('Acte supprimé.'));
    }

    public function askLink(int $id): void
    {
        $this->authorizeWrite('registers.manage');
        $this->linkingId = RegisterEntry::where('register_id', $this->register->id)->findOrFail($id)->id;
        $e = RegisterEntry::find($this->linkingId);
        $this->memberSearch = trim($e->last_name.' '.$e->first_name);
        $this->dispatch('open-modal', name: 'link');
    }

    public function link(Registers $registers, int $memberId): void
    {
        $this->authorizeWrite('registers.manage');
        $registers->link(RegisterEntry::where('register_id', $this->register->id)->findOrFail($this->linkingId), $memberId);
        $this->dispatch('close-modal', name: 'link');
        $this->notify(__('Acte relié à la fiche du membre ; son étape de vie porte maintenant la référence du registre.'));
    }

    public function unlink(Registers $registers, int $id): void
    {
        $this->authorizeWrite('registers.manage');
        $registers->unlink(RegisterEntry::where('register_id', $this->register->id)->findOrFail($id));
    }

    public function render(DocumentTypes $types)
    {
        $term = trim($this->search);
        $entries = RegisterEntry::with('member')->where('register_id', $this->register->id)
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('last_name', 'like', "%{$term}%")->orWhere('first_name', 'like', "%{$term}%")
                ->orWhere('middle_name', 'like', "%{$term}%")->orWhere('entry_number', $term)))
            ->orderByRaw('CAST(entry_number AS UNSIGNED) DESC')->orderByDesc('id')->paginate(25);
        // Le modèle d'attestation qui correspond à ce registre, pour rééditer un acte.
        $reissue = $types->available($this->organization())->first(fn (DocumentType $t) => $t->subject === 'entry' && $t->life_event_type === $this->register->kind);

        return view('livewire.registers.show', [
            'entries' => $entries,
            'canManage' => Gate::allows('registers.manage') && ! $this->organization()->isReadOnly(),
            'canIssue' => Gate::allows('documents.issue') && ! $this->organization()->isReadOnly(),
            'reissue' => $reissue,
            'candidates' => $this->linkingId && trim($this->memberSearch) !== '' ? Member::search($this->memberSearch)->orderBy('last_name')->limit(8)->get() : collect(),
        ])->title($this->register->name);
    }
}
