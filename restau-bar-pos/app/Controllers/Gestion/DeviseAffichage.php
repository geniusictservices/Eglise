<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Database;
use App\Core\I18n;

/** Lecture et affichage des nombres et des dates des écrans devises, taux et compte. */
final class DeviseAffichage
{
    private static ?int $decalageBase = null;

    /** Taux lisible : « 2 850 », « 0,92 » (fr) ou « 2,850 », « 0.92 » (en), sans zéros inutiles. */
    public static function taux(float|string|null $taux): string
    {
        if ($taux === null || $taux === '') {
            return '';
        }
        $texte = rtrim(rtrim(number_format((float) $taux, 6, '.', ''), '0'), '.');
        $decimales = str_contains($texte, '.') ? strlen(substr($texte, strpos($texte, '.') + 1)) : 0;
        return I18n::langue() === 'fr'
            ? number_format((float) $taux, $decimales, ',', "\u{202F}")
            : number_format((float) $taux, $decimales, '.', ',');
    }

    /** Taux à mettre dans un champ de saisie : sans séparateur de milliers (« 2850 », « 0,92 »). */
    public static function tauxSaisie(float|string|null $taux): string
    {
        if ($taux === null || $taux === '') {
            return '';
        }
        $texte = rtrim(rtrim(number_format((float) $taux, 6, '.', ''), '0'), '.');
        return I18n::langue() === 'fr' ? str_replace('.', ',', $texte) : $texte;
    }

    /** Arrondi lisible : « 100 », « 0,05 ». */
    public static function arrondi(float|string $arrondi): string
    {
        return self::taux($arrondi);
    }

    /**
     * Lit un nombre saisi : « 2850 », « 2 850 », « 0,92 », « 0.92 », « 2,850.50 ».
     * Renvoie la chaîne décimale normalisée (« 2850.5 »), ou null si ce n'est pas un nombre.
     */
    public static function lireNombre(mixed $saisie): ?string
    {
        if (!is_string($saisie)) {
            return null;
        }
        $texte = (string) preg_replace('/[\s\x{00A0}\x{202F}\']+/u', '', $saisie);
        if ($texte === '') {
            return null;
        }
        $virgule = strrpos($texte, ',');
        $point = strrpos($texte, '.');
        if ($virgule !== false && $point !== false) {
            // Les deux : le dernier est le séparateur décimal, l'autre sépare les milliers
            $decimal = $virgule > $point ? ',' : '.';
            $texte = str_replace($decimal === ',' ? '.' : ',', '', $texte);
            $texte = str_replace(',', '.', $texte);
        } elseif ($virgule !== false) {
            // « 2,850 » en anglais = deux mille huit cent cinquante ; sinon la virgule est décimale
            $texte = I18n::langue() === 'en' && preg_match('/^\d{1,3}(,\d{3})+$/', $texte)
                ? str_replace(',', '', $texte)
                : str_replace(',', '.', $texte);
        } elseif ($point !== false && I18n::langue() === 'fr' && preg_match('/^\d{1,3}(\.\d{3}){2,}$/', $texte)) {
            // « 1.000.000 » : points de milliers
            $texte = str_replace('.', '', $texte);
        }
        if (!preg_match('/^\d+(\.\d+)?$/', $texte)) {
            return null;
        }
        return $texte;
    }

    /** Date et heure selon la langue : « 26/10/2026 14:05 » ou « 2026-10-26 14:05 ». */
    public static function dateHeure(?string $dateHeure): string
    {
        if ($dateHeure === null || $dateHeure === '') {
            return '';
        }
        $horodatage = strtotime($dateHeure);
        if ($horodatage === false) {
            return '';
        }
        return date(I18n::langue() === 'fr' ? 'd/m/Y H:i' : 'Y-m-d H:i', $horodatage);
    }

    /**
     * Date écrite par la base (NOW(), CURRENT_TIMESTAMP), affichée à l'heure locale de l'instance.
     * Le fuseau de MySQL peut différer de celui de PHP (fuseau_horaire de l'instance).
     */
    public static function dateHeureBase(?string $dateHeure): string
    {
        if ($dateHeure === null || $dateHeure === '' || ($horodatage = strtotime($dateHeure)) === false) {
            return '';
        }
        if (self::$decalageBase === null) {
            $ligne = Database::un('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS decalage');
            // Arrondi au quart d'heure : la requête prend quelques millisecondes
            self::$decalageBase = (int) (round(((int) ($ligne['decalage'] ?? 0)) / 900) * 900);
        }
        $local = $horodatage - self::$decalageBase + (int) date('Z', $horodatage);
        return self::dateHeure(date('Y-m-d H:i:s', $local));
    }
}
