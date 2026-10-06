<?php

namespace App\Http\Middleware;

use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Détermine l'organisation dans laquelle l'utilisateur travaille et sa langue.
 * Sans organisation accessible, il est envoyé vers le choix de communauté.
 */
class SetCurrentOrganization
{
    public function __construct(private CurrentOrganization $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        App::setLocale($user->locale ?: config('app.locale'));

        $organization = $user->currentOrganization;

        if (! $organization || ! $user->canAccess($organization)) {
            $organization = $user->directOrganizations()->first();
            $user->forceFill(['current_organization_id' => $organization?->id])->saveQuietly();
        }

        // L'équipe Genius ICT sans communauté travaille dans son espace d'administration.
        if (! $organization && $user->isPlatformStaff()) {
            return $request->routeIs('admin.*', 'livewire.*', 'profile.edit', 'logout')
                ? $next($request)
                : redirect()->route('admin.dashboard');
        }

        if (! $organization) {
            return $request->routeIs('organizations.none', 'logout')
                ? $next($request)
                : redirect()->route('organizations.none');
        }

        $this->current->set($organization);
        view()->share('currentOrganization', $organization);

        return $next($request);
    }
}
