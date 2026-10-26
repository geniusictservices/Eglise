<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Session;
use App\Core\View;
use App\Repositories\GestionEtablissementRepository;

/** Grilles de prix (normal, VIP, nuit…) : liste, ajout, modification, suppression si inutilisée. */
final class GrilleController
{
    private GestionEtablissementRepository $depot;
    private EtablissementSaisie $saisie;

    public function __construct()
    {
        $this->depot = new GestionEtablissementRepository();
        $this->saisie = new EtablissementSaisie($this->depot);
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
        [$valeurs, $erreurs] = $this->saisie->grille($_POST);
        if ($erreurs !== []) {
            $this->afficher($utilisateur, ['nouvelle' => $valeurs, 'erreurs' => ['nouvelle' => $erreurs]], 422);
            return;
        }
        $id = $this->depot->creerGrille($valeurs);
        Session::flash(t('grilles.creee', ['nom' => $valeurs['nom_fr']]));
        rediriger('/gestion/grilles#grille-' . $id);
    }

    public function modifier(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $grille = $this->grilleOu404($id);
        [$valeurs, $erreurs] = $this->saisie->grille($_POST, (int) $grille['id']);
        if ($erreurs !== []) {
            $this->afficher($utilisateur, [
                'saisies' => [(int) $grille['id'] => $valeurs],
                'erreurs' => ['grille-' . $grille['id'] => $erreurs],
            ], 422);
            return;
        }
        $this->depot->modifierGrille((int) $grille['id'], $valeurs);
        Session::flash(t('grilles.enregistree', ['nom' => $valeurs['nom_fr']]));
        rediriger('/gestion/grilles#grille-' . (int) $grille['id']);
    }

    public function supprimer(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $grille = $this->grilleOu404($id);
        if (GestionEtablissementRepository::grilleUtilisee($grille)) {
            $this->afficher($utilisateur, ['erreurs' => ['grille-' . $grille['id'] => [
                'supprimer' => t('grilles.erreur_utilisee', ['nom' => champ($grille, 'nom')]),
            ]]], 422);
            return;
        }
        $this->depot->supprimerGrille((int) $grille['id']);
        Session::flash(t('grilles.supprimee', ['nom' => champ($grille, 'nom')]));
        rediriger('/gestion/grilles');
    }

    private function afficher(array $utilisateur, array $etat = [], int $code = 200): void
    {
        http_response_code($code);
        View::afficher('gestion/grilles/index', [
            'titre' => t('grilles.titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'grilles' => $this->depot->grilles(),
            'nouvelle' => $etat['nouvelle'] ?? ['code' => '', 'nom_fr' => '', 'nom_en' => ''],
            'saisies' => $etat['saisies'] ?? [],
            'erreurs' => $etat['erreurs'] ?? [],
        ], 'gestion');
    }

    private function grilleOu404(string $id): array
    {
        $grille = ctype_digit($id) && strlen($id) < 10 ? $this->depot->grille((int) $id) : null;
        if ($grille === null) {
            pageIntrouvable();
        }
        return $grille;
    }
}
