<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Dépenses et catégories de dépenses. Chaque paiement d'une dépense sort d'un compte de trésorerie
 * (qui donne le moyen et la devise) et écrit sa ligne dans le journal, dans la même transaction.
 */
final class GestionDepenseRepository
{
    private GestionTresorerieRepository $tresorerie;

    public function __construct(?GestionTresorerieRepository $tresorerie = null)
    {
        $this->tresorerie = $tresorerie ?? new GestionTresorerieRepository();
    }

    // ---------- Catégories ----------

    /** Catégories, actives d'abord, avec le nombre de dépenses de chacune. */
    public function categories(): array
    {
        return Database::tous(
            'SELECT c.*, (SELECT COUNT(*) FROM depenses d WHERE d.categorie_id = c.id) AS nb_depenses
               FROM categories_depenses c
              ORDER BY c.active DESC, c.position, c.nom_fr'
        );
    }

    public function categorie(int $id): ?array
    {
        foreach ($this->categories() as $categorie) {
            if ((int) $categorie['id'] === $id) {
                return $categorie;
            }
        }
        return null;
    }

    public function nomCategoriePris(string $nom, int $saufId = 0): bool
    {
        return Database::un('SELECT id FROM categories_depenses WHERE nom_fr = ? AND id <> ?', [$nom, $saufId]) !== null;
    }

    public function positionCategorieSuivante(): int
    {
        $ligne = Database::un('SELECT MAX(position) AS p FROM categories_depenses');
        return min(999, (int) ($ligne['p'] ?? 0) + 1);
    }

    public function creerCategorie(string $nomFr, string $nomEn, int $position): int
    {
        Database::executer('INSERT INTO categories_depenses (nom_fr, nom_en, position) VALUES (?, ?, ?)', [$nomFr, $nomEn, $position]);
        return Database::dernierId();
    }

    public function modifierCategorie(int $id, string $nomFr, string $nomEn, int $position): void
    {
        Database::executer('UPDATE categories_depenses SET nom_fr = ?, nom_en = ?, position = ? WHERE id = ?', [$nomFr, $nomEn, $position, $id]);
    }

    public function definirCategorieActive(int $id, bool $active): void
    {
        Database::executer('UPDATE categories_depenses SET active = ? WHERE id = ?', [(int) $active, $id]);
    }

    /** Suppression d'une catégorie qu'aucune dépense n'utilise. */
    public function supprimerCategorie(int $id): void
    {
        Database::executer('DELETE FROM categories_depenses WHERE id = ? AND NOT EXISTS (SELECT 1 FROM depenses WHERE categorie_id = ?)', [$id, $id]);
    }

    // ---------- Dépenses ----------

