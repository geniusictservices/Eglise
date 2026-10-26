<?php

declare(strict_types=1);

/**
 * Événements (soirées des lounges et nightclubs) : liste, rentabilité, fiche, création, modification, coûts.
 * Les chemins fixes passent avant ceux qui prennent un identifiant.
 * @var App\Core\Router $routeur
 */

use App\Controllers\Gestion\EvenementController;

$routeur->get('/gestion/evenements', [EvenementController::class, 'index']);
$routeur->get('/gestion/evenements/rentabilite', [EvenementController::class, 'rentabilite']);
$routeur->get('/gestion/evenements/nouveau', [EvenementController::class, 'nouveau']);
$routeur->post('/gestion/evenements/nouveau', [EvenementController::class, 'creer']);
$routeur->get('/gestion/evenements/{id}', [EvenementController::class, 'fiche']);
$routeur->get('/gestion/evenements/{id}/modifier', [EvenementController::class, 'editer']);
$routeur->post('/gestion/evenements/{id}/modifier', [EvenementController::class, 'modifier']);
$routeur->post('/gestion/evenements/{id}/couts', [EvenementController::class, 'couts']);
$routeur->post('/gestion/evenements/{id}/statut', [EvenementController::class, 'statut']);
$routeur->post('/gestion/evenements/{id}/supprimer', [EvenementController::class, 'supprimer']);
