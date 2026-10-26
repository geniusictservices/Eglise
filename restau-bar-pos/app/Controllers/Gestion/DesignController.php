<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Session;
use App\Core\View;

/** Page vivante du système de design « Volcan » : jetons et composants dans les deux thèmes. */
final class DesignController
{
    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();

        View::afficher('gestion/design', [
            'titre' => t('design.titre'),
            'utilisateur' => $utilisateur,
        ], 'gestion');
    }
}
