<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Journal;
use App\Core\Reglement;

/**
 * Minimum de consommation (carré VIP, table VIP) : par zone ou par table (la table l'emporte), pour une note — une
 * soirée. Montants dans la devise locale et/ou en dollars ; une devise sans montant prend la différence de la devise
 * du ticket, convertie au taux du jour et arrondie vers le haut. À l'encaissement d'une note sous le minimum : la
 * différence s'ajoute en ligne « minimum non atteint » (action « ajouter ») ou la caisse avertit seulement
 * (« avertir ») ; un gérant peut lever le minimum avec son PIN.
 */
final class MinimumRepository
{
    public static function disponible(): bool
    {
        return RapportRepository::tableExiste('minimums_conso');
    }

    /**
     * Minimum qui s'applique à une note (table d'abord, puis zone), avec ses montants par code de devise.
     * @return array{id: int, action: string, montants: array<string, float>, zone_id: ?int, table_id: ?int}|null
     */
    public function pour(int $etabId, ?int $zoneId, ?int $tableId): ?array
    {
        if (!self::disponible() || ($zoneId === null && $tableId === null)) {
            return null;
        }
        $min = null;
        if ($tableId !== null) {
            $min = Database::un('SELECT * FROM minimums_conso WHERE etablissement_id = ? AND table_id = ? AND actif = 1 ORDER BY id LIMIT 1', [$etabId, $tableId]);
        }
        if ($min === null && $zoneId !== null) {
            $min = Database::un('SELECT * FROM minimums_conso WHERE etablissement_id = ? AND zone_id = ? AND table_id IS NULL AND actif = 1 ORDER BY id LIMIT 1', [$etabId, $zoneId]);
        }
        if ($min === null) {
            return null;
        }
        $montants = [];
        foreach (Database::tous('SELECT d.code, m.montant FROM minimum_montants m JOIN devises d ON d.id = m.devise_id WHERE m.minimum_id = ?', [(int) $min['id']]) as $m) {
            if ((float) $m['montant'] > 0) {
                $montants[$m['code']] = (float) $m['montant'];
            }
        }
        if ($montants === []) {
            return null;
        }
        return ['id' => (int) $min['id'], 'action' => $min['action'], 'montants' => $montants,
            'zone_id' => $min['zone_id'] !== null ? (int) $min['zone_id'] : null, 'table_id' => $min['table_id'] !== null ? (int) $min['table_id'] : null];
    }

    /**
     * Ce qui manque pour atteindre le minimum, dans chaque devise acceptée (0 si atteint).
     * @param array<string, ?float> $consomme total consommé de la note par code de devise (toutes les lignes non annulées)
     * @param array<string, array> $acceptees code → devise (decimales, arrondi, taux)
     * @return array{manque: array<string, ?float>, minimum: array<string, ?float>, atteint: bool}
     */
    public static function manque(array $minimum, array $consomme, array $acceptees, string $principale, DeviseRepository $devises): array
    {
        $taux = static fn (string $code): ?float => ($t = $acceptees[$code]['taux'] ?? ($devises->actives()[(int) ($acceptees[$code]['id'] ?? 0)]['taux'] ?? null)) !== null && (float) $t > 0 ? (float) $t : null;
        // Minimum dans la devise du ticket : donné, sinon converti depuis une devise donnée
        $minP = $minimum['montants'][$principale] ?? null;
        if ($minP === null) {
            foreach ($minimum['montants'] as $code => $m) {
                if (($tc = $taux($code)) !== null && ($tp = $taux($principale)) !== null) {
                    $minP = Reglement::versLeHaut($m / $tc * $tp, $acceptees[$principale]);
                    break;
                }
            }
        }
        $consP = $consomme[$principale] ?? null;
        $manqueP = $minP === null || $consP === null ? 0.0 : max(0.0, $minP - $consP);
        $manqueP = $manqueP > 0 ? Reglement::versLeHaut($manqueP, $acceptees[$principale]) : 0.0;
        $manque = [];
        $mins = [];
        foreach ($acceptees as $code => $d) {
            if (isset($minimum['montants'][$code])) {
                $mins[$code] = $minimum['montants'][$code];
                $c = $consomme[$code] ?? null;
                $manque[$code] = $c === null ? null : ($manqueP > 0 ? Reglement::versLeHaut(max(0.0, $minimum['montants'][$code] - $c), $d) : 0.0);
            } else {
                $tc = $taux($code);
                $tp = $taux($principale);
                $mins[$code] = $minP !== null && $tc !== null && $tp !== null ? Reglement::versLeHaut($minP / $tp * $tc, $d) : null;
                $manque[$code] = $tc !== null && $tp !== null ? ($manqueP > 0 ? Reglement::versLeHaut($manqueP / $tp * $tc, $d) : 0.0) : null;
            }
        }
        $manque[$principale] = $manqueP;
        $mins[$principale] = $minP;
        return ['manque' => $manque, 'minimum' => $mins, 'atteint' => $manqueP <= 0];
    }

