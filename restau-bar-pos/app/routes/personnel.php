<?php

declare(strict_types=1);

/**
 * Personnel et paie : employés, présence (pointage par code QR et GPS, corrections, bornes, réglages),
 * plannings et jours fériés, congés, paie, avances, rapport du personnel ; pages publiques du pointage
 * (borne et téléphone) ; pointage de secours à la caisse.
 * @var App\Core\Router $routeur
 */

use App\Controllers\CaissePointageController;
use App\Controllers\Gestion\AvanceController;
use App\Controllers\Gestion\CongeController;
use App\Controllers\Gestion\EmployeController;
use App\Controllers\Gestion\PaieController;
use App\Controllers\Gestion\PlanningController;
use App\Controllers\Gestion\PresenceController;
use App\Controllers\Gestion\RapportPersonnelController;
use App\Controllers\Gestion\RapportPresenceController;
use App\Controllers\PointageController;

// Pages publiques : borne de pointage (tablette autorisée) et téléphone de l'employé
$routeur->get('/pointage/borne', [PointageController::class, 'borne']);
$routeur->get('/pointage/borne/jeton', [PointageController::class, 'jeton']);
$routeur->get('/pointer', [PointageController::class, 'page']);
$routeur->post('/pointer', [PointageController::class, 'pointer']);
$routeur->post('/pointer/identifier', [PointageController::class, 'identifier']);
$routeur->post('/pointer/oublier', [PointageController::class, 'oublier']);

// Caisse : pointage de secours (PIN de l'employé validé par le PIN d'un gérant)
$routeur->post('/caisse/pointage', [CaissePointageController::class, 'pointer']);

// Employés
$routeur->get('/gestion/personnel/employes', [EmployeController::class, 'index']);
$routeur->get('/gestion/personnel/employes/nouveau', [EmployeController::class, 'nouveau']);
$routeur->post('/gestion/personnel/employes/nouveau', [EmployeController::class, 'creer']);
$routeur->get('/gestion/personnel/employes/{id}', [EmployeController::class, 'fiche']);
$routeur->get('/gestion/personnel/employes/{id}/modifier', [EmployeController::class, 'modifier']);
$routeur->post('/gestion/personnel/employes/{id}/modifier', [EmployeController::class, 'enregistrerModification']);
$routeur->post('/gestion/personnel/employes/{id}/activer', [EmployeController::class, 'activer']);
$routeur->post('/gestion/personnel/employes/{id}/pin', [EmployeController::class, 'pin']);
$routeur->post('/gestion/personnel/employes/{id}/debloquer', [EmployeController::class, 'debloquer']);
$routeur->post('/gestion/personnel/employes/{id}/telephones/{telephone}/retirer', [EmployeController::class, 'retirerTelephone']);
$routeur->post('/gestion/personnel/employes/{id}/documents', [EmployeController::class, 'ajouterDocument']);
$routeur->get('/gestion/personnel/employes/{id}/documents/{document}', [EmployeController::class, 'document']);
$routeur->post('/gestion/personnel/employes/{id}/documents/{document}/supprimer', [EmployeController::class, 'supprimerDocument']);

// Présence
$routeur->get('/gestion/personnel/presence', [PresenceController::class, 'index']);
$routeur->get('/gestion/personnel/presence/pointages', [PresenceController::class, 'liste']);
$routeur->get('/gestion/personnel/presence/pointages/nouveau', [PresenceController::class, 'nouveau']);
$routeur->post('/gestion/personnel/presence/pointages/nouveau', [PresenceController::class, 'creer']);
$routeur->get('/gestion/personnel/presence/pointages/{id}', [PresenceController::class, 'fiche']);
$routeur->post('/gestion/personnel/presence/pointages/{id}', [PresenceController::class, 'modifier']);
$routeur->post('/gestion/personnel/presence/pointages/{id}/supprimer', [PresenceController::class, 'supprimer']);
$routeur->get('/gestion/personnel/presence/bornes', [PresenceController::class, 'bornes']);
$routeur->post('/gestion/personnel/presence/bornes', [PresenceController::class, 'autoriserBorne']);
$routeur->post('/gestion/personnel/presence/bornes/{id}/retirer', [PresenceController::class, 'retirerBorne']);
$routeur->get('/gestion/personnel/presence/reglages', [PresenceController::class, 'reglages']);
$routeur->post('/gestion/personnel/presence/reglages', [PresenceController::class, 'enregistrerReglages']);

// Plannings et jours fériés
$routeur->get('/gestion/personnel/plannings', [PlanningController::class, 'index']);
$routeur->post('/gestion/personnel/plannings', [PlanningController::class, 'ajouter']);
$routeur->post('/gestion/personnel/plannings/copier', [PlanningController::class, 'copier']);
$routeur->get('/gestion/personnel/plannings/feries', [PlanningController::class, 'feries']);
$routeur->post('/gestion/personnel/plannings/feries', [PlanningController::class, 'ajouterFerie']);
$routeur->post('/gestion/personnel/plannings/feries/{id}', [PlanningController::class, 'modifierFerie']);
$routeur->post('/gestion/personnel/plannings/{id}/supprimer', [PlanningController::class, 'supprimer']);

// Congés et absences justifiées
$routeur->get('/gestion/personnel/conges', [CongeController::class, 'index']);
$routeur->post('/gestion/personnel/conges', [CongeController::class, 'demander']);
$routeur->post('/gestion/personnel/conges/{id}/decider', [CongeController::class, 'decider']);

// Paie (les adresses fixes avant /paie/{id})
$routeur->get('/gestion/personnel/paie', [PaieController::class, 'index']);
$routeur->post('/gestion/personnel/paie/preparer', [PaieController::class, 'preparer']);
$routeur->post('/gestion/personnel/paie/pourboires', [PaieController::class, 'pourboires']);
$routeur->get('/gestion/personnel/paie/masse', [PaieController::class, 'masse']);
$routeur->post('/gestion/personnel/paie/paiements/{id}/annuler', [PaieController::class, 'annulerPaiement']);
$routeur->get('/gestion/personnel/paie/{id}', [PaieController::class, 'fiche']);
$routeur->post('/gestion/personnel/paie/{id}', [PaieController::class, 'modifier']);
$routeur->post('/gestion/personnel/paie/{id}/annuler', [PaieController::class, 'annuler']);
$routeur->post('/gestion/personnel/paie/{id}/payer', [PaieController::class, 'payer']);
$routeur->get('/gestion/personnel/paie/{id}/imprimer', [PaieController::class, 'imprimer']);

// Avances
$routeur->get('/gestion/personnel/avances', [AvanceController::class, 'index']);
$routeur->post('/gestion/personnel/avances', [AvanceController::class, 'creer']);
$routeur->post('/gestion/personnel/avances/{id}/annuler', [AvanceController::class, 'annuler']);

// Rapports > Personnel : classement des vendeurs et dettes (RapportServeursController, via RapportPersonnelController),
// avec la présence de la période (partiel views/gestion/rapports/_personnel-presence.php) ;
// Rapports > Présence : détail de la présence, export CSV et masse salariale
$routeur->get('/gestion/rapports/personnel', [RapportPersonnelController::class, 'index']);
$routeur->get('/gestion/rapports/presence', [RapportPresenceController::class, 'index']);
