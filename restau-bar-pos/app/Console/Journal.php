<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\BaseConsole;

/** Journal de la console : qui (super admin) a fait quoi, pour quel client. */
final class Journal
{
    public static function ecrire(string $action, ?int $clientId = null, array $details = [], ?int $adminId = null): void
    {
        if ($adminId === null && session_status() === PHP_SESSION_ACTIVE) {
            $adminId = isset($_SESSION['super_admin']) ? (int) $_SESSION['super_admin'] : null;
        }
        BaseConsole::executer(
            'INSERT INTO journal (super_admin_id, client_id, action, details, ip) VALUES (?, ?, ?, ?, ?)',
            [
                $adminId, $clientId, $action,
                $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
                substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
            ]
        );
    }
}
