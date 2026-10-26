<?php

declare(strict_types=1);

namespace App\Controllers\Console;

use App\Console\SessionConsole;
use App\Core\View;

/** Aides communes aux écrans de la console. */
abstract class Base
{
    protected function admin(): array
    {
        $admin = SessionConsole::admin();
        if ($admin === null) {
            rediriger('/connexion');
        }
        return $admin;
    }

    /** Formulaire : super admin connecté et jeton CSRF valable. */
    protected function poste(): array
    {
        $admin = $this->admin();
        SessionConsole::verifierCsrf();
        return $admin;
    }

    protected function vue(string $nom, array $donnees, int $code = 200): void
    {
        http_response_code($code);
        View::afficher('console/' . $nom, $donnees + [
            'admin' => SessionConsole::admin(),
            'flash' => SessionConsole::lireFlash(),
        ], 'console');
    }

    protected static function texte(string $cle, int $max = 255): string
    {
        $valeur = $_POST[$cle] ?? '';
        return is_string($valeur) ? mb_substr(trim((string) preg_replace('/\p{Cc}/u', '', $valeur)), 0, $max) : '';
    }

    protected static function date(string $cle): ?string
    {
        $valeur = self::texte($cle, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur) && checkdate((int) substr($valeur, 5, 2), (int) substr($valeur, 8, 2), (int) substr($valeur, 0, 4)) ? $valeur : null;
    }
}
