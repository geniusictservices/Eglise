<?php

declare(strict_types=1);

namespace App\Core;

/** Session de l'espace de gestion, avec jeton CSRF pour les formulaires. */
final class Session
{
    public static function demarrer(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('rbp_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
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
            exit('Formulaire expiré. Rechargez la page et recommencez.');
        }
    }

    public static function utilisateur(): ?array
    {
        self::demarrer();
        return $_SESSION['utilisateur'] ?? null;
    }

    public static function connecter(array $utilisateur): void
    {
        self::demarrer();
        session_regenerate_id(true);
        $_SESSION['utilisateur'] = [
            'id' => (int) $utilisateur['id'],
            'nom' => $utilisateur['nom'],
            'email' => $utilisateur['email'],
        ];
    }

    public static function deconnecter(): void
    {
        self::demarrer();
        $_SESSION = [];
        session_regenerate_id(true);
    }

    /** Redirige vers la page de connexion si personne n'est connecté. */
    public static function exigerConnexion(): array
    {
        $utilisateur = self::utilisateur();
        if ($utilisateur === null) {
            rediriger('/gestion/connexion');
        }
        return $utilisateur;
    }

    public static function flash(string $message): void
    {
        self::demarrer();
        $_SESSION['flash'] = $message;
    }

    public static function lireFlash(): ?string
    {
        self::demarrer();
        $message = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $message;
    }
}
