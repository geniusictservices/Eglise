<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Journee;

/**
 * Plan de salle et réservations (cahier des charges, section 4). Chaque zone (Salle, Terrasse, Carré VIP) a son
 * plan : une grille de COLONNES × RANGEES cases où chaque table a sa position et sa taille.
 * Statut d'une table : libre, occupée (note ouverte), addition demandée, réservée (réservation à venir du jour).
 */
final class SalleRepository
{
    public const COLONNES = 24;
    public const RANGEES = 16;
    public const FORMES = ['carree', 'ronde', 'rectangle', 'banquette'];
    public const STATUTS_RESA = ['prevue', 'arrivee', 'annulee', 'absente'];
    /** Une table est « réservée » de 3 h avant l'heure prévue à 30 min après */
    public const RESA_AVANT_MIN = 180;
    public const RESA_APRES_MIN = 30;

    /** @return list<array> tables actives (ou toutes) de l'établissement, rangées par zone puis position */
    public function tables(int $etabId, bool $actives = true): array
    {
        return Database::tous(
            'SELECT t.*, z.code AS zone_code, z.nom_fr AS zone_nom_fr, z.nom_en AS zone_nom_en, z.grille_prix_id AS zone_grille
               FROM salle_tables t LEFT JOIN zones z ON z.id = t.zone_id
              WHERE t.etablissement_id = ?' . ($actives ? ' AND t.actif = 1' : '') . '
              ORDER BY z.id IS NULL, z.id, t.y, t.x, t.id',
            [$etabId]
        );
    }

    public function table(int $id): ?array
    {
        return Database::un(
            'SELECT t.*, z.code AS zone_code, z.grille_prix_id AS zone_grille FROM salle_tables t LEFT JOIN zones z ON z.id = t.zone_id WHERE t.id = ?',
            [$id]
        );
    }

    /** Nom déjà pris par une autre table active de l'établissement ? */
    public function nomPris(int $etabId, string $nom, ?int $sauf = null): bool
    {
        return Database::un(
            'SELECT id FROM salle_tables WHERE etablissement_id = ? AND actif = 1 AND nom = ?' . ($sauf ? ' AND id <> ?' : ''),
            $sauf ? [$etabId, $nom, $sauf] : [$etabId, $nom]
        ) !== null;
    }

    /** @param array{zone_id: ?int, nom: string, places: int, forme: string, x: int, y: int, largeur: int, hauteur: int} $t */
    public function creer(int $etabId, array $t): int
    {
        Database::executer(
            'INSERT INTO salle_tables (etablissement_id, zone_id, nom, places, forme, x, y, largeur, hauteur) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$etabId, $t['zone_id'], $t['nom'], $t['places'], $t['forme'], $t['x'], $t['y'], $t['largeur'], $t['hauteur']]
        );
        return Database::dernierId();
    }

    public function modifier(int $id, array $t): void
    {
        Database::executer(
            'UPDATE salle_tables SET zone_id = ?, nom = ?, places = ?, forme = ?, x = ?, y = ?, largeur = ?, hauteur = ? WHERE id = ?',
            [$t['zone_id'], $t['nom'], $t['places'], $t['forme'], $t['x'], $t['y'], $t['largeur'], $t['hauteur'], $id]
        );
    }

    /** Position et taille seulement (glisser-déposer). */
    public function placer(int $id, int $x, int $y, int $largeur, int $hauteur): void
    {
        Database::executer('UPDATE salle_tables SET x = ?, y = ?, largeur = ?, hauteur = ? WHERE id = ?', [$x, $y, $largeur, $hauteur, $id]);
    }

    /** Retire une table du plan (gardée pour l'historique des notes). */
    public function retirer(int $id): void
    {
        Database::executer('UPDATE salle_tables SET actif = 0 WHERE id = ?', [$id]);
    }

    /** Position, taille et forme valides dans la grille ; la table reste entière dans le plan. */
    public static function borner(array $t): array
    {
        $t['largeur'] = max(1, min(self::COLONNES, (int) $t['largeur']));
        $t['hauteur'] = max(1, min(self::RANGEES, (int) $t['hauteur']));
        $t['x'] = max(0, min(self::COLONNES - $t['largeur'], (int) $t['x']));
        $t['y'] = max(0, min(self::RANGEES - $t['hauteur'], (int) $t['y']));
        $t['places'] = max(1, min(50, (int) $t['places']));
        $t['forme'] = in_array($t['forme'] ?? '', self::FORMES, true) ? $t['forme'] : 'carree';
        return $t;
    }

    // ---------- Réservations ----------

    /**
     * Réservations d'une journée d'exploitation (de l'heure de fin de journée au lendemain même heure).
     * @return list<array>
     */
    public function reservations(array $etab, string $jour, bool $avecAnnulees = true): array
    {
        [$debut, $fin] = Journee::bornes($jour, $etab);
        return Database::tous(
            'SELECT r.*, t.nom AS table_nom, u.nom AS cree_par_nom FROM reservations r
          LEFT JOIN salle_tables t ON t.id = r.table_id LEFT JOIN utilisateurs u ON u.id = r.cree_par
              WHERE r.etablissement_id = ? AND r.debut >= ? AND r.debut < ?' . ($avecAnnulees ? '' : " AND r.statut <> 'annulee'") . '
              ORDER BY r.debut, r.id',
            [(int) $etab['id'], $debut, $fin]
        );
    }

