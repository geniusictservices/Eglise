<?php

declare(strict_types=1);

/**
 * Gestion des établissements (fiche, apparence, prix, horaires, zones) et des grilles de prix.
 * @var App\Core\Router $routeur
 */

use App\Controllers\Gestion\EtablissementController;
use App\Controllers\Gestion\GrilleController;

$routeur->get('/gestion/etablissements', [EtablissementController::class, 'index']);

// « nouveau » avant {id}, que le motif accepterait aussi
$routeur->get('/gestion/etablissements/nouveau', [EtablissementController::class, 'nouveau']);
$routeur->post('/gestion/etablissements/nouveau', [EtablissementController::class, 'creer']);
$routeur->get('/gestion/etablissements/{id}', [EtablissementController::class, 'fiche']);
$routeur->post('/gestion/etablissements/{id}', [EtablissementController::class, 'enregistrerInfos']);
$routeur->post('/gestion/etablissements/{id}/apparence', [EtablissementController::class, 'enregistrerApparence']);
$routeur->post('/gestion/etablissements/{id}/logo/supprimer', [EtablissementController::class, 'supprimerLogo']);
$routeur->post('/gestion/etablissements/{id}/couverture/supprimer', [EtablissementController::class, 'supprimerCouverture']);
$routeur->post('/gestion/etablissements/{id}/prix', [EtablissementController::class, 'enregistrerPrix']);
$routeur->post('/gestion/etablissements/{id}/horaires', [EtablissementController::class, 'enregistrerHoraires']);
$routeur->post('/gestion/etablissements/{id}/zones', [EtablissementController::class, 'ajouterZone']);
$routeur->post('/gestion/etablissements/{id}/zones/{zone}', [EtablissementController::class, 'modifierZone']);
$routeur->post('/gestion/etablissements/{id}/zones/{zone}/supprimer', [EtablissementController::class, 'supprimerZone']);
$routeur->post('/gestion/etablissements/{id}/desactiver', [EtablissementController::class, 'desactiver']);
$routeur->post('/gestion/etablissements/{id}/reactiver', [EtablissementController::class, 'reactiver']);

// Grilles de prix
$routeur->get('/gestion/grilles', [GrilleController::class, 'index']);
$routeur->post('/gestion/grilles', [GrilleController::class, 'creer']);
$routeur->post('/gestion/grilles/{id}', [GrilleController::class, 'modifier']);
$routeur->post('/gestion/grilles/{id}/supprimer', [GrilleController::class, 'supprimer']);
