<?php

declare(strict_types=1);

/**
 * Paramètres > Utilisateurs : personnes, rôles, appareils de caisse, journal.
 * @var App\Core\Router $routeur
 */

use App\Controllers\Gestion\AppareilController;
use App\Controllers\Gestion\RoleController;
use App\Controllers\Gestion\UtilisateurController;

// Chemins fixes avant {id}, que le motif accepterait aussi
$routeur->get('/gestion/utilisateurs', [UtilisateurController::class, 'index']);
$routeur->get('/gestion/utilisateurs/nouveau', [UtilisateurController::class, 'nouveau']);
$routeur->post('/gestion/utilisateurs/nouveau', [UtilisateurController::class, 'creer']);
$routeur->get('/gestion/utilisateurs/roles', [RoleController::class, 'index']);
$routeur->get('/gestion/utilisateurs/roles/nouveau', [RoleController::class, 'nouveau']);
$routeur->post('/gestion/utilisateurs/roles/nouveau', [RoleController::class, 'creer']);
$routeur->get('/gestion/utilisateurs/roles/{id}', [RoleController::class, 'fiche']);
$routeur->post('/gestion/utilisateurs/roles/{id}', [RoleController::class, 'modifier']);
$routeur->post('/gestion/utilisateurs/roles/{id}/supprimer', [RoleController::class, 'supprimer']);
$routeur->get('/gestion/utilisateurs/appareils', [AppareilController::class, 'index']);
$routeur->post('/gestion/utilisateurs/appareils/reglages/{id}', [AppareilController::class, 'reglages']);
$routeur->post('/gestion/utilisateurs/appareils/{id}/retirer', [AppareilController::class, 'retirer']);
$routeur->get('/gestion/utilisateurs/journal', [AppareilController::class, 'journal']);
$routeur->get('/gestion/utilisateurs/{id}', [UtilisateurController::class, 'fiche']);
$routeur->post('/gestion/utilisateurs/{id}', [UtilisateurController::class, 'modifier']);
$routeur->post('/gestion/utilisateurs/{id}/pin', [UtilisateurController::class, 'pin']);
$routeur->post('/gestion/utilisateurs/{id}/photo', [UtilisateurController::class, 'photo']);
$routeur->post('/gestion/utilisateurs/{id}/debloquer', [UtilisateurController::class, 'debloquer']);
$routeur->post('/gestion/utilisateurs/{id}/desactiver', [UtilisateurController::class, 'desactiver']);
$routeur->post('/gestion/utilisateurs/{id}/activer', [UtilisateurController::class, 'activer']);
