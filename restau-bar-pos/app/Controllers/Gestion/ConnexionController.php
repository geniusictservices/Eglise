<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Config;
use App\Core\Database;
use App\Core\Session;
use App\Core\View;

final class ConnexionController
{
    private const ESSAIS_MAX = 5;
    private const BLOCAGE_SECONDES = 300;

    public function formulaire(): void
    {
        if (Session::utilisateur() !== null) {
            rediriger('/gestion');
        }
        View::afficher('gestion/connexion', ['titre' => t('gestion.connexion'), 'erreur' => null, 'email' => ''], 'gestion');
    }

    public function connecter(): void
    {
        Session::verifierCsrf();
        $email = trim((string) ($_POST['email'] ?? ''));
        $motDePasse = (string) ($_POST['mot_de_passe'] ?? '');

        // Limite simple des essais par session
        $essais = $_SESSION['essais_connexion'] ?? ['nombre' => 0, 'depuis' => time()];
        if (time() - $essais['depuis'] > self::BLOCAGE_SECONDES) {
            $essais = ['nombre' => 0, 'depuis' => time()];
        }
        if ($essais['nombre'] >= self::ESSAIS_MAX) {
            $this->echec($email, t('gestion.trop_essais'));
        }

        $utilisateur = Database::un('SELECT * FROM utilisateurs WHERE email = ? AND actif = 1', [$email]);
        if ($utilisateur === null || !password_verify($motDePasse, $utilisateur['mot_de_passe'])) {
            $essais['nombre']++;
            $_SESSION['essais_connexion'] = $essais;
            $this->echec($email, t('gestion.identifiants_invalides'));
        }

        unset($_SESSION['essais_connexion']);
        Database::executer('UPDATE utilisateurs SET derniere_connexion = NOW() WHERE id = ?', [$utilisateur['id']]);
        Session::connecter($utilisateur);
        // Langue préférée du compte (Mon compte)
        if (in_array($utilisateur['langue'] ?? '', Config::get('langues', ['fr']), true)) {
            setcookie('lang', $utilisateur['langue'], [
                'expires' => time() + 365 * 86400,
                'path' => '/',
                'samesite' => 'Lax',
            ]);
        }
        rediriger('/gestion');
    }

    public function deconnecter(): void
    {
        Session::verifierCsrf();
        Session::deconnecter();
        rediriger('/gestion/connexion');
    }

    private function echec(string $email, string $erreur): never
    {
        http_response_code(422);
        View::afficher('gestion/connexion', ['titre' => t('gestion.connexion'), 'erreur' => $erreur, 'email' => $email], 'gestion');
        exit;
    }
}
