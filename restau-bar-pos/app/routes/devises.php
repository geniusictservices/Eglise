<?php

declare(strict_types=1);

/**
 * Devises et taux de change.
 * @var App\Core\Router $routeur
 */

use App\Controllers\Gestion\DeviseController;

$routeur->get('/gestion/devises', [DeviseController::class, 'index']);
// Chemins fixes avant {id}, que le motif accepterait aussi
$routeur->post('/gestion/devises/taux', [DeviseController::class, 'enregistrerTaux']);
$routeur->post('/gestion/devises/reference', [DeviseController::class, 'choisirReference']);
$routeur->get('/gestion/devises/nouvelle', [DeviseController::class, 'nouvelle']);
$routeur->post('/gestion/devises/nouvelle', [DeviseController::class, 'creer']);
$routeur->get('/gestion/devises/{id}', [DeviseController::class, 'fiche']);
$routeur->post('/gestion/devises/{id}', [DeviseController::class, 'modifier']);
$routeur->post('/gestion/devises/{id}/activer', [DeviseController::class, 'activer']);
$routeur->post('/gestion/devises/{id}/desactiver', [DeviseController::class, 'desactiver']);
