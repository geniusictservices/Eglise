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
     * Menu public d'un établissement : catégories principales visibles, avec leurs produits
     * et leurs sous-catégories (« sous_categories », chacune avec ses produits), et le prix
     * de chaque conditionnement vendable dans la grille et la devise demandées.
     *
     * Un produit à tailles porte ses tailles dans « tailles » (chacune avec ses conditionnements
     * et son « disponible ») : il reste une seule ligne du menu. Une taille épuisée l'est seule ;
     * un produit principal épuisé rend toutes ses tailles épuisées.
     *
     * Ordre de recherche d'un prix :
     *   1. prix fixe de la grille dans la devise demandée ;
     *   2. prix de la grille en devise de référence, converti ;
     *   3. et 4. idem avec la grille par défaut de l'établissement.
     */
    public function menu(int $etablissementId, int $grilleId, int $grilleDefautId, array $devise, bool $seulementPublic = true): array
    {
        $filtrePublic = $seulementPublic
            ? 'AND p.visible_public = 1 AND c.visible_public = 1 AND (cp.id IS NULL OR cp.visible_public = 1)'
            : '';
        $produits = Database::tous(
            "SELECT p.*
               FROM produits p
               JOIN produit_etablissements pe ON pe.produit_id = p.id AND pe.etablissement_id = ?
               JOIN categories c ON c.id = p.categorie_id
               LEFT JOIN categories cp ON cp.id = c.parent_id
              WHERE p.actif = 1 $filtrePublic
              ORDER BY p.position, p.id",
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

        // Produits principaux, puis leurs tailles (une taille sans prix ou sans produit principal n'apparaît pas)
        $principaux = [];
        foreach ($produits as $produit) {
            if ($produit['parent_id'] === null) {
                $produit['conditionnements'] = $parProduit[$produit['id']] ?? [];
                $produit['badges'] = $produit['badges'] ? explode(',', $produit['badges']) : [];
                $produit['tailles'] = [];
                $principaux[(int) $produit['id']] = $produit;
            }
        }
        foreach ($produits as $taille) {
            $parent = (int) $taille['parent_id'];
            if ($taille['parent_id'] === null || !isset($principaux[$parent]) || empty($parProduit[$taille['id']])) {
                continue;
            }
            $taille['conditionnements'] = $parProduit[$taille['id']];
            $taille['disponible'] = $taille['disponible'] && $principaux[$parent]['disponible'] ? 1 : 0;
            $taille['libelle_taille'] = GestionCatalogueRepository::libelleTaille($taille);
            $principaux[$parent]['tailles'][] = $taille;
        }

        $parCategorie = [];
        foreach ($principaux as $produit) {
            if ($produit['conditionnements'] === [] && $produit['tailles'] === []) {
                continue;
            }
            if ($produit['tailles'] !== []) {
                // Épuisé seulement si plus rien n'est disponible
                $produit['disponible'] = $produit['disponible'] && ($produit['conditionnements'] !== []
                    || array_filter($produit['tailles'], static fn (array $t) => (bool) $t['disponible']) !== []) ? 1 : 0;
            }
            $parCategorie[(int) $produit['categorie_id']][] = $produit;
        }

        // Arbre des catégories : catégorie principale, ses produits, puis ses sous-catégories
        $categories = GestionCatalogueRepository::arbre(Database::tous('SELECT * FROM categories ORDER BY position, id'));
        $menu = [];
        foreach ($categories as $categorie) {
            $id = (int) $categorie['id'];
            $ligne = ['id' => $id, 'nom_fr' => $categorie['nom_fr'], 'nom_en' => $categorie['nom_en'], 'produits' => $parCategorie[$id] ?? []];
            if ($categorie['niveau'] === 0) {
                $menu[$id] = $ligne + ['sous_categories' => []];
            } elseif ($ligne['produits'] !== []) {
                $menu[(int) $categorie['parente']['id']]['sous_categories'][] = $ligne;
            }
        }
        return array_values(array_filter($menu, static fn (array $c) => $c['produits'] !== [] || $c['sous_categories'] !== []));
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
