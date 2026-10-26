<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Journal;
use App\Core\Session;
use App\Core\View;
use App\Repositories\GestionUtilisateurRepository;

/** Paramètres > Utilisateurs > Appareils de caisse (autorisés depuis la caisse) et journal des actions. */
final class AppareilController
{
    private GestionUtilisateurRepository $depot;

    public function __construct()
    {
        $this->depot = new GestionUtilisateurRepository();
    }

    public function index(): void
    {
        $moi = Session::exigerConnexion();
        View::afficher('gestion/utilisateurs/appareils', [
            'titre' => t('appareils.titre'),
            'utilisateur' => $moi,
            'flash' => Session::lireFlash(),
            'appareils' => $this->depot->appareils(),
        ], 'gestion');
    }

    /** Retirer un appareil : il ne peut plus servir de caisse partagée (tablette perdue, remplacée…). */
    public function retirer(string $id): void
    {
        Session::exigerConnexion();
        Session::verifierCsrf();
        $appareil = ctype_digit($id) ? $this->depot->appareil((int) $id) : null;
        if ($appareil === null) {
            pageIntrouvable();
        }
        $this->depot->retirerAppareil((int) $appareil['id']);
        Journal::ecrire('appareil_retire', $appareil['nom'], [], null, (int) $appareil['id']);
        Session::flash(t('appareils.retire', ['nom' => $appareil['nom']]));
        rediriger('/gestion/utilisateurs/appareils');
    }

    public function journal(): void
    {
        $moi = Session::exigerConnexion();
        $personne = isset($_GET['personne']) && ctype_digit((string) $_GET['personne']) ? (int) $_GET['personne'] : null;
        View::afficher('gestion/utilisateurs/journal', [
            'titre' => t('journal.titre'),
            'utilisateur' => $moi,
            'entrees' => $this->depot->journal($personne),
            'personnes' => $this->depot->tous(),
            'personne' => $personne,
        ], 'gestion');
    }
}
