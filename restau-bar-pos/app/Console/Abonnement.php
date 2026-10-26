<?php

declare(strict_types=1);

namespace App\Console;

/**
 * Calcul du statut d'un abonnement à partir de ses dates (sans base de données) : utilisé par la console
 * et par chaque instance (App\Core\Plateforme), qui garde les dates en cache pour continuer à les appliquer
 * si la console est injoignable.
 *
 *   essai         essai gratuit, jusqu'à l'échéance
 *   actif         payé, échéance dans plus de 7 jours
 *   a_relancer    échéance dans 7 jours ou moins (J-7 … J0) : relances au propriétaire
 *   grace         échéance dépassée, délai de grâce (7 jours par défaut) : tout fonctionne encore
 *   lecture_seule ensuite (15 jours par défaut) : on voit les données, on ne vend plus, on ne modifie plus
 *   suspendu      ensuite : seule une page explique la suspension ; les données ne sont jamais supprimées
 *
 * Le super admin peut forcer un statut (actif, lecture seule, suspendu) ; « selon les dates » sinon.
 * Un blocage ne coupe jamais une caisse ouverte : il s'applique à la prochaine ouverture (Plateforme::garder()).
 */
final class Abonnement
{
    public const STATUTS = ['essai', 'actif', 'a_relancer', 'grace', 'lecture_seule', 'suspendu'];
    public const PERIODES = ['mois' => 1, 'trimestre' => 3, 'annee' => 12];
    public const JOURS_RELANCE = 7;

    /**
     * @param array{echeance?: ?string, essai?: int|string|bool, jours_grace?: int|string, jours_lecture_seule?: int|string, statut_force?: ?string} $abonnement
     * @return array{code: string, force: bool, jours: ?int, grace_jusqua: ?string, lecture_seule_jusqua: ?string, essai: bool}
     */
    public static function statut(array $abonnement, ?string $aujourdhui = null): array
    {
        $aujourdhui ??= date('Y-m-d');
        $echeance = $abonnement['echeance'] ?? null;
        $grace = (int) ($abonnement['jours_grace'] ?? 7);
        $lecture = (int) ($abonnement['jours_lecture_seule'] ?? 15);
        $essai = !empty($abonnement['essai']);
        $resultat = [
            'code' => 'actif', 'force' => false, 'jours' => null, 'essai' => $essai,
            'grace_jusqua' => $echeance ? self::ajouterJours($echeance, $grace) : null,
            'lecture_seule_jusqua' => $echeance ? self::ajouterJours($echeance, $grace + $lecture) : null,
        ];
        if ($echeance) {
            $resultat['jours'] = self::ecart($aujourdhui, $echeance);
        }
        $force = $abonnement['statut_force'] ?? null;
        if (in_array($force, ['actif', 'lecture_seule', 'suspendu'], true)) {
            return ['code' => $force, 'force' => true] + $resultat;
        }
        if (!$echeance) {
            return $resultat;   // sans échéance (client interne) : toujours actif
        }
        $jours = $resultat['jours'];
        if ($jours >= 0) {
            $resultat['code'] = $essai ? 'essai' : ($jours <= self::JOURS_RELANCE ? 'a_relancer' : 'actif');
        } elseif (-$jours <= $grace) {
            $resultat['code'] = 'grace';
        } elseif (-$jours <= $grace + $lecture) {
            $resultat['code'] = 'lecture_seule';
        } else {
            $resultat['code'] = 'suspendu';
        }
        return $resultat;
    }

    /**
     * Nouvelle échéance après un paiement de N périodes : on prolonge depuis l'échéance en cours (le client
     * garde son rythme, même s'il paie en retard pendant la grâce ou la lecture seule) ; après une suspension
     * selon les dates, on repart d'aujourd'hui (il n'a pas eu le service).
     */
    public static function prolonger(array $abonnement, string $periode, int $periodes, ?string $aujourdhui = null): string
    {
        $aujourdhui ??= date('Y-m-d');
        $echeance = $abonnement['echeance'] ?? null;
        $base = $echeance ?: $aujourdhui;
        if ($echeance) {
            $retard = -self::ecart($aujourdhui, $echeance);
            $limite = (int) ($abonnement['jours_grace'] ?? 7) + (int) ($abonnement['jours_lecture_seule'] ?? 15);
            if ($retard > $limite) {
                $base = $aujourdhui;
            }
        }
        return self::ajouterMois($base, self::PERIODES[$periode] * max(1, $periodes));
    }

    /** Ajoute des mois sans déborder : 31 janvier + 1 mois = 28 (ou 29) février. */
    public static function ajouterMois(string $date, int $mois): string
    {
        [$a, $m, $j] = array_map('intval', explode('-', $date));
        $total = $a * 12 + ($m - 1) + $mois;
        $a = intdiv($total, 12);
        $m = $total % 12 + 1;
        $dernier = (int) date('t', mktime(0, 0, 0, $m, 1, $a));
        return sprintf('%04d-%02d-%02d', $a, $m, min($j, $dernier));
    }

    public static function ajouterJours(string $date, int $jours): string
    {
        return date('Y-m-d', strtotime($date . ' 12:00 ' . ($jours >= 0 ? '+' : '') . $jours . ' days'));
    }

    /** Jours de $de à $a (positif si $a est après $de). */
    public static function ecart(string $de, string $a): int
    {
        return (int) round((strtotime($a . ' 12:00') - strtotime($de . ' 12:00')) / 86400);
    }

    /** Prix de la formule pour une période, en dollars (null : période non proposée). */
    public static function prix(?array $formule, string $periode): ?float
    {
        $valeur = $formule['prix_' . $periode] ?? null;
        return $valeur === null || $valeur === '' ? null : (float) $valeur;
    }

    /** Équivalent mensuel (revenu mensuel récurrent). */
    public static function mensuel(?array $formule, string $periode): float
    {
        $prix = self::prix($formule, $periode);
        return $prix === null ? 0.0 : $prix / self::PERIODES[$periode];
    }

    /** Le statut bloque-t-il la vente (ouverture de la caisse) ? */
    public static function bloqueVente(string $code): bool
    {
        return $code === 'lecture_seule' || $code === 'suspendu';
    }
}
