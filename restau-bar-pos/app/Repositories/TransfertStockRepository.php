<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Journal;

/**
 * Transferts de marchandise entre lieux (dépôt → comptoir, comptoir → comptoir, dépôt central → dépôt d'un
 * établissement), validés des deux côtés : brouillon → expédié (le stock quitte la source) → reçu (le stock
 * entre dans la destination pour la quantité reçue ; l'écart avec l'envoi est gardé). Un envoi peut être
 * annulé avant la réception : la marchandise revient à la source.
 */
final class TransfertStockRepository
{
    public const STATUTS = ['brouillon', 'expedie', 'recu', 'annule'];

    private StockRepository $stock;

    public function __construct(?StockRepository $stock = null)
    {
        $this->stock = $stock ?? new StockRepository();
    }

    /**
     * Transferts touchant ces lieux (source ou destination), les plus récents d'abord.
     * @param list<int> $lieuIds
     */
    public function liste(array $lieuIds, ?string $statut, string $debut, string $fin): array
    {
        if ($lieuIds === []) {
            return [];
        }
        $marques = implode(',', array_fill(0, count($lieuIds), '?'));
        $conditions = ['(t.lieu_source_id IN (' . $marques . ') OR t.lieu_destination_id IN (' . $marques . '))', '(t.statut IN (\'brouillon\', \'expedie\') OR t.cree_le BETWEEN ? AND ?)'];
        $params = array_merge(array_values($lieuIds), array_values($lieuIds), [$debut, $fin]);
        if ($statut !== null) {
            $conditions[] = 't.statut = ?';
            $params[] = $statut;
        }
        return Database::tous(
            'SELECT t.*, ls.nom AS source_nom, ls.etablissement_id AS source_etablissement_id, es.nom AS source_etablissement,
                    ld.nom AS destination_nom, ld.etablissement_id AS destination_etablissement_id, ed.nom AS destination_etablissement,
                    uc.nom AS createur, ue.nom AS expediteur, ur.nom AS receveur,
                    (SELECT COUNT(*) FROM transfert_stock_lignes x WHERE x.transfert_id = t.id) AS nb_lignes,
                    (SELECT COUNT(*) FROM transfert_stock_lignes x WHERE x.transfert_id = t.id
                        AND x.quantite_recue_base IS NOT NULL AND ABS(x.quantite_recue_base - x.quantite_base) > 0.0005) AS nb_ecarts
               FROM transferts_stock t
               JOIN lieux_stock ls ON ls.id = t.lieu_source_id
               JOIN lieux_stock ld ON ld.id = t.lieu_destination_id
          LEFT JOIN etablissements es ON es.id = ls.etablissement_id
          LEFT JOIN etablissements ed ON ed.id = ld.etablissement_id
          LEFT JOIN utilisateurs uc ON uc.id = t.cree_par
          LEFT JOIN utilisateurs ue ON ue.id = t.expedie_par
          LEFT JOIN utilisateurs ur ON ur.id = t.recu_par
              WHERE ' . implode(' AND ', $conditions) . '
              ORDER BY FIELD(t.statut, \'expedie\', \'brouillon\', \'recu\', \'annule\'), t.cree_le DESC, t.id DESC
              LIMIT 300',
            $params
        );
    }

    public function transfert(int $id): ?array
    {
        $t = Database::un(
            'SELECT t.*, ls.nom AS source_nom, ls.etablissement_id AS source_etablissement_id, es.nom AS source_etablissement,
                    ld.nom AS destination_nom, ld.etablissement_id AS destination_etablissement_id, ed.nom AS destination_etablissement,
                    uc.nom AS createur, ue.nom AS expediteur, ur.nom AS receveur, ua.nom AS annuleur
               FROM transferts_stock t
               JOIN lieux_stock ls ON ls.id = t.lieu_source_id
               JOIN lieux_stock ld ON ld.id = t.lieu_destination_id
          LEFT JOIN etablissements es ON es.id = ls.etablissement_id
          LEFT JOIN etablissements ed ON ed.id = ld.etablissement_id
          LEFT JOIN utilisateurs uc ON uc.id = t.cree_par
          LEFT JOIN utilisateurs ue ON ue.id = t.expedie_par
          LEFT JOIN utilisateurs ur ON ur.id = t.recu_par
          LEFT JOIN utilisateurs ua ON ua.id = t.annule_par
              WHERE t.id = ?',
            [$id]
        );
        if ($t === null) {
            return null;
        }
        $t['lignes'] = Database::tous(
            'SELECT x.*, p.nom_fr, p.nom_en, p.unite_base, c.nom_fr AS format_nom_fr, c.nom_en AS format_nom_en, c.quantite_base AS format_base
               FROM transfert_stock_lignes x
               JOIN produits p ON p.id = x.produit_id
          LEFT JOIN conditionnements c ON c.id = x.conditionnement_id
              WHERE x.transfert_id = ? ORDER BY x.position, x.id',
            [$id]
        );
        return $t;
    }

    /**
     * @param list<array{produit_id: int, conditionnement_id: ?int, quantite: float, quantite_base: float}> $lignes
     * @param bool $expedier confirmer la sortie tout de suite
     */
    public function creer(int $sourceId, int $destinationId, array $lignes, ?string $notes, int $utilisateurId, bool $expedier, ?string $date = null): int
    {
        return $this->transaction(function () use ($sourceId, $destinationId, $lignes, $notes, $utilisateurId, $expedier, $date): int {
            Database::executer(
                'INSERT INTO transferts_stock (lieu_source_id, lieu_destination_id, notes, cree_par, cree_le) VALUES (?, ?, ?, ?, ?)',
                [$sourceId, $destinationId, $notes, $utilisateurId, $date ?? date('Y-m-d H:i:s')]
            );
            $id = Database::dernierId();
            Database::executer('UPDATE transferts_stock SET numero = ? WHERE id = ?', [sprintf('TR-%06d', $id), $id]);
            foreach (array_values($lignes) as $position => $l) {
                Database::executer(
                    'INSERT INTO transfert_stock_lignes (transfert_id, produit_id, conditionnement_id, quantite, quantite_base, position) VALUES (?, ?, ?, ?, ?, ?)',
                    [$id, $l['produit_id'], $l['conditionnement_id'], StockRepository::q($l['quantite']), StockRepository::q($l['quantite_base']), $position]
                );
            }
            if ($expedier) {
                $this->expedier($id, $utilisateurId, $date);
            }
            return $id;
        });
    }

    /** L'expéditeur confirme la sortie : le stock quitte la source. Faux si le transfert n'est plus un brouillon. */
    public function expedier(int $id, int $utilisateurId, ?string $date = null): bool
    {
        return $this->transaction(function () use ($id, $utilisateurId, $date): bool {
            $t = Database::un('SELECT * FROM transferts_stock WHERE id = ? FOR UPDATE', [$id]);
            if ($t === null || $t['statut'] !== 'brouillon') {
                return false;
            }
            $date ??= date('Y-m-d H:i:s');
            $destination = $this->stock->lieu((int) $t['lieu_destination_id']);
            $motif = $t['numero'] . ' → ' . ($destination['nom'] ?? '');
            foreach (Database::tous('SELECT * FROM transfert_stock_lignes WHERE transfert_id = ? ORDER BY position, id', [$id]) as $l) {
                $this->stock->mouvement((int) $t['lieu_source_id'], (int) $l['produit_id'], -(float) $l['quantite_base'], 'transfert_sortie', $id,
                    $date, $motif, $utilisateurId, $l['conditionnement_id'] !== null ? (int) $l['conditionnement_id'] : null, (float) $l['quantite']);
            }
            Database::executer('UPDATE transferts_stock SET statut = \'expedie\', expedie_par = ?, expedie_le = ? WHERE id = ?', [$utilisateurId, $date, $id]);
            Journal::ecrire('stock_transfert_expedie', $t['numero'], [], $utilisateurId);
            return true;
        });
    }

    /**
     * Le lieu qui reçoit confirme la réception, ligne par ligne (quantité reçue en unités de base).
     * @param array<int, float> $recues ligne_id => quantité reçue (unités de base)
     */
    public function recevoir(int $id, array $recues, ?string $note, int $utilisateurId, ?string $date = null): bool
    {
        return $this->transaction(function () use ($id, $recues, $note, $utilisateurId, $date): bool {
            $t = Database::un('SELECT * FROM transferts_stock WHERE id = ? FOR UPDATE', [$id]);
            if ($t === null || $t['statut'] !== 'expedie') {
                return false;
            }
            $date ??= date('Y-m-d H:i:s');
            $source = $this->stock->lieu((int) $t['lieu_source_id']);
            $motif = $t['numero'] . ' ← ' . ($source['nom'] ?? '');
            $ecarts = 0;
            foreach (Database::tous('SELECT * FROM transfert_stock_lignes WHERE transfert_id = ? ORDER BY position, id', [$id]) as $l) {
                $recu = $recues[(int) $l['id']] ?? (float) $l['quantite_base'];
                Database::executer('UPDATE transfert_stock_lignes SET quantite_recue_base = ? WHERE id = ?', [StockRepository::q($recu), (int) $l['id']]);
                if (abs($recu - (float) $l['quantite_base']) > 0.0005) {
                    $ecarts++;
                }
                if ($recu > 0.0005) {
                    $this->stock->mouvement((int) $t['lieu_destination_id'], (int) $l['produit_id'], $recu, 'transfert_entree', $id, $date,
                        $motif, $utilisateurId);
                }
            }
            Database::executer('UPDATE transferts_stock SET statut = \'recu\', recu_par = ?, recu_le = ?, note_reception = ? WHERE id = ?', [$utilisateurId, $date, $note, $id]);
            Journal::ecrire('stock_transfert_recu', $t['numero'], $ecarts > 0 ? ['ecarts' => $ecarts] : [], $utilisateurId);
            return true;
        });
    }

    /** Annule un brouillon, ou un envoi pas encore reçu (la marchandise revient à la source). */
    public function annuler(int $id, int $utilisateurId): bool
    {
        return $this->transaction(function () use ($id, $utilisateurId): bool {
            $t = Database::un('SELECT * FROM transferts_stock WHERE id = ? FOR UPDATE', [$id]);
            if ($t === null || !in_array($t['statut'], ['brouillon', 'expedie'], true)) {
                return false;
            }
            $date = date('Y-m-d H:i:s');
            if ($t['statut'] === 'expedie') {
                foreach (Database::tous('SELECT * FROM transfert_stock_lignes WHERE transfert_id = ?', [$id]) as $l) {
                    $this->stock->mouvement((int) $t['lieu_source_id'], (int) $l['produit_id'], (float) $l['quantite_base'], 'transfert_retour', $id,
                        $date, 'Annulation ' . $t['numero'], $utilisateurId);
                }
            }
            Database::executer('UPDATE transferts_stock SET statut = \'annule\', annule_par = ?, annule_le = ? WHERE id = ?', [$utilisateurId, $date, $id]);
            Journal::ecrire('stock_transfert_annule', $t['numero'], [], $utilisateurId);
            return true;
        });
    }

    private function transaction(callable $fonction): mixed
    {
        return (new GestionTresorerieRepository())->transaction($fonction);
    }
}
