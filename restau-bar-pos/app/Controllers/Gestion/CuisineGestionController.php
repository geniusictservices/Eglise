<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Database;
use App\Core\Journal;
use App\Core\Journee;
use App\Core\Session;
use App\Core\View;
use App\Repositories\CuisineRepository;

/**
 * Cuisine > Postes et écrans : les postes de l'établissement (grill, froid, pizza, pâtisserie, bar…), leur alerte de
 * retard et le comptoir du stock qu'ils servent ; le routage des catégories vers les postes ; les écrans de
 * cuisine autorisés (à retirer s'ils sont perdus) ; les temps de préparation de la journée.
 * Voir : « cuisine.voir » ; modifier : « cuisine.parametrer ».
 */
final class CuisineGestionController
{
    private CuisineRepository $cuisine;

    public function __construct()
    {
        $this->cuisine = new CuisineRepository();
    }

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $liste = SalleAcces::etablissements($uid, 'cuisine.voir');
        $etab = SalleAcces::choisi($liste);
        if ($etab === null) {
            SalleAcces::interdit($utilisateur);
        }
        $etabId = (int) $etab['id'];
        $categories = Database::tous('SELECT c.id, c.parent_id, c.nom_fr, c.nom_en FROM categories c ORDER BY COALESCE((SELECT p.position FROM categories p WHERE p.id = c.parent_id), c.position), COALESCE(c.parent_id, c.id), c.parent_id IS NOT NULL, c.position, c.id');
        // Comptoirs du module stock (s'il est installé) et poste qu'ils donneraient à chaque catégorie
        $comptoirs = [];
        $parStock = [];
        try {
            $comptoirs = Database::tous("SELECT id, nom FROM lieux_stock WHERE etablissement_id = ? AND type = 'comptoir' AND actif = 1 ORDER BY position, id", [$etabId]);
        } catch (\Throwable) {
            $comptoirs = [];
        }
        $routage = $this->cuisine->routageCategories($etabId);
        foreach ($categories as $c) {
            if (!isset($routage[(int) $c['id']])) {
                $parStock[(int) $c['id']] = $this->cuisine->postePour($etabId, (int) $c['id']);
            }
        }
        [$debut, $fin] = Journee::bornes(Journee::aujourdhui($etab), $etab);
        View::afficher('gestion/cuisine/index', [
            'titre' => t('gcuisine.titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'erreur' => $_SESSION['cuisine_erreur'] ?? null,
            'etablissements' => $liste,
            'etab' => $etab,
            'postes' => $this->cuisine->postes($etabId, false),
            'categories' => $categories,
            'routage' => $routage,
            'parStock' => $parStock,
            'comptoirs' => $comptoirs,
            'ecrans' => $this->cuisine->ecrans([$etabId]),
            'temps' => $this->cuisine->tempsPreparation($etabId, $debut, $fin),
            'peutModifier' => Acces::a($uid, 'cuisine.parametrer', $etabId),
        ], 'gestion');
        unset($_SESSION['cuisine_erreur']);
    }

    public function creerPoste(): void
    {
        [$utilisateur, $etab] = $this->etablissement(SalleAcces::entier($_POST['etablissement'] ?? null, 0, PHP_INT_MAX, 0));
        $nom = SalleAcces::texte($_POST['nom'] ?? '', 60);
        if ($nom === '') {
            $this->retour($etab, null, t('gcuisine.erreur_nom'));
        }
        $this->cuisine->creerPoste((int) $etab['id'], $nom, $this->lieu((int) $etab['id']), SalleAcces::entier($_POST['alerte'] ?? 15, 1, 240, 15));
        Journal::ecrire('poste_cuisine_cree', $nom, ['etablissement' => $etab['nom']], (int) $utilisateur['id']);
        $this->retour($etab, t('gcuisine.poste_cree', ['nom' => $nom]));
    }

    public function modifierPoste(string $id): void
    {
        $poste = ctype_digit($id) ? $this->cuisine->poste((int) $id) : null;
        if ($poste === null) {
            pageIntrouvable();
        }
        [, $etab] = $this->etablissement((int) $poste['etablissement_id']);
        $nom = SalleAcces::texte($_POST['nom'] ?? '', 60);
        if ($nom === '') {
            $this->retour($etab, null, t('gcuisine.erreur_nom'));
        }
        $this->cuisine->modifierPoste((int) $poste['id'], $nom, $this->lieu((int) $etab['id']), SalleAcces::entier($_POST['alerte'] ?? 15, 1, 240, 15), !empty($_POST['actif']));
        $this->retour($etab, t('gcuisine.poste_modifie', ['nom' => $nom]));
    }

    /** POST : routage = {categorie: poste} (0 : selon le stock, ou pas de cuisine). */
    public function routage(): void
    {
        [$utilisateur, $etab] = $this->etablissement(SalleAcces::entier($_POST['etablissement'] ?? null, 0, PHP_INT_MAX, 0));
        $routage = [];
        foreach (is_array($_POST['routage'] ?? null) ? $_POST['routage'] : [] as $categorie => $poste) {
            $routage[(int) $categorie] = (int) $poste;
        }
        $this->cuisine->enregistrerRoutage((int) $etab['id'], $routage);
        Journal::ecrire('routage_cuisine', $etab['nom'], ['routage' => array_filter($routage)], (int) $utilisateur['id']);
        $this->retour($etab, t('gcuisine.routage_enregistre'));
    }

    public function retirerEcran(string $id): void
    {
        $ecran = ctype_digit($id) ? Database::un('SELECT * FROM ecrans_cuisine WHERE id = ?', [(int) $id]) : null;
        if ($ecran === null) {
            pageIntrouvable();
        }
        [$utilisateur, $etab] = $this->etablissement((int) $ecran['etablissement_id']);
        Database::executer('UPDATE ecrans_cuisine SET actif = 0 WHERE id = ?', [(int) $ecran['id']]);
        Journal::ecrire('ecran_cuisine_retire', $ecran['nom'], ['etablissement' => $etab['nom']], (int) $utilisateur['id']);
        $this->retour($etab, t('gcuisine.ecran_retire', ['nom' => $ecran['nom']]));
    }

    /** Comptoir du stock choisi pour un poste (de cet établissement), ou null. */
    private function lieu(int $etabId): ?int
    {
        $lieu = SalleAcces::entier($_POST['lieu'] ?? 0, 0, PHP_INT_MAX, 0);
        if ($lieu === 0) {
            return null;
        }
        try {
            return Database::un("SELECT id FROM lieux_stock WHERE id = ? AND etablissement_id = ? AND type = 'comptoir'", [$lieu, $etabId]) !== null ? $lieu : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{0: array, 1: array} */
    private function etablissement(int $etabId): array
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        return [$utilisateur, SalleAcces::exiger($utilisateur, 'cuisine.parametrer', $etabId)];
    }

    private function retour(array $etab, ?string $flash, ?string $erreur = null): never
    {
        if ($flash !== null) {
            Session::flash($flash);
        }
        if ($erreur !== null) {
            $_SESSION['cuisine_erreur'] = $erreur;
        }
        rediriger('/gestion/cuisine?etablissement=' . (int) $etab['id']);
    }
}
