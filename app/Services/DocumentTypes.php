<?php

namespace App\Services;

use App\Models\DocumentType;
use App\Models\LifeEvent;
use App\Models\Member;
use App\Models\Organization;
use App\Support\DefaultDocumentTypes;
use App\Support\DocumentStyles;
use App\Support\DocumentTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/** Les modèles de documents d'une communauté et le calcul de leurs variables. */
class DocumentTypes
{
    public function __construct(private MemberRegistry $registry) {}

    /**
     * Les modèles utilisables à ce niveau : les siens et ceux de ses niveaux
     * supérieurs, sauf ceux qu'il a adaptés (sa copie les remplace).
     *
     * @return Collection<int, DocumentType>
     */
    public function available(Organization $organization, bool $withInactive = false): Collection
    {
        $this->installDefaults($organization->root());
        $lineage = [...$organization->ancestorIds(), $organization->id];
        $types = DocumentType::with('organization')->whereIn('organization_id', $lineage)
            ->when(! $withInactive, fn ($q) => $q->where('is_active', true))->get();
        // Le niveau le plus proche l'emporte : une copie remplace l'original, et s'il est inactif, l'original reste masqué.
        $replaced = DocumentType::whereIn('organization_id', $lineage)->whereNotNull('replaces_id')->pluck('replaces_id')->all();

        return $types->reject(fn (DocumentType $t) => in_array($t->id, $replaced, true))
            ->sortBy(fn (DocumentType $t) => sprintf('%05d %s', $t->position, $t->name))->values();
    }

    public function installDefaults(Organization $root): void
    {
        if (DocumentType::withTrashed()->where('organization_id', $root->id)->exists()) {
            return;
        }
        foreach (DefaultDocumentTypes::all() as $i => $type) {
            DocumentType::create($type + ['organization_id' => $root->id, 'position' => ($i + 1) * 10]);
        }
    }

    /** Une paroisse adapte un modèle de son siège : elle en garde une copie, qui remplace l'original chez elle. */
    public function adapt(DocumentType $type, Organization $organization): DocumentType
    {
        if ($type->organization_id === $organization->id) {
            return $type;
        }
        $existing = DocumentType::where('organization_id', $organization->id)->where('replaces_id', $type->id)->first();

        return $existing ?? DocumentType::create($type->only(['key', 'name', 'title', 'subject', 'life_event_type', 'body', 'fields', 'code', 'number_format', 'signatory_title', 'show_photo', 'orientation', 'style', 'position'])
            + ['organization_id' => $organization->id, 'replaces_id' => $type->id]);
    }

    public function save(Organization $organization, array $data, ?DocumentType $type = null): DocumentType
    {
        if ($type && $type->organization_id !== $organization->id) {
            throw new InvalidArgumentException(__('Ce modèle appartient à un niveau supérieur : adaptez-le d’abord pour votre communauté.'));
        }
        $fields = collect($data['fields'] ?? [])->filter(fn ($f) => trim((string) ($f['label'] ?? '')) !== '')
            ->map(fn ($f) => ['key' => DocumentTemplate::fieldKey($f['label']), 'label' => trim($f['label']),
                'type' => array_key_exists($f['type'] ?? '', DocumentType::FIELD_TYPES) ? $f['type'] : 'text', 'required' => (bool) ($f['required'] ?? false)])
            ->unique('key')->values()->all();
        $reserved = collect(DocumentTemplate::variables())->flatMap(fn ($group) => array_keys($group))->all();
        if ($clash = collect($fields)->first(fn ($f) => in_array($f['key'], $reserved, true))) {
            throw new InvalidArgumentException(__('Le champ « :l » porte le nom d’une variable de Waumini : donnez-lui un autre nom.', ['l' => $clash['label']]));
        }
        if (! str_contains($data['number_format'] ?? '', '{NUMERO}')) {
            throw new InvalidArgumentException(__('Le format du numéro doit contenir {NUMERO}.'));
        }

        $values = [
            'name' => trim($data['name']), 'title' => trim($data['title']), 'code' => strtoupper(trim($data['code'])),
            'subject' => array_key_exists($data['subject'] ?? '', DocumentType::SUBJECTS) ? $data['subject'] : 'member',
            'life_event_type' => ($data['life_event_type'] ?? null) ?: null, 'body' => trim($data['body']), 'fields' => $fields,
            'number_format' => trim($data['number_format']), 'signatory_title' => trim((string) ($data['signatory_title'] ?? '')) ?: null,
            'show_photo' => ($data['subject'] ?? 'member') !== 'free' && (bool) ($data['show_photo'] ?? false),
            'orientation' => array_key_exists($data['orientation'] ?? '', DocumentStyles::ORIENTATIONS) ? $data['orientation'] : 'portrait',
            'style' => array_key_exists($data['style'] ?? '', DocumentStyles::STYLES) ? $data['style'] : null,
        ];
        if ($type) {
            $type->update($values);

            return $type;
        }

        return DocumentType::create($values + ['organization_id' => $organization->id,
            'position' => (int) DocumentType::where('organization_id', $organization->id)->max('position') + 10]);
    }

    /** Le numéro d'un document : « ABA/HIM/2026/0012 ». */
    public function formatNumber(DocumentType $type, Organization $organization, int $year, int $sequence): string
    {
        return strtr($type->number_format, [
            '{CODE}' => $type->code, '{SIGLE}' => $this->registry->code($organization), '{SIEGE}' => $this->registry->code($organization->root()),
            '{ANNEE}' => (string) $year, '{AN}' => substr((string) $year, -2), '{NUMERO}' => str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
        ]);
    }

