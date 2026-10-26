<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

/**
 * Rapports > Personnel (/gestion/rapports/personnel, routes du module Personnel et paie).
 * Le classement des ventes et les dettes liées aux utilisateurs sont dans RapportServeursController ; la présence
 * s'ajoute dans la même page avec le partiel views/gestion/rapports/_personnel-presence.php (module Personnel).
 */
final class RapportPersonnelController
{
    public function index(): void
    {
        (new RapportServeursController())->index();
    }
}
