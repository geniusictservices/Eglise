<?php

namespace App\Support;

/**
 * Le texte d'un modèle de document : des variables entre accolades
 * ({nom_complet}, {evenement_date}…) remplacées au moment de délivrer.
 * Une variable sans valeur laisse des pointillés, à compléter à la main.
 * **gras** met en gras ; une ligne vide sépare deux paragraphes.
 */
class DocumentTemplate
{
    public const BLANK = '……………………';

    /** Les variables proposées dans l'éditeur, par groupe. */
    public static function variables(): array
    {
        return [
            __('La personne') => [
                'civilite' => __('Monsieur, Madame (ou l’enfant)'),
                'nom_complet' => __('Prénom, nom et post-nom'),
                'nom_officiel' => __('NOM Post-nom Prénom'),
                'nom' => __('Nom'),
                'postnom' => __('Post-nom'),
                'prenom' => __('Prénom'),
                'né' => __('né ou née'),
                'il' => __('il ou elle'),
                'Il' => __('Il ou Elle, en début de phrase'),
                'e' => __('accord au féminin : « inscrit{e} »'),
                'date_naissance' => __('Date de naissance'),
                'lieu_naissance' => __('Lieu de naissance'),
                'parents' => __('Père et mère'),
                'numero_membre' => __('Numéro de membre'),
                'date_adhesion' => __('Date d’adhésion'),
                'statut' => __('Statut dans la communauté'),
                'fonction' => __('Fonction actuelle'),
                'fonction_depuis' => __('Fonction exercée depuis le'),
                'adresse' => __('Adresse'),
                'conjoint' => __('Conjoint(e)'),
            ],
            __('L’étape de vie ou l’acte du registre') => [
                'evenement_date' => __('Date (baptême, mariage…)'),
                'evenement_lieu' => __('Lieu'),
                'evenement_officiant' => __('Officiant'),
                'evenement_temoins' => __('Témoins, parrain et marraine'),
                'evenement_registre' => __('Référence dans le registre'),
            ],
            __('Le document') => [
                'communaute' => __('Nom de la communauté'),
                'ville' => __('Ville de la communauté'),
                'signataire' => __('Nom du signataire'),
                'qualite_signataire' => __('Qualité du signataire'),
                'date' => __('Date du document'),
                'numero_document' => __('Numéro du document'),
            ],
        ];
    }

    /** Les variables utilisées dans un texte. @return string[] */
    public static function used(string $body): array
    {
        preg_match_all('/\{([\p{L}_]+)\}/u', $body, $m);

        return array_values(array_unique($m[1]));
    }

    /** Le texte avec ses valeurs, en HTML sûr : paragraphes, retours à la ligne et gras. */
    public static function render(string $body, array $values): string
    {
        $text = preg_replace_callback('/\{([\p{L}_]+)\}/u', function ($m) use ($values) {
            $value = $values[$m[1]] ?? null;

            // Les accords ({e}, {né}, {il}) peuvent valoir une chaîne vide ; les autres variables vides laissent des pointillés.
            return $value === null || ($value === '' && ! in_array($m[1], ['e'], true)) ? "\u{1}".self::BLANK."\u{2}" : "\u{3}".$value."\u{4}";
        }, $body);

        $html = e($text);
        $html = strtr($html, ["\u{1}" => '<span class="blank">', "\u{2}" => '</span>', "\u{3}" => '', "\u{4}" => '']);
        $html = preg_replace('/\*\*(.+?)\*\*/su', '<strong>$1</strong>', $html);
        $paragraphs = preg_split('/\R\s*\R/u', trim($html));

        return collect($paragraphs)->map(fn ($p) => '<p>'.nl2br(trim($p), false).'</p>')->implode("\n");
    }

    /** Code de champ propre au modèle, à partir de son libellé : « Lieu de mission » → lieu_de_mission. */
    public static function fieldKey(string $label): string
    {
        return (string) str($label)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(30, '');
    }
}
