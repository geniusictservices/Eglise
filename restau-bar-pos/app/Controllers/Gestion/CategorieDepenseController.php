<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Session;
use App\Core\View;
use App\Repositories\GestionDepenseRepository;

/** Catégories de dépenses : ajout, nom en français et en anglais, ordre, désactivation, suppression si inutilisée. */
final class CategorieDepenseController
{
    private GestionDepenseRepository $depenses;

    public function __construct()
    {
        $this->depenses = new GestionDepenseRepository();
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
        [$valeurs, $erreurs] = $this->lire(0);
        if ($erreurs !== []) {
            $this->afficher($utilisateur, ['nouvelle' => $valeurs, 'erreursNouvelle' => $erreurs], 422);
            return;
        }
        $id = $this->depenses->creerCategorie($valeurs['nom_fr'], $valeurs['nom_en'], (int) $valeurs['position']);
        Session::flash(t('categories_depenses.creee', ['nom' => $valeurs['nom_fr']]));
        rediriger('/gestion/depenses/categories#categorie-' . $id);
    }

    public function modifier(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $categorie = $this->categorieOu404($id);
        [$valeurs, $erreurs] = $this->lire((int) $categorie['id']);
        if ($erreurs !== []) {
            $this->afficher($utilisateur, ['saisies' => [(int) $categorie['id'] => $valeurs], 'erreursLignes' => [(int) $categorie['id'] => $erreurs]], 422);
            return;
        }
        $this->depenses->modifierCategorie((int) $categorie['id'], $valeurs['nom_fr'], $valeurs['nom_en'], (int) $valeurs['position']);
        Session::flash(t('categories_depenses.enregistree', ['nom' => $valeurs['nom_fr']]));
        rediriger('/gestion/depenses/categories#categorie-' . (int) $categorie['id']);
    }

    public function activer(string $id): void
    {
        $this->changerEtat($id, true);
    }

    public function desactiver(string $id): void
    {
        $this->changerEtat($id, false);
    }

    public function supprimer(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $categorie = $this->categorieOu404($id);
        if ((int) $categorie['nb_depenses'] > 0) {
            $this->afficher($utilisateur, ['erreurs' => [t('categories_depenses.erreur_utilisee', [
                'nom' => champ($categorie, 'nom'), 'n' => (int) $categorie['nb_depenses'],
            ])]], 422);
            return;
        }
        $this->depenses->supprimerCategorie((int) $categorie['id']);
        Session::flash(t('categories_depenses.supprimee', ['nom' => champ($categorie, 'nom')]));
        rediriger('/gestion/depenses/categories');
    }

    private function changerEtat(string $id, bool $active): void
    {
        Session::exigerConnexion();
        Session::verifierCsrf();
        $categorie = $this->categorieOu404($id);
        $this->depenses->definirCategorieActive((int) $categorie['id'], $active);
        Session::flash(t($active ? 'categories_depenses.activee' : 'categories_depenses.desactivee', ['nom' => champ($categorie, 'nom')]));
        rediriger('/gestion/depenses/categories#categorie-' . (int) $categorie['id']);
    }

    private function afficher(array $utilisateur, array $etat = [], int $code = 200): void
    {
        http_response_code($code);
        View::afficher('gestion/depenses/categories', [
            'titre' => t('categories_depenses.titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'categories' => $this->depenses->categories(),
            'nouvelle' => $etat['nouvelle'] ?? ['nom_fr' => '', 'nom_en' => '', 'position' => ''],
            'erreursNouvelle' => $etat['erreursNouvelle'] ?? [],
            'saisies' => $etat['saisies'] ?? [],
            'erreursLignes' => $etat['erreursLignes'] ?? [],
            'erreurs' => $etat['erreurs'] ?? [],
        ], 'gestion');
    }

    /** @return array{array, array<string, string>} */
    private function lire(int $id): array
    {
        $valeurs = [
            'nom_fr' => mb_substr(TresorerieSaisie::post('nom_fr'), 0, 200),
            'nom_en' => mb_substr(TresorerieSaisie::post('nom_en'), 0, 200),
            'position' => TresorerieSaisie::post('position'),
        ];
        $erreurs = [];
        if ($valeurs['nom_fr'] === '') {
            $erreurs['nom_fr'] = t('categories_depenses.erreur_nom');
        } elseif (mb_strlen($valeurs['nom_fr']) > 128) {
            $erreurs['nom_fr'] = t('tresorerie.erreur_texte_long', ['n' => 128]);
        } elseif ($this->depenses->nomCategoriePris($valeurs['nom_fr'], $id)) {
            $erreurs['nom_fr'] = t('categories_depenses.erreur_nom_pris', ['nom' => $valeurs['nom_fr']]);
        }
        if (mb_strlen($valeurs['nom_en']) > 128) {
            $erreurs['nom_en'] = t('tresorerie.erreur_texte_long', ['n' => 128]);
        }
        if ($valeurs['position'] === '') {
            $valeurs['position'] = (string) ($id ? (int) ($this->depenses->categorie($id)['position'] ?? 0) : $this->depenses->positionCategorieSuivante());
        }
        if (!ctype_digit($valeurs['position']) || (int) $valeurs['position'] > 999) {
            $erreurs['position'] = t('tresorerie.erreur_position');
        }
        if ($valeurs['nom_en'] === '' && !isset($erreurs['nom_fr'])) {
            $valeurs['nom_en'] = $valeurs['nom_fr'];
        }
        return [$valeurs, $erreurs];
    }

    private function categorieOu404(string $id): array
    {
        $categorie = ctype_digit($id) ? $this->depenses->categorie((int) $id) : null;
        if ($categorie === null) {
            pageIntrouvable();
        }
        return $categorie;
    }
}
