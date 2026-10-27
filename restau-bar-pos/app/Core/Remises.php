<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Remises et offerts à la caisse : calcul (le même tourne dans caisse-nuit.js pour l'affichage, le serveur refait tout).
 *
 * Une remise retire une PART de la ligne ou du ticket : 10 % → 0,1 ; 5 000 FC sur une ligne de 25 000 FC → 0,2 ;
 * « offert » → 1. Comme chaque devise a son propre total (prix fixés ou convertis), la même part s'applique dans
 * chaque devise : 10 % de 88 000 FC et 10 % de 31 $. Une remise en montant se donne dans une devise du ticket.
 * Ordre : les remises des lignes, puis celle du ticket sur ce qui reste. Les lignes « minimum non atteint » et les
 * composants d'une formule (prix 0) ne se remisent pas. Totaux arrondis aux décimales de chaque devise.
 *
 * La limite d'une personne se compare à chaque part (une ligne offerte = 100 %, au-delà des 10 % d'un superviseur).
 */
final class Remises
{
    public const MOTIFS = ['geste', 'client_vip', 'erreur', 'personnel', 'promotion', 'fidelite', 'autre'];
    public const TYPES = ['pourcent', 'montant', 'offert'];
    private const EPS = 1e-9;

    /**
     * Remise demandée par la caisse, vérifiée : type, valeur, devise (montant), motif et précision.
     * @param array<string, mixed> $codes devises du ticket (code → devise)
     * @return array{type: string, valeur: float, devise: ?string, motif: string, precision: ?string, recompense?: int}
     * @throws \InvalidArgumentException
     */
    public static function lire(mixed $r, array $codes): array
    {
        if (!is_array($r)) {
            throw new \InvalidArgumentException('remise');
        }
        $type = is_string($r['type'] ?? null) ? $r['type'] : '';
        $motif = is_string($r['motif'] ?? null) ? $r['motif'] : '';
        $fidelite = $type === 'fidelite';
        if ((!in_array($type, self::TYPES, true) && !$fidelite) || (!$fidelite && !in_array($motif, self::MOTIFS, true))) {
            throw new \InvalidArgumentException('remise');
        }
        $valeur = $type === 'offert' ? 100.0 : (is_numeric($r['valeur'] ?? null) ? (float) $r['valeur'] : 0.0);
        $devise = null;
        if ($type === 'montant') {
            $devise = is_string($r['devise'] ?? null) && isset($codes[$r['devise']]) ? $r['devise'] : null;
            if ($devise === null) {
                throw new \InvalidArgumentException('remise');
            }
        }
        if ($valeur <= 0 || ($type !== 'montant' && $valeur > 100 + self::EPS) || $valeur > 1e12) {
            throw new \InvalidArgumentException('remise');
        }
        $precision = is_string($r['precision'] ?? null) ? trim(preg_replace('/\s+/u', ' ', $r['precision']) ?? '') : '';
        $lue = ['type' => $type, 'valeur' => round($valeur, 3), 'devise' => $devise, 'motif' => $fidelite ? 'fidelite' : $motif,
            'precision' => $precision !== '' ? mb_substr($precision, 0, 160) : null];
        if ($fidelite) {
            $lue['recompense'] = (int) ($r['recompense'] ?? 0);
        }
        return $lue;
    }

    /**
     * Part retirée par une remise sur une base (montants dans chaque devise).
     * @param array<string, ?float> $base
     * @throws \InvalidArgumentException montant plus grand que la base, ou dans une devise sans prix
     */
    public static function part(array $remise, array $base): float
    {
        if ($remise['type'] === 'offert') {
            return 1.0;
        }
        if ($remise['type'] === 'montant') {
            $b = $base[$remise['devise']] ?? null;
            if ($b === null || $b <= 0 || $remise['valeur'] > $b + 0.0005) {
                throw new \InvalidArgumentException('remise_trop');
            }
            return min(1.0, $remise['valeur'] / $b);
        }
        return min(1.0, $remise['valeur'] / 100);
    }

    /**
     * Applique les remises.
     * @param list<array{brut: array<string, ?float>, remisable: bool}> $lignes brut de chaque ligne (prix × quantité) par devise
     * @param array<int, array> $remisesLignes index de ligne → remise lue
     * @param array<string, array> $devises code → ['decimales' => int]
     * @return array{lignes: list<array{part: float, net: array<string, ?float>}>, partTicket: float,
     *               totaux: array<string, ?float>, bruts: array<string, ?float>}
     * @throws \InvalidArgumentException
     */
    public static function appliquer(array $lignes, array $remisesLignes, ?array $remiseTicket, array $devises): array
    {
        $codes = array_keys($devises);
        $resultat = [];
        $apresLignes = array_fill_keys($codes, 0.0);
        foreach ($lignes as $i => $l) {
            $part = 0.0;
            if (isset($remisesLignes[$i])) {
                if (!$l['remisable']) {
                    throw new \InvalidArgumentException('remise');
                }
                $part = self::part($remisesLignes[$i], $l['brut']);
            }
            $net = [];
            foreach ($codes as $c) {
                $b = $l['brut'][$c] ?? null;
                $net[$c] = $b === null ? null : $b * (1 - $part);
                if ($l['remisable']) {
                    $apresLignes[$c] = $apresLignes[$c] === null || $net[$c] === null ? null : $apresLignes[$c] + $net[$c];
                }
            }
            $resultat[$i] = ['part' => $part, 'net' => $net];
        }
        $partTicket = $remiseTicket !== null ? self::part($remiseTicket, $apresLignes) : 0.0;
        $totaux = array_fill_keys($codes, 0.0);
        $bruts = array_fill_keys($codes, 0.0);
        foreach ($lignes as $i => $l) {
            foreach ($codes as $c) {
                $b = $l['brut'][$c] ?? null;
                $bruts[$c] = $bruts[$c] === null || $b === null ? null : $bruts[$c] + $b;
                $n = $resultat[$i]['net'][$c];
                if ($n !== null && $l['remisable']) {
                    $n *= (1 - $partTicket);
                }
                $resultat[$i]['net'][$c] = $n;
                $totaux[$c] = $totaux[$c] === null || $n === null ? null : $totaux[$c] + $n;
            }
        }
        foreach ($totaux as $c => $t) {
            $totaux[$c] = $t === null ? null : round($t, (int) ($devises[$c]['decimales'] ?? 2));
        }
        return ['lignes' => $resultat, 'partTicket' => $partTicket, 'totaux' => $totaux, 'bruts' => $bruts];
    }
}
