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
                    fn ($item) => Route::has($item['route']) && Gate::any((array) $item['can'])
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
                'label' => __('Finances'),
                'items' => [
                    ['route' => 'finances.index', 'label' => __('Finances'), 'icon' => 'wallet', 'can' => 'finance.view', 'mobile' => true, 'short' => __('Finances')],
                    ['route' => 'finances.collections', 'label' => __('Collecte du culte'), 'icon' => 'hand-coins', 'can' => 'finance.view'],
                    ['route' => 'finances.pledges', 'label' => __('Promesses'), 'icon' => 'heart-handshake', 'can' => 'finance.pledges'],
                    ['route' => 'finances.expenses', 'label' => __('Dépenses'), 'icon' => 'banknote', 'can' => ['finance.view', 'finance.expenses.request', 'finance.expenses.approve']],
                    ['route' => 'finances.declarations', 'label' => __('Paiements déclarés'), 'icon' => 'smartphone', 'can' => 'finance.payments.validate'],
                    ['route' => 'finances.journal', 'label' => __('Opérations'), 'icon' => 'history', 'can' => 'finance.view'],
                    ['route' => 'finances.reports', 'label' => __('Rapports'), 'icon' => 'file-text', 'can' => 'finance.reports'],
                    ['route' => 'finances.closings', 'label' => __('Clôtures'), 'icon' => 'lock', 'can' => ['finance.close', 'finance.reopen', 'finance.reports']],
                    ['route' => 'finances.settings', 'label' => __('Comptes et catégories'), 'icon' => 'landmark', 'can' => 'finance.settings'],
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
        $current = (string) request()->route()?->getName();

        // L'entrée la plus précise l'emporte : « Dépenses » couvre la fiche d'une dépense,
        // « Finances » (une entrée « liste ») couvre les autres sous-écrans du module.
        $covers = function (string $item) use ($current): int {
            $prefix = str_contains($item, '.') ? substr($item, 0, strrpos($item, '.')) : $item;

            return match (true) {
                $item === $current => PHP_INT_MAX,
                str_starts_with($current, $item.'.') => strlen($item),
                str_ends_with($item, '.index') && str_starts_with($current, $prefix.'.') => strlen($prefix),
                default => 0,
            };
        };

        $best = collect(self::definitions())->flatMap(fn ($s) => $s['items'])->pluck('route')->sortByDesc($covers)->first();

        return $covers($route) > 0 && $best === $route;
    }
}
