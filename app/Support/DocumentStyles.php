<?php

namespace App\Support;

use App\Models\DocumentType;
use App\Models\Organization;

/**
 * Les styles des documents délivrés. Chaque style a deux mises en page : le certificat
 * (en paysage : attestations, baptême, mariage) et la lettre (en portrait : recommandation,
 * ordre de mission, convocation). Les couleurs sont celles de l'église.
 */
class DocumentStyles
{
    public const DEFAULT = 'prestige';

    public const STYLES = [
        'prestige' => ['name' => 'Prestige', 'description' => 'Double cadre, coins ornés, nom en lettres calligraphiées et sceau rond.'],
        'solennel' => ['name' => 'Solennel', 'description' => 'Bordure guillochée comme un diplôme, logo en filigrane, ruban.'],
        'moderne' => ['name' => 'Moderne', 'description' => 'Bandeau de couleur sur le côté, titres nets, mise en page aérée.'],
        'classique' => ['name' => 'Classique', 'description' => 'En-tête officiel, filet double, cadre fin : sobre et administratif.'],
        'epure' => ['name' => 'Épuré', 'description' => 'Beaucoup de blanc, un trait de couleur, une typographie élégante.'],
    ];

    public const ORIENTATIONS = ['landscape' => 'Paysage', 'portrait' => 'Portrait'];

    /** Le style choisi par l'église, ou celui de son niveau supérieur, sinon le style par défaut. */
    public static function forOrganization(Organization $organization): string
    {
        $style = $organization->documentIdentity()->display()['style'] ?? null;

        return isset(self::STYLES[$style]) ? $style : self::DEFAULT;
    }

    /** Le style d'un modèle : le sien s'il en a choisi un, sinon celui de l'église. */
    public static function resolve(?DocumentType $type, Organization $organization): string
    {
        return $type?->style && isset(self::STYLES[$type->style]) ? $type->style : self::forOrganization($organization);
    }

    /** La ligne mise en valeur sur un certificat : le nom du bénéficiaire, ou les deux époux. */
    public static function headline(?DocumentType $type, array $values, ?string $beneficiary): ?string
    {
        if ($type?->subject === 'free') {
            return null;
        }
        if (($type?->key === 'marriage' || $type?->life_event_type === 'marriage') && ! empty($values['conjoint'])) {
            return trim(($values['nom_officiel'] ?? $values['nom_complet'] ?? $beneficiary).' & '.$values['conjoint']);
        }

        return $values['nom_officiel'] ?? $beneficiary;
    }
}
