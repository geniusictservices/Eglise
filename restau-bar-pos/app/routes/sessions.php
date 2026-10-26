<?php

declare(strict_types=1);

/**
 * Ventes > Sessions de caisse : liste, rapport de clôture, clôture d'une session oubliée.
 * @var App\Core\Router $routeur
 */

use App\Controllers\Gestion\SessionController;

$routeur->get('/gestion/sessions-caisse', [SessionController::class, 'index']);
$routeur->get('/gestion/sessions-caisse/{id}', [SessionController::class, 'fiche']);
$routeur->post('/gestion/sessions-caisse/{id}/cloturer', [SessionController::class, 'cloturer']);
