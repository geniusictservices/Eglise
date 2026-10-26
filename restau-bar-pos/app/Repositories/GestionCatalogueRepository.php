<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Catalogue côté espace de gestion : catégories, produits, formats (conditionnements), prix.
 * La lecture du menu pour la vitrine et la caisse reste dans CatalogueRepository.
 */
final class GestionCatalogueRepository
{
    public const UNITES = ['bouteille', 'canette', 'verre', 'cl', 'l', 'g', 'kg', 'piece'];
    public const BADGES = ['nouveau', 'populaire', 'epice'];

    // Catégories

    /** Catégories en service, avec le nombre de produits actifs. */
    public function categories(): array
    {
        return Database::tous(
            'SELECT c.*, (SELECT COUNT(*) FROM produits p WHERE p.categorie_id = c.id AND p.actif = 1) AS nb_produits
               FROM categories c
              WHERE c.actif = 1
              ORDER BY c.position, c.id'
        );
    }

    public function categorie(int $id): ?array
    {
        return Database::un('SELECT * FROM categories WHERE id = ? AND actif = 1', [$id]);
    }

    public function creerCategorie(string $nomFr, string $nomEn, bool $visible): int
    {
        Database::executer(
            'INSERT INTO categories (nom_fr, nom_en, visible_public, position)
             SELECT ?, ?, ?, COALESCE(MAX(position), 0) + 1 FROM categories WHERE actif = 1',
            [$nomFr, $nomEn, (int) $visible]
        );
        return Database::dernierId();
    }

    public function modifierCategorie(int $id, string $nomFr, string $nomEn, bool $visible): void
    {
        Database::executer(
            'UPDATE categories SET nom_fr = ?, nom_en = ?, visible_public = ? WHERE id = ?',
            [$nomFr, $nomEn, (int) $visible, $id]
        );
    }

    /**
     * Supprime une catégorie sans produit actif. Si elle garde des produits désactivés,
     * elle est seulement archivée (ces produits y restent rattachés).
     *
     * @return bool faux si la catégorie contient encore des produits actifs
     */
    public function supprimerCategorie(int $id): bool
    {
        $compte = Database::un(
            'SELECT SUM(actif = 1) AS actifs, COUNT(*) AS tous FROM produits WHERE categorie_id = ?',
            [$id]
        );
        if ((int) $compte['actifs'] > 0) {
            return false;
        }
        if ((int) $compte['tous'] > 0) {
            Database::executer('UPDATE categories SET actif = 0 WHERE id = ?', [$id]);
        } else {
            Database::executer('DELETE FROM categories WHERE id = ?', [$id]);
        }
        return true;
    }

    /** Monte (-1) ou descend (+1) une catégorie d'un cran. */
    public function deplacerCategorie(int $id, int $sens): void
    {
        $ids = array_map('intval', array_column(
            Database::tous('SELECT id FROM categories WHERE actif = 1 ORDER BY position, id'),
            'id'
        ));
        $this->echanger('categories', $ids, $id, $sens);
    }

    // Produits

