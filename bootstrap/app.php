<?php

use App\Http\Middleware\SetCurrentOrganization;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // L'organisation courante doit être fixée AVANT que Laravel charge le modèle désigné dans
        // l'adresse (/projets/12, /finances/recu/40…) : sinon le filtre par église est encore vide
        // et une autre église pourrait l'ouvrir en changeant le numéro.
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            SetCurrentOrganization::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
