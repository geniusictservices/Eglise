<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Réglages de l'instance (table parametres) : messagerie, relances…
 * Les clés d'API y sont gardées telles quelles ; les écrans ne les réaffichent jamais (seulement « •••• 1234 »).
 */
final class Parametres
{
    /** @var array<string, ?string>|null */
    private static ?array $valeurs = null;

    public static function get(string $cle, ?string $defaut = null): ?string
    {
        self::$valeurs ??= array_column(Database::tous('SELECT cle, valeur FROM parametres'), 'valeur', 'cle');
        $valeur = self::$valeurs[$cle] ?? null;
        return $valeur === null || $valeur === '' ? $defaut : $valeur;
    }

    public static function definir(string $cle, ?string $valeur, ?int $par = null): void
    {
        Database::executer(
            'INSERT INTO parametres (cle, valeur, modifie_par) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE valeur = VALUES(valeur), modifie_par = VALUES(modifie_par)',
            [$cle, $valeur, $par]
        );
        self::$valeurs = null;
    }

    /** « •••• 1234 » pour une clé secrète enregistrée, chaîne vide sinon. */
    public static function masque(string $cle): string
    {
        $valeur = self::get($cle);
        return $valeur === null ? '' : '•••• ' . mb_substr($valeur, -4);
    }

    public static function oublier(): void
    {
        self::$valeurs = null;
    }
}
