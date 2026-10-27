<?php

declare(strict_types=1);

/**
 * Lounge et nightclub : caisse de l'entrée et vestiaire (mêmes règles d'abonnement que la caisse), cashless, invités,
 * fidélité à la caisse (JSON) ; écrans de gestion du module Nuit (formules, minimums, billetterie, invités et
 * promoteurs, vestiaire, espace du promoteur), du cashless, des remises et de la fidélité.
 * @var App\Core\Router $routeur
 */

use App\Controllers\CaisseController;
use App\Controllers\CaisseNuitController;

// Caisse : écrans de l'entrée et du vestiaire
$routeur->get('/caisse/entree', [CaisseController::class, 'entree']);
$routeur->get('/caisse/vestiaire', [CaisseController::class, 'vestiaire']);
$routeur->post('/caisse/entree/controle', [CaisseNuitController::class, 'controle']);
$routeur->get('/caisse/entree/compteurs', [CaisseNuitController::class, 'compteurs']);
$routeur->get('/caisse/entree/invites', [CaisseNuitController::class, 'invites']);
$routeur->post('/caisse/entree/invites/{id}/pointer', [CaisseNuitController::class, 'pointer']);
$routeur->post('/caisse/vestiaire/deposer', [CaisseNuitController::class, 'deposer']);
$routeur->post('/caisse/vestiaire/rendre', [CaisseNuitController::class, 'rendre']);
$routeur->get('/caisse/vestiaire/liste', [CaisseNuitController::class, 'restants']);
// Caisse : cashless, fidélité, invité d'une note
$routeur->get('/caisse/nuit/carte', [CaisseNuitController::class, 'carte']);
$routeur->post('/caisse/nuit/carte/nouvelle', [CaisseNuitController::class, 'nouvelleCarte']);
$routeur->post('/caisse/nuit/carte/recharger', [CaisseNuitController::class, 'recharger']);
$routeur->post('/caisse/nuit/carte/rembourser', [CaisseNuitController::class, 'rembourser']);
$routeur->post('/caisse/nuit/carte/bloquer', [CaisseNuitController::class, 'bloquer']);
$routeur->get('/caisse/nuit/clients', [CaisseNuitController::class, 'clients']);
$routeur->post('/caisse/nuit/clients', [CaisseNuitController::class, 'creerClient']);
$routeur->post('/caisse/notes/{id}/invite', [CaisseNuitController::class, 'inviteNote']);
