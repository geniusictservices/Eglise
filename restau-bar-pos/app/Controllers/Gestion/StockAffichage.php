<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\I18n;

/**
 * Affichage des quantités de stock : « 9 casiers + 7 bouteilles », « 3 bouteilles 70 cl + 12 cl »,
 * « 2,5 kg », avec les formats (conditionnements) propres au produit.
 */
final class StockAffichage
{
    /** Unités de base connues (libellés : stock.unite_<code>_1 et _n). */
    public const UNITES = ['bouteille', 'canette', 'verre', 'piece', 'cl', 'l', 'g', 'kg', 'portion', 'sachet', 'botte'];

    /** « 1 234,5 » (fr) ou « 1,234.5 » (en), trois décimales au plus. */
    public static function nombre(float|string|null $valeur): string
    {
        $valeur = round((float) $valeur, 3);
        $decimales = 0;
        foreach ([1, 2, 3] as $d) {
            if (abs($valeur * 10 ** ($d - 1) - round($valeur * 10 ** ($d - 1))) > 0.0000001) {
                $decimales = $d;
            }
        }
        return I18n::langue() === 'fr'
            ? number_format($valeur, $decimales, ',', "\u{202F}")
            : number_format($valeur, $decimales, '.', ',');
    }

    /** Valeur d'un champ de saisie : « 12,5 » (fr) ou « 12.5 » (en). */
    public static function saisie(float|string|null $valeur): string
    {
        if ($valeur === null || $valeur === '') {
            return '';
        }
        $texte = rtrim(rtrim(number_format((float) $valeur, 3, '.', ''), '0'), '.');
        return I18n::langue() === 'fr' ? str_replace('.', ',', $texte) : $texte;
    }

    /** Libellé de l'unité de base : « bouteille », « bouteilles », « cl ». */
    public static function unite(string $code, float $quantite = 1.0): string
    {
        $pluriel = self::pluriel($quantite);
        if (in_array($code, self::UNITES, true)) {
            return t('stock.unite_' . $code . ($pluriel ? '_n' : '_1'));
        }
        return $code;
    }

    /** Nom d'un format au singulier ou au pluriel, en minuscule : « casier », « bouteilles 70 cl ». */
    public static function format(array $conditionnement, float $quantite = 1.0): string
    {
        $nom = champ($conditionnement, 'nom');
        $nom = mb_strtolower(mb_substr($nom, 0, 1)) . mb_substr($nom, 1);
        if (!self::pluriel($quantite)) {
            return $nom;
        }
        $mots = explode(' ', $nom, 2);
        $mot = $mots[0];
        if (I18n::langue() === 'en') {
            if (preg_match('/(s|x|z|ch|sh)$/', $mot)) {
                $mot .= 'es';
            } elseif (preg_match('/[^aeiou]y$/', $mot)) {
                $mot = substr($mot, 0, -1) . 'ies';
            } else {
                $mot .= 's';
            }
        } elseif (!preg_match('/[sxz]$/u', $mot)) {
            $mot .= preg_match('/(eau|au|eu)$/u', $mot) ? 'x' : 's';
        }
        return $mot . (isset($mots[1]) ? ' ' . $mots[1] : '');
    }

    private static function pluriel(float $quantite): bool
    {
        $quantite = abs($quantite);
        return I18n::langue() === 'fr' ? $quantite >= 2 : abs($quantite - 1) > 0.0005;
    }

    /**
     * Quantité lisible avec le plus grand format du produit (de préférence un format d'achat), puis le reste
     * en unités : « 9 casiers + 7 bouteilles », « −3 bouteilles ».
     * @param array{unite_base: string, conditionnements?: list<array>} $article
     */
    public static function quantite(float|string|null $quantite, array $article): string
    {
        $quantite = round((float) $quantite, 3);
        $signe = $quantite < -0.0005 ? "\u{2212}" : '';
        $q = abs($quantite);
        $formats = array_values(array_filter($article['conditionnements'] ?? [], static fn (array $c): bool => (float) $c['quantite_base'] > 0));
        $grands = array_filter($formats, static fn (array $c): bool => (float) $c['quantite_base'] > 1.0005);
        $achat = array_filter($grands, static fn (array $c): bool => !empty($c['achetable']));
        $choix = $achat ?: $grands;
        usort($choix, static fn (array $a, array $b): int => (float) $b['quantite_base'] <=> (float) $a['quantite_base']);
        $grand = $choix[0] ?? null;
        $unitaire = null;
        foreach ($formats as $c) {
            if (abs((float) $c['quantite_base'] - 1) < 0.0005) {
                $unitaire = $c;
                break;
            }
        }
        $petit = static fn (float $n): string => self::nombre($n) . ' ' . ($unitaire !== null ? self::format($unitaire, $n) : self::unite((string) $article['unite_base'], $n));

        if ($grand !== null && $q >= (float) $grand['quantite_base'] - 0.0005) {
            $taille = (float) $grand['quantite_base'];
            $n = floor(($q + 0.0005) / $taille);
            $reste = round($q - $n * $taille, 3);
            $texte = self::nombre($n) . ' ' . self::format($grand, $n);
            if ($reste > 0.0005) {
                $texte .= ' + ' . $petit($reste);
            }
            return $signe . $texte;
        }
        return $signe . $petit($q);
    }

    /** Quantité en unités de base, toujours : « 115 bouteilles », « 2 345 cl ». */
    public static function base(float|string|null $quantite, array $article): string
    {
        $q = round((float) $quantite, 3);
        return ($q < -0.0005 ? "\u{2212}" : '') . self::nombre(abs($q)) . ' ' . self::unite((string) $article['unite_base'], $q);
    }
}
