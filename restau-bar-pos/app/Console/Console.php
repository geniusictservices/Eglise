<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\BaseConsole;
use App\Core\Migrations;
use PDO;
use RuntimeException;

/** Installation et mise à jour de la base de la console, comptes super admin. */
final class Console
{
    public const LONGUEUR_MIN = 12;
    public const LONGUEUR_MAX = 72;
    public const DOSSIER_MIGRATIONS = RACINE . '/database/console/migrations';

    /** « vide » (rien d'installé), « sans_admin » ou « prete ». */
    public static function etat(): string
    {
        $tables = BaseConsole::pdo()->query("SHOW TABLES LIKE 'super_admins'")->fetchAll(PDO::FETCH_COLUMN);
        if ($tables === []) {
            return 'vide';
        }
        return (int) BaseConsole::valeur('SELECT COUNT(*) FROM super_admins WHERE actif = 1') > 0 ? 'prete' : 'sans_admin';
    }

    /** @return string[] migrations appliquées */
    public static function installerTables(): array
    {
        $pdo = BaseConsole::pdo();
        if ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) !== []) {
            throw new RuntimeException('La base de la console contient déjà des tables : rien n\'a été changé (php bin/console-admin.php migrer pour la mettre à jour).');
        }
        Migrations::executerFichier($pdo, RACINE . '/database/console/schema.sql');
        return Migrations::appliquer($pdo, self::DOSSIER_MIGRATIONS);
    }

    /** @return string[] */
    public static function migrer(): array
    {
        return Migrations::appliquer(BaseConsole::pdo(), self::DOSSIER_MIGRATIONS);
    }

    public static function erreurMotDePasse(string $motDePasse): ?string
    {
        if (mb_strlen($motDePasse) < self::LONGUEUR_MIN) {
            return t('console.compte.erreur_court', ['n' => self::LONGUEUR_MIN]);
        }
        if (strlen($motDePasse) > self::LONGUEUR_MAX) {
            return t('console.compte.erreur_long', ['n' => self::LONGUEUR_MAX]);
        }
        return null;
    }

    /** Crée le super admin, ou change son mot de passe s'il existe ; true s'il existait. */
    public static function enregistrerAdmin(string $email, ?string $nom, string $motDePasse): bool
    {
        $existant = BaseConsole::un('SELECT id FROM super_admins WHERE email = ?', [$email]);
        $empreinte = password_hash($motDePasse, PASSWORD_DEFAULT);
        if ($existant !== null) {
            BaseConsole::executer(
                'UPDATE super_admins SET mot_de_passe = ?, actif = 1, connexion_essais = 0, connexion_bloquee_jusqua = NULL' . ($nom !== null ? ', nom = ?' : '') . ' WHERE id = ?',
                $nom !== null ? [$empreinte, $nom, (int) $existant['id']] : [$empreinte, (int) $existant['id']]
            );
            return true;
        }
        BaseConsole::executer('INSERT INTO super_admins (nom, email, mot_de_passe) VALUES (?, ?, ?)', [$nom ?: $email, $email, $empreinte]);
        return false;
    }
}
