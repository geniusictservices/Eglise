<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\BaseConsole;
use App\Core\View;

/**
 * Session de la console super admin : cookie propre (« rbp_console », jamais celui des instances), jeton CSRF,
 * super admin connecté (relu à chaque requête : un compte désactivé est déconnecté tout de suite).
 */
final class SessionConsole
{
    public const NOM = 'rbp_console';
    /** Une heure d'inactivité, et la session s'arrête. */
    private const INACTIVITE = 3600;

    public static function demarrer(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name(self::NOM);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    public static function jetonCsrf(): string
    {
        self::demarrer();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function verifierCsrf(): void
    {
        self::demarrer();
        $jeton = $_POST['csrf'] ?? '';
        if (!is_string($jeton) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $jeton)) {
            http_response_code(419);
            View::afficher('console/expire', ['titre' => t('console.expire_titre')], 'console');
            exit;
        }
    }

    /** Super admin connecté (relu dans la base), ou null. */
    public static function admin(): ?array
    {
        self::demarrer();
        $id = (int) ($_SESSION['super_admin'] ?? 0);
        if ($id === 0) {
            return null;
        }
        if (time() - (int) ($_SESSION['vu_le'] ?? 0) > self::INACTIVITE) {
            self::deconnecter();
            return null;
        }
        $admin = BaseConsole::un('SELECT id, nom, email, langue FROM super_admins WHERE id = ? AND actif = 1', [$id]);
        if ($admin === null) {
            self::deconnecter();
            return null;
        }
        $_SESSION['vu_le'] = time();
        return $admin;
    }

    public static function connecter(array $admin): void
    {
        self::demarrer();
        session_regenerate_id(true);
        $_SESSION['super_admin'] = (int) $admin['id'];
        $_SESSION['vu_le'] = time();
    }

    public static function deconnecter(): void
    {
        self::demarrer();
        $_SESSION = [];
        session_regenerate_id(true);
    }

    public static function flash(string $message, string $ton = 'succes'): void
    {
        self::demarrer();
        $_SESSION['flash'] = ['texte' => $message, 'ton' => $ton];
    }

    /** @return array{texte: string, ton: string}|null */
    public static function lireFlash(): ?array
    {
        self::demarrer();
        $message = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $message;
    }
}
