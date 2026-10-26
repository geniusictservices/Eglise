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
        $principale = $acceptees[(int) $etab['devise_affichage_id']] ?? $devises->reference() ?? reset($acceptees);

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
     */
    private function donnees(int $etablissementId, int $grille, int $grilleDefaut, array $acceptees, array $principale, string $type): array
    {
        $catalogue = new CatalogueRepository(new DeviseRepository());
        $categories = [];
        $produits = [];

        foreach ($acceptees as $devise) {
            foreach ($catalogue->menu($etablissementId, $grille, $grilleDefaut, $devise, false) as $categorie) {
                $categories[$categorie['id']] ??= ['id' => $categorie['id'], 'nom' => champ($categorie, 'nom')];
                foreach ($categorie['produits'] as $produit) {
                    $produits[$produit['id']] ??= [
                        'id' => (int) $produit['id'],
                        'categorie' => (int) $produit['categorie_id'],
                        'nom' => champ($produit, 'nom'),
                        'recherche' => mb_strtolower($produit['nom_fr'] . ' ' . $produit['nom_en']),
                        'disponible' => (bool) $produit['disponible'],
                        'photo' => urlImageSure($produit['photo']),
                        'formats' => [],
                    ];
                    foreach ($produit['conditionnements'] as $cond) {
                        $produits[$produit['id']]['formats'][$cond['id']] ??= [
                            'id' => (int) $cond['id'],
                            'nom' => champ($cond, 'nom'),
                            'unite' => (float) $cond['quantite_base'] === 1.0,
                            'prix' => [],
                        ];
                        $produits[$produit['id']]['formats'][$cond['id']]['prix'][$devise['code']] = $cond['montant'];
                    }
                }
            }
        }

        foreach ($produits as &$produit) {
            $produit['formats'] = array_values($produit['formats']);
        }

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
}
