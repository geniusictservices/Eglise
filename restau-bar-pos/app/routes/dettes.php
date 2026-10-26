<?php

declare(strict_types=1);

/**
 * Clients et dettes dans la gestion : clients, dettes, remboursements, garanties, messagerie et rapport des créances.
 * Les routes fixes (« nouveau », « journal ») passent avant celles qui prennent un identifiant.
 * @var App\Core\Router $routeur
 */

use App\Controllers\Gestion\ClientController;
use App\Controllers\Gestion\DetteController;
use App\Controllers\Gestion\GarantieController;
use App\Controllers\Gestion\MessagerieController;
use App\Controllers\Gestion\RapportCreanceController;
use App\Controllers\Gestion\RemboursementController;

$routeur->get('/gestion/clients', [ClientController::class, 'index']);
$routeur->get('/gestion/clients/nouveau', [ClientController::class, 'nouveau']);
$routeur->post('/gestion/clients/nouveau', [ClientController::class, 'creer']);
$routeur->get('/gestion/clients/{id}', [ClientController::class, 'fiche']);
$routeur->get('/gestion/clients/{id}/modifier', [ClientController::class, 'modifier']);
$routeur->post('/gestion/clients/{id}/modifier', [ClientController::class, 'enregistrer']);

$routeur->get('/gestion/dettes', [DetteController::class, 'index']);
$routeur->get('/gestion/dettes/{id}', [DetteController::class, 'fiche']);
$routeur->post('/gestion/dettes/{id}/responsable', [DetteController::class, 'responsable']);
$routeur->post('/gestion/dettes/{id}/a-charge', [DetteController::class, 'aCharge']);
$routeur->post('/gestion/dettes/{id}/cloturer', [DetteController::class, 'cloturer']);

$routeur->get('/gestion/remboursements', [RemboursementController::class, 'index']);
$routeur->get('/gestion/remboursements/nouveau', [RemboursementController::class, 'nouveau']);
$routeur->post('/gestion/remboursements/nouveau', [RemboursementController::class, 'creer']);
$routeur->get('/gestion/remboursements/{id}', [RemboursementController::class, 'fiche']);
$routeur->post('/gestion/remboursements/{id}/annuler', [RemboursementController::class, 'annuler']);

$routeur->get('/gestion/garanties', [GarantieController::class, 'index']);
$routeur->post('/gestion/garanties/{id}', [GarantieController::class, 'repondre']);

$routeur->get('/gestion/messagerie', [MessagerieController::class, 'index']);
$routeur->post('/gestion/messagerie', [MessagerieController::class, 'enregistrer']);
$routeur->post('/gestion/messagerie/essai', [MessagerieController::class, 'essai']);
$routeur->get('/gestion/messagerie/journal', [MessagerieController::class, 'journal']);

$routeur->get('/gestion/rapports/creances', [RapportCreanceController::class, 'index']);
