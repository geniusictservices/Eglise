<?php

declare(strict_types=1);

/**
 * Paramètres > Assistance : accord du propriétaire (24 h) et entrée du support par le jeton de la console.
 * @var App\Core\Router $routeur
 */

use App\Controllers\Gestion\AssistanceController;

$routeur->get('/gestion/assistance', [AssistanceController::class, 'index']);
$routeur->post('/gestion/assistance', [AssistanceController::class, 'changer']);
$routeur->get('/gestion/assistance/entrer/{jeton}', [AssistanceController::class, 'entrer']);
