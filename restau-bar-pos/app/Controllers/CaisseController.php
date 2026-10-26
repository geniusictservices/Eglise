<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Session;
use App\Core\View;
use App\Repositories\CatalogueRepository;
use App\Repositories\DeviseRepository;
use App\Repositories\EtablissementRepository;

/**
 * Écran de caisse : produits en grille et ticket en cours.
 * L'écran s'adapte au type d'établissement (mode comptoir rapide et thème sombre pour un nightclub).
 * L'enregistrement des ventes et l'encaissement arrivent en phase 3.
 */
final class CaisseController
{
    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $etablissements = new EtablissementRepository();
        $devises = new DeviseRepository();

        $liste = $etablissements->actifs();
        if ($liste === []) {
            pageIntrouvable();
        }
        $etab = $liste[0];
        if (isset($_GET['e']) && is_string($_GET['e'])) {
            $etab = $etablissements->parSlug($_GET['e']) ?? $etab;
        }
        $id = (int) $etab['id'];

        $zones = $etablissements->zones($id);
        $zone = null;
        if (isset($_GET['zone']) && is_string($_GET['zone'])) {
            $zone = $etablissements->zoneParCode($id, $_GET['zone']);
        }
        $grilleDefaut = (int) $etab['grille_prix_id'];
        $grille = $zone && $zone['grille_prix_id'] ? (int) $zone['grille_prix_id'] : $grilleDefaut;

        $acceptees = $devises->accepteesParmi($etablissements->deviseIds($id));
        // Tickets en devise locale, le dollar en deuxième position (ordre de DeviseRepository::actives())
        $locale = $devises->locale();
        $principale = ($locale ? ($acceptees[(int) $locale['id']] ?? null) : null)
            ?? $acceptees[(int) $etab['devise_affichage_id']] ?? $devises->reference() ?? reset($acceptees);

        View::afficher('caisse/index', [
            'titre' => t('caisse.titre'),
            'utilisateur' => $utilisateur,
            'etab' => $etab,
            'etablissements' => $liste,
            'zones' => $zones,
            'zone' => $zone,
            'donnees' => $this->donnees($id, $grille, $grilleDefaut, $acceptees, $principale, $etab['type']),
        ], 'caisse');
    }

    /**
     * Catalogue de la caisse, avec le prix de chaque format dans chaque devise acceptée.
     * Les prix sont calculés côté serveur pour respecter les prix fixes et les arrondis de chaque devise.
     *
     * Onglets : catégories principales, chacune avec ses sous-catégories (« sous »).
     * Un produit à tailles est une seule tuile : ses formats sont ceux de toutes ses tailles,
     * chacun avec le libellé de sa taille (« Grande 72 cl ») et sa disponibilité.
     */
    private function donnees(int $etablissementId, int $grille, int $grilleDefaut, array $acceptees, array $principale, string $type): array
    {
        $catalogue = new CatalogueRepository(new DeviseRepository());
        $categories = [];
        $produits = [];

        foreach ($acceptees as $devise) {
            foreach ($catalogue->menu($etablissementId, $grille, $grilleDefaut, $devise, false) as $categorie) {
                $categories[$categorie['id']] ??= ['id' => $categorie['id'], 'nom' => champ($categorie, 'nom'), 'sous' => []];
                $groupes = [[$categorie, 0]];
                foreach ($categorie['sous_categories'] as $sous) {
                    $categories[$categorie['id']]['sous'][$sous['id']] ??= ['id' => $sous['id'], 'nom' => champ($sous, 'nom')];
                    $groupes[] = [$sous, (int) $sous['id']];
                }
                foreach ($groupes as [$groupe, $sousId]) {
                    foreach ($groupe['produits'] as $produit) {
                        $this->ajouterProduit($produits, $produit, (int) $categorie['id'], $sousId, $devise);
                    }
                }
            }
        }

        foreach ($produits as &$produit) {
            $produit['formats'] = array_values($produit['formats']);
        }
        unset($produit);
        foreach ($categories as &$categorie) {
            $categorie['sous'] = array_values($categorie['sous']);
        }
        unset($categorie);

        return [
            'mode' => $type === 'nightclub' ? 'rapide' : 'standard',
            'langue' => \App\Core\I18n::langue(),
            'principale' => $principale['code'],
            'devises' => array_values(array_map(static fn (array $d) => [
                'code' => $d['code'],
                'symbole' => $d['symbole'],
                'decimales' => (int) $d['decimales'],
                'symboleAvant' => (bool) $d['symbole_avant'],
            ], $acceptees)),
            'categories' => array_values($categories),
            'produits' => array_values($produits),
        ];
    }

    /** Ajoute un produit (et ses tailles) au catalogue de la caisse, avec ses prix dans une devise. */
    private function ajouterProduit(array &$produits, array $produit, int $categorieId, int $sousId, array $devise): void
    {
        $id = (int) $produit['id'];
        $recherche = $produit['nom_fr'] . ' ' . $produit['nom_en'];
        foreach ($produit['tailles'] as $taille) {
            $recherche .= ' ' . $taille['taille_fr'] . ' ' . $taille['taille_en'] . ' ' . $taille['contenance'];
        }
        $produits[$id] ??= [
            'id' => $id,
            'categorie' => $categorieId,
            'sous' => $sousId,
            'nom' => champ($produit, 'nom'),
            'recherche' => mb_strtolower($recherche),
            'disponible' => (bool) $produit['disponible'],
            'photo' => urlImageSure($produit['photo']),
            'tailles' => count($produit['tailles']),
            'formats' => [],
        ];
        // Formats du produit lui-même, puis ceux de chaque taille
        $sources = [[$produit, '', (bool) $produit['disponible']]];
        foreach ($produit['tailles'] as $taille) {
            $sources[] = [$taille, $taille['libelle_taille'], (bool) $taille['disponible']];
        }
        foreach ($sources as [$source, $libelleTaille, $disponible]) {
            foreach ($source['conditionnements'] as $cond) {
                $produits[$id]['formats'][$cond['id']] ??= [
                    'id' => (int) $cond['id'],
                    'nom' => champ($cond, 'nom'),
                    'unite' => (float) $cond['quantite_base'] === 1.0,
                    'taille' => $libelleTaille,
                    'tailleId' => (int) $source['id'],
                    'freres' => count($source['conditionnements']),
                    'disponible' => $disponible,
                    'prix' => [],
                ];
                $produits[$id]['formats'][$cond['id']]['prix'][$devise['code']] = $cond['montant'];
            }
        }
    }
}