    // ---------- Gestion ----------

    public function liste(int $etabId): array
    {
        $lignes = Database::tous(
            'SELECT m.*, z.nom_fr AS zone_fr, z.nom_en AS zone_en, t.nom AS table_nom
               FROM minimums_conso m LEFT JOIN zones z ON z.id = m.zone_id LEFT JOIN salle_tables t ON t.id = m.table_id
              WHERE m.etablissement_id = ? ORDER BY m.table_id IS NOT NULL, z.nom_fr, t.nom, m.id',
            [$etabId]
        );
        foreach ($lignes as &$l) {
            $l['montants'] = array_map('floatval', array_column(Database::tous('SELECT devise_id, montant FROM minimum_montants WHERE minimum_id = ?', [(int) $l['id']]), 'montant', 'devise_id'));
        }
        unset($l);
        return $lignes;
    }

    /** @param array<int, float> $montants devise_id → montant (0 ou absent : pas de montant dans cette devise) */
    public function enregistrer(?int $id, int $etabId, ?int $zoneId, ?int $tableId, string $action, bool $actif, array $montants, int $par): int
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            if ($id === null) {
                Database::executer('INSERT INTO minimums_conso (etablissement_id, zone_id, table_id, action, actif, cree_par) VALUES (?, ?, ?, ?, ?, ?)',
                    [$etabId, $zoneId, $tableId, $action, $actif ? 1 : 0, $par]);
                $id = Database::dernierId();
            } else {
                Database::executer('UPDATE minimums_conso SET zone_id = ?, table_id = ?, action = ?, actif = ? WHERE id = ? AND etablissement_id = ?',
                    [$zoneId, $tableId, $action, $actif ? 1 : 0, $id, $etabId]);
            }
            Database::executer('DELETE FROM minimum_montants WHERE minimum_id = ?', [$id]);
            foreach ($montants as $deviseId => $m) {
                if ($m > 0) {
                    Database::executer('INSERT INTO minimum_montants (minimum_id, devise_id, montant) VALUES (?, ?, ?)', [$id, $deviseId, number_format($m, 3, '.', '')]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        Journal::ecrire('minimum_enregistre', (string) $id, ['zone' => $zoneId, 'table' => $tableId, 'action' => $action, 'montants' => $montants], $par);
        return $id;
    }

    public function supprimer(int $id, int $etabId, int $par): void
    {
        Database::executer('DELETE FROM minimums_conso WHERE id = ? AND etablissement_id = ?', [$id, $etabId]);
        Journal::ecrire('minimum_supprime', (string) $id, [], $par);
    }

    /** Minimum levé pour cette note (PIN d'un gérant). */
    public function lever(int $noteId, int $par): void
    {
        Database::executer('UPDATE notes SET minimum_leve_par = ?, minimum_leve_le = NOW(), version = version + 1 WHERE id = ?', [$par, $noteId]);
    }
}
