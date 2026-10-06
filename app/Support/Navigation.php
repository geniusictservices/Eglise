<?php

namespace App\Support;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/** Menu principal, filtré selon les permissions de l'utilisateur. */
class Navigation
{
    public static function sections(): array
    {
        $sections = [
            [
                'label' => null,
                'items' => [
                    ['route' => 'dashboard', 'label' => __('Tableau de bord'), 'icon' => 'layout-dashboard', 'can' => 'organization.view', 'mobile' => true, 'short' => __('Accueil')],
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
                ],
            ],
        ];

        return collect($sections)
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

    public static function mobile(): array
    {
        return collect(self::sections())->flatMap(fn ($s) => $s['items'])->filter(fn ($i) => $i['mobile'] ?? false)->take(3)->values()->all();
    }

    public static function isActive(string $route): bool
    {
        $prefix = str_contains($route, '.') ? substr($route, 0, strrpos($route, '.')) : $route;

        return request()->routeIs($route) || request()->routeIs($prefix.'.*');
    }
}
