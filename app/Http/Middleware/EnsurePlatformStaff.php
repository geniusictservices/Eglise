<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/** Réserve l'espace Genius ICT à l'équipe de la plateforme. */
class EnsurePlatformStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isPlatformStaff(), 403);
        App::setLocale($request->user()->locale ?: config('app.locale'));

        return $next($request);
    }
}
