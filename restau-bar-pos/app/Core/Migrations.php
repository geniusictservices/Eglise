<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Migrations de la base d'une instance : database/migrations/NNN_nom.sql, appliquées dans l'ordre
 * et une seule fois (table schema_migrations). La base de la console super admin a les siennes
 * (database/console/migrations, même mécanisme).
 */
final class Migrations
{
    /** @return string[] noms des migrations appliquées lors de cet appel */
    public static function appliquer(PDO $pdo, ?string $dossier = null): array
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            nom VARCHAR(128) PRIMARY KEY,
            appliquee_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $faites = array_flip($pdo->query('SELECT nom FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
        $fichiers = self::fichiers($dossier);
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

    /**
     * Migrations pas encore appliquées à cette base (sans rien changer).
     * @return string[]
     */
    public static function enAttente(PDO $pdo, ?string $dossier = null): array
    {
        $existe = $pdo->query("SHOW TABLES LIKE 'schema_migrations'")->fetch();
        $faites = $existe ? array_flip($pdo->query('SELECT nom FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN)) : [];
        return array_values(array_filter(
            array_map(static fn (string $f) => basename($f, '.sql'), self::fichiers($dossier)),
            static fn (string $nom) => !isset($faites[$nom])
        ));
    }

    /** @return string[] fichiers .sql du dossier, dans l'ordre */
    private static function fichiers(?string $dossier): array
    {
        $fichiers = glob(($dossier ?? RACINE . '/database/migrations') . '/*.sql') ?: [];
        sort($fichiers, SORT_STRING);
        return $fichiers;
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
