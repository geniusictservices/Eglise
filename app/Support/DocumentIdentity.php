<?php

namespace App\Support;

use App\Models\Organization;

/**
 * Ce qui apparaît en tête et en pied des documents (reçus, attestations…) :
 * logo, identité juridique et coordonnées. Chaque communauté choisit ce
 * qu'elle affiche ; une paroisse peut reprendre l'identité juridique de son siège.
 */
class DocumentIdentity
{
    /** Champs de l'identité juridique. */
    public const LEGAL_FIELDS = [
        'legal_name' => 'Dénomination officielle',
        'legal_form' => 'Forme juridique',
        'legal_registration' => 'Personnalité juridique',
        'national_id' => 'Identification nationale (Id. Nat.)',
        'tax_number' => 'Numéro d’impôt (NIF)',
        'representative' => 'Représentant légal',
        'motto' => 'Devise ou verset',
    ];

    /** Formes juridiques courantes en RDC (la communauté peut en écrire une autre). */
    public const LEGAL_FORMS = ['ASBL (association sans but lucratif)', 'Établissement d’utilité publique', 'Confession religieuse reconnue', 'Communauté membre d’une plateforme (ECC, CENCO…)'];

    /** Éléments que la communauté peut afficher ou masquer, et leur réglage par défaut. */
    public const DISPLAY = [
        'logo' => ['Logo', true],
        'legal_name' => ['Dénomination officielle', true],
        'legal_form' => ['Forme juridique', true],
        'legal_registration' => ['Personnalité juridique (arrêté, décret)', true],
        'national_id' => ['Id. Nat.', false],
        'tax_number' => ['NIF', false],
        'representative' => ['Représentant légal', false],
        'motto' => ['Devise ou verset', true],
        'address' => ['Adresse', true],
        'phone' => ['Téléphone', true],
        'email' => ['E-mail', true],
        'parent' => ['Niveau supérieur (siège, région)', true],
    ];

    public const RECEIPT_FORMATS = ['a4' => 'A4 (deux exemplaires)', '80' => 'Ticket 80 mm', '58' => 'Ticket 58 mm'];

    public function __construct(public Organization $organization) {}

    /** L'identité juridique : la sienne, ou celle du siège si la paroisse la reprend. */
    public function legal(): array
    {
        $own = $this->organization->legal ?? [];
        $root = $this->organization->root();

        if (! $this->organization->isRoot() && ($own['inherit'] ?? true)) {
            return array_merge(array_filter($root->legal ?? []), ['legal_name' => ($root->legal['legal_name'] ?? null) ?: $root->name]);
        }

        return $own;
    }

    /** Réglages d'affichage : les siens, sinon ceux du niveau supérieur le plus proche, sinon par défaut. */
    public function display(): array
    {
        $saved = $this->organization->settings['documents'] ?? null;
        if ($saved === null) {
            $saved = Organization::whereIn('id', $this->organization->ancestorIds())->orderByDesc('depth')->get()
                ->map(fn ($o) => $o->settings['documents'] ?? null)->filter()->first() ?? [];
        }

        return collect(self::DISPLAY)->mapWithKeys(fn ($d, $key) => [$key => (bool) ($saved['show'][$key] ?? $d[1])])->all()
            + ['footer' => $saved['footer'] ?? '', 'receipt_format' => $saved['receipt_format'] ?? 'a4'];
    }

    public function show(string $key): bool
    {
        return $this->display()[$key] ?? false;
    }

    /** Logo de la communauté, ou à défaut celui de son niveau supérieur le plus proche. */
    public function logoOrganization(): ?Organization
    {
        if ($this->organization->logo_path) {
            return $this->organization;
        }

        return Organization::whereIn('id', $this->organization->ancestorIds())->whereNotNull('logo_path')
            ->orderByDesc('depth')->first();
    }

    public function logoUrl(): ?string
    {
        $owner = $this->logoOrganization();

        return $this->show('logo') && $owner ? route('organizations.logo', [$owner, 'v' => substr(md5($owner->logo_path), 0, 8)]) : null;
    }

    /** Lignes de l'en-tête, dans l'ordre d'affichage. */
    public function headerLines(): array
    {
        $legal = $this->legal();
        $o = $this->organization;
        $lines = [];

        if ($this->show('legal_name') && ! empty($legal['legal_name']) && $legal['legal_name'] !== $o->name) {
            $lines['legal_name'] = $legal['legal_name'];
        }
        if ($this->show('parent') && ! $o->isRoot() && $o->parent_id) {
            $lines['parent'] = $o->root()->name;
        }
        if ($this->show('legal_form') && ! empty($legal['legal_form'])) {
            $lines['legal_form'] = $legal['legal_form'];
        }
        if ($this->show('legal_registration') && ! empty($legal['legal_registration'])) {
            $lines['legal_registration'] = __('Personnalité juridique : :r', ['r' => $legal['legal_registration']]);
        }
        $ids = array_filter([
            $this->show('national_id') && ! empty($legal['national_id']) ? __('Id. Nat. :n', ['n' => $legal['national_id']]) : null,
            $this->show('tax_number') && ! empty($legal['tax_number']) ? __('NIF :n', ['n' => $legal['tax_number']]) : null,
        ]);
        if ($ids) {
            $lines['ids'] = implode(' · ', $ids);
        }

        return $lines;
    }

    /** Coordonnées, pour l'en-tête ou le pied. */
    public function contactLines(): array
    {
        $o = $this->organization;

        return array_filter([
            'address' => $this->show('address') ? collect([$o->address, $o->city, $o->province])->filter()->implode(', ') : null,
            'phone' => $this->show('phone') && $o->phone ? Phone::format($o->phone) : null,
            'email' => $this->show('email') ? $o->email : null,
        ]);
    }

    public function motto(): ?string
    {
        $legal = $this->legal();

        return $this->show('motto') ? ($legal['motto'] ?? null) : null;
    }

    public function representative(): ?string
    {
        $legal = $this->legal();

        return $this->show('representative') ? ($legal['representative'] ?? null) : null;
    }

    public function footer(): ?string
    {
        return trim((string) $this->display()['footer']) ?: null;
    }
}
