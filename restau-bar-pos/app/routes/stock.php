<?php

declare(strict_types=1);

/**
 * Stock : état du stock, mouvements, transferts, inventaires, pertes, fiches techniques, emballages,
 * dépôts et comptoirs. Chemins fixes avant {id}, que le motif accepterait aussi.
 * @var App\Core\Router $routeur
 */

use App\Controllers\Gestion\EmballageController;
use App\Controllers\Gestion\InventaireController;
use App\Controllers\Gestion\LieuStockController;
use App\Controllers\Gestion\PerteController;
use App\Controllers\Gestion\RecetteController;
use App\Controllers\Gestion\StockController;
use App\Controllers\Gestion\TransfertStockController;

$routeur->get('/gestion/stock', [StockController::class, 'index']);
$routeur->post('/gestion/stock/seuils', [StockController::class, 'seuils']);
$routeur->get('/gestion/stock/ajuster', [StockController::class, 'formulaireAjustement']);
$routeur->post('/gestion/stock/ajuster', [StockController::class, 'ajuster']);
$routeur->get('/gestion/stock/suggestions', [StockController::class, 'suggestions']);
$routeur->post('/gestion/stock/suggestions/commande', [StockController::class, 'commanderSuggestion']);
$routeur->get('/gestion/stock/mouvements', [StockController::class, 'mouvements']);

$routeur->get('/gestion/stock/lieux', [LieuStockController::class, 'index']);
$routeur->post('/gestion/stock/lieux', [LieuStockController::class, 'creer']);
$routeur->post('/gestion/stock/lieux/routage/{id}', [LieuStockController::class, 'routage']);
$routeur->post('/gestion/stock/lieux/reglages/{id}', [LieuStockController::class, 'reglages']);
$routeur->post('/gestion/stock/lieux/{id}', [LieuStockController::class, 'modifier']);
$routeur->post('/gestion/stock/lieux/{id}/defaut', [LieuStockController::class, 'parDefaut']);
$routeur->post('/gestion/stock/lieux/{id}/supprimer', [LieuStockController::class, 'supprimer']);

$routeur->get('/gestion/stock/transferts', [TransfertStockController::class, 'index']);
$routeur->get('/gestion/stock/transferts/nouveau', [TransfertStockController::class, 'nouveau']);
$routeur->post('/gestion/stock/transferts/nouveau', [TransfertStockController::class, 'creer']);
$routeur->get('/gestion/stock/transferts/{id}', [TransfertStockController::class, 'fiche']);
$routeur->post('/gestion/stock/transferts/{id}/expedier', [TransfertStockController::class, 'expedier']);
$routeur->post('/gestion/stock/transferts/{id}/recevoir', [TransfertStockController::class, 'recevoir']);
$routeur->post('/gestion/stock/transferts/{id}/annuler', [TransfertStockController::class, 'annuler']);

$routeur->get('/gestion/stock/inventaires', [InventaireController::class, 'index']);
$routeur->post('/gestion/stock/inventaires', [InventaireController::class, 'creer']);
$routeur->get('/gestion/stock/inventaires/{id}', [InventaireController::class, 'fiche']);
$routeur->post('/gestion/stock/inventaires/{id}/compter', [InventaireController::class, 'compter']);
$routeur->post('/gestion/stock/inventaires/{id}/ajouter', [InventaireController::class, 'ajouter']);
$routeur->post('/gestion/stock/inventaires/{id}/actualiser', [InventaireController::class, 'actualiser']);
$routeur->post('/gestion/stock/inventaires/{id}/valider', [InventaireController::class, 'valider']);
$routeur->post('/gestion/stock/inventaires/{id}/rouvrir', [InventaireController::class, 'rouvrir']);
$routeur->post('/gestion/stock/inventaires/{id}/annuler', [InventaireController::class, 'annuler']);

$routeur->get('/gestion/stock/pertes', [PerteController::class, 'index']);
$routeur->post('/gestion/stock/pertes', [PerteController::class, 'creer']);

$routeur->get('/gestion/stock/recettes', [RecetteController::class, 'index']);
$routeur->get('/gestion/stock/recettes/{id}', [RecetteController::class, 'fiche']);
$routeur->post('/gestion/stock/recettes/{id}', [RecetteController::class, 'enregistrer']);
$routeur->post('/gestion/stock/recettes/{id}/supprimer', [RecetteController::class, 'supprimer']);

$routeur->get('/gestion/stock/emballages', [EmballageController::class, 'index']);
$routeur->post('/gestion/stock/emballages', [EmballageController::class, 'creer']);
$routeur->post('/gestion/stock/emballages/liens', [EmballageController::class, 'lier']);
$routeur->post('/gestion/stock/emballages/echange', [EmballageController::class, 'echanger']);
$routeur->post('/gestion/stock/emballages/{id}/consigne', [EmballageController::class, 'consigne']);
