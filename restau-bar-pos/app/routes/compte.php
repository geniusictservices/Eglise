<?php

declare(strict_types=1);

/**
 * Mon compte : nom, langue, mot de passe.
 * @var App\Core\Router $routeur
 */

use App\Controllers\Gestion\CompteController;

$routeur->get('/gestion/compte', [CompteController::class, 'index']);
$routeur->post('/gestion/compte', [CompteController::class, 'enregistrer']);
$routeur->post('/gestion/compte/mot-de-passe', [CompteController::class, 'changerMotDePasse']);
$routeur->post('/gestion/compte/photo', [CompteController::class, 'photo']);
