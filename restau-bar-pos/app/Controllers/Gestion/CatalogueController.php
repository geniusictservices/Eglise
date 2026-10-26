<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Session;
use App\Core\View;
use App\Repositories\DeviseRepository;
use App\Repositories\EtablissementRepository;
use App\Repositories\GestionCatalogueRepository;

/** Liste des produits du catalogue, disponibilité en un toucher et ordre dans la catégorie. */
final class CatalogueController
{
    private GestionCatalogueRepository $catalogue;

    public function __construct()
    {
        $this->catalogue = new GestionCatalogueRepository();
    }

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $devises = new DeviseRepository();
        $reference = $devises->reference();

        $filtres = [
            'categorie' => self::entier($_GET['categorie'] ?? null),
            'etablissement' => self::entier($_GET['etablissement'] ?? null),
            'recherche' => is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 64) : '',
            'desactives' => !empty($_GET['desactives']),
        ];
        $produits = $this->catalogue->produits($filtres, $this->catalogue->grilleParDefaut(), $reference ? (int) $reference['id'] : null);

        // Regroupement par catégorie. Sans filtre, les catégories vides sont aussi affichées.
        $categories = $this->catalogue->categories();
        $groupes = [];
        $filtre = $filtres['categorie'] || $filtres['etablissement'] || $filtres['recherche'] !== '' || $filtres['desactives'];
        if (!$filtre) {
            foreach ($categories as $categorie) {
                $groupes[$categorie['id']] = ['categorie' => $categorie, 'produits' => []];
            }
        }
        foreach ($produits as $produit) {
            $groupes[$produit['categorie_id']] ??= ['categorie' => [
                'id' => $produit['categorie_id'],
                'nom_fr' => $produit['categorie_fr'],
                'nom_en' => $produit['categorie_en'],
                'visible_public' => $produit['categorie_visible'],
                'actif' => $produit['categorie_active'],
            ], 'produits' => []];
            $groupes[$produit['categorie_id']]['produits'][] = $produit;
        }

        View::afficher('gestion/catalogue/index', [
            'titre' => t('catalogue.titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'filtres' => $filtres,
            'filtre' => $filtre,
            'groupes' => array_values($groupes),
            'categories' => $categories,
            'etablissements' => (new EtablissementRepository())->actifs(),
            'reference' => $reference,
            'devises' => $devises,
            'nbResultats' => count($produits),
        ], 'gestion');
    }

    /** Disponible ⇄ épuisé. Répond en JSON à un appel fetch, sinon revient à la liste. */
    public function basculerDisponible(string $id): void
    {
        Session::exigerConnexion();
        Session::verifierCsrf();
        $produit = $this->produitOu404($id);

        $disponible = $this->catalogue->basculerDisponible((int) $produit['id']);
        $message = t($disponible ? 'catalogue.devenu_disponible' : 'catalogue.devenu_epuise', ['nom' => champ($produit, 'nom')]);

        if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['disponible' => $disponible, 'message' => $message], JSON_UNESCAPED_UNICODE);
            return;
        }
        Session::flash($message);
        rediriger(self::retour() . '#produit-' . (int) $produit['id']);
    }

    public function monter(string $id): void
    {
        $this->deplacer($id, -1);
    }

    public function descendre(string $id): void
    {
        $this->deplacer($id, 1);
    }

    private function deplacer(string $id, int $sens): void
    {
        Session::exigerConnexion();
        Session::verifierCsrf();
        $produit = $this->produitOu404($id);
        $this->catalogue->deplacerProduit((int) $produit['id'], $sens);
        rediriger(self::retour() . '#produit-' . (int) $produit['id']);
    }

    private function produitOu404(string $id): array
    {
        $produit = ctype_digit($id) ? $this->catalogue->produit((int) $id) : null;
        if ($produit === null) {
            pageIntrouvable();
        }
        return $produit;
    }

    /** Page de retour transmise par le formulaire, limitée à la liste du catalogue (filtres compris). */
    private static function retour(): string
    {
        $retour = (string) ($_POST['retour'] ?? '');
        return preg_match('#^/gestion/catalogue(\?[^\s\#]*)?$#', $retour) ? $retour : '/gestion/catalogue';
    }

    private static function entier(mixed $valeur): int
    {
        return is_string($valeur) && ctype_digit($valeur) ? (int) $valeur : 0;
    }
}
