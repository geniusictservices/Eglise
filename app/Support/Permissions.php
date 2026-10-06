<?php

namespace App\Support;

/** Accès au catalogue des permissions défini dans config/waumini.php. */
class Permissions
{
    /** @return array<string, array{label: string, items: array<string, string>}> */
    public static function groups(): array
    {
        return config('waumini.permissions', []);
    }

    /** @return list<string> */
    public static function all(): array
    {
        return collect(self::groups())->flatMap(fn ($group) => array_keys($group['items']))->values()->all();
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    public static function label(string $permission): string
    {
        foreach (self::groups() as $group) {
            if (isset($group['items'][$permission])) {
                return $group['items'][$permission];
            }
        }

        return $permission;
    }

    /**
     * Développe le joker « * » et retire les clés inconnues.
     *
     * @param  list<string>  $permissions
     * @return list<string>
     */
    public static function expand(array $permissions): array
    {
        if (in_array('*', $permissions, true)) {
            return self::all();
        }

        return array_values(array_intersect(self::all(), $permissions));
    }
}
