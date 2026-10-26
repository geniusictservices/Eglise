<?php

declare(strict_types=1);

use App\Controllers\Gestion\DesignController;

/** @var App\Core\Router $routeur */
$routeur->get('/gestion/design', [DesignController::class, 'index']);
