<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Migrations de la base d'une instance : database/migrations/NNN_nom.sql, appliquées dans l'ordre
 * et une seule fois (table schema_migrations).
 */
final class Migrations
{
    /** @return string[] noms des migrations appliquées lors de cet appel */
    public static function appliquer(PDO $pdo): array
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            nom VARCHAR(128) PRIMARY KEY,
            appliquee_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $faites = array_flip($pdo->query('SELECT nom FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
        $fichiers = glob(RACINE . '/database/migrations/*.sql') ?: [];
        sort($fichiers, SORT_STRING);

        $appliquees = [];
        foreach ($fichiers as $fichier) {
            $nom = basename($fichier, '.sql');
            if (isset($faites[$nom])) {
                continue;
            }
            self::executerFichier($pdo, $fichier);
            $pdo->prepare('INSERT INTO schema_migrations (nom) VALUES (?)')->execute([$nom]);
            $appliquees[] = $nom;
        }
        return $appliquees;
    }

    /** Exécute un fichier SQL instruction par instruction (une instruction se termine par « ; » en fin de ligne). */
    public static function executerFichier(PDO $pdo, string $fichier): void
    {
        $sql = (string) file_get_contents($fichier);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (preg_split('/;\s*$/m', $sql) as $instruction) {
            if (trim($instruction) !== '') {
                $pdo->exec($instruction);
            }
        }
    }
}
