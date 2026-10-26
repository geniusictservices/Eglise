<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Session;
use App\Core\View;
use App\Repositories\GestionCatalogueRepository;

/**
 * Catégories du catalogue : liste en arbre, ajout, modification, ordre, suppression.
 * Deux niveaux au plus : une catégorie principale peut avoir des sous-catégories (Bières > Locales),
 * une sous-catégorie n'en a pas. L'ordre se règle à l'intérieur de chaque niveau.
 */
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
        [$valeurs, $erreurs] = $this->lire(null);
        if ($erreurs !== []) {
            $this->afficher($utilisateur, ['nouvelle' => $valeurs, 'erreurs' => ['nouvelle' => $erreurs]], 422);
            return;
        }
        $id = $this->catalogue->creerCategorie($valeurs['nom_fr'], $valeurs['nom_en'], $valeurs['visible_public'], $valeurs['parent_id']);
        Session::flash(t('categories.creee', ['nom' => $valeurs['nom_fr']]));
        rediriger('/gestion/catalogue/categories#categorie-' . $id);
    }

    public function modifier(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $categorie = $this->categorieOu404($id);
        [$valeurs, $erreurs] = $this->lire($categorie);
        if ($erreurs !== []) {
            $this->afficher($utilisateur, [
                'saisies' => [(int) $categorie['id'] => $valeurs],
                'erreurs' => ['categorie-' . $categorie['id'] => $erreurs],
            ], 422);
            return;
        }
        $this->catalogue->modifierCategorie((int) $categorie['id'], $valeurs['nom_fr'], $valeurs['nom_en'], $valeurs['visible_public'], $valeurs['parent_id']);
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
        $sous = $this->catalogue->sousCategories((int) $categorie['id']);
        $refus = $this->catalogue->supprimerCategorie((int) $categorie['id']);
        if ($refus !== null) {
            $message = $refus === 'sous_categories'
                ? t('categories.erreur_a_sous_categories', ['nom' => champ($categorie, 'nom'), 'liste' => implode(', ', array_map(static fn (array $c) => champ($c, 'nom'), $sous))])
                : t('categories.erreur_non_vide', ['nom' => champ($categorie, 'nom')]);
            $this->afficher($utilisateur, ['erreurs' => ['categorie-' . $categorie['id'] => [$message]]], 422);
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
            'nouvelle' => $etat['nouvelle'] ?? ['nom_fr' => '', 'nom_en' => '', 'visible_public' => true, 'parent_id' => null],
            'saisies' => $etat['saisies'] ?? [],
            'erreurs' => $etat['erreurs'] ?? [],
        ], 'gestion');
    }

    /**
     * @param ?array $categorie la catégorie modifiée, null à la création
     * @return array{array, string[]}
     */
    private function lire(?array $categorie): array
    {
        $parent = $_POST['parent_id'] ?? '';
        $valeurs = [
            'nom_fr' => is_string($_POST['nom_fr'] ?? null) ? trim((string) preg_replace('/\p{Cc}/u', '', $_POST['nom_fr'])) : '',
            'nom_en' => is_string($_POST['nom_en'] ?? null) ? trim((string) preg_replace('/\p{Cc}/u', '', $_POST['nom_en'])) : '',
            'visible_public' => !empty($_POST['visible_public']),
            'parent_id' => is_string($parent) && ctype_digit($parent) && (int) $parent > 0 ? (int) $parent : null,
        ];
        $erreurs = [];
        if ($valeurs['nom_fr'] === '') {
            $erreurs[] = t('categories.erreur_nom');
        }
        if (mb_strlen($valeurs['nom_fr']) > 128 || mb_strlen($valeurs['nom_en']) > 128) {
            $erreurs[] = t('catalogue.erreur_trop_long', ['champ' => t('categories.nom'), 'max' => 128]);
        }
        if (is_string($parent) && $parent !== '' && $valeurs['parent_id'] === null) {
            $erreurs[] = t('categories.erreur_parente_inconnue');
        }
        $erreur = $this->catalogue->erreurParente($categorie, $valeurs['parent_id']);
        if ($erreur !== null) {
            $erreurs[] = $erreur;
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
