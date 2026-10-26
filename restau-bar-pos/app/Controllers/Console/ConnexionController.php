<?php

declare(strict_types=1);

namespace App\Controllers\Console;

use App\Console\Journal;
use App\Console\SessionConsole;
use App\Core\BaseConsole;

/**
 * Connexion des super admins : e-mail et mot de passe ; comme dans les instances, 5 essais puis 5 minutes
 * de blocage, par navigateur et par compte (en base : changer de navigateur ne donne pas d'essais en plus).
 */
final class ConnexionController extends Base
{
    private const ESSAIS_MAX = 5;
    private const BLOCAGE_SECONDES = 300;

    public function formulaire(): void
    {
        if (SessionConsole::admin() !== null) {
            rediriger('/');
        }
        $this->vue('connexion', ['titre' => t('console.connexion.titre'), 'erreur' => null, 'email' => '']);
    }

    public function connecter(): void
    {
        SessionConsole::verifierCsrf();
        $email = strtolower(self::texte('email', 128));
        $motDePasse = is_string($_POST['mot_de_passe'] ?? null) ? $_POST['mot_de_passe'] : '';

        $essais = $_SESSION['essais_console'] ?? ['nombre' => 0, 'depuis' => time()];
        if (time() - $essais['depuis'] > self::BLOCAGE_SECONDES) {
            $essais = ['nombre' => 0, 'depuis' => time()];
        }
        if ($essais['nombre'] >= self::ESSAIS_MAX) {
            $this->echec($email, t('console.connexion.trop_essais'), 429);
        }
        $admin = BaseConsole::un('SELECT * FROM super_admins WHERE email = ? AND actif = 1', [$email]);
        if ($admin !== null && $admin['connexion_bloquee_jusqua'] !== null && strtotime($admin['connexion_bloquee_jusqua']) > time()) {
            Journal::ecrire('connexion_bloquee', null, ['email' => $email], (int) $admin['id']);
            $this->echec($email, t('console.connexion.trop_essais'), 429);
        }
        if ($admin === null || !password_verify($motDePasse, $admin['mot_de_passe'])) {
            $essais['nombre']++;
            $_SESSION['essais_console'] = $essais;
            if ($admin !== null) {
                $nombre = (int) $admin['connexion_essais'] + 1;
                $bloque = $nombre >= self::ESSAIS_MAX ? date('Y-m-d H:i:s', time() + self::BLOCAGE_SECONDES) : null;
                BaseConsole::executer('UPDATE super_admins SET connexion_essais = ?, connexion_bloquee_jusqua = ? WHERE id = ?', [$bloque ? 0 : $nombre, $bloque, $admin['id']]);
            }
            Journal::ecrire('connexion_echec', null, ['email' => $email], $admin ? (int) $admin['id'] : null);
            $this->echec($email, t('console.connexion.invalide'), 422);
        }
        unset($_SESSION['essais_console']);
        BaseConsole::executer('UPDATE super_admins SET derniere_connexion = NOW(), connexion_essais = 0, connexion_bloquee_jusqua = NULL WHERE id = ?', [$admin['id']]);
        SessionConsole::connecter($admin);
        Journal::ecrire('connexion', null, [], (int) $admin['id']);
        if (in_array($admin['langue'], ['fr', 'en'], true) && !isset($_COOKIE['lang'])) {
            setcookie('lang', $admin['langue'], ['expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax']);
        }
        rediriger('/');
    }

    public function deconnecter(): void
    {
        SessionConsole::verifierCsrf();
        SessionConsole::deconnecter();
        rediriger('/connexion');
    }

    private function echec(string $email, string $erreur, int $code): never
    {
        $this->vue('connexion', ['titre' => t('console.connexion.titre'), 'erreur' => $erreur, 'email' => $email], $code);
        exit;
    }
}
