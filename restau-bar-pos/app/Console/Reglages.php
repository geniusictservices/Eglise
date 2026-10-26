<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\BaseConsole;

/**
 * Réglages de la plateforme (table parametres de la console) : API de messagerie, contact, relances, installation.
 * Les secrets (sms.cle, whatsapp.jeton) ne sont jamais réaffichés : seulement « •••• 1234 ».
 */
final class Reglages
{
    public const SECRETS = ['sms.cle', 'whatsapp.jeton'];

    /** @var array<string, ?string>|null */
    private static ?array $valeurs = null;

    public static function get(string $cle, ?string $defaut = null): ?string
    {
        self::$valeurs ??= array_column(BaseConsole::tous('SELECT cle, valeur FROM parametres'), 'valeur', 'cle');
        $valeur = self::$valeurs[$cle] ?? null;
        return $valeur === null || $valeur === '' ? $defaut : $valeur;
    }

    public static function definir(string $cle, ?string $valeur, ?int $par = null): void
    {
        BaseConsole::executer(
            'INSERT INTO parametres (cle, valeur, modifie_par) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE valeur = VALUES(valeur), modifie_par = VALUES(modifie_par)',
            [$cle, $valeur, $par]
        );
        self::$valeurs = null;
    }

    public static function masque(string $cle): string
    {
        $valeur = self::get($cle);
        return $valeur === null ? '' : '•••• ' . mb_substr($valeur, -4);
    }

    /** Réglages sms.* et whatsapp.* sous la forme attendue par App\Core\Messagerie::transmettre(). */
    public static function messagerie(): array
    {
        $resultat = [];
        foreach (\App\Core\Plateforme::CLES_MESSAGERIE as $cle) {
            if (($valeur = self::get($cle)) !== null) {
                $resultat[$cle] = $valeur;
            }
        }
        return $resultat;
    }

    public static function oublier(): void
    {
        self::$valeurs = null;
    }
}
