<?php

namespace App\Services;

use App\Models\DocumentType;
use App\Models\IssuedDocument;
use App\Models\Member;
use App\Models\Organization;
use App\Models\RegisterEntry;
use App\Support\DocumentStyles;
use App\Support\DocumentTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Délivrer un document : numéro suivant, texte figé, QR code de vérification. */
class Documents
{
    public function __construct(private DocumentTypes $types) {}

    /**
     * @param  array{member_id?: ?int, beneficiary?: ?string, fields?: array, signatory?: ?string, signatory_title?: ?string, issued_on?: ?string, person?: array, event?: array, data?: array}  $data
     */
    public function issue(Organization $organization, DocumentType $type, array $data): IssuedDocument
    {
        $member = ($data['member_id'] ?? null)
            ? Member::withoutOrganizationScope()->where('organization_id', $organization->id)->find($data['member_id']) : null;
        $entry = ($data['register_entry_id'] ?? null)
            ? RegisterEntry::withoutOrganizationScope()->with('register')->where('organization_id', $organization->id)->find($data['register_entry_id']) : null;
        // Réédition d'un acte d'un ancien registre : l'acte fait foi, même si la personne est aussi membre.
        if ($entry) {
            $member = null;
            $data['person'] = $entry->person();
            $data['event'] = $entry->event();
        }
        $person = $data['person'] ?? null;
        if ($type->subject === 'member' && ! $member) {
            throw new InvalidArgumentException(__('Choisissez le membre à qui délivrer ce document.'));
        }
        if ($type->subject === 'entry' && ! $member && ! $person) {
            throw new InvalidArgumentException(__('Choisissez le membre, ou l’acte de l’ancien registre.'));
        }
        $beneficiary = $member?->officialName() ?? ($person['official_name'] ?? null) ?? trim((string) ($data['beneficiary'] ?? ''));
        if ($beneficiary === '') {
            throw new InvalidArgumentException(__('Indiquez le destinataire du document.'));
        }
        foreach ($type->customFields() as $field) {
            if ($field['required'] && trim((string) ($data['fields'][$field['key']] ?? '')) === '') {
                throw new InvalidArgumentException(__('Remplissez le champ « :f ».', ['f' => $field['label']]));
            }
        }
        $issuedOn = Carbon::parse($data['issued_on'] ?? today())->startOfDay();
        if ($issuedOn->isFuture()) {
            throw new InvalidArgumentException(__('Un document ne se date pas dans l’avenir.'));
        }

        // La photo est copiée au moment de la délivrance : changer la photo du membre ne change pas un document déjà remis.
        $photo = null;
        $source = $member?->photo_path ?? ($entry?->member_id ? Member::withoutOrganizationScope()->find($entry->member_id)?->photo_path : null);
        if ($type->show_photo && $source && Storage::disk('local')->exists($source)) {
            $photo = 'documents/'.$organization->id.'/photo-'.Str::random(16).'.jpg';
            Storage::disk('local')->copy($source, $photo);
        }

        return DB::transaction(function () use ($organization, $type, $data, $member, $entry, $person, $beneficiary, $issuedOn, $photo) {
            $year = $issuedOn->year;
            $sequence = (int) IssuedDocument::withoutOrganizationScope()->where('organization_id', $organization->id)
                ->whereHas('type', fn ($q) => $q->where('code', $type->code))->where('year', $year)->lockForUpdate()->max('sequence') + 1;
            do {
                $number = $this->types->formatNumber($type, $organization, $year, $sequence);
                $taken = IssuedDocument::withoutOrganizationScope()->where('organization_id', $organization->id)->where('number', $number)->exists();
                $sequence += $taken ? 1 : 0;
            } while ($taken);

            $values = $this->types->values($type, $organization, [
                'member' => $member, 'person' => $member ? null : $person, 'event' => $data['event'] ?? null, 'fields' => $data['fields'] ?? [],
                'signatory' => trim((string) ($data['signatory'] ?? '')) ?: null, 'signatory_title' => trim((string) ($data['signatory_title'] ?? '')) ?: $type->signatory_title,
                'date' => $issuedOn, 'number' => $number,
            ]);

            return IssuedDocument::create([
                'organization_id' => $organization->id, 'document_type_id' => $type->id, 'number' => $number, 'year' => $year, 'sequence' => $sequence,
                'member_id' => $member?->id ?? $entry?->member_id, 'register_entry_id' => $entry?->id, 'beneficiary' => $beneficiary, 'title' => $type->title,
                'body' => DocumentTemplate::render($type->body, $values), 'photo_path' => $photo,
                'data' => ['fields' => $data['fields'] ?? [], 'headline' => DocumentStyles::headline($type, $values, $beneficiary)] + ($data['data'] ?? []),
                'signatory' => $values['signataire'], 'signatory_title' => $values['qualite_signataire'],
                'issued_on' => $issuedOn->toDateString(), 'token' => Str::random(32), 'issued_by' => auth()->id(),
            ]);
        });
    }

    public function cancel(IssuedDocument $document, string $reason): void
    {
        if ($document->isCancelled()) {
            throw new InvalidArgumentException(__('Ce document est déjà annulé.'));
        }
        $document->update(['cancelled_at' => now(), 'cancelled_by' => auth()->id(), 'cancel_reason' => $reason]);
    }

    /** Le dernier signataire utilisé dans la communauté, pour gagner du temps. */
    public function lastSignatory(Organization $organization): ?string
    {
        return IssuedDocument::withoutOrganizationScope()->where('organization_id', $organization->id)->whereNotNull('signatory')->latest('id')->value('signatory');
    }
}