    public function reservation(int $id): ?array
    {
        return Database::un('SELECT * FROM reservations WHERE id = ?', [$id]);
    }

    /** @param array{debut: string, nom: string, telephone: ?string, personnes: int, table_id: ?int, notes: ?string} $r */
    public function enregistrerReservation(int $etabId, ?int $id, array $r, int $par): int
    {
        if ($id === null) {
            Database::executer(
                'INSERT INTO reservations (etablissement_id, debut, nom, telephone, personnes, table_id, notes, cree_par) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$etabId, $r['debut'], $r['nom'], $r['telephone'], $r['personnes'], $r['table_id'], $r['notes'], $par]
            );
            return Database::dernierId();
        }
        Database::executer(
            'UPDATE reservations SET debut = ?, nom = ?, telephone = ?, personnes = ?, table_id = ?, notes = ? WHERE id = ? AND etablissement_id = ?',
            [$r['debut'], $r['nom'], $r['telephone'], $r['personnes'], $r['table_id'], $r['notes'], $id, $etabId]
        );
        return $id;
    }

    public function statutReservation(int $id, string $statut): void
    {
        if (in_array($statut, self::STATUTS_RESA, true)) {
            Database::executer('UPDATE reservations SET statut = ? WHERE id = ?', [$statut, $id]);
        }
    }

    /**
     * Réservation qui rend chaque table « réservée » maintenant : prévue, de 3 h avant l'heure à 30 min après.
     * @return array<int, array> table → réservation
     */
    public function reserveesMaintenant(int $etabId): array
    {
        $maintenant = time();
        $lignes = Database::tous(
            "SELECT id, table_id, debut, nom, personnes, telephone, notes FROM reservations
              WHERE etablissement_id = ? AND statut = 'prevue' AND table_id IS NOT NULL AND debut BETWEEN ? AND ? ORDER BY debut",
            [$etabId, date('Y-m-d H:i:s', $maintenant - self::RESA_APRES_MIN * 60), date('Y-m-d H:i:s', $maintenant + self::RESA_AVANT_MIN * 60)]
        );
        $parTable = [];
        foreach ($lignes as $l) {
            $parTable[(int) $l['table_id']] ??= $l;
        }
        return $parTable;
    }

    /**
     * État de la salle pour la caisse : chaque table avec son statut (libre, occupee, addition, reservee), sa note
     * (reste, durée, serveur, plats prêts), sa réservation ; et les notes sans table (notes nommées).
     * @return array{tables: array<int, array>, notes: list<array>}
     */
    public function etat(int $etabId, NoteRepository $notes): array
    {
        $tables = [];
        $reservees = $this->reserveesMaintenant($etabId);
        foreach ($this->tables($etabId) as $t) {
            $id = (int) $t['id'];
            $r = $reservees[$id] ?? null;
            $tables[$id] = ['statut' => $r ? 'reservee' : 'libre', 'note' => null, 'reservation' => $r ? self::resumeResa($r) : null];
        }
        $sansTable = [];
        foreach ($notes->ouvertes($etabId) as $n) {
            $resume = self::resumeNote($n);
            $tableId = $n['table_id'] !== null ? (int) $n['table_id'] : null;
            if ($tableId !== null && isset($tables[$tableId]) && $tables[$tableId]['note'] === null) {
                $tables[$tableId]['note'] = $resume;
                $tables[$tableId]['statut'] = $n['addition_le'] !== null ? 'addition' : 'occupee';
            } else {
                $sansTable[] = $resume;
            }
        }
        return ['tables' => $tables, 'notes' => $sansTable];
    }

    public static function resumeNote(array $n): array
    {
        return [
            'id' => (int) $n['id'], 'table' => $n['table_id'] !== null ? (int) $n['table_id'] : null, 'tableNom' => $n['table_nom'] ?? null,
            'nom' => $n['nom'], 'reste' => round((float) $n['reste'], 3), 'depuis' => $n['ouverte_le'],
            'minutes' => (int) floor((time() - strtotime($n['ouverte_le'])) / 60),
            'serveur' => $n['serveur_nom'], 'serveurId' => $n['serveur_id'] !== null ? (int) $n['serveur_id'] : null,
            'addition' => $n['addition_le'] !== null, 'prets' => (int) $n['prets'], 'attente' => (int) $n['attente'],
            'lignes' => (int) $n['lignes'], 'version' => (int) $n['version'],
        ];
    }

    private static function resumeResa(array $r): array
    {
        return ['id' => (int) $r['id'], 'nom' => $r['nom'], 'heure' => date('H:i', strtotime($r['debut'])), 'personnes' => (int) $r['personnes'], 'notes' => $r['notes']];
    }
}
