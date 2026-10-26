<?php

declare(strict_types=1);

/**
 * Achats : fournisseurs, commandes, réceptions et paiements, dettes fournisseurs, prix d'achat.
 * Chemins fixes avant {id}, que le motif accepterait aussi.
 * @var App\Core\Router $routeur
 */

use App\Controllers\Gestion\AchatController;
use App\Controllers\Gestion\CommandeAchatController;
use App\Controllers\Gestion\FournisseurController;
use App\Controllers\Gestion\ReceptionController;

$routeur->get('/gestion/achats/fournisseurs', [FournisseurController::class, 'index']);
$routeur->get('/gestion/achats/fournisseurs/nouveau', [FournisseurController::class, 'nouveau']);
$routeur->post('/gestion/achats/fournisseurs/nouveau', [FournisseurController::class, 'creer']);
$routeur->get('/gestion/achats/fournisseurs/{id}', [FournisseurController::class, 'fiche']);
$routeur->get('/gestion/achats/fournisseurs/{id}/modifier', [FournisseurController::class, 'editer']);
$routeur->post('/gestion/achats/fournisseurs/{id}/modifier', [FournisseurController::class, 'modifier']);

$routeur->get('/gestion/achats/commandes', [CommandeAchatController::class, 'index']);
$routeur->get('/gestion/achats/commandes/nouvelle', [CommandeAchatController::class, 'nouvelle']);
$routeur->post('/gestion/achats/commandes/nouvelle', [CommandeAchatController::class, 'creer']);
$routeur->get('/gestion/achats/commandes/{id}', [CommandeAchatController::class, 'fiche']);
$routeur->get('/gestion/achats/commandes/{id}/modifier', [CommandeAchatController::class, 'editer']);
$routeur->post('/gestion/achats/commandes/{id}/modifier', [CommandeAchatController::class, 'modifier']);
$routeur->post('/gestion/achats/commandes/{id}/envoyer', [CommandeAchatController::class, 'envoyer']);
$routeur->post('/gestion/achats/commandes/{id}/annuler', [CommandeAchatController::class, 'annuler']);

$routeur->get('/gestion/achats/receptions', [ReceptionController::class, 'index']);
$routeur->get('/gestion/achats/receptions/nouvelle', [ReceptionController::class, 'nouvelle']);
$routeur->post('/gestion/achats/receptions/nouvelle', [ReceptionController::class, 'creer']);
$routeur->get('/gestion/achats/receptions/{id}', [ReceptionController::class, 'fiche']);
$routeur->post('/gestion/achats/receptions/{id}/payer', [ReceptionController::class, 'payer']);
$routeur->post('/gestion/achats/paiements/{id}/supprimer', [ReceptionController::class, 'supprimerPaiement']);

$routeur->get('/gestion/achats/dettes', [AchatController::class, 'dettes']);
$routeur->get('/gestion/achats/prix', [AchatController::class, 'prix']);
