<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Journal;

/**
 * Inventaires (comptage physique) d'un lieu : complet, de fin de service (comptoir) ou partiel (une catégorie).
 *
 * À la création, le stock attendu de chaque article est figé, avec son détail depuis l'inventaire validé
 * précédent du lieu : stock du début + entrées − ventes − pertes ± autres = attendu. On compte ensuite
 * dans n'importe quel format (casiers, bouteilles, bouteille entamée en fraction ou en cl). À la validation
 * (de préférence par une autre personne que celle qui a compté), le stock est corrigé de l'écart
 * compté − attendu ; les ventes faites depuis la création restent comptées.
 */
final class InventaireRepository
{
    public const TYPES = ['complet', 'service', 'partiel'];

    private StockRepository $stock;

    public function __construct(?StockRepository $stock = null)
    {
        $this->stock = $stock ?? new StockRepository();
    }

    /** @param list<int> $lieuIds */
    public function liste(array $lieuIds, ?string $statut): array
    {
        if ($lieuIds === []) {
            return [];
        }
        $conditions = ['i.lieu_id IN (' . implode(',', array_fill(0, count($lieuIds), '?')) . ')'];
        $params = array_values($lieuIds);
        if ($statut !== null) {
            $conditions[] = 'i.statut = ?';
            $params[] = $statut;
        }
        return Database::tous(
            'SELECT i.*, l.nom AS lieu_nom, l.type AS lieu_type, l.etablissement_id, e.nom AS etablissement_nom,
                    uc.nom AS createur, uk.nom AS compteur, uv.nom AS validateur,
                    (SELECT COUNT(*) FROM inventaire_lignes x WHERE x.inventaire_id = i.id) AS nb_lignes,
                    (SELECT COUNT(*) FROM inventaire_lignes x WHERE x.inventaire_id = i.id AND x.compte IS NOT NULL) AS nb_comptees,
                    (SELECT COUNT(*) FROM inventaire_lignes x WHERE x.inventaire_id = i.id AND x.compte IS NOT NULL AND ABS(x.compte - x.attendu) > 0.0005) AS nb_ecarts,
                    (SELECT SUM((x.compte - x.attendu) * x.cout_unitaire) FROM inventaire_lignes x WHERE x.inventaire_id = i.id AND x.compte IS NOT NULL) AS valeur_ecarts
               FROM inventaires i
               JOIN lieux_stock l ON l.id = i.lieu_id
          LEFT JOIN etablissements e ON e.id = l.etablissement_id
          LEFT JOIN utilisateurs uc ON uc.id = i.cree_par
          LEFT JOIN utilisateurs uk ON uk.id = i.compte_par
          LEFT JOIN utilisateurs uv ON uv.id = i.valide_par
              WHERE ' . implode(' AND ', $conditions) . '
              ORDER BY FIELD(i.statut, \'en_cours\', \'compte\', \'valide\', \'annule\'), i.cree_le DESC, i.id DESC
              LIMIT 200',
            $params
        );
    }

    public function inventaire(int $id): ?array
    {
        $inventaire = Database::un(
            'SELECT i.*, l.nom AS lieu_nom, l.type AS lieu_type, l.etablissement_id, e.nom AS etablissement_nom,
                    uc.nom AS createur, uk.nom AS compteur, uv.nom AS validateur, c.nom_fr AS categorie_nom_fr, c.nom_en AS categorie_nom_en
               FROM inventaires i
               JOIN lieux_stock l ON l.id = i.lieu_id
          LEFT JOIN etablissements e ON e.id = l.etablissement_id
          LEFT JOIN utilisateurs uc ON uc.id = i.cree_par
          LEFT JOIN utilisateurs uk ON uk.id = i.compte_par
          LEFT JOIN utilisateurs uv ON uv.id = i.valide_par
          LEFT JOIN categories c ON c.id = i.categorie_id
              WHERE i.id = ?',
            [$id]
        );
        if ($inventaire === null) {
            return null;
        }
        $inventaire['lignes'] = array_column(Database::tous('SELECT * FROM inventaire_lignes WHERE inventaire_id = ? ORDER BY id', [$id]), null, 'produit_id');
        return $inventaire;
    }

    /** Inventaire en cours (pas encore validé) d'un lieu. */
    public function enCours(int $lieuId): ?array
    {
        return Database::un('SELECT * FROM inventaires WHERE lieu_id = ? AND statut IN (\'en_cours\', \'compte\') ORDER BY id DESC LIMIT 1', [$lieuId]);
    }

    /**
     * @param list<int> $produitIds articles à compter
     */
    public function creer(int $lieuId, string $type, ?int $categorieId, array $produitIds, ?string $notes, int $utilisateurId, ?string $date = null): int
    {
        return $this->transaction(function () use ($lieuId, $type, $categorieId, $produitIds, $notes, $utilisateurId, $date): int {
            $date ??= date('Y-m-d H:i:s');
            Database::executer(
                'INSERT INTO inventaires (lieu_id, type, categorie_id, fige_le, notes, cree_par, cree_le) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$lieuId, $type, $categorieId, $date, $notes, $utilisateurId, $date]
            );
            $id = Database::dernierId();
            Database::executer('UPDATE inventaires SET numero = ? WHERE id = ?', [sprintf('INV-%06d', $id), $id]);
            foreach (array_unique($produitIds) as $produitId) {
                $this->ajouterLigne($id, $lieuId, (int) $produitId, $date);
            }
            Journal::ecrire('stock_inventaire_cree', sprintf('INV-%06d', $id), ['lieu' => $lieuId, 'type' => $type], $utilisateurId);
            return $id;
        });
    }

    /** Ajoute un article oublié à un inventaire en cours (attendu figé à maintenant). */
    public function ajouterArticle(int $id, int $produitId): void
    {
        $inventaire = Database::un('SELECT * FROM inventaires WHERE id = ?', [$id]);
        if ($inventaire === null || !in_array($inventaire['statut'], ['en_cours', 'compte'], true)) {
            return;
        }
        $this->ajouterLigne($id, (int) $inventaire['lieu_id'], $produitId, (string) $inventaire['fige_le']);
    }

    /** Refige le stock attendu de toutes les lignes (inventaire pas encore compté entièrement). */
    public function actualiser(int $id): void
    {
        $this->transaction(function () use ($id): void {
            $inventaire = Database::un('SELECT * FROM inventaires WHERE id = ? FOR UPDATE', [$id]);
            if ($inventaire === null || $inventaire['statut'] !== 'en_cours') {
                return;
            }
            $date = date('Y-m-d H:i:s');
            Database::executer('UPDATE inventaires SET fige_le = ? WHERE id = ?', [$date, $id]);
            foreach (Database::tous('SELECT produit_id FROM inventaire_lignes WHERE inventaire_id = ?', [$id]) as $l) {
                $detail = $this->detail((int) $inventaire['lieu_id'], (int) $l['produit_id'], $date);
                Database::executer(
                    'UPDATE inventaire_lignes SET debut = ?, entrees = ?, ventes = ?, pertes = ?, autres = ?, attendu = ? WHERE inventaire_id = ? AND produit_id = ?',
                    [...array_map([StockRepository::class, 'q'], array_values($detail)), $id, (int) $l['produit_id']]
                );
            }
        });
    }

    private function ajouterLigne(int $id, int $lieuId, int $produitId, string $date): void
    {
        $detail = $this->detail($lieuId, $produitId, $date);
        Database::executer(
            'INSERT IGNORE INTO inventaire_lignes (inventaire_id, produit_id, debut, entrees, ventes, pertes, autres, attendu) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $produitId, ...array_map([StockRepository::class, 'q'], array_values($detail))]
        );
    }

    /**
     * Stock attendu d'un article à une date, détaillé depuis l'inventaire validé précédent du lieu.
     * @return array{debut: float, entrees: float, ventes: float, pertes: float, autres: float, attendu: float}
     */
    public function detail(int $lieuId, int $produitId, string $date): array
    {
        $precedent = Database::un(
            'SELECT valide_le FROM inventaires WHERE lieu_id = ? AND statut = \'valide\' AND valide_le <= ? ORDER BY valide_le DESC LIMIT 1',
            [$lieuId, $date]
        );
        $depuis = $precedent['valide_le'] ?? '1000-01-01 00:00:00';
        $sommes = Database::un(
            'SELECT COALESCE(SUM(IF(date_mouvement <= ?, quantite, 0)), 0) AS attendu,
                    COALESCE(SUM(IF(date_mouvement > ? AND date_mouvement <= ? AND type IN (\'reception\', \'transfert_entree\', \'ouverture\', \'vide_recupere\'), quantite, 0)), 0) AS entrees,
                    COALESCE(SUM(IF(date_mouvement > ? AND date_mouvement <= ? AND type IN (\'vente\', \'recette\', \'annulation_vente\'), -quantite, 0)), 0) AS ventes,
                    COALESCE(SUM(IF(date_mouvement > ? AND date_mouvement <= ? AND type = \'perte\', -quantite, 0)), 0) AS pertes,
                    COALESCE(SUM(IF(date_mouvement > ? AND date_mouvement <= ? AND type IN (\'transfert_sortie\', \'transfert_retour\', \'inventaire\', \'ajustement\', \'vide_rendu\', \'production\'), quantite, 0)), 0) AS autres
               FROM stock_mouvements WHERE lieu_id = ? AND produit_id = ?',
            [$date, $depuis, $date, $depuis, $date, $depuis, $date, $depuis, $date, $lieuId, $produitId]
        );
        $attendu = (float) $sommes['attendu'];
        $entrees = (float) $sommes['entrees'];
        $ventes = (float) $sommes['ventes'];
        $pertes = (float) $sommes['pertes'];
        $autres = (float) $sommes['autres'];
        return [
            'debut' => round($attendu - $entrees + $ventes + $pertes - $autres, 3),
            'entrees' => $entrees, 'ventes' => $ventes, 'pertes' => $pertes, 'autres' => $autres, 'attendu' => $attendu,
        ];
    }

    /**
     * Enregistre le comptage (lignes absentes : inchangées ; null : pas encore compté).
     * @param array<int, array{compte: ?float, saisie: ?string}> $comptes produit_id => comptage
     */
    public function compter(int $id, array $comptes, int $utilisateurId, bool $terminer): bool
    {
        return $this->transaction(function () use ($id, $comptes, $utilisateurId, $terminer): bool {
            $inventaire = Database::un('SELECT * FROM inventaires WHERE id = ? FOR UPDATE', [$id]);
            if ($inventaire === null || !in_array($inventaire['statut'], ['en_cours', 'compte'], true)) {
                return false;
            }
            foreach ($comptes as $produitId => $c) {
                Database::executer(
                    'UPDATE inventaire_lignes SET compte = ?, saisie = ? WHERE inventaire_id = ? AND produit_id = ?',
                    [$c['compte'] !== null ? StockRepository::q($c['compte']) : null, $c['saisie'], $id, $produitId]
                );
            }
            Database::executer(
                'UPDATE inventaires SET compte_par = ?, compte_le = NOW()' . ($terminer ? ', statut = \'compte\'' : '') . ' WHERE id = ?',
                [$utilisateurId, $id]
            );
            return true;
        });
    }

    /**
     * Valide l'inventaire : le stock de chaque article compté est corrigé de son écart.
     * @param array<int, string> $justifications produit_id => justification de l'écart
     */
    public function valider(int $id, array $justifications, int $utilisateurId, bool $seul, ?string $date = null): bool
    {
        return $this->transaction(function () use ($id, $justifications, $utilisateurId, $seul, $date): bool {
            $inventaire = Database::un('SELECT * FROM inventaires WHERE id = ? FOR UPDATE', [$id]);
            if ($inventaire === null || $inventaire['statut'] !== 'compte') {
                return false;
            }
            $date ??= date('Y-m-d H:i:s');
            foreach (Database::tous('SELECT * FROM inventaire_lignes WHERE inventaire_id = ? AND compte IS NOT NULL', [$id]) as $l) {
                $produitId = (int) $l['produit_id'];
                $ecart = round((float) $l['compte'] - (float) $l['attendu'], 3);
                $cout = $this->stock->cump($produitId);
                Database::executer(
                    'UPDATE inventaire_lignes SET cout_unitaire = ?, justification = ? WHERE id = ?',
                    [$cout !== null ? number_format($cout, 6, '.', '') : null, isset($justifications[$produitId]) && $justifications[$produitId] !== '' ? mb_substr($justifications[$produitId], 0, 255) : null, (int) $l['id']]
                );
                if (abs($ecart) > 0.0005) {
                    $motif = $inventaire['numero'] . (isset($justifications[$produitId]) && $justifications[$produitId] !== '' ? ' : ' . $justifications[$produitId] : '');
                    $this->stock->mouvement((int) $inventaire['lieu_id'], $produitId, $ecart, 'inventaire', $id, $date, $motif, $utilisateurId);
                }
            }
            Database::executer(
                'UPDATE inventaires SET statut = \'valide\', valide_par = ?, valide_le = ?, valide_seul = ? WHERE id = ?',
                [$utilisateurId, $date, (int) $seul, $id]
            );
            Journal::ecrire('stock_inventaire_valide', $inventaire['numero'], $seul ? ['seul' => true] : [], $utilisateurId);
            return true;
        });
    }

    /** Reprend le comptage d'un inventaire compté (pas encore validé). */
    public function rouvrir(int $id): bool
    {
        return Database::executer('UPDATE inventaires SET statut = \'en_cours\' WHERE id = ? AND statut = \'compte\'', [$id]) > 0;
    }

    public function annuler(int $id, int $utilisateurId): bool
    {
        $fait = Database::executer(
            'UPDATE inventaires SET statut = \'annule\', annule_par = ?, annule_le = NOW() WHERE id = ? AND statut IN (\'en_cours\', \'compte\')',
            [$utilisateurId, $id]
        ) > 0;
        if ($fait) {
            Journal::ecrire('stock_inventaire_annule', sprintf('INV-%06d', $id), [], $utilisateurId);
        }
        return $fait;
    }

    private function transaction(callable $fonction): mixed
    {
        return (new GestionTresorerieRepository())->transaction($fonction);
    }
}