    /**
     * Produits pour la liste de gestion, dans l'ordre du menu.
     * Filtres : categorie (id), etablissement (id), recherche (texte), desactives (bool).
     */
    public function produits(array $filtres, int $grilleId, ?int $deviseReferenceId): array
    {
        $conditions = ['p.actif = ?'];
        $params = [empty($filtres['desactives']) ? 1 : 0];

        if (!empty($filtres['categorie'])) {
            $conditions[] = 'p.categorie_id = ?';
            $params[] = (int) $filtres['categorie'];
        }
        if (!empty($filtres['etablissement'])) {
            $conditions[] = 'EXISTS (SELECT 1 FROM produit_etablissements pe WHERE pe.produit_id = p.id AND pe.etablissement_id = ?)';
            $params[] = (int) $filtres['etablissement'];
        }
        if (($filtres['recherche'] ?? '') !== '') {
            $motif = '%' . addcslashes((string) $filtres['recherche'], '%_\\') . '%';
            $conditions[] = '(p.nom_fr LIKE ? OR p.nom_en LIKE ?)';
            array_push($params, $motif, $motif);
        }

        $produits = Database::tous(
            'SELECT p.*, c.nom_fr AS categorie_fr, c.nom_en AS categorie_en, c.visible_public AS categorie_visible, c.actif AS categorie_active
               FROM produits p
               JOIN categories c ON c.id = p.categorie_id
              WHERE ' . implode(' AND ', $conditions) . '
              ORDER BY c.actif DESC, c.position, c.id, p.position, p.id',
            $params
        );
        if ($produits === []) {
            return [];
        }

        $ids = array_map('intval', array_column($produits, 'id'));
        $marques = implode(',', array_fill(0, count($ids), '?'));

        $etablissements = [];
        foreach (Database::tous(
            "SELECT pe.produit_id, e.nom FROM produit_etablissements pe
               JOIN etablissements e ON e.id = pe.etablissement_id
              WHERE e.actif = 1 AND pe.produit_id IN ($marques)
              ORDER BY e.position, e.nom",
            $ids
        ) as $ligne) {
            $etablissements[$ligne['produit_id']][] = $ligne['nom'];
        }

        // Prix principal : premier format vendable, grille par défaut, devise de référence
        $formats = [];
        foreach (Database::tous(
            "SELECT c.produit_id, c.nom_fr, c.nom_en, c.quantite_base, pr.montant
               FROM conditionnements c
               LEFT JOIN prix pr ON pr.conditionnement_id = c.id AND pr.grille_prix_id = ? AND pr.devise_id = ?
              WHERE c.vendable = 1 AND c.produit_id IN ($marques)
              ORDER BY c.position, c.id",
            array_merge([$grilleId, (int) $deviseReferenceId], $ids)
        ) as $ligne) {
            $formats[$ligne['produit_id']][] = $ligne;
        }

        foreach ($produits as &$produit) {
            $produit['etablissements'] = $etablissements[$produit['id']] ?? [];
            $produit['formats'] = $formats[$produit['id']] ?? [];
        }
        return $produits;
    }

    public function produit(int $id): ?array
    {
        return Database::un('SELECT * FROM produits WHERE id = ?', [$id]);
    }

