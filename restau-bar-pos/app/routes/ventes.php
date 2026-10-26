<?php

declare(strict_types=1);

/**
 * Ventes (tickets encaissés : liste, fiche, annulation) et rapport « ventes par vendeur ».
 * @var App\Core\Router $routeur
 */

use App\Controllers\Gestion\RapportVenteController;
use App\Controllers\Gestion\VenteController;

$routeur->get('/gestion/ventes', [VenteController::class, 'index']);
$routeur->get('/gestion/ventes/{id}', [VenteController::class, 'fiche']);
$routeur->post('/gestion/ventes/{id}/annuler', [VenteController::class, 'annuler']);

$routeur->get('/gestion/rapports/ventes', [RapportVenteController::class, 'index']);
