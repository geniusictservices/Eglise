<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Session;
use App\Core\View;
use App\Repositories\GestionCatalogueRepository;

/** Catégories du catalogue : liste, ajout, modification, ordre, suppression. */
final class CategorieController
{
    private GestionCatalogueRepository $catalogue;

    public function __construct()
    {
        $this->catalogue = new GestionCatalogueRepository();
    }

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $this->afficher($utilisateur);
    }

    public function creer(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        [$valeurs, $erreurs] = $this->lire();
        if ($erreurs !== []) {
            $this->afficher($utilisateur, ['nouvelle' => $valeurs, 'erreurs' => ['nouvelle' => $erreurs]], 422);
            return;
        }
        $id = $this->catalogue->creerCategorie($valeurs['nom_fr'], $valeurs['nom_en'], $valeurs['visible_public']);
        Session::flash(t('categories.creee', ['nom' => $valeurs['nom_fr']]));
        rediriger('/gestion/catalogue/categories#categorie-' . $id);
    }

    public function modifier(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $categorie = $this->categorieOu404($id);
        [$valeurs, $erreurs] = $this->lire();
        if ($erreurs !== []) {
            $this->afficher($utilisateur, [
                'saisies' => [(int) $categorie['id'] => $valeurs],
                'erreurs' => ['categorie-' . $categorie['id'] => $erreurs],
            ], 422);
            return;
        }
        $this->catalogue->modifierCategorie((int) $categorie['id'], $valeurs['nom_fr'], $valeurs['nom_en'], $valeurs['visible_public']);
        Session::flash(t('categories.enregistree', ['nom' => $valeurs['nom_fr']]));
        rediriger('/gestion/catalogue/categories#categorie-' . (int) $categorie['id']);
    }

    public function monter(string $id): void
    {
        $this->deplacer($id, -1);
    }

    public function descendre(string $id): void
    {
        $this->deplacer($id, 1);
    }

    public function supprimer(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $categorie = $this->categorieOu404($id);
        if (!$this->catalogue->supprimerCategorie((int) $categorie['id'])) {
            $this->afficher($utilisateur, ['erreurs' => ['categorie-' . $categorie['id'] => [
                t('categories.erreur_non_vide', ['nom' => champ($categorie, 'nom')]),
            ]]], 422);
            return;
        }
        Session::flash(t('categories.supprimee', ['nom' => champ($categorie, 'nom')]));
        rediriger('/gestion/catalogue/categories');
    }

    private function deplacer(string $id, int $sens): void
    {
        Session::exigerConnexion();
        Session::verifierCsrf();
        $categorie = $this->categorieOu404($id);
        $this->catalogue->deplacerCategorie((int) $categorie['id'], $sens);
        rediriger('/gestion/catalogue/categories#categorie-' . (int) $categorie['id']);
    }

    private function afficher(array $utilisateur, array $etat = [], int $code = 200): void
    {
        http_response_code($code);
        View::afficher('gestion/catalogue/categories', [
            'titre' => t('categories.titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'categories' => $this->catalogue->categories(),
            'nouvelle' => $etat['nouvelle'] ?? ['nom_fr' => '', 'nom_en' => '', 'visible_public' => true],
            'saisies' => $etat['saisies'] ?? [],
            'erreurs' => $etat['erreurs'] ?? [],
        ], 'gestion');
    }

    /** @return array{array, string[]} */
    private function lire(): array
    {
        $valeurs = [
            'nom_fr' => is_string($_POST['nom_fr'] ?? null) ? trim((string) preg_replace('/\p{Cc}/u', '', $_POST['nom_fr'])) : '',
            'nom_en' => is_string($_POST['nom_en'] ?? null) ? trim((string) preg_replace('/\p{Cc}/u', '', $_POST['nom_en'])) : '',
            'visible_public' => !empty($_POST['visible_public']),
        ];
        $erreurs = [];
        if ($valeurs['nom_fr'] === '') {
            $erreurs[] = t('categories.erreur_nom');
        }
        if (mb_strlen($valeurs['nom_fr']) > 128 || mb_strlen($valeurs['nom_en']) > 128) {
            $erreurs[] = t('catalogue.erreur_trop_long', ['champ' => t('categories.nom'), 'max' => 128]);
        }
        return [$valeurs, $erreurs];
    }

    private function categorieOu404(string $id): array
    {
        $categorie = ctype_digit($id) ? $this->catalogue->categorie((int) $id) : null;
        if ($categorie === null) {
            pageIntrouvable();
        }
        return $categorie;
    }
}