    /**
     * Les valeurs des variables pour une personne et un document.
     *
     * @param  array{member?: ?Member, event?: ?LifeEvent, person?: array, fields?: array, signatory?: ?string, signatory_title?: ?string, date?: ?Carbon, number?: ?string}  $context
     */
    public function values(DocumentType $type, Organization $organization, array $context): array
    {
        $date = fn ($d) => $d ? Carbon::parse($d)->translatedFormat('j F Y') : null;
        $member = $context['member'] ?? null;
        $person = $context['person'] ?? ($member ? $this->personOf($member) : []);
        $event = $context['event'] ?? ($member && $type->life_event_type
            ? $member->lifeEvents()->where('type', $type->life_event_type)->latest('occurred_on')->first() : null);
        $event = $event instanceof LifeEvent ? $event->only(['occurred_on', 'place', 'officiant', 'witnesses', 'register_number']) : ($event ?? []);
        $female = ($person['gender'] ?? null) === 'F';
        $known = ($person['gender'] ?? null) !== null;

        $values = [
            'civilite' => $known ? ($female ? 'Madame' : 'Monsieur') : '',
            'nom_complet' => $person['full_name'] ?? null, 'nom_officiel' => $person['official_name'] ?? null,
            'nom' => $person['last_name'] ?? null, 'postnom' => $person['middle_name'] ?? null, 'prenom' => $person['first_name'] ?? null,
            'né' => $female ? 'née' : ($known ? 'né' : 'né(e)'), 'il' => $female ? 'elle' : ($known ? 'il' : 'il (elle)'),
            'Il' => $female ? 'Elle' : ($known ? 'Il' : 'Il (elle)'), 'e' => $female ? 'e' : ($known ? '' : '(e)'),
            'date_naissance' => $date($person['birth_date'] ?? null), 'lieu_naissance' => $person['birth_place'] ?? null,
            'parents' => $person['parents'] ?? null, 'numero_membre' => $person['number'] ?? null,
            'date_adhesion' => $date($person['joined_on'] ?? null), 'statut' => $person['status'] ?? null,
            'fonction' => $person['function'] ?? null, 'fonction_depuis' => $date($person['function_since'] ?? null),
            'adresse' => $person['address'] ?? null, 'conjoint' => $person['spouse'] ?? null,
            'evenement_date' => $date($event['occurred_on'] ?? null), 'evenement_lieu' => $event['place'] ?? null,
            'evenement_officiant' => $event['officiant'] ?? null, 'evenement_temoins' => $event['witnesses'] ?? null,
            'evenement_registre' => $event['register_number'] ?? null,
            'communaute' => $organization->name, 'ville' => $organization->city,
            'signataire' => $context['signatory'] ?? null, 'qualite_signataire' => $context['signatory_title'] ?? $type->signatory_title,
            'date' => $date($context['date'] ?? today()), 'numero_document' => $context['number'] ?? null,
        ];
        foreach ($type->customFields() as $field) {
            $raw = $context['fields'][$field['key']] ?? null;
            $values[$field['key']] = $field['type'] === 'date' ? $date($raw ?: null) : (trim((string) $raw) ?: null);
        }

        return array_map(fn ($v) => $v === null ? null : (string) $v, $values);
    }

    /** Ce que la fiche d'un membre apporte aux variables. */
    public function personOf(Member $member): array
    {
        $member->loadMissing(['status', 'household']);
        $term = $member->functionTerms()->with('function')->whereNull('ended_on')->orderByDesc('started_on')->first();
        $spouse = $member->household_id
            ? Member::withoutOrganizationScope()->where('household_id', $member->household_id)->whereKeyNot($member->id)
                ->whereIn('household_role', $member->household_role === 'spouse' ? ['head'] : ['spouse'])->first()
            : null;

        return [
            'gender' => $member->gender, 'full_name' => $member->fullName(), 'official_name' => $member->officialName(),
            'last_name' => mb_strtoupper((string) $member->last_name), 'middle_name' => $member->middle_name, 'first_name' => $member->first_name,
            'birth_date' => $member->birth_date, 'birth_place' => $member->birth_place, 'number' => $member->number,
            'joined_on' => $member->joined_on, 'status' => $member->status?->name, 'function' => $term?->function?->name,
            'function_since' => $term?->started_on, 'address' => $member->address() ?: null, 'spouse' => $spouse?->fullName(),
        ];
    }

    /** Un exemple pour l'aperçu de l'éditeur : un membre de la communauté, sinon une personne fictive. */
    public function sample(DocumentType $type, Organization $organization): array
    {
        $member = Member::withoutOrganizationScope()->where('organization_id', $organization->id)->whereNotNull('birth_date')->whereNotNull('joined_on')
            ->when($type->life_event_type, fn ($q) => $q->whereHas('lifeEvents', fn ($q) => $q->where('type', $type->life_event_type)))->first();
        $fields = collect($type->customFields())->mapWithKeys(fn ($f) => [$f['key'] => $f['type'] === 'date' ? today()->addWeek()->toDateString() : $f['label']])->all();
        $context = ['fields' => $fields, 'signatory' => $organization->documentIdentity()->representative() ?? __('Nom du signataire'), 'number' => $this->formatNumber($type, $organization, (int) now()->year, 1)];

        return $this->values($type, $organization, $member ? $context + ['member' => $member] : $context + ['person' => [
            'gender' => 'F', 'full_name' => 'Esther Kahindo Vagheni', 'official_name' => 'KAHINDO Vagheni Esther', 'last_name' => 'KAHINDO', 'first_name' => 'Esther',
            'birth_date' => '1982-03-14', 'birth_place' => 'Goma', 'number' => 'HIM-2015-0007', 'joined_on' => '2015-06-07', 'status' => 'Membre',
        ]]);
    }
}
