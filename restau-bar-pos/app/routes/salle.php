<?php

declare(strict_types=1);

/**
 * Salle et cuisine : notes ouvertes à la caisse (JSON), écran de cuisine (/caisse/cuisine, mêmes règles
 * d'abonnement que la caisse), et écrans de gestion (plan de salle, notes, réservations, services horaires,
 * postes de cuisine).
 * @var App\Core\Router $routeur
 */

use App\Controllers\CaisseNoteController;
use App\Controllers\CuisineController;
use App\Controllers\Gestion\CuisineGestionController;
use App\Controllers\Gestion\NoteController;
use App\Controllers\Gestion\ReservationController;
use App\Controllers\Gestion\SalleController;
use App\Controllers\Gestion\ServiceController;

// Caisse : salle et notes ouvertes
$routeur->get('/caisse/salle', [CaisseNoteController::class, 'etat']);
$routeur->get('/caisse/notes/{id}', [CaisseNoteController::class, 'voir']);
$routeur->post('/caisse/notes/envoyer', [CaisseNoteController::class, 'envoyer']);
$routeur->post('/caisse/notes/{id}/addition', [CaisseNoteController::class, 'addition']);
$routeur->post('/caisse/notes/{id}/lancer', [CaisseNoteController::class, 'lancer']);
$routeur->post('/caisse/notes/{id}/servi', [CaisseNoteController::class, 'servi']);
$routeur->post('/caisse/notes/{id}/transferer', [CaisseNoteController::class, 'transferer']);
$routeur->post('/caisse/notes/{id}/fusionner', [CaisseNoteController::class, 'fusionner']);
$routeur->post('/caisse/notes/{id}/fermer', [CaisseNoteController::class, 'fermer']);
$routeur->post('/caisse/notes/lignes/{id}/annuler', [CaisseNoteController::class, 'annulerLigne']);
$routeur->get('/caisse/bon/{id}', [CaisseNoteController::class, 'bon']);

// Écran de cuisine
$routeur->get('/caisse/cuisine', [CuisineController::class, 'index']);
$routeur->get('/caisse/cuisine/donnees', [CuisineController::class, 'donnees']);
$routeur->post('/caisse/cuisine/statut', [CuisineController::class, 'statut']);
$routeur->get('/caisse/cuisine/ruptures', [CuisineController::class, 'ruptures']);
$routeur->post('/caisse/cuisine/rupture', [CuisineController::class, 'rupture']);
$routeur->get('/caisse/cuisine/bon/{id}', [CuisineController::class, 'bon']);
$routeur->post('/caisse/cuisine/autoriser', [CuisineController::class, 'autoriser']);

// Gestion > Salle
$routeur->get('/gestion/salle', [SalleController::class, 'plan']);
$routeur->post('/gestion/salle/tables', [SalleController::class, 'creer']);
$routeur->post('/gestion/salle/tables/{id}', [SalleController::class, 'modifier']);
$routeur->post('/gestion/salle/tables/{id}/retirer', [SalleController::class, 'retirer']);
$routeur->post('/gestion/salle/placer', [SalleController::class, 'placer']);
$routeur->get('/gestion/salle/notes', [NoteController::class, 'index']);
$routeur->get('/gestion/salle/notes/{id}', [NoteController::class, 'fiche']);
$routeur->get('/gestion/salle/reservations', [ReservationController::class, 'index']);
$routeur->post('/gestion/salle/reservations', [ReservationController::class, 'enregistrer']);
$routeur->post('/gestion/salle/reservations/{id}', [ReservationController::class, 'enregistrer']);
$routeur->post('/gestion/salle/reservations/{id}/statut', [ReservationController::class, 'statut']);
$routeur->get('/gestion/salle/services', [ServiceController::class, 'index']);
$routeur->post('/gestion/salle/services', [ServiceController::class, 'enregistrer']);
$routeur->post('/gestion/salle/services/journee', [ServiceController::class, 'journee']);
$routeur->post('/gestion/salle/services/happy-hours', [ServiceController::class, 'happyHour']);
$routeur->post('/gestion/salle/services/happy-hours/{id}', [ServiceController::class, 'happyHour']);
$routeur->post('/gestion/salle/services/happy-hours/{id}/supprimer', [ServiceController::class, 'supprimerHappyHour']);
$routeur->post('/gestion/salle/services/{id}/supprimer', [ServiceController::class, 'supprimer']);

// Gestion > Cuisine : postes, routage des catégories, écrans autorisés
$routeur->get('/gestion/cuisine', [CuisineGestionController::class, 'index']);
$routeur->post('/gestion/cuisine/postes', [CuisineGestionController::class, 'creerPoste']);
$routeur->post('/gestion/cuisine/postes/{id}', [CuisineGestionController::class, 'modifierPoste']);
$routeur->post('/gestion/cuisine/routage', [CuisineGestionController::class, 'routage']);
$routeur->post('/gestion/cuisine/ecrans/{id}/retirer', [CuisineGestionController::class, 'retirerEcran']);
