<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static array $valeurs = [];

    public static function charger(array $valeurs): void
    {
        self::$valeurs = $valeurs;
    }

    public static function definir(string $cle, mixed $valeur): void
    {
        self::$valeurs[$cle] = $valeur;
    }

    /** Lit une valeur, avec la notation pointée : get('db.hote') */
    public static function get(string $cle, mixed $defaut = null): mixed
    {
        $valeur = self::$valeurs;
        foreach (explode('.', $cle) as $partie) {
            if (!is_array($valeur) || !array_key_exists($partie, $valeur)) {
                return $defaut;
            }
            $valeur = $valeur[$partie];
        }
        return $valeur;
    }
}
