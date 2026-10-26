<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\I18n;
use App\Repositories\DeviseRepository;

/** Affichage des montants, taux et dates des écrans trésorerie et dépenses. */
final class TresorerieAffichage
{
    private static ?DeviseRepository $devises = null;

    private static function devises(): DeviseRepository
    {
        return self::$devises ??= new DeviseRepository();
    }

    /** « 990 $ », « −150 $ », « 3 605 000 FC » ; avec $signe, « +800 $ ». */
    public static function montant(float|string|null $montant, array $devise, bool $signe = false): string
    {
        $montant = (float) $montant;
        $texte = self::devises()->formater(abs($montant), $devise);
        if (abs($montant) < 0.0005) {
            return $texte;
        }
        return ($montant < 0 ? "\u{2212}" : ($signe ? '+' : '')) . $texte;
    }

    /** Montant à mettre dans un champ de saisie : « 142500 », « 12,5 » (fr) ou « 12.5 » (en). */
    public static function saisie(float|string|null $montant): string
    {
        if ($montant === null || $montant === '') {
            return '';
        }
        return DeviseAffichage::tauxSaisie((float) $montant);
    }

    /**
     * Taux lisible entre deux devises, toujours dans le sens où il dépasse 1 :
     * « 1 USD = 2 850 CDF » plutôt que « 1 CDF = 0,000351 USD ».
     * @param float $taux unités de $vers pour 1 unité de $de
     */
    public static function taux(string $de, string $vers, float $taux): string
    {
        if ($taux <= 0) {
            return '';
        }
        if ($taux < 1) {
            [$de, $vers, $taux] = [$vers, $de, 1 / $taux];
        }
        return '1 ' . $de . ' = ' . DeviseAffichage::taux(round($taux, $taux >= 100 ? 2 : 4)) . ' ' . $vers;
    }

    public static function dateHeure(?string $date): string
    {
        return DeviseAffichage::dateHeure($date);
    }

    /** « 26/10/2026 » (fr) ou « 2026-10-26 » (en). */
    public static function jour(?string $date): string
    {
        if ($date === null || $date === '' || ($horodatage = strtotime($date)) === false) {
            return '';
        }
        return date(I18n::langue() === 'fr' ? 'd/m/Y' : 'Y-m-d', $horodatage);
    }

    /** Date et heure, sans l'heure si elle vaut minuit. */
    public static function date(?string $date): string
    {
        if ($date === null || $date === '') {
            return '';
        }
        return str_ends_with($date, '00:00:00') ? self::jour($date) : self::dateHeure($date);
    }

    /** Valeur d'un champ datetime-local. */
    public static function champDate(?string $date): string
    {
        $horodatage = $date !== null && $date !== '' ? strtotime($date) : false;
        return $horodatage === false ? '' : date('Y-m-d\TH:i', $horodatage);
    }

    /** Données d'une devise pour le JavaScript des formulaires (mêmes règles que DeviseRepository). */
    public static function deviseJs(array $devise): array
    {
        return [
            'code' => $devise['code'],
            'symbole' => $devise['symbole'],
            'avant' => (bool) $devise['symbole_avant'],
            'decimales' => (int) $devise['decimales'],
            'arrondi' => (float) $devise['arrondi'],
            'taux' => $devise['taux'] !== null ? (float) $devise['taux'] : null,
        ];
    }
}