    /** @param array $donnees colonnes de la table produits (informations et vente) */
    public function creerProduit(array $donnees): int
    {
        $position = (int) Database::un(
            'SELECT COALESCE(MAX(position), 0) + 1 AS n FROM produits WHERE categorie_id = ?',
            [$donnees['categorie_id']]
        )['n'];
        Database::executer(
            'INSERT INTO produits (categorie_id, nom_fr, nom_en, description_fr, description_en, unite_base, badges,
                                   allergenes_fr, allergenes_en, visible_public, disponible, position)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $donnees['categorie_id'], $donnees['nom_fr'], $donnees['nom_en'],
                $donnees['description_fr'], $donnees['description_en'], $donnees['unite_base'], $donnees['badges'],
                $donnees['allergenes_fr'], $donnees['allergenes_en'],
                (int) $donnees['visible_public'], (int) $donnees['disponible'], $position,
            ]
        );
        return Database::dernierId();
    }

    public function modifierInformations(int $id, array $donnees): void
    {
        $actuel = $this->produit($id);
        $position = $actuel['position'];
        if ((int) $actuel['categorie_id'] !== (int) $donnees['categorie_id']) {
            // Changement de catégorie : le produit passe en fin de la nouvelle catégorie
            $position = (int) Database::un(
                'SELECT COALESCE(MAX(position), 0) + 1 AS n FROM produits WHERE categorie_id = ?',
                [$donnees['categorie_id']]
            )['n'];
        }
        Database::executer(
            'UPDATE produits SET categorie_id = ?, nom_fr = ?, nom_en = ?, description_fr = ?, description_en = ?,
                    unite_base = ?, badges = ?, allergenes_fr = ?, allergenes_en = ?, position = ?
              WHERE id = ?',
            [
                $donnees['categorie_id'], $donnees['nom_fr'], $donnees['nom_en'],
                $donnees['description_fr'], $donnees['description_en'], $donnees['unite_base'], $donnees['badges'],
                $donnees['allergenes_fr'], $donnees['allergenes_en'], $position, $id,
            ]
        );
    }

    public function modifierVente(int $id, bool $visible, bool $disponible, array $etablissementIds): void
    {
        Database::executer('UPDATE produits SET visible_public = ?, disponible = ? WHERE id = ?', [(int) $visible, (int) $disponible, $id]);
        $this->definirEtablissements($id, $etablissementIds);
    }

    /** @param int[] $etablissementIds */
    public function definirEtablissements(int $produitId, array $etablissementIds): void
    {
        Database::executer('DELETE FROM produit_etablissements WHERE produit_id = ?', [$produitId]);
        foreach (array_unique($etablissementIds) as $etablissementId) {
            Database::executer(
                'INSERT INTO produit_etablissements (produit_id, etablissement_id) VALUES (?, ?)',
                [$produitId, $etablissementId]
            );
        }
    }

    /** @return int[] */
    public function etablissementsDuProduit(int $produitId): array
    {
        return array_map('intval', array_column(
            Database::tous('SELECT etablissement_id FROM produit_etablissements WHERE produit_id = ?', [$produitId]),
            'etablissement_id'
        ));
    }

    public function definirPhoto(int $id, ?string $photo, ?string $credit): void
    {
        Database::executer('UPDATE produits SET photo = ?, photo_credit = ? WHERE id = ?', [$photo, $credit, $id]);
    }

    public function definirCreditPhoto(int $id, ?string $credit): void
    {
        Database::executer('UPDATE produits SET photo_credit = ? WHERE id = ?', [$credit, $id]);
    }

    /** Bascule disponible / épuisé et rend le nouvel état. */
    public function basculerDisponible(int $id): bool
    {
        Database::executer('UPDATE produits SET disponible = 1 - disponible WHERE id = ?', [$id]);
        return (bool) Database::un('SELECT disponible FROM produits WHERE id = ?', [$id])['disponible'];
    }

    public function desactiver(int $id): void
    {
        Database::executer('UPDATE produits SET actif = 0 WHERE id = ?', [$id]);
    }

    /** Réactive le produit, et sa catégorie si elle avait été archivée. */
    public function reactiver(int $id): void
    {
        Database::executer('UPDATE produits SET actif = 1 WHERE id = ?', [$id]);
        Database::executer(
            'UPDATE categories c JOIN produits p ON p.categorie_id = c.id
                SET c.actif = 1 WHERE p.id = ? AND c.actif = 0',
            [$id]
        );
    }

    /** Monte (-1) ou descend (+1) un produit d'un cran dans sa catégorie. */
    public function deplacerProduit(int $id, int $sens): void
    {
        $produit = $this->produit($id);
        if ($produit === null) {
            return;
        }
        $ids = array_map('intval', array_column(
            Database::tous(
                'SELECT id FROM produits WHERE categorie_id = ? AND actif = 1 ORDER BY position, id',
                [$produit['categorie_id']]
            ),
            'id'
        ));
        $this->echanger('produits', $ids, $id, $sens);
    }

    // Formats (conditionnements)

    public function formats(int $produitId): array
    {
        return Database::tous('SELECT * FROM conditionnements WHERE produit_id = ? ORDER BY position, id', [$produitId]);
    }

    public function format(int $produitId, int $id): ?array
    {
        return Database::un('SELECT * FROM conditionnements WHERE id = ? AND produit_id = ?', [$id, $produitId]);
    }

    public function ajouterFormat(int $produitId, array $donnees): int
    {
        Database::executer(
            'INSERT INTO conditionnements (produit_id, nom_fr, nom_en, quantite_base, vendable, achetable, code_barres, position)
             SELECT ?, ?, ?, ?, ?, ?, ?, COALESCE(MAX(position), 0) + 1 FROM conditionnements WHERE produit_id = ?',
            [
                $produitId, $donnees['nom_fr'], $donnees['nom_en'], $donnees['quantite_base'],
                (int) $donnees['vendable'], (int) $donnees['achetable'], $donnees['code_barres'], $produitId,
            ]
        );
        return Database::dernierId();
    }

    public function modifierFormat(int $id, array $donnees): void
    {
        Database::executer(
            'UPDATE conditionnements SET nom_fr = ?, nom_en = ?, quantite_base = ?, vendable = ?, achetable = ?, code_barres = ?
              WHERE id = ?',
            [
                $donnees['nom_fr'], $donnees['nom_en'], $donnees['quantite_base'],
                (int) $donnees['vendable'], (int) $donnees['achetable'], $donnees['code_barres'], $id,
            ]
        );
    }

    /** Supprime un format et ses prix (clé étrangère en cascade). */
    public function supprimerFormat(int $id): void
    {
        Database::executer('DELETE FROM conditionnements WHERE id = ?', [$id]);
    }

    /** Produit d'un autre format qui porte déjà ce code-barres, sinon null. */
    public function codeBarresUtilise(string $code, int $saufFormatId = 0): ?array
    {
        return Database::un(
            'SELECT p.id, p.nom_fr, p.nom_en, c.nom_fr AS format_fr, c.nom_en AS format_en
               FROM conditionnements c JOIN produits p ON p.id = c.produit_id
              WHERE c.code_barres = ? AND c.id <> ? AND p.actif = 1
              LIMIT 1',
            [$code, $saufFormatId]
        );
    }

    // Prix

    public function grilles(): array
    {
        return Database::tous('SELECT * FROM grilles_prix ORDER BY id');
    }

    /** Grille appliquée par défaut : celle du premier établissement actif, sinon la première grille. */
    public function grilleParDefaut(): int
    {
        $ligne = Database::un('SELECT grille_prix_id FROM etablissements WHERE actif = 1 ORDER BY position, nom LIMIT 1')
            ?? Database::un('SELECT id AS grille_prix_id FROM grilles_prix ORDER BY id LIMIT 1');
        return (int) ($ligne['grille_prix_id'] ?? 0);
    }

    /** @return array<int, array<int, array<int, float>>> [format][grille][devise] => montant */
    public function prixDuProduit(int $produitId): array
    {
        $prix = [];
        foreach (Database::tous(
            'SELECT pr.conditionnement_id, pr.grille_prix_id, pr.devise_id, pr.montant
               FROM prix pr JOIN conditionnements c ON c.id = pr.conditionnement_id
              WHERE c.produit_id = ?',
            [$produitId]
        ) as $ligne) {
            $prix[(int) $ligne['conditionnement_id']][(int) $ligne['grille_prix_id']][(int) $ligne['devise_id']] = (float) $ligne['montant'];
        }
        return $prix;
    }

    /** Enregistre un prix, ou le supprime si le montant est null. */
    public function definirPrix(int $formatId, int $grilleId, int $deviseId, ?float $montant): void
    {
        if ($montant === null) {
            Database::executer(
                'DELETE FROM prix WHERE conditionnement_id = ? AND grille_prix_id = ? AND devise_id = ?',
                [$formatId, $grilleId, $deviseId]
            );
            return;
        }
        Database::executer(
            'INSERT INTO prix (conditionnement_id, grille_prix_id, devise_id, montant) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE montant = VALUES(montant)',
            [$formatId, $grilleId, $deviseId, number_format($montant, 2, '.', '')]
        );
    }

    /** Renumérote 1..n puis échange l'élément avec son voisin. */
    private function echanger(string $table, array $ids, int $id, int $sens): void
    {
        $index = array_search($id, $ids, true);
        if ($index === false) {
            return;
        }
        $voisin = $index + ($sens < 0 ? -1 : 1);
        if (isset($ids[$voisin])) {
            [$ids[$index], $ids[$voisin]] = [$ids[$voisin], $ids[$index]];
        }
        $requete = Database::pdo()->prepare("UPDATE $table SET position = ? WHERE id = ?");
        foreach ($ids as $position => $idLigne) {
            $requete->execute([$position + 1, $idLigne]);
        }
    }
}
