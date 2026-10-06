<?php

namespace App\Support;

/** Icônes Lucide embarquées (resources/icons/icons.php). */
class Icons
{
    private static ?array $icons = null;

    public static function paths(string $name): string
    {
        self::$icons ??= require resource_path('icons/icons.php');

        return self::$icons[$name] ?? '';
    }
}
