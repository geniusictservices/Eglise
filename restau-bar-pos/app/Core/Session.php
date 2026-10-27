<?php

declare(strict_types=1);

namespace App\Core;

/** Session de l'espace de gestion, avec jeton CSRF pour les formulaires. */
final class Session
{
    /** Secondes pendant lesquelles un ancien identifiant renvoie à la session qui l'a remplacé (voir regenerer()) */
    private const RENVOI_SECONDES = 30;

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
        // Identifiant remplacé il y a quelques secondes par une requête voisine (PIN envoyé deux fois, requête partie
        // avec l'ancien cookie) : on suit le renvoi vers la nouvelle session, et le navigateur reçoit le nouveau cookie
        for ($sauts = 0; is_array($renvoi = $_SESSION['_renvoi'] ?? null); $sauts++) {
            if ($sauts >= 3 || time() - (int) ($renvoi['le'] ?? 0) > self::RENVOI_SECONDES || !is_string($renvoi['id'] ?? null) || $renvoi['id'] === '') {
                $_SESSION = [];
                break;
            }
            session_write_close();
            session_id($renvoi['id']);
            session_start();
            setcookie(session_name(), $renvoi['id'], self::parametresCookie());
        }
    }

    /**
     * Nouvel identifiant de session (connexion, PIN, verrouillage). L'ancienne session ne garde rien d'autre qu'un renvoi
     * vers la nouvelle pendant quelques secondes : avec plusieurs processus PHP, une requête voisine partie avec l'ancien
     * cookie (PIN touché deux fois, demande de la clé hors ligne) ne tombe pas sur une session vide, et le navigateur
     * qui n'a pas reçu la réponse de la première requête n'est pas déconnecté.
     */
    public static function regenerer(): void
    {
        self::demarrer();
        $donnees = $_SESSION;
        $nouveau = session_create_id();
        $_SESSION = ['_renvoi' => ['id' => $nouveau, 'le' => time()]];
        session_write_close();
        session_id($nouveau);
        session_start();
        $_SESSION = $donnees;
        setcookie(session_name(), $nouveau, self::parametresCookie());
    }

    private static function parametresCookie(): array
    {
        $p = session_get_cookie_params();
        return ['expires' => 0, 'path' => $p['path'], 'domain' => $p['domain'], 'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite']];
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
        self::regenerer();
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