    /**
     * Dépenses d'une période, les plus récentes d'abord, avec leurs paiements.
     * @param array{debut: string, fin: string, categorie: ?int, etablissement: string, moyen: ?string} $filtres
     *        etablissement : '' (tous), 'commun' (dépenses communes) ou l'id d'un établissement
     */
    public function depenses(array $filtres): array
    {
        $conditions = ['d.date_depense BETWEEN ? AND ?'];
        $params = [$filtres['debut'], $filtres['fin']];
        if ($filtres['categorie']) {
            $conditions[] = 'd.categorie_id = ?';
            $params[] = $filtres['categorie'];
        }
        if ($filtres['etablissement'] === 'commun') {
            $conditions[] = 'd.etablissement_id IS NULL';
        } elseif ($filtres['etablissement'] !== '') {
            $conditions[] = 'd.etablissement_id = ?';
            $params[] = (int) $filtres['etablissement'];
        }
        if ($filtres['moyen']) {
            $conditions[] = 'EXISTS (SELECT 1 FROM depense_paiements p JOIN comptes_tresorerie c ON c.id = p.compte_id
                                      WHERE p.depense_id = d.id AND c.type = ?)';
            $params[] = $filtres['moyen'];
        }
        $depenses = Database::tous(
            'SELECT d.*, cat.nom_fr AS categorie_nom_fr, cat.nom_en AS categorie_nom_en, e.nom AS etablissement_nom, u.nom AS auteur
               FROM depenses d
               JOIN categories_depenses cat ON cat.id = d.categorie_id
               LEFT JOIN etablissements e ON e.id = d.etablissement_id
               LEFT JOIN utilisateurs u ON u.id = d.utilisateur_id
              WHERE ' . implode(' AND ', $conditions) . '
              ORDER BY d.date_depense DESC, d.id DESC
              LIMIT 500',
            $params
        );
        return $this->avecPaiements($depenses);
    }

    public function depense(int $id): ?array
    {
        $depense = Database::un(
            'SELECT d.*, cat.nom_fr AS categorie_nom_fr, cat.nom_en AS categorie_nom_en, e.nom AS etablissement_nom, u.nom AS auteur
               FROM depenses d
               JOIN categories_depenses cat ON cat.id = d.categorie_id
               LEFT JOIN etablissements e ON e.id = d.etablissement_id
               LEFT JOIN utilisateurs u ON u.id = d.utilisateur_id
              WHERE d.id = ?',
            [$id]
        );
        return $depense === null ? null : $this->avecPaiements([$depense])[0];
    }

    /** Ajoute à chaque dépense ses paiements (compte, type, devise, montant, contre-valeur du journal). */
    private function avecPaiements(array $depenses): array
    {
        if ($depenses === []) {
            return [];
        }
        $ids = array_map(static fn (array $d): int => (int) $d['id'], $depenses);
        $marques = implode(', ', array_fill(0, count($ids), '?'));
        $lignes = Database::tous(
            'SELECT p.*, c.nom AS compte_nom, c.type AS compte_type, c.devise_id, c.active AS compte_actif,
                    m.contre_valeur, m.reference_id
               FROM depense_paiements p
               JOIN comptes_tresorerie c ON c.id = p.compte_id
               LEFT JOIN mouvements_tresorerie m
                      ON m.nature = \'depense\' AND m.source_id = p.depense_id AND m.compte_id = p.compte_id
              WHERE p.depense_id IN (' . $marques . ')
              ORDER BY p.depense_id, p.position, p.id',
            $ids
        );
        $parDepense = [];
        foreach ($lignes as $ligne) {
            $parDepense[(int) $ligne['depense_id']][] = $ligne;
        }
        foreach ($depenses as &$depense) {
            $depense['paiements'] = $parDepense[(int) $depense['id']] ?? [];
        }
        return $depenses;
    }

    /**
     * @param array{date_depense: string, categorie_id: int, etablissement_id: ?int, libelle: string, beneficiaire: ?string, notes: ?string} $v
     * @param array<int, array{compte: array, montant: float}> $paiements
     */
    public function creer(array $v, array $paiements, int $utilisateurId): int
    {
        return $this->tresorerie->transaction(function () use ($v, $paiements, $utilisateurId): int {
            Database::executer(
                'INSERT INTO depenses (date_depense, categorie_id, etablissement_id, libelle, beneficiaire, notes, utilisateur_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$v['date_depense'], $v['categorie_id'], $v['etablissement_id'], $v['libelle'], $v['beneficiaire'], $v['notes'], $utilisateurId]
            );
            $id = Database::dernierId();
            $this->ecrirePaiements($id, $v, $paiements, $utilisateurId);
            return $id;
        });
    }

    public function modifier(int $id, array $v, array $paiements, int $utilisateurId): void
    {
        $this->tresorerie->transaction(function () use ($id, $v, $paiements, $utilisateurId): void {
            Database::executer(
                'UPDATE depenses SET date_depense = ?, categorie_id = ?, etablissement_id = ?, libelle = ?, beneficiaire = ?, notes = ?
                  WHERE id = ?',
                [$v['date_depense'], $v['categorie_id'], $v['etablissement_id'], $v['libelle'], $v['beneficiaire'], $v['notes'], $id]
            );
            $this->ecrirePaiements($id, $v, $paiements, $utilisateurId);
        });
    }

    /** Remplace les paiements d'une dépense et leurs lignes du journal. */
    private function ecrirePaiements(int $id, array $v, array $paiements, int $utilisateurId): void
    {
        $this->tresorerie->supprimerMouvements('depense', $id);
        Database::executer('DELETE FROM depense_paiements WHERE depense_id = ?', [$id]);
        foreach (array_values($paiements) as $position => $paiement) {
            Database::executer(
                'INSERT INTO depense_paiements (depense_id, compte_id, montant, position) VALUES (?, ?, ?, ?)',
                [$id, (int) $paiement['compte']['id'], GestionTresorerieRepository::decimal($paiement['montant']), $position]
            );
            $this->tresorerie->ajouterMouvement($paiement['compte'], 'sortie', $paiement['montant'], 'depense', $id,
                $v['date_depense'], $v['libelle'], $utilisateurId);
        }
    }

    /** Suppression d'une dépense et de ses lignes du journal. */
    public function supprimer(int $id): void
    {
        $this->tresorerie->transaction(function () use ($id): void {
            $this->tresorerie->supprimerMouvements('depense', $id);
            Database::executer('DELETE FROM depenses WHERE id = ?', [$id]);
        });
    }
}
