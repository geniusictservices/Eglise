<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Base de données de la console super admin (clients, formules, abonnements, paiements, réglages de la messagerie),
 * séparée des bases des instances. Ses accès sont dans config/console.php (non versionné, modèle config/console.php.dist).
 *
 * La console (admin.<domaine>) l'utilise pour tout ; chaque instance la lit seulement pour connaître son abonnement,
 * ses modules et ses limites, et les réglages communs de la messagerie (App\Core\Plateforme).
 * Sans config/console.php (développement, démonstration), il n'y a pas de console : les instances sont libres.
 *
 * La variable d'environnement RBP_CONSOLE_CONFIG peut désigner un autre fichier (tests : une console d'essai
 * sans toucher aux autres serveurs qui partagent le même code).
 */
final class BaseConsole
{
    private static ?PDO $pdo = null;
    private static array|false|null $config = null;

    public static function fichier(): string
    {
        $autre = getenv('RBP_CONSOLE_CONFIG');
        return is_string($autre) && $autre !== '' ? $autre : RACINE . '/config/console.php';
    }

    /** Configuration de la console, null si elle n'est pas installée sur ce serveur. */
    public static function config(): ?array
    {
        if (self::$config === null) {
            $fichier = self::fichier();
            $config = is_file($fichier) ? require $fichier : null;
            self::$config = is_array($config) && isset($config['db']['nom']) ? $config : false;
        }
        return self::$config === false ? null : self::$config;
    }

    public static function configuree(): bool
    {
        return self::config() !== null;
    }

    /** Connexion (ouverte une fois) ; lève une PDOException si la base est injoignable. */
    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $config = self::config();
            if ($config === null) {
                throw new \RuntimeException('Console non configurée (config/console.php).');
            }
            $db = $config['db'];
            self::$pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['hote'] ?? '127.0.0.1', (int) ($db['port'] ?? 3306), $db['nom']),
                $db['utilisateur'],
                $db['mot_de_passe'] ?? '',
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => 5,
                ]
            );
            self::$pdo->exec("SET time_zone = '" . date('P') . "'");
        }
        return self::$pdo;
    }

    public static function tous(string $sql, array $params = []): array
    {
        $requete = self::pdo()->prepare($sql);
        $requete->execute($params);
        return $requete->fetchAll();
    }

    public static function un(string $sql, array $params = []): ?array
    {
        $requete = self::pdo()->prepare($sql);
        $requete->execute($params);
        $ligne = $requete->fetch();
        return $ligne === false ? null : $ligne;
    }

    public static function valeur(string $sql, array $params = []): mixed
    {
        $requete = self::pdo()->prepare($sql);
        $requete->execute($params);
        $valeur = $requete->fetchColumn();
        return $valeur === false ? null : $valeur;
    }

    public static function executer(string $sql, array $params = []): int
    {
        $requete = self::pdo()->prepare($sql);
        $requete->execute($params);
        return $requete->rowCount();
    }

    public static function dernierId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    /** Pour les tests et les scripts qui changent de fichier de configuration. */
    public static function oublier(): void
    {
        self::$pdo = null;
        self::$config = null;
    }
}
