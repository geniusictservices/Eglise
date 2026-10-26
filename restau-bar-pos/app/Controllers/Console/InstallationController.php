<?php

declare(strict_types=1);

namespace App\Controllers\Console;

use App\Console\Console;
use App\Console\Instances;
use App\Console\SessionConsole;
use App\Core\BaseConsole;
use App\Core\Installation;
use App\Core\View;
use PDOException;
use Throwable;

/**
 * Installation de la console depuis le navigateur (hébergement sans SSH) : seulement tant qu'aucun super admin
 * n'existe, puis la page disparaît pour toujours.
 *
 * 1. Preuve d'accès à l'hébergement : créer, avec le gestionnaire de fichiers de cPanel, un fichier vide dont le nom
 *    est donné par la page (storage/installation-<code>.txt) — ou saisir le jeton_installation de config/console.php.
 *    Sans cette preuve, n'importe qui pourrait s'approprier une console fraîchement déposée.
 * 2. Base de la console (créée dans cPanel) : test de la connexion, écriture de config/console.php
 *    (ou son contenu à coller si le dossier config/ n'est pas accessible en écriture).
 * 3. Tables de la console et premier super admin (mot de passe de 12 caractères au moins).
 */
final class InstallationController
{
    public function __construct(private string $etat)
    {
    }

    public function index(): void
    {
        $this->afficher();
    }

    public function enregistrer(): void
    {
        SessionConsole::verifierCsrf();
        $etape = $_POST['etape'] ?? '';
        if (!$this->prouve()) {
            $this->afficher(['preuve' => t('console.installation.erreur_preuve')], 403);
            return;
        }
        $_SESSION['installation_prouvee'] = true;
        if ($etape === 'base' && $this->etat === 'non_configuree') {
            $this->base();
            return;
        }
        if ($etape === 'admin' && in_array($this->etat, ['vide', 'sans_admin'], true)) {
            $this->premierAdmin();
            return;
        }
        rediriger('/installation');
    }

