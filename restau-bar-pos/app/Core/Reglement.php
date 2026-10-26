<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Règlement d'un ticket payé en une ou plusieurs devises (cahier des charges, section 10.4).
 *
 * Chaque devise a son propre total, calculé avec ses propres prix (prix fixés ou convertis, arrondis
 * de la devise) : 88 000 FC ou 31 $. Un paiement couvre donc une part du ticket : 20 $ sur 31 $ = 64,5 %.
 * - le reste à payer s'affiche dans chaque devise, arrondi vers le haut (on ne demande jamais trop peu) ;
 * - le ticket est réglé quand le reste, dans la devise du ticket, est inférieur à la moitié de son
 *   pas d'arrondi (moins de 50 FC pour un arrondi à 100) ;
 * - la monnaie se rend dans la devise choisie, arrondie vers le bas à son pas.
 *
 * Le même calcul tourne dans la caisse (caisse.js) pour l'affichage ; le serveur refait tout.
 */
final class Reglement
{
    /**
     * @param array<string, float> $totaux   code devise → total du ticket dans cette devise
     * @param array<string, array> $devises  code devise → ['decimales' => int, 'arrondi' => float]
     * @param list<array{devise: string, recu: float}> $paiements
     * @param string $principale devise du ticket (la devise locale)
     * @return array{part: float, regle: bool, reste: array<string, float>, rendu: array<string, float>}
     *         reste et rendu dans chaque devise ; rendu vide si rien à rendre
     */
    public static function calculer(array $totaux, array $devises, array $paiements, string $principale): array
    {
        $part = 0.0;
        foreach ($paiements as $paiement) {
            $total = $totaux[$paiement['devise']] ?? 0.0;
            if ($total > 0 && $paiement['recu'] > 0) {
                $part += $paiement['recu'] / $total;
            }
        }
        $resteBrut = (1 - $part) * ($totaux[$principale] ?? 0.0);
        $demiPas = self::pas($devises[$principale] ?? []) / 2;
        $regle = $resteBrut < $demiPas - 1e-9;

        $reste = [];
        $rendu = [];
        foreach ($totaux as $code => $total) {
            $reste[$code] = $regle ? 0.0 : self::versLeHaut(max(0.0, (1 - $part) * $total), $devises[$code] ?? []);
            $exces = ($part - 1) * $total;
            $rendu[$code] = $regle && $exces > 0 ? self::versLeBas($exces, $devises[$code] ?? []) : 0.0;
        }
        return ['part' => $part, 'regle' => $regle, 'reste' => $reste, 'rendu' => $rendu];
    }

    /** Plus petit montant d'une devise : son arrondi (100 FC) ou, à défaut, sa plus petite unité (0,01 $). */
    public static function pas(array $devise): float
    {
        $arrondi = (float) ($devise['arrondi'] ?? 0);
        return $arrondi > 0 ? $arrondi : 10 ** -((int) ($devise['decimales'] ?? 2));
    }

    public static function versLeHaut(float $montant, array $devise): float
    {
        $pas = self::pas($devise);
        return round(ceil(round($montant / $pas, 6)) * $pas, (int) ($devise['decimales'] ?? 2));
    }

    public static function versLeBas(float $montant, array $devise): float
    {
        $pas = self::pas($devise);
        return round(floor(round($montant / $pas, 6)) * $pas, (int) ($devise['decimales'] ?? 2));
    }
}
