<?php

declare(strict_types=1);

/**
 * Routes de la console super admin (admin.<domaine> seulement, chargées par app/console.php).
 * @var App\Core\Router $routeur
 */

use App\Controllers\Console\ClientController;
use App\Controllers\Console\CompteController;
use App\Controllers\Console\ConnexionController;
use App\Controllers\Console\FormuleController;
use App\Controllers\Console\JournalController;
use App\Controllers\Console\MessagerieController;
use App\Controllers\Console\PaiementController;
use App\Controllers\Console\ReglagesController;
use App\Controllers\Console\TableauController;

$routeur->get('/connexion', [ConnexionController::class, 'formulaire']);
$routeur->post('/connexion', [ConnexionController::class, 'connecter']);
$routeur->post('/deconnexion', [ConnexionController::class, 'deconnecter']);

$routeur->get('/', [TableauController::class, 'index']);

// Clients et instances (« nouveau » et « mettre-a-jour » avant {id})
$routeur->get('/clients', [ClientController::class, 'index']);
$routeur->get('/clients/nouveau', [ClientController::class, 'nouveau']);
$routeur->post('/clients/nouveau', [ClientController::class, 'creer']);
$routeur->post('/clients/mettre-a-jour', [ClientController::class, 'migrerTout']);
$routeur->get('/clients/{id}', [ClientController::class, 'fiche']);
$routeur->post('/clients/{id}', [ClientController::class, 'modifier']);
$routeur->post('/clients/{id}/abonnement', [ClientController::class, 'abonnement']);
$routeur->post('/clients/{id}/statut', [ClientController::class, 'statut']);
$routeur->post('/clients/{id}/expediteur', [ClientController::class, 'expediteur']);
$routeur->post('/clients/{id}/tester', [ClientController::class, 'tester']);
$routeur->post('/clients/{id}/installer', [ClientController::class, 'installer']);
$routeur->post('/clients/{id}/mettre-a-jour', [ClientController::class, 'migrer']);
$routeur->post('/clients/{id}/sauvegarde', [ClientController::class, 'sauvegarde']);
$routeur->post('/clients/{id}/assistance', [ClientController::class, 'assistance']);
$routeur->post('/clients/{id}/proprietaire', [ClientController::class, 'proprietaire']);
$routeur->post('/clients/{id}/paiements', [PaiementController::class, 'enregistrer']);
$routeur->post('/clients/{id}/paiements/{paiement}/annuler', [PaiementController::class, 'annuler']);

// Formules d'abonnement
$routeur->get('/formules', [FormuleController::class, 'index']);
$routeur->get('/formules/nouvelle', [FormuleController::class, 'nouvelle']);
$routeur->post('/formules/nouvelle', [FormuleController::class, 'creer']);
$routeur->get('/formules/{id}', [FormuleController::class, 'fiche']);
$routeur->post('/formules/{id}', [FormuleController::class, 'modifier']);
$routeur->post('/formules/{id}/supprimer', [FormuleController::class, 'supprimer']);

// API de messagerie (Africa's Talking, WhatsApp Cloud API), commune à tous les clients
$routeur->get('/messagerie', [MessagerieController::class, 'index']);
$routeur->post('/messagerie', [MessagerieController::class, 'enregistrer']);
$routeur->post('/messagerie/essai', [MessagerieController::class, 'essai']);

$routeur->get('/reglages', [ReglagesController::class, 'index']);
$routeur->post('/reglages', [ReglagesController::class, 'enregistrer']);
$routeur->get('/journal', [JournalController::class, 'index']);
$routeur->get('/compte', [CompteController::class, 'index']);
$routeur->post('/compte', [CompteController::class, 'motDePasse']);
