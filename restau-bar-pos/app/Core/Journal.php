<?php

declare(strict_types=1);

namespace App\Core;

/** Journal des actions sensibles : qui a fait quoi, quand, sur quel appareil (cahier des charges, section 6.2). */
final class Journal
{
    public static function ecrire(string $action, ?string $cible = null, array $details = [], ?int $utilisateurId = null, ?int $appareilId = null): void
    {
        // En ligne de commande (scripts, relances), pas de session : l'appelant donne l'utilisateur
        if ($utilisateurId === null && PHP_SAPI !== 'cli') {
            $utilisateurId = isset(Session::utilisateur()['id']) ? (int) Session::utilisateur()['id'] : null;
        }
        Database::executer(
            'INSERT INTO journal (utilisateur_id, action, cible, details, appareil_id, ip) VALUES (?, ?, ?, ?, ?, ?)',
            [
                $utilisateurId, $action, $cible,
                $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
                $appareilId, substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
            ]
        );
    }
}
