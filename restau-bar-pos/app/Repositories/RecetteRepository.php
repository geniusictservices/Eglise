<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Journal;

/**
 * Fiches techniques (cocktails, plats) et coûts de revient : ingrédients en unités de base avec leur part
 * utilisable, rendement de la fiche ; coût au CUMP des ingrédients ; marge par format vendu.
 */
final class RecetteRepository
{
    private StockRepository $stock;

    public function __construct(?StockRepository $stock = null)
    {
        $this->stock = $stock ?? new StockRepository();
    }

    /**
     * Produits vendus (au moins un format vendable), avec leur fiche technique s'ils en ont une.
     * @return list<array>
     */
    public function produitsVendus(): array
    {
        return Database::tous(
            'SELECT p.id, p.nom_fr, p.nom_en, p.unite_base, p.parent_id, c.nom_fr AS cat_nom_fr, c.nom_en AS cat_nom_en,
                    r.id AS recette_id, r.actif AS recette_active, r.rendement,
                    (SELECT COUNT(*) FROM recette_ingredients ri WHERE ri.recette_id = r.id) AS nb_ingredients
               FROM produits p
               JOIN categories c ON c.id = p.categorie_id
          LEFT JOIN categories cp ON cp.id = c.parent_id
          LEFT JOIN recettes r ON r.produit_id = p.id
              WHERE p.actif = 1 AND EXISTS (SELECT 1 FROM conditionnements x WHERE x.produit_id = p.id AND x.vendable = 1)
           ORDER BY r.id IS NULL, COALESCE(cp.position, c.position), c.position, p.position, p.id'
        );
    }

    public function recette(int $produitId): ?array
    {
        $recette = Database::un('SELECT r.*, u.nom AS auteur FROM recettes r LEFT JOIN utilisateurs u ON u.id = r.maj_par WHERE r.produit_id = ?', [$produitId]);
        if ($recette === null) {
            return null;
        }
        $recette['ingredients'] = Database::tous(
            'SELECT ri.*, p.nom_fr, p.nom_en, p.unite_base FROM recette_ingredients ri JOIN produits p ON p.id = ri.produit_id
              WHERE ri.recette_id = ? ORDER BY ri.position, ri.id',
            [(int) $recette['id']]
        );
        return $recette;
    }

    /**
     * Crée ou remplace la fiche technique d'un produit.
     * @param list<array{produit_id: int, quantite: float, rendement_pct: float}> $ingredients
     */
    public function enregistrer(int $produitId, float $rendement, ?string $notes, bool $active, array $ingredients, int $utilisateurId): void
    {
        (new GestionTresorerieRepository())->transaction(function () use ($produitId, $rendement, $notes, $active, $ingredients, $utilisateurId): void {
            Database::executer(
                'INSERT INTO recettes (produit_id, rendement, notes, actif, maj_par, maj_le) VALUES (?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE rendement = VALUES(rendement), notes = VALUES(notes), actif = VALUES(actif), maj_par = VALUES(maj_par), maj_le = VALUES(maj_le)',
                [$produitId, StockRepository::q($rendement), $notes, (int) $active, $utilisateurId]
            );
            $id = (int) Database::un('SELECT id FROM recettes WHERE produit_id = ?', [$produitId])['id'];
            if (StockRepository::suite()) {
                Database::executer('UPDATE recettes SET preparation = ? WHERE id = ?', [(int) $this->estPreparation($produitId), $id]);
            }
            Database::executer('DELETE FROM recette_ingredients WHERE recette_id = ?', [$id]);
            foreach (array_values($ingredients) as $position => $i) {
                Database::executer(
                    'INSERT INTO recette_ingredients (recette_id, produit_id, quantite, rendement_pct, position) VALUES (?, ?, ?, ?, ?)',
                    [$id, $i['produit_id'], number_format($i['quantite'], 4, '.', ''), number_format($i['rendement_pct'], 2, '.', ''), $position]
                );
            }
            Journal::ecrire('stock_recette', (string) $produitId, ['ingredients' => count($ingredients)], $utilisateurId);
        });
        $this->stock->oublierCache();
    }

    // ---------- Préparations intermédiaires ----------

    /** Catégorie cachée « Préparations » (créée au besoin). */
    public function categoriePreparations(): int
    {
        $c = Database::un('SELECT id FROM categories WHERE nom_fr = \'Préparations\' AND parent_id IS NULL LIMIT 1');
        if ($c !== null) {
            return (int) $c['id'];
        }
        $position = (int) Database::un('SELECT COALESCE(MAX(position), 0) + 1 AS p FROM categories WHERE parent_id IS NULL')['p'];
        Database::executer('INSERT INTO categories (nom_fr, nom_en, position, visible_public) VALUES (\'Préparations\', \'Preparations\', ?, 0)', [$position]);
        return Database::dernierId();
    }

    /** Une préparation : produit de la catégorie « Préparations », ou avec une fiche « préparation ». */
    public function estPreparation(int $produitId): bool
    {
        if (StockRepository::suite() && Database::un('SELECT id FROM recettes WHERE produit_id = ? AND preparation = 1', [$produitId]) !== null) {
            return true;
        }
        return Database::un(
            'SELECT p.id FROM produits p JOIN categories c ON c.id = p.categorie_id WHERE p.id = ? AND c.nom_fr = \'Préparations\' AND c.parent_id IS NULL',
            [$produitId]
        ) !== null;
    }

