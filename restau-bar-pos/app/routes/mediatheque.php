<?php

declare(strict_types=1);

/**
 * Médiathèque : images de l'instance, envoi, choix depuis les formulaires, fiche et suppression.
 * @var App\Core\Router $routeur
 */

use App\Controllers\Gestion\MediathequeController;

$routeur->get('/gestion/mediatheque', [MediathequeController::class, 'index']);
// Chemins fixes avant {id}, que le motif accepterait aussi
$routeur->post('/gestion/mediatheque/envoyer', [MediathequeController::class, 'envoyer']);
$routeur->get('/gestion/mediatheque/liste', [MediathequeController::class, 'liste']);
$routeur->get('/gestion/mediatheque/{id}', [MediathequeController::class, 'fiche']);
$routeur->post('/gestion/mediatheque/{id}', [MediathequeController::class, 'modifier']);
$routeur->post('/gestion/mediatheque/{id}/supprimer', [MediathequeController::class, 'supprimer']);
