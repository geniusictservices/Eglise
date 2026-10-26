<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Database;
use App\Core\Session;
use App\Core\View;
use App\Repositories\DeviseRepository;
use App\Repositories\EtablissementRepository;

final class TableauController
{
    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $devises = new DeviseRepository();

        View::afficher('gestion/tableau', [
            'titre' => t('gestion.tableau'),
            'utilisateur' => $utilisateur,
            'etablissements' => (new EtablissementRepository())->actifs(),
            'devises' => $devises->actives(),
            'reference' => $devises->reference(),
            'nbProduits' => (int) Database::un('SELECT COUNT(*) AS n FROM produits WHERE actif = 1')['n'],
            'scans7j' => (int) Database::un(
                'SELECT COALESCE(SUM(nombre), 0) AS n FROM qr_scans WHERE jour >= ?',
                [date('Y-m-d', strtotime('-6 days'))]
            )['n'],
        ], 'gestion');
    }
}