    /** Identifiants de toutes les préparations. @return list<int> */
    public function idsPreparations(): array
    {
        $ids = array_map('intval', array_column(Database::tous(
            'SELECT p.id FROM produits p JOIN categories c ON c.id = p.categorie_id WHERE c.nom_fr = \'Préparations\' AND c.parent_id IS NULL'
        ), 'id'));
        if (StockRepository::suite()) {
            $ids = array_merge($ids, array_map('intval', array_column(Database::tous('SELECT produit_id FROM recettes WHERE preparation = 1'), 'produit_id')));
        }
        return array_values(array_unique($ids));
    }

    /** Préparations avec leur fiche (si elle existe). */
    public function preparations(): array
    {
        $ids = $this->idsPreparations();
        if ($ids === []) {
            return [];
        }
        return Database::tous(
            'SELECT p.id, p.nom_fr, p.nom_en, p.unite_base, r.id AS recette_id, r.actif AS recette_active, r.rendement,
                    (SELECT COUNT(*) FROM recette_ingredients ri WHERE ri.recette_id = r.id) AS nb_ingredients
               FROM produits p LEFT JOIN recettes r ON r.produit_id = p.id
              WHERE p.actif = 1 AND p.id IN (' . implode(',', $ids) . ') ORDER BY p.nom_fr'
        );
    }

    /**
     * Nouvelle préparation : produit caché (ni menu ni caisse), compté dans son unité de base, avec un format
     * d'une unité (pour les transferts, inventaires et pertes).
     */
    public function creerPreparation(string $nomFr, string $nomEn, string $unite): int
    {
        return (new GestionTresorerieRepository())->transaction(function () use ($nomFr, $nomEn, $unite): int {
            $categorie = $this->categoriePreparations();
            $position = (int) Database::un('SELECT COALESCE(MAX(position), 0) + 1 AS p FROM produits WHERE categorie_id = ?', [$categorie])['p'];
            Database::executer('INSERT INTO produits (categorie_id, nom_fr, nom_en, unite_base, visible_public, position) VALUES (?, ?, ?, ?, 0, ?)', [$categorie, $nomFr, $nomEn, $unite, $position]);
            $id = Database::dernierId();
            Database::executer(
                'INSERT INTO conditionnements (produit_id, nom_fr, nom_en, quantite_base, vendable, achetable, position) VALUES (?, ?, ?, 1, 0, 0, 1)',
                [$id, t('stock.unite_' . $unite . '_1'), \App\Core\I18n::dans('en', 'stock.unite_' . $unite . '_1')]
            );
            return $id;
        });
    }

    public function supprimer(int $produitId, int $utilisateurId): void
    {
        Database::executer('DELETE FROM recettes WHERE produit_id = ?', [$produitId]);
        Journal::ecrire('stock_recette_supprimee', (string) $produitId, [], $utilisateurId);
        $this->stock->oublierCache();
    }

    /**
     * Coût et marge de chaque format vendable : coût de revient (référence) × contenu du format, prix de la
     * grille par défaut de l'établissement en devise de référence (prix fixe, sinon converti depuis la devise locale).
     * @return list<array{produit_id: int, conditionnement_id: int, nom_fr: string, nom_en: string, format_fr: string, format_en: string,
     *                    cout: ?float, complet: bool, prix: ?float, marge: ?float, taux: ?float, recette: bool}>
     */
    public function marges(int $grilleId, array $reference, ?array $locale): array
    {
        $formats = Database::tous(
            'SELECT c.id, c.produit_id, c.nom_fr AS format_fr, c.nom_en AS format_en, c.quantite_base, p.nom_fr, p.nom_en,
                    (SELECT montant FROM prix x WHERE x.conditionnement_id = c.id AND x.grille_prix_id = ? AND x.devise_id = ?) AS prix_reference,
                    (SELECT montant FROM prix x WHERE x.conditionnement_id = c.id AND x.grille_prix_id = ? AND x.devise_id = ?) AS prix_locale
               FROM conditionnements c
               JOIN produits p ON p.id = c.produit_id AND p.actif = 1
               JOIN categories cat ON cat.id = p.categorie_id
          LEFT JOIN categories cp ON cp.id = cat.parent_id
              WHERE c.vendable = 1
           ORDER BY COALESCE(cp.position, cat.position), cat.position, p.position, p.id, c.position, c.id',
            [$grilleId, (int) $reference['id'], $grilleId, $locale ? (int) $locale['id'] : 0]
        );
        $resultat = [];
        foreach ($formats as $f) {
            $revient = $this->stock->coutRevient((int) $f['produit_id']);
            $cout = $revient['cout'] !== null ? $revient['cout'] * (float) $f['quantite_base'] : null;
            $prix = $f['prix_reference'] !== null ? (float) $f['prix_reference']
                : ($f['prix_locale'] !== null && $locale && (float) $locale['taux'] > 0 ? (float) $f['prix_locale'] / (float) $locale['taux'] : null);
            $marge = $cout !== null && $prix !== null ? $prix - $cout : null;
            $resultat[] = [
                'produit_id' => (int) $f['produit_id'], 'conditionnement_id' => (int) $f['id'],
                'nom_fr' => $f['nom_fr'], 'nom_en' => $f['nom_en'], 'format_fr' => $f['format_fr'], 'format_en' => $f['format_en'],
                'cout' => $cout, 'complet' => $revient['complet'], 'prix' => $prix, 'marge' => $marge,
                'taux' => $marge !== null && $prix > 0 ? $marge / $prix : null,
                'recette' => $this->stock->recetteActive((int) $f['produit_id']) !== null,
            ];
        }
        return $resultat;
    }
}