    private function base(): void
    {
        $db = [
            'hote' => self::champ('db_hote', 'localhost'), 'port' => (int) self::champ('db_port', '3306'),
            'nom' => self::champ('db_nom'), 'utilisateur' => self::champ('db_utilisateur'),
            'mot_de_passe' => is_string($_POST['db_mot_de_passe'] ?? null) ? $_POST['db_mot_de_passe'] : '',
        ];
        if ($db['nom'] === '' || $db['utilisateur'] === '') {
            $this->afficher(['base' => t('console.installation.erreur_champs'), 'saisie' => $db], 422);
            return;
        }
        try {
            $examen = Installation::examinerBase(Instances::connecter($db));
        } catch (PDOException $e) {
            $this->afficher(['base' => Instances::expliquer($e), 'saisie' => $db], 422);
            return;
        }
        if ($examen['etat'] === 'instance') {
            $this->afficher(['base' => t('console.installation.erreur_base_instance'), 'saisie' => $db], 422);
            return;
        }
        $contenu = "<?php\n\n// Console super admin, écrit le " . date('Y-m-d H:i') . " par la page d'installation (modèle : config/console.php.dist)\nreturn "
            . var_export(['db' => $db, 'jeton_installation' => '', 'cache_licence_secondes' => 300], true) . ";\n";
        $fichier = BaseConsole::fichier();
        if ((is_file($fichier) ? is_writable($fichier) : is_writable(dirname($fichier))) && file_put_contents($fichier, $contenu, LOCK_EX) !== false) {
            @chmod($fichier, 0600);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($fichier, true);
            }
            SessionConsole::flash(t('console.installation.config_ecrite'));
            rediriger('/installation');
        }
        // Dossier non accessible en écriture : le contenu à coller avec le gestionnaire de fichiers
        $this->afficher(['contenu' => $contenu]);
    }

    private function premierAdmin(): void
    {
        $nom = self::champ('nom');
        $email = strtolower(self::champ('email'));
        $motDePasse = is_string($_POST['mot_de_passe'] ?? null) ? $_POST['mot_de_passe'] : '';
        $confirmation = is_string($_POST['confirmation'] ?? null) ? $_POST['confirmation'] : '';
        $erreurs = [];
        if ($nom === '') {
            $erreurs['nom'] = t('console.installation.erreur_nom');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erreurs['email'] = t('console.erreur_email');
        }
        if (($erreur = Console::erreurMotDePasse($motDePasse)) !== null) {
            $erreurs['mot_de_passe'] = $erreur;
        } elseif (!hash_equals($motDePasse, $confirmation)) {
            $erreurs['confirmation'] = t('console.compte.erreur_confirmation');
        }
        if ($erreurs !== []) {
            $this->afficher(['admin' => $erreurs, 'saisie' => ['nom' => $nom, 'email' => $email]], 422);
            return;
        }
        try {
            if ($this->etat === 'vide') {
                Console::installerTables();
            } else {
                Console::migrer();
            }
            // Dernière vérification : un autre super admin a pu être créé entre-temps
            if (Console::etat() === 'prete') {
                pageIntrouvable();
            }
            Console::enregistrerAdmin($email, $nom, $motDePasse);
            \App\Console\Journal::ecrire('console_installee', null, ['email' => $email], (int) BaseConsole::valeur('SELECT id FROM super_admins WHERE email = ?', [$email]));
        } catch (Throwable $e) {
            error_log('Installation de la console : ' . $e->getMessage());
            $this->afficher(['admin' => ['general' => t('console.installation.erreur_tables', ['message' => mb_substr($e->getMessage(), 0, 200)])], 'saisie' => ['nom' => $nom, 'email' => $email]], 500);
            return;
        }
        self::effacerPreuve();
        unset($_SESSION['installation_prouvee']);
        SessionConsole::flash(t('console.installation.terminee'));
        rediriger('/connexion');
    }

    /** Preuve d'accès à l'hébergement : fichier créé dans storage/, jeton de config/console.php, ou déjà prouvé dans cette session. */
    private function prouve(): bool
    {
        if (!empty($_SESSION['installation_prouvee'])) {
            return true;
        }
        $jeton = (string) (BaseConsole::config()['jeton_installation'] ?? '');
        $saisi = is_string($_POST['jeton'] ?? null) ? trim($_POST['jeton']) : '';
        if (strlen($jeton) >= 20 && $saisi !== '' && hash_equals($jeton, $saisi)) {
            return true;
        }
        $code = self::code();
        return $code !== null && is_file(RACINE . '/storage/installation-' . $code . '.txt');
    }

    /** Code du fichier de preuve, gardé dans storage/ (le même pour tous les visiteurs ; seul l'hébergeur peut créer le fichier). */
    public static function code(): ?string
    {
        $fichier = RACINE . '/storage/console-installation.code';
        $code = is_file($fichier) ? trim((string) file_get_contents($fichier)) : '';
        if (!preg_match('/^[a-z0-9]{12}$/', $code)) {
            $code = substr(bin2hex(random_bytes(8)), 0, 12);
            if (@file_put_contents($fichier, $code) === false) {
                return null;
            }
        }
        return $code;
    }

    private static function effacerPreuve(): void
    {
        $code = self::code();
        @unlink(RACINE . '/storage/installation-' . $code . '.txt');
        @unlink(RACINE . '/storage/console-installation.code');
    }

    private function afficher(array $erreurs = [], int $code = 200): void
    {
        http_response_code($code);
        SessionConsole::demarrer();
        View::afficher('console/installation', [
            'titre' => t('console.installation.titre'),
            'etat' => $this->etat,
            'prouve' => !empty($_SESSION['installation_prouvee']),
            'code' => self::code(),
            'jetonPossible' => strlen((string) (BaseConsole::config()['jeton_installation'] ?? '')) >= 20,
            'erreurs' => $erreurs,
            'saisie' => $erreurs['saisie'] ?? [],
            'contenu' => $erreurs['contenu'] ?? null,
            'flash' => SessionConsole::lireFlash(),
            'racine' => RACINE,
            'admin' => null,
        ], 'console');
    }

    private static function champ(string $cle, string $defaut = ''): string
    {
        $valeur = is_string($_POST[$cle] ?? null) ? trim($_POST[$cle]) : '';
        return $valeur === '' ? $defaut : mb_substr($valeur, 0, 128);
    }
}
