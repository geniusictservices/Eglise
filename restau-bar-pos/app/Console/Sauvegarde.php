<?php

declare(strict_types=1);

namespace App\Console;

use PDO;

/**
 * Sauvegarde SQL d'une instance écrite en PHP (mysqldump n'existe pas toujours sur un hébergement mutualisé) :
 * structure de chaque table puis ses lignes, en INSERT de 200 lignes, dans un flux (rien n'est gardé en mémoire).
 * Le fichier se restaure avec phpMyAdmin (cPanel) ou « mysql base < fichier.sql ».
 */
final class Sauvegarde
{
    /** @param resource $sortie */
    public static function ecrire(PDO $pdo, $sortie, string $sousDomaine): void
    {
        $base = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        fwrite($sortie, "-- Sauvegarde de l'instance « $sousDomaine » (base $base), " . date('Y-m-d H:i:s') . "\n"
            . "-- restau-bar-pos · console super admin\n\n"
            . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET time_zone = '+00:00';\n\n");
        $pdo->exec("SET time_zone = '+00:00'");
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        foreach ($tables as [$table]) {
            $nom = '`' . str_replace('`', '``', $table) . '`';
            $creation = $pdo->query("SHOW CREATE TABLE $nom")->fetch(PDO::FETCH_NUM)[1];
            fwrite($sortie, "DROP TABLE IF EXISTS $nom;\n$creation;\n\n");
            $requete = $pdo->query("SELECT * FROM $nom", PDO::FETCH_NUM);
            $lot = [];
            while (($ligne = $requete->fetch(PDO::FETCH_NUM)) !== false) {
                $lot[] = '(' . implode(',', array_map(static fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $ligne)) . ')';
                if (count($lot) === 200) {
                    fwrite($sortie, "INSERT INTO $nom VALUES\n" . implode(",\n", $lot) . ";\n");
                    $lot = [];
                }
            }
            if ($lot !== []) {
                fwrite($sortie, "INSERT INTO $nom VALUES\n" . implode(",\n", $lot) . ";\n");
            }
            fwrite($sortie, "\n");
        }
        fwrite($sortie, "SET FOREIGN_KEY_CHECKS = 1;\n");
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
}
