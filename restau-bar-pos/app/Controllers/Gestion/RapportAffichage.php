<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\I18n;

/**
 * Affichage des rapports : montants en devise locale puis en dollars (« 2 850 000 FC · 1 000 $ », section 10.1),
 * écarts avec la période précédente, pourcentages, période lisible.
 */
final class RapportAffichage
{
    /** « 2 850 000 FC · 1 000 $ » ; la devise de référence seule si la locale manque ou est la même. */
    public static function double(float $locale, float $reference, ?array $deviseLocale, ?array $deviseReference, bool $approx = false): string
    {
        $parties = [];
        if ($deviseLocale !== null) {
            $parties[] = ($approx ? '≈ ' : '') . TresorerieAffichage::montant($locale, $deviseLocale);
        }
        if ($deviseReference !== null && ($deviseLocale === null || (int) $deviseLocale['id'] !== (int) $deviseReference['id'])) {
            $parties[] = TresorerieAffichage::montant($reference, $deviseReference);
        }
        return implode(' · ', $parties);
    }

    /** Écart relatif entre deux valeurs (null si la valeur précédente est nulle). */
    public static function ecart(float $actuel, float $precedent): ?float
    {
        if (abs($precedent) < 0.0005) {
            return null;
        }
        return ($actuel - $precedent) / abs($precedent);
    }

    /** Montant court pour un axe : « 1,2 M FC », « 850 k FC », « 320 $ ». */
    public static function compact(float $montant, ?array $devise): string
    {
        $symbole = $devise['symbole'] ?? '';
        $avant = !empty($devise['symbole_avant']);
        $abs = abs($montant);
        if ($abs >= 1e6) {
            $texte = self::nombre($montant / 1e6, $abs >= 1e7 ? 0 : 1) . "\u{00A0}M";
        } elseif ($abs >= 1e3) {
            $texte = self::nombre($montant / 1e3, $abs >= 1e4 ? 0 : 1) . "\u{00A0}k";
        } else {
            $texte = self::nombre($montant, 0);
        }
        $texte = preg_replace('/[,.]0(?=\x{00A0})/u', '', $texte);
        return $avant ? $symbole . $texte : $texte . "\u{00A0}" . $symbole;
    }

    /** « +12 % », « −8 % ». */
    public static function pourcentSigne(?float $part): string
    {
        if ($part === null) {
            return '';
        }
        $valeur = (int) round($part * 100);
        return ($valeur > 0 ? '+' : ($valeur < 0 ? "\u{2212}" : '')) . abs($valeur) . "\u{00A0}%";
    }

    /** « 64 % ». */
    public static function pourcent(float $part): string
    {
        return (string) round($part * 100) . "\u{00A0}%";
    }

    /** Nombre simple : « 1 234 » (fr) ou « 1,234 » (en). */
    public static function nombre(float|int $n, int $decimales = 0): string
    {
        return I18n::langue() === 'fr' ? number_format((float) $n, $decimales, ',', "\u{202F}") : number_format((float) $n, $decimales, '.', ',');
    }

    /** « du 20/10/2026 au 26/10/2026 », « le 26/10/2026 ». */
    public static function periode(string $du, string $au): string
    {
        if ($du === $au) {
            return t('rapports.le', ['date' => TresorerieAffichage::jour($du)]);
        }
        return t('rapports.du_au', ['du' => TresorerieAffichage::jour($du), 'au' => TresorerieAffichage::jour($au)]);
    }

    /** « lun. 20 » pour les étiquettes d'un graphique par jour. */
    public static function jourCourt(string $date): string
    {
        $t = strtotime($date);
        $jours = I18n::langue() === 'fr' ? ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'] : ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        return $jours[(int) date('N', $t) - 1] . "\u{00A0}" . date('j', $t);
    }

    /** « vendredi 31 octobre 2026 » / « Friday 31 October 2026 ». */
    public static function jourLong(string $date): string
    {
        $t = strtotime($date);
        if (I18n::langue() === 'fr') {
            $jours = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
            $mois = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
            return $jours[(int) date('N', $t) - 1] . ' ' . date('j', $t) . ' ' . $mois[(int) date('n', $t) - 1] . ' ' . date('Y', $t);
        }
        return date('l j F Y', $t);
    }

    /** « octobre 2026 » / « October 2026 ». */
    public static function mois(string $date): string
    {
        $t = strtotime($date);
        if (I18n::langue() === 'fr') {
            $mois = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
            return $mois[(int) date('n', $t) - 1] . ' ' . date('Y', $t);
        }
        return date('F Y', $t);
    }

    /** « 22:00 ». */
    public static function heure(string $date): string
    {
        return date('H:i', strtotime($date));
    }
}
