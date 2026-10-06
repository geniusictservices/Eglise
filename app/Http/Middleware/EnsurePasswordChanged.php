<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Après un mot de passe provisoire, l'utilisateur doit d'abord choisir le sien. */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password && ! $request->routeIs('profile.edit', 'logout', 'livewire.*')) {
            return redirect()->route('profile.edit');
        }

        return $next($request);
    }
}
