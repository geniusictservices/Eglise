<?php

declare(strict_types=1);

/**
 * Trésorerie (comptes, transferts) et dépenses.
 * @var App\Core\Router $routeur
 */

use App\Controllers\Gestion\CategorieDepenseController;
use App\Controllers\Gestion\DepenseController;
use App\Controllers\Gestion\TransfertController;
use App\Controllers\Gestion\TresorerieController;

// Comptes
$routeur->get('/gestion/tresorerie', [TresorerieController::class, 'index']);
$routeur->get('/gestion/tresorerie/comptes/nouveau', [TresorerieController::class, 'nouveau']);
$routeur->post('/gestion/tresorerie/comptes/nouveau', [TresorerieController::class, 'creer']);
$routeur->get('/gestion/tresorerie/comptes/{id}', [TresorerieController::class, 'fiche']);
$routeur->get('/gestion/tresorerie/comptes/{id}/modifier', [TresorerieController::class, 'editer']);
$routeur->post('/gestion/tresorerie/comptes/{id}/modifier', [TresorerieController::class, 'modifier']);
$routeur->post('/gestion/tresorerie/comptes/{id}/desactiver', [TresorerieController::class, 'desactiver']);
$routeur->post('/gestion/tresorerie/comptes/{id}/activer', [TresorerieController::class, 'activer']);
$routeur->post('/gestion/tresorerie/comptes/{id}/supprimer', [TresorerieController::class, 'supprimer']);

// Transferts entre comptes
$routeur->get('/gestion/tresorerie/transferts', [TransfertController::class, 'index']);
$routeur->post('/gestion/tresorerie/transferts', [TransfertController::class, 'creer']);
$routeur->post('/gestion/tresorerie/transferts/{id}/supprimer', [TransfertController::class, 'supprimer']);

// Dépenses : chemins fixes avant {id}, que le motif accepterait aussi
$routeur->get('/gestion/depenses', [DepenseController::class, 'index']);
$routeur->get('/gestion/depenses/categories', [CategorieDepenseController::class, 'index']);
$routeur->post('/gestion/depenses/categories', [CategorieDepenseController::class, 'creer']);
$routeur->post('/gestion/depenses/categories/{id}', [CategorieDepenseController::class, 'modifier']);
$routeur->post('/gestion/depenses/categories/{id}/activer', [CategorieDepenseController::class, 'activer']);
$routeur->post('/gestion/depenses/categories/{id}/desactiver', [CategorieDepenseController::class, 'desactiver']);
$routeur->post('/gestion/depenses/categories/{id}/supprimer', [CategorieDepenseController::class, 'supprimer']);
$routeur->get('/gestion/depenses/nouvelle', [DepenseController::class, 'nouvelle']);
$routeur->post('/gestion/depenses/nouvelle', [DepenseController::class, 'creer']);
$routeur->get('/gestion/depenses/{id}', [DepenseController::class, 'fiche']);
$routeur->post('/gestion/depenses/{id}', [DepenseController::class, 'modifier']);
$routeur->post('/gestion/depenses/{id}/supprimer', [DepenseController::class, 'supprimer']);
