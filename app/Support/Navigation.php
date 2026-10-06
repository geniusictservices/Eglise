<?php

namespace App\Support;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/** Menu principal, filtré selon les permissions de l'utilisateur. */
class Navigation
{
    public static function sections(): array
    {
        return collect(self::definitions())
            ->map(function ($section) {
                $section['items'] = array_values(array_filter(
                    $section['items'],
                    fn ($item) => Route::has($item['route']) && Gate::allows($item['can'])
                ));

                return $section;
            })
            ->filter(fn ($section) => $section['items'] !== [])
            ->values()
            ->all();
    }

    private static function definitions(): array
    {
        return [
            [
                'label' => null,
                'items' => [
                    ['route' => 'dashboard', 'label' => __('Tableau de bord'), 'icon' => 'layout-dashboard', 'can' => 'organization.view', 'mobile' => true, 'short' => __('Accueil')],
                ],
            ],
            [
                'label' => __('Communauté'),
                'items' => [
                    ['route' => 'members.index', 'label' => __('Membres'), 'icon' => 'contact-round', 'can' => 'members.view', 'mobile' => true, 'short' => __('Membres')],
                    ['route' => 'households.index', 'label' => __('Ménages'), 'icon' => 'house', 'can' => 'members.view'],
                    ['route' => 'departments.index', 'label' => __('Départements'), 'icon' => 'users-round', 'can' => 'members.view'],
                    ['route' => 'members.settings', 'label' => __('Réglages du registre'), 'icon' => 'sliders-horizontal', 'can' => 'members.settings'],
                ],
            ],
            [
                'label' => __('Administration'),
                'items' => [
                    ['route' => 'hierarchy.index', 'label' => __('Hiérarchie'), 'icon' => 'network', 'can' => 'organization.view'],
                    ['route' => 'users.index', 'label' => __('Utilisateurs'), 'icon' => 'users', 'can' => 'users.view', 'mobile' => true, 'short' => __('Utilisateurs')],
                    ['route' => 'roles.index', 'label' => __('Rôles et permissions'), 'icon' => 'shield-check', 'can' => 'roles.manage'],
                    ['route' => 'currencies.index', 'label' => __('Devises et taux'), 'icon' => 'arrow-left-right', 'can' => 'currencies.manage', 'mobile' => true, 'short' => __('Taux')],
                    ['route' => 'audit.index', 'label' => __('Journal d’audit'), 'icon' => 'history', 'can' => 'audit.view'],
                    ['route' => 'settings.edit', 'label' => __('Paramètres'), 'icon' => 'settings', 'can' => 'organization.settings'],
                    ['route' => 'subscription', 'label' => __('Abonnement'), 'icon' => 'badge-check', 'can' => 'organization.settings'],
                ],
            ],
        ];
    }

    public static function mobile(): array
    {
        return collect(self::sections())->flatMap(fn ($s) => $s['items'])->filter(fn ($i) => $i['mobile'] ?? false)->take(3)->values()->all();
    }

    public static function isActive(string $route): bool
    {
        $prefix = str_contains($route, '.') ? substr($route, 0, strrpos($route, '.')) : $route;
        $current = request()->route()?->getName();

        // Un écran qui a sa propre entrée de menu n'allume pas sa voisine (Réglages du registre ≠ Membres).
        if ($current !== $route && collect(self::definitions())->flatMap(fn ($s) => $s['items'])->contains('route', $current)) {
            return false;
        }

        // Seule l'entrée « liste » d'un module couvre ses sous-écrans (fiche, modification…).
        return request()->routeIs($route) || (str_ends_with($route, '.index') && request()->routeIs($prefix.'.*'));
    }
}
