<?php

namespace App\Livewire\Documents;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\Member;
use App\Models\RegisterEntry;
use App\Services\Documents;
use App\Services\DocumentTypes;
use App\Services\MemberAccounts;
use App\Support\DocumentTemplate;
use InvalidArgumentException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Délivrer un document : le modèle, la personne, les champs, l'aperçu, puis l'impression. */
#[Title('Délivrer un document')]
class Issue extends Component
{
    use WritesInOrganization;

    #[Url(as: 'modele')]
    public ?int $typeId = null;

    #[Url(as: 'membre')]
    public ?int $memberId = null;

    #[Url(as: 'demande')]
    public ?int $requestId = null;

    #[Url(as: 'acte')]
    public ?int $entryId = null;

    public string $memberSearch = '';

    public string $entrySearch = '';

    public string $beneficiary = '';

    public array $fields = [];

    public string $signatory = '';

    public string $signatoryTitle = '';

    public string $issuedOn = '';

    public function mount(Documents $documents): void
    {
        $this->authorizeWrite('documents.issue');
        $this->signatory = (string) $documents->lastSignatory($this->organization());
        $this->issuedOn = today()->toDateString();
        if ($this->typeId) {
            $this->chooseType($this->typeId);
        }
    }

    private function type(): ?DocumentType
    {
        return $this->typeId ? app(DocumentTypes::class)->available($this->organization())->firstWhere('id', $this->typeId) : null;
    }

    public function chooseType(int $id): void
    {
        $type = app(DocumentTypes::class)->available($this->organization())->firstWhere('id', $id) ?? abort(404);
        $this->typeId = $type->id;
        $this->signatoryTitle = (string) $type->signatory_title;
        $this->fields = collect($type->customFields())->mapWithKeys(fn ($f) => [$f['key'] => $this->fields[$f['key']] ?? ''])->all();
        if ($type->subject === 'free') {
            $this->memberId = null;
        }
        $this->resetValidation();
    }

    public function changeType(): void
    {
        $this->typeId = null;
    }

    public function chooseMember(int $id): void
    {
        $this->memberId = Member::findOrFail($id)->id;
        $this->entryId = null;
        $this->memberSearch = '';
    }

    public function chooseEntry(int $id): void
    {
        $this->entryId = RegisterEntry::findOrFail($id)->id;
        $this->memberId = null;
        $this->entrySearch = '';
    }

    public function issue(Documents $documents)
    {
        $this->authorizeWrite('documents.issue');
        $type = $this->type() ?? abort(404);
        $this->validate([
            'signatory' => 'nullable|string|max:120', 'signatoryTitle' => 'nullable|string|max:80',
            'issuedOn' => 'required|date|before_or_equal:today', 'beneficiary' => 'nullable|string|max:200', 'fields.*' => 'nullable|string|max:3000',
        ], attributes: ['issuedOn' => __('date')]);

        try {
            $document = $documents->issue($this->organization(), $type, [
                'member_id' => $this->memberId, 'register_entry_id' => $type->subject === 'entry' ? $this->entryId : null, 'beneficiary' => $this->beneficiary, 'fields' => $this->fields,
                'signatory' => $this->signatory, 'signatory_title' => $this->signatoryTitle, 'issued_on' => $this->issuedOn,
            ]);
        } catch (InvalidArgumentException $e) {
            $this->addError('issue', $e->getMessage());

            return null;
        }

        // Une demande venue de l'espace membre est servie : la personne est prévenue.
        if ($this->requestId && ($request = DocumentRequest::where('status', 'pending')->find($this->requestId))) {
            app(MemberAccounts::class)->fulfil($request, $document);
        }

        return $this->redirectRoute('documents.print', $document);
    }

    private function searchEntries(?string $kind)
    {
        $words = preg_split('/\s+/', trim($this->entrySearch));

        return RegisterEntry::with('register')
            ->when($kind, fn ($q) => $q->whereHas('register', fn ($q) => $q->where('kind', $kind)))
            ->where(function ($q) use ($words) {
                foreach ($words as $word) {
                    $q->where(fn ($q) => $q->where('last_name', 'like', "%{$word}%")->orWhere('first_name', 'like', "%{$word}%")
                        ->orWhere('middle_name', 'like', "%{$word}%")->orWhere('entry_number', $word));
                }
            })->orderBy('last_name')->limit(8)->get();
    }

    public function render(DocumentTypes $types)
    {
        $organization = $this->organization();
        $type = $this->type();
        $entry = $type?->subject === 'entry' && $this->entryId ? RegisterEntry::with('register')->find($this->entryId) : null;
        $member = ! $entry && $this->memberId ? Member::find($this->memberId) : null;
        $data = [];
        if ($type) {
            $values = $types->values($type, $organization, [
                'member' => $member, 'event' => $entry?->event(),
                'person' => $member ? null : ($entry ? $entry->person() : ($type->subject === 'free' ? ['official_name' => $this->beneficiary, 'full_name' => $this->beneficiary] : [])),
                'fields' => $this->fields, 'signatory' => $this->signatory ?: null, 'signatory_title' => $this->signatoryTitle ?: null,
                'date' => $this->issuedOn ?: today(), 'number' => __('attribué à la délivrance'),
            ]);
            $labels = collect(DocumentTemplate::variables())->collapse()->merge(collect($type->customFields())->pluck('label', 'key'));
            $data = [
                'preview' => DocumentTemplate::render($type->body, $values),
                'values' => $values,
                // Ce que la fiche ne dit pas encore : à compléter dans la fiche, ou à la main sur le papier.
                'missing' => collect(DocumentTemplate::used($type->body))->filter(fn ($k) => ($values[$k] ?? null) === null && ! in_array($k, ['numero_document', 'signataire'], true))
                    ->map(fn ($k) => $labels[$k] ?? $k)->values()->all(),
            ];
        }

        // La photo du membre, si le modèle la demande : celle de sa fiche, ou celle du membre relié à l'acte.
        $photoMember = $type?->show_photo ? ($member ?? ($entry?->member_id ? Member::find($entry->member_id) : null)) : null;

        return view('livewire.documents.issue', $data + [
            'photo' => $photoMember?->photo_path ? route('members.photo', $photoMember) : null,
            'photoMissing' => $type?->show_photo && ($member || $entry) && ! $photoMember?->photo_path,
            'types' => $types->available($organization),
            'type' => $type,
            'member' => $member,
            'entry' => $entry,
            'entryCandidates' => $type?->subject === 'entry' && trim($this->entrySearch) !== '' ? $this->searchEntries($type->life_event_type) : collect(),
            'candidates' => trim($this->memberSearch) !== '' ? Member::search($this->memberSearch)->orderBy('last_name')->limit(6)->get() : collect(),
            'identity' => $organization->documentIdentity(),
            'organization' => $organization,
        ]);
    }
}
