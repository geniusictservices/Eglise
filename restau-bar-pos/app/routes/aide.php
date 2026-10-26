<?php

declare(strict_types=1);

use App\Controllers\Gestion\AideController;

/** @var App\Core\Router $routeur */
$routeur->get('/gestion/aide', [AideController::class, 'index']);
$routeur->get('/gestion/aide/manuel.pdf', [AideController::class, 'pdf']);
$routeur->get('/gestion/aide/captures/{nom}.jpg', [AideController::class, 'capture']);
