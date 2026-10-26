<?php

declare(strict_types=1);

/**
 * Rapports : rapport du personnel (si le module Personnel et paie ne l'a pas déjà déclaré : la première route
 * qui correspond l'emporte) et export comptable. Le tableau de bord est déclaré dans public/index.php.
 * @var App\Core\Router $routeur
 */

use App\Controllers\Gestion\ExportComptableController;
use App\Controllers\Gestion\RapportServeursController;

$routeur->get('/gestion/rapports/personnel', [RapportServeursController::class, 'index']);
$routeur->get('/gestion/rapports/export-comptable', [ExportComptableController::class, 'index']);
