<?php

declare(strict_types=1);

/**
 * Mode hors ligne de la caisse : service worker (portée /caisse), état du réseau et heure du serveur, clé hors ligne
 * de la personne sur l'appareil, évènements de la caisse partagée vécus sans réseau, page de secours.
 * Les ventes faites sans réseau repartent par /caisse/encaisser (bloc « hors_ligne »).
 * @var App\Core\Router $routeur
 */

use App\Controllers\CaisseHorsLigneController;

$routeur->get('/caisse/sw.js', [CaisseHorsLigneController::class, 'serviceWorker']);
$routeur->get('/caisse/etat', [CaisseHorsLigneController::class, 'etat']);
$routeur->get('/caisse/hors-ligne/page', [CaisseHorsLigneController::class, 'page']);
$routeur->post('/caisse/hors-ligne/cle', [CaisseHorsLigneController::class, 'cle']);
$routeur->post('/caisse/hors-ligne/evenements', [CaisseHorsLigneController::class, 'evenements']);
