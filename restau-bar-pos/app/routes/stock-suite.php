<?php

declare(strict_types=1);

/**
 * Stock, suite : préparations et productions, péremptions, achats au marché (caisse de la cuisine), vente en gros
 * au dépôt, rapport des coûts et de la rentabilité. Chargé avant stock.php (ordre alphabétique) : la route fixe
 * /gestion/stock/recettes/preparation passe avant /gestion/stock/recettes/{id}.
 * @var App\Core\Router $routeur
 */

use App\Controllers\Gestion\MarcheController;
use App\Controllers\Gestion\PeremptionController;
use App\Controllers\Gestion\ProductionController;
use App\Controllers\Gestion\RapportCoutsController;
use App\Controllers\Gestion\RecetteController;
use App\Controllers\Gestion\VenteGrosController;

$routeur->post('/gestion/stock/recettes/preparation', [RecetteController::class, 'creerPreparation']);

$routeur->get('/gestion/stock/productions', [ProductionController::class, 'index']);
$routeur->post('/gestion/stock/productions', [ProductionController::class, 'creer']);

$routeur->get('/gestion/stock/peremptions', [PeremptionController::class, 'index']);
$routeur->post('/gestion/stock/peremptions/{id}/perte', [PeremptionController::class, 'perte']);

$routeur->get('/gestion/achats/marche', [MarcheController::class, 'index']);
$routeur->post('/gestion/achats/marche', [MarcheController::class, 'creer']);

$routeur->get('/gestion/gros', [VenteGrosController::class, 'index']);
$routeur->get('/gestion/gros/nouvelle', [VenteGrosController::class, 'nouvelle']);
$routeur->post('/gestion/gros/nouvelle', [VenteGrosController::class, 'creer']);
$routeur->get('/gestion/gros/prix', [VenteGrosController::class, 'prix']);
$routeur->post('/gestion/gros/prix', [VenteGrosController::class, 'enregistrerPrix']);

$routeur->get('/gestion/rapports/couts', [RapportCoutsController::class, 'index']);
