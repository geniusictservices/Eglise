<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class CatalogueRepository
{
    public function __construct(private readonly DeviseRepository $devises)
    {
    }

    /**
     * Menu public d'un établissement : catégories visibles, avec leurs produits et le prix
     * de chaque conditionnement vendable dans la grille et la devise demandées.
     *
     * Ordre de recherche d'un prix :
     *   1. prix fixe de la grille dans la devise demandée ;
     *   2. prix de la grille en devise de référence, converti ;
     *   3. et 4. idem avec la grille par défaut de l'établissement.
     */
    public function menu(int $etablissementId, int $grilleId, int $grilleDefautId, array $devise, bool $seulementPublic = true): array
    {
        $filtrePublic = $seulementPublic ? 'AND p.visible_public = 1 AND c.visible_public = 1' : '';
        $produits = Database::tous(
            "SELECT p.*, c.nom_fr AS categorie_fr, c.nom_en AS categorie_en, c.position AS categorie_position
               FROM produits p
               JOIN produit_etablissements pe ON pe.produit_id = p.id AND pe.etablissement_id = ?
               JOIN categories c ON c.id = p.categorie_id
              WHERE p.actif = 1 $filtrePublic
              ORDER BY c.position, c.id, p.position, p.id",
            [$etablissementId]
        );
        if ($produits === []) {
            return [];
        }

        $ids = array_column($produits, 'id');
        $marques = implode(',', array_fill(0, count($ids), '?'));

        $conditionnements = Database::tous(
            "SELECT * FROM conditionnements WHERE vendable = 1 AND produit_id IN ($marques) ORDER BY position, id",
            $ids
        );
        $prixLignes = $conditionnements === [] ? [] : Database::tous(
            'SELECT conditionnement_id, grille_prix_id, devise_id, montant FROM prix
              WHERE grille_prix_id IN (?, ?) AND conditionnement_id IN ('
                . implode(',', array_fill(0, count($conditionnements), '?')) . ')',
            array_merge([$grilleId, $grilleDefautId], array_column($conditionnements, 'id'))
        );

        $prix = [];
        foreach ($prixLignes as $ligne) {
            $prix[$ligne['conditionnement_id']][$ligne['grille_prix_id']][$ligne['devise_id']] = (float) $ligne['montant'];
        }

        $reference = $this->devises->reference();
        $parProduit = [];
        foreach ($conditionnements as $cond) {
            $montant = $this->trouverPrix($prix[$cond['id']] ?? [], [$grilleId, $grilleDefautId], $devise, $reference);
            if ($montant === null) {
                continue;
            }
            $cond['montant'] = $montant;
            $cond['montant_formate'] = $this->devises->formater($montant, $devise);
            $parProduit[$cond['produit_id']][] = $cond;
        }

        $categories = [];
        foreach ($produits as $produit) {
            $produit['conditionnements'] = $parProduit[$produit['id']] ?? [];
            if ($produit['conditionnements'] === []) {
                continue;
            }
            $produit['badges'] = $produit['badges'] ? explode(',', $produit['badges']) : [];
            $idCategorie = $produit['categorie_id'];
            $categories[$idCategorie] ??= [
                'id' => (int) $idCategorie,
                'nom_fr' => $produit['categorie_fr'],
                'nom_en' => $produit['categorie_en'],
                'produits' => [],
            ];
            $categories[$idCategorie]['produits'][] = $produit;
        }
        return array_values($categories);
    }

    private function trouverPrix(array $prixCond, array $grilles, array $devise, ?array $reference): ?float
    {
        foreach (array_unique($grilles) as $grille) {
            $parDevise = $prixCond[$grille] ?? [];
            if (isset($parDevise[$devise['id']])) {
                return $parDevise[$devise['id']];
            }
            if ($reference !== null && isset($parDevise[$reference['id']])) {
                return $this->devises->convertirDepuisReference($parDevise[$reference['id']], $devise);
            }
        }
        return null;
    }
}
