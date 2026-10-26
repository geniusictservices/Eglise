<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Avatars;
use App\Core\Config;
use App\Core\Database;
use App\Core\I18n;
use App\Core\Session;
use App\Core\View;

/** Mon compte : nom, langue préférée, mot de passe. */
final class CompteController
{
    private const LONGUEUR_MIN = 10;
    private const LONGUEUR_MAX = 72; // au-delà, password_hash (bcrypt) ignore la fin
    private const ESSAIS_MAX = 5;
    private const BLOCAGE_SECONDES = 300;

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $this->afficher($utilisateur);
    }

    /** Nom et langue. La langue s'applique tout de suite (cookie « lang »). */
    public function enregistrer(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();

        $nom = is_string($_POST['nom'] ?? null) ? trim((string) preg_replace('/\p{Cc}/u', '', $_POST['nom'])) : '';
        $langue = is_string($_POST['langue'] ?? null) ? $_POST['langue'] : '';
        $langues = Config::get('langues', ['fr', 'en']);

        $erreurs = [];
        if ($nom === '') {
            $erreurs['nom'] = t('compte.erreur_nom');
        } elseif (mb_strlen($nom) > 128) {
            $erreurs['nom'] = t('compte.erreur_nom_long');
        }
        if (!in_array($langue, $langues, true)) {
            $erreurs['langue'] = t('compte.erreur_langue');
        }
        if ($erreurs !== []) {
            $this->afficher($utilisateur, ['erreursProfil' => $erreurs, 'saisieProfil' => ['nom' => $nom, 'langue' => $langue]], 422);
            return;
        }

        Database::executer('UPDATE utilisateurs SET nom = ?, langue = ? WHERE id = ?', [$nom, $langue, (int) $utilisateur['id']]);
        $_SESSION['utilisateur']['nom'] = $nom;

        setcookie('lang', $langue, [
            'expires' => time() + 365 * 86400,
            'path' => '/',
            'samesite' => 'Lax',
        ]);
        // Message dans la nouvelle langue
        $_COOKIE['lang'] = $langue;
        I18n::initialiser(Config::get('parametres.langue_defaut'));
        Session::flash(t('compte.enregistre'));
        rediriger('/gestion/compte');
    }

    public function changerMotDePasse(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();

        $actuel = is_string($_POST['actuel'] ?? null) ? $_POST['actuel'] : '';
        $nouveau = is_string($_POST['nouveau'] ?? null) ? $_POST['nouveau'] : '';
        $confirmation = is_string($_POST['confirmation'] ?? null) ? $_POST['confirmation'] : '';

        // Limite simple des essais du mot de passe actuel, comme à la connexion
        $essais = $_SESSION['essais_mot_de_passe'] ?? ['nombre' => 0, 'depuis' => time()];
        if (time() - $essais['depuis'] > self::BLOCAGE_SECONDES) {
            $essais = ['nombre' => 0, 'depuis' => time()];
        }
        if ($essais['nombre'] >= self::ESSAIS_MAX) {
            $this->afficher($utilisateur, ['erreursMotDePasse' => ['actuel' => t('gestion.trop_essais')]], 429);
            return;
        }

        $ligne = Database::un('SELECT mot_de_passe FROM utilisateurs WHERE id = ? AND actif = 1', [(int) $utilisateur['id']]);
        $erreurs = [];
        if ($ligne === null || !password_verify($actuel, $ligne['mot_de_passe'])) {
            $essais['nombre']++;
            $_SESSION['essais_mot_de_passe'] = $essais;
            $erreurs['actuel'] = t('compte.erreur_actuel');
        }
        if (mb_strlen($nouveau) < self::LONGUEUR_MIN) {
            $erreurs['nouveau'] = t('compte.erreur_court', ['n' => self::LONGUEUR_MIN]);
        } elseif (strlen($nouveau) > self::LONGUEUR_MAX) {
            $erreurs['nouveau'] = t('compte.erreur_long', ['n' => self::LONGUEUR_MAX]);
        } elseif (!isset($erreurs['actuel']) && hash_equals($actuel, $nouveau)) {
            $erreurs['nouveau'] = t('compte.erreur_identique');
        }
        if (!isset($erreurs['nouveau']) && !hash_equals($nouveau, $confirmation)) {
            $erreurs['confirmation'] = t('compte.erreur_confirmation');
        }
        if ($erreurs !== []) {
            $this->afficher($utilisateur, ['erreursMotDePasse' => $erreurs], 422);
            return;
        }

        unset($_SESSION['essais_mot_de_passe']);
        Database::executer(
            'UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?',
            [password_hash($nouveau, PASSWORD_DEFAULT), (int) $utilisateur['id']]
        );
        // Mot de passe temporaire donné à la création de l'instance (console) : remplacé
        \App\Core\Plateforme::motDePasseChange((int) $utilisateur['id']);
        // Nouvel identifiant de session : une session volée avant le changement ne sert plus
        session_regenerate_id(true);
        Session::flash(t('compte.mot_de_passe_change'));
        rediriger('/gestion/compte');
    }

    /** Sa propre photo de profil (montrée sur l'écran « Qui êtes-vous ? » et à la caisse). */
    public function photo(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $erreur = Avatars::traiterFormulaire((int) $utilisateur['id'], $utilisateur['nom']);
        if ($erreur !== null) {
            $this->afficher($utilisateur, ['erreurPhoto' => $erreur], 422);
            return;
        }
        Session::flash(t('profil.photo_enregistree_moi'));
        rediriger('/gestion/compte#photo');
    }

    private function afficher(array $utilisateur, array $etat = [], int $code = 200): void
    {
        http_response_code($code);
        $compte = Database::un(
            'SELECT nom, email, avatar, langue, derniere_connexion, cree_le FROM utilisateurs WHERE id = ?',
            [(int) $utilisateur['id']]
        );
        if ($compte === null) {
            Session::deconnecter();
            rediriger('/gestion/connexion');
        }
        View::afficher('gestion/compte/index', [
            'titre' => t('compte.titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'compte' => $compte,
            'langues' => Config::get('langues', ['fr', 'en']),
            'saisieProfil' => $etat['saisieProfil'] ?? ['nom' => $compte['nom'], 'langue' => $compte['langue']],
            'erreursProfil' => $etat['erreursProfil'] ?? [],
            'erreursMotDePasse' => $etat['erreursMotDePasse'] ?? [],
            'erreurPhoto' => $etat['erreurPhoto'] ?? null,
            'longueurMin' => self::LONGUEUR_MIN,
        ], 'gestion');
    }
}
