<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Après un mot de passe provisoire, l'utilisateur doit d'abord choisir le sien. */
class EnsurePasswordChanged
{
    /** Les composants Livewire qui restent utilisables avec un mot de passe provisoire. */
    private const ALLOWED_COMPONENTS = ['profile.edit', 'notifications.bell', 'organization-switcher'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->must_change_password || $request->routeIs('profile.edit', 'logout')) {
            return $next($request);
        }

        // Une requête Livewire : seule la page du profil peut agir ; les onglets déjà ouverts ailleurs n'écrivent rien.
        if ($this->isLivewire($request)) {
            abort_unless($this->onlyAllowedComponents($request), 403, __('Choisissez d’abord votre mot de passe dans votre profil.'));

            return $next($request);
        }

        return redirect()->route('profile.edit');
    }

    /** La route des mises à jour Livewire s'appelle « livewire.update », parfois avec un préfixe (« default-livewire.update »). */
    private function isLivewire(Request $request): bool
    {
        return (bool) $request->route()?->named('*livewire.update', '*livewire.upload-file');
    }

    private function onlyAllowedComponents(Request $request): bool
    {
        if (! $request->route()?->named('*livewire.update')) {
            return true;
        }
        foreach ((array) $request->input('components', []) as $component) {
            $snapshot = is_string($component['snapshot'] ?? null) ? json_decode($component['snapshot'], true) : ($component['snapshot'] ?? null);
            $name = $snapshot['memo']['name'] ?? null;
            if (! in_array($name, self::ALLOWED_COMPONENTS, true)) {
                return false;
            }
        }

        return true;
    }
}
