<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/** Connexion PDO à la base de l'instance courante, ouverte à la première requête. */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $db = Instance::courante()->config['db'];
            self::$pdo = self::connecter($db);
        }
        return self::$pdo;
    }

    public static function connecter(array $db): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $db['hote'] ?? '127.0.0.1',
            (int) ($db['port'] ?? 3306),
            $db['nom'],
        );
        return new PDO($dsn, $db['utilisateur'], $db['mot_de_passe'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
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
}
