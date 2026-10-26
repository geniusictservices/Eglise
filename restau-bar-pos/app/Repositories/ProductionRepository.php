<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Journal;

/**
 * Production des préparations intermédiaires (sauce, marinade, bouillon) : d'après la fiche « préparation »,
 * les ingrédients sortent du lieu et N unités de la préparation y entrent (avec un lot si elle périme). Son coût
 * moyen se met à jour avec le coût des ingrédients consommés ; les plats qui l'utilisent la consomment ensuite.
 */
final class ProductionRepository
{
    private StockRepository $stock;

    public function __construct(?StockRepository $stock = null)
    {
        $this->stock = $stock ?? new StockRepository();
    }

    /** Préparations : produits avec une fiche « préparation » active. */
    public function preparations(): array
    {
        return Database::tous(
            'SELECT p.id, p.nom_fr, p.nom_en, p.unite_base, r.rendement, r.id AS recette_id
               FROM recettes r JOIN produits p ON p.id = r.produit_id
              WHERE r.preparation = 1 AND r.actif = 1 AND p.actif = 1
                AND EXISTS (SELECT 1 FROM recette_ingredients ri WHERE ri.recette_id = r.id)
           ORDER BY p.nom_fr'
        );
    }

    /** @param list<int> $lieuIds */
    public function liste(array $lieuIds, string $debut, string $fin): array
    {
        if ($lieuIds === []) {
            return [];
        }
        return Database::tous(
            'SELECT x.*, p.nom_fr, p.nom_en, p.unite_base, l.nom AS lieu_nom, e.nom AS etablissement_nom, u.nom AS auteur
               FROM productions x
               JOIN produits p ON p.id = x.produit_id
               JOIN lieux_stock l ON l.id = x.lieu_id
          LEFT JOIN etablissements e ON e.id = l.etablissement_id
          LEFT JOIN utilisateurs u ON u.id = x.utilisateur_id
              WHERE x.lieu_id IN (' . implode(',', array_fill(0, count($lieuIds), '?')) . ') AND x.date_production BETWEEN ? AND ?
           ORDER BY x.date_production DESC, x.id DESC LIMIT 300',
            array_merge(array_values($lieuIds), [$debut, $fin])
        );
    }

    /**
     * Ingrédients que demande la production de $quantite unités de la préparation.
     * @return array<int, float> ingrédient => quantité (unité de base)
     */
    public function besoins(int $produitId, float $quantite): array
    {
        $recette = $this->stock->recettePreparation($produitId);
        return $recette === null ? [] : $this->stock->consommation($recette, $quantite);
    }

    /** Produit $quantite unités de la préparation dans le lieu. @return int identifiant de la production */
    public function produire(int $lieuId, int $produitId, float $quantite, string $date, ?string $peremption, ?string $notes, int $utilisateurId): int
    {
        return (new GestionTresorerieRepository())->transaction(function () use ($lieuId, $produitId, $quantite, $date, $peremption, $notes, $utilisateurId): int {
            $besoins = $this->besoins($produitId, $quantite);
            if ($besoins === []) {
                throw new \DomainException('Pas de fiche de préparation pour le produit ' . $produitId);
            }
            $cout = 0.0;
            $complet = true;
            foreach ($besoins as $ingredientId => $q) {
                $c = $this->stock->cump($ingredientId);
                $complet = $complet && $c !== null;
                $cout += ($c ?? 0) * $q;
            }
            Database::executer(
                'INSERT INTO productions (lieu_id, produit_id, quantite, cout_total, peremption, notes, utilisateur_id, date_production) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$lieuId, $produitId, StockRepository::q($quantite), number_format($cout, 6, '.', ''), $peremption, $notes, $utilisateurId, $date]
            );
            $id = Database::dernierId();
            $numero = sprintf('PR-%06d', $id);
            Database::executer('UPDATE productions SET numero = ? WHERE id = ?', [$numero, $id]);
            $produit = Database::un('SELECT nom_fr FROM produits WHERE id = ?', [$produitId]);
            $motif = $numero . ' · ' . ($produit['nom_fr'] ?? '');
            foreach ($besoins as $ingredientId => $q) {
                $this->stock->mouvement($lieuId, $ingredientId, -$q, 'production', $id, $date, $motif, $utilisateurId, null, null, ['origine' => $produitId]);
            }
            $reference = (new DeviseRepository())->reference();
            if ($reference !== null && $cout > 0) {
                $this->stock->majCump($produitId, $quantite, $cout / $quantite, (int) $reference['id']);
            }
            $this->stock->mouvement($lieuId, $produitId, $quantite, 'production', $id, $date, $motif, $utilisateurId, null, null,
                ['lot' => ['peremption' => $peremption, 'numero' => $numero, 'origine' => 'production']]);
            Journal::ecrire('stock_production', $numero, ['produit' => $produitId, 'quantite' => $quantite, 'cout_complet' => $complet], $utilisateurId);
            return $id;
        });
    }
}
