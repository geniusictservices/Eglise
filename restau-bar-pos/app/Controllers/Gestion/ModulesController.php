<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Modules;
use App\Core\Session;
use App\Core\View;
use App\Repositories\GestionDetteRepository;

/** Écran d'accueil après la connexion : la liste des modules, et les garanties qui attendent la réponse de la personne. */
final class ModulesController
{
    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        View::afficher('gestion/modules', [
            'titre' => t('modules.titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'modules' => Modules::pour((int) $utilisateur['id']),
            'garanties' => (new GestionDetteRepository())->nombreGarantiesEnAttente((int) $utilisateur['id']),
        ], 'gestion');
    }
}
