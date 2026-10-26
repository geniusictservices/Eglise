<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Config;
use App\Core\Database;
use App\Core\Journal;
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
        $identifiant = trim((string) ($_POST['email'] ?? ''));
        $motDePasse = (string) ($_POST['mot_de_passe'] ?? '');

        // Limite des essais par session (navigateur)…
        $essais = $_SESSION['essais_connexion'] ?? ['nombre' => 0, 'depuis' => time()];
        if (time() - $essais['depuis'] > self::BLOCAGE_SECONDES) {
            $essais = ['nombre' => 0, 'depuis' => time()];
        }
        if ($essais['nombre'] >= self::ESSAIS_MAX) {
            $this->echec($identifiant, t('gestion.trop_essais'));
        }

        // Identifiant : e-mail ou numéro de téléphone
        $utilisateur = str_contains($identifiant, '@')
            ? Database::un('SELECT * FROM utilisateurs WHERE email = ? AND actif = 1', [$identifiant])
            : Database::un('SELECT * FROM utilisateurs WHERE telephone = ? AND actif = 1', [normaliserTelephone($identifiant)]);

        // … et par compte, en base : changer de navigateur ne donne pas d'essais en plus
        if ($utilisateur !== null && $utilisateur['connexion_bloquee_jusqua'] !== null && strtotime($utilisateur['connexion_bloquee_jusqua']) > time()) {
            Journal::ecrire('connexion_bloquee', $identifiant, [], (int) $utilisateur['id']);
            $this->echec($identifiant, t('gestion.trop_essais'));
        }

        if ($utilisateur === null || $utilisateur['mot_de_passe'] === null || !password_verify($motDePasse, $utilisateur['mot_de_passe'])) {
            $essais['nombre']++;
            $_SESSION['essais_connexion'] = $essais;
            if ($utilisateur !== null) {
                $nombre = (int) $utilisateur['connexion_essais'] + 1;
                $bloque = $nombre >= self::ESSAIS_MAX ? date('Y-m-d H:i:s', time() + self::BLOCAGE_SECONDES) : null;
                Database::executer(
                    'UPDATE utilisateurs SET connexion_essais = ?, connexion_bloquee_jusqua = ? WHERE id = ?',
                    [$bloque ? 0 : $nombre, $bloque, $utilisateur['id']]
                );
            }
            Journal::ecrire('connexion_echec', $identifiant, [], $utilisateur ? (int) $utilisateur['id'] : null);
            $this->echec($identifiant, t('gestion.identifiants_invalides'));
        }

        unset($_SESSION['essais_connexion']);
        Database::executer(
            'UPDATE utilisateurs SET derniere_connexion = NOW(), connexion_essais = 0, connexion_bloquee_jusqua = NULL WHERE id = ?',
            [$utilisateur['id']]
        );
        Session::connecter($utilisateur);
        Journal::ecrire('connexion', null, [], (int) $utilisateur['id']);
        // Langue préférée du compte (Mon compte)
        if (in_array($utilisateur['langue'] ?? '', Config::get('langues', ['fr']), true)) {
            setcookie('lang', $utilisateur['langue'], [
                'expires' => time() + 365 * 86400,
                'path' => '/',
                'samesite' => 'Lax',
            ]);
        }
        // Une personne qui n'a que la caisse (serveur, barman) y va directement
        rediriger(Acces::aLaGestion((int) $utilisateur['id']) ? '/gestion' : '/caisse');
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
