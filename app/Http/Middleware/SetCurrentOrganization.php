<?php

namespace App\Http\Middleware;

use App\Support\CurrentOrganization;
use App\Support\SupportAccess;
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

        // Un agent Genius ICT entré avec l'accès du support : la communauté qui l'a autorisé, en lecture seule.
        if ($user->is_platform_staff) {
            $support = app(SupportAccess::class);
            if ($support->revoked($user)) {
                $support->stop();

                return redirect()->route('admin.dashboard')->with('status', __('La communauté a retiré l’accès du support, ou il a expiré.'));
            }
            if (($granted = $support->organization($user)) && ! $request->routeIs('admin.*')) {
                abort_unless($request->isMethodSafe() || $request->routeIs('livewire.*', 'logout', 'support.leave'), 403, __('Lecture seule : le support ne modifie rien.'));
                $organization = $user->currentOrganization && $support->covers($user, $user->currentOrganization) ? $user->currentOrganization : $granted;
                $this->current->set($organization);
                view()->share('currentOrganization', $organization);

                return $next($request);
            }
        }

        $organization = $user->currentOrganization;

        if (! $organization || ! $user->canAccess($organization)) {
            $organization = $user->directOrganizations()->first();
            $user->forceFill(['current_organization_id' => $organization?->id])->saveQuietly();
        }

        // L'équipe Genius ICT sans communauté travaille dans son espace d'administration.
        if (! $organization && $user->isPlatformStaff()) {
            return $request->routeIs('admin.*', 'livewire.*', 'profile.edit', 'logout', 'notifications.*')
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
