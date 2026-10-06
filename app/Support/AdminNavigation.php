<?php

namespace App\Support;

use Illuminate\Support\Facades\Gate;

/** Menu de l'espace Genius ICT. */
class AdminNavigation
{
    public static function items(): array
    {
        return array_values(array_filter([
            ['route' => 'admin.dashboard', 'label' => __('Vue d’ensemble'), 'icon' => 'layout-dashboard', 'can' => 'admin.access'],
            ['route' => 'admin.communities', 'label' => __('Communautés'), 'icon' => 'building-2', 'can' => 'admin.communities'],
            ['route' => 'admin.pricing', 'label' => __('Offres et tarifs'), 'icon' => 'tag', 'can' => 'admin.pricing'],
            ['route' => 'admin.legal', 'label' => __('Textes juridiques'), 'icon' => 'scroll-text', 'can' => 'admin.legal'],
            ['route' => 'admin.settings', 'label' => __('Coordonnées et réglages'), 'icon' => 'settings', 'can' => 'admin.settings'],
            ['route' => 'admin.staff', 'label' => __('Équipe Genius ICT'), 'icon' => 'users', 'can' => 'admin.staff'],
        ], fn ($item) => Gate::allows($item['can'])));
    }

    public static function isActive(string $route): bool
    {
        return request()->routeIs($route) || request()->routeIs($route.'.*');
    }
}
