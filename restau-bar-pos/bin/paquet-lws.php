<?php

declare(strict_types=1);

/**
 * Archive prête pour l'hébergement LWS (mutualisé cPanel) : le code du dernier commit, plus les bases vides
 * à importer avec phpMyAdmin si besoin, et le guide docs/installation-lws.md.
 *
 *   php bin/paquet-lws.php                           → restau-bar-pos-lws-AAAAMMJJ.zip à la racine du dépôt
 *   php bin/paquet-lws.php --sortie=/chemin/x.zip
 *   php bin/paquet-lws.php --demo=rbp_demo           ajoute installation/instance-demo.sql (copie de cette base)
 *
 * À lancer sur le poste de développement : il faut mysql et mysqldump (connexion root par le socket, ou
 * RBP_DB_ADMIN / RBP_DB_ADMIN_MDP), git et zip.
 *
 * Contenu de installation/ dans l'archive :
 * - instance-vide.sql : toutes les tables d'une instance et les données de base (devises, rôles modèles, réglages),
 *   sans aucun utilisateur ni établissement ; la console la termine (migrations plus récentes, compte propriétaire) ;
 * - console-vide.sql : les tables de la console avec les formules et réglages de départ, sans super admin
 *   (la page d'installation de la console le crée) ;
 * - instance-demo.sql (option --demo) : une instance de démonstration complète.
 * Ne contient jamais config/console.php, config/instances/*.php, les journaux ni les fichiers envoyés.
 */

use App\Core\Installation;
use App\Core\Migrations;

if (PHP_SAPI !== 'cli') {
    exit("À lancer en ligne de commande.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

function arreter(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (!preg_match('/^--(sortie|demo)=(.+)$/', $argument, $m)) {
        arreter("Option inconnue : $argument");
    }
    $options[$m[1]] = $m[2];
}
$sortie = $options['sortie'] ?? RACINE . '/restau-bar-pos-lws-' . date('Ymd') . '.zip';

$admin = getenv('RBP_DB_ADMIN') ?: 'root';
$mdp = getenv('RBP_DB_ADMIN_MDP') ?: '';
$client = static function (string $commande) use ($admin, $mdp): string {
    return $commande . ' -u ' . escapeshellarg($admin) . ($mdp !== '' ? ' -p' . escapeshellarg($mdp) : '');
};
$executer = static function (string $commande): void {
    exec($commande . ' 2>&1', $lignes, $code);
    if ($code !== 0) {
        arreter("Échec : $commande\n" . implode("\n", $lignes));
    }
};
$pdoAdmin = new PDO('mysql:unix_socket=' . (ini_get('pdo_mysql.default_socket') ?: '/run/mysqld/mysqld.sock'), $admin, $mdp, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$dossier = sys_get_temp_dir() . '/rbp-paquet-' . bin2hex(random_bytes(4));
mkdir($dossier . '/installation', 0700, true);
$dump = static function (string $base, string $fichier) use ($client, $executer): void {
    $executer($client('mysqldump') . ' --single-transaction --skip-comments --skip-dump-date --no-tablespaces --default-character-set=utf8mb4 '
        . escapeshellarg($base) . ' > ' . escapeshellarg($fichier));
    // Sans DEFINER (refusé sur un hébergement mutualisé)
    file_put_contents($fichier, preg_replace('/\/\*!50017 DEFINER=[^*]*\*\/ ?/', '', (string) file_get_contents($fichier)));
};
$baseTemporaire = static function (string $nom) use ($pdoAdmin): PDO {
    $pdoAdmin->exec("DROP DATABASE IF EXISTS `$nom`");
    $pdoAdmin->exec("CREATE DATABASE `$nom` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = new PDO('mysql:unix_socket=' . (ini_get('pdo_mysql.default_socket') ?: '/run/mysqld/mysqld.sock') . ';dbname=' . $nom . ';charset=utf8mb4',
        getenv('RBP_DB_ADMIN') ?: 'root', getenv('RBP_DB_ADMIN_MDP') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    return $pdo;
};

try {
    // 1. Instance vide : tables et données de base, aucun utilisateur
    $nom = 'rbp_paquet_instance';
    $pdo = $baseTemporaire($nom);
    $migrations = Installation::installerTables($pdo, '', false, 'fr');
    // Le secret des codes QR de pointage est propre à chaque instance : jamais dans le fichier commun (voir Installation)
    $pdo->exec("DELETE FROM parametres WHERE cle IN ('nom_client', 'pointage.secret')");
    if ((int) $pdo->query('SELECT COUNT(*) FROM utilisateurs')->fetchColumn() !== 0) {
        arreter('La base vide contient des utilisateurs : arrêt.');
    }
    $dump($nom, $dossier . '/installation/instance-vide.sql');
    $pdoAdmin->exec("DROP DATABASE `$nom`");
    echo 'instance-vide.sql : schema.sql + ' . count($migrations) . " migrations\n";

    // 2. Console vide : tables, formules et réglages de départ, aucun super admin
    $nom = 'rbp_paquet_console';
    $pdo = $baseTemporaire($nom);
    Migrations::executerFichier($pdo, RACINE . '/database/console/schema.sql');
    $consoleMigrations = Migrations::appliquer($pdo, RACINE . '/database/console/migrations');
    $dump($nom, $dossier . '/installation/console-vide.sql');
    $pdoAdmin->exec("DROP DATABASE `$nom`");
    echo 'console-vide.sql : schema + ' . count($consoleMigrations) . " migrations\n";

    // 3. Démonstration (facultatif)
    if (isset($options['demo'])) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $options['demo'])) {
            arreter('Nom de base invalide.');
        }
        $dump($options['demo'], $dossier . '/installation/instance-demo.sql');
        echo "instance-demo.sql : copie de {$options['demo']}\n";
    }

    file_put_contents($dossier . '/installation/LISEZMOI.txt', <<<TXT
        restau-bar-pos — fichiers d'installation
        =========================================

        Suivez docs/installation-lws.md. En résumé :

        - console-vide.sql : seulement si la page d'installation de la console ne peut pas créer les tables
          (importer dans la base de la console avec phpMyAdmin, puis ouvrir https://admin.<domaine>).
        - instance-vide.sql : seulement si la création d'un client par la console échoue (délai dépassé…) :
          importer dans la base du client avec phpMyAdmin, puis refaire « Installer » dans la console,
          qui termine (migrations récentes, compte propriétaire).
        - instance-demo.sql (si présent) : une instance de démonstration complète pour présenter l'application.

        TXT);

    // Les fichiers SQL ne sont jamais servis sur le web, même si le dossier du projet l'est par erreur
    copy(RACINE . '/config/.htaccess', $dossier . '/installation/.htaccess');

    // 4. Archive : le code du dernier commit + installation/
    $racineGit = trim((string) shell_exec('git -C ' . escapeshellarg(RACINE) . ' rev-parse --show-toplevel'));
    $prefixe = trim((string) shell_exec('git -C ' . escapeshellarg(RACINE) . ' rev-parse --show-prefix'));
    if ($racineGit === '') {
        arreter('Pas de dépôt git.');
    }
    if (trim((string) shell_exec('git -C ' . escapeshellarg(RACINE) . ' status --porcelain -- .')) !== '') {
        fwrite(STDERR, "Attention : des changements ne sont pas commités ; l'archive contient le dernier commit.\n");
    }
    @unlink($sortie);
    $arbre = 'HEAD' . ($prefixe !== '' ? ':' . rtrim($prefixe, '/') : '');
    $executer('git -C ' . escapeshellarg($racineGit) . ' archive --format=zip --prefix=restau-bar-pos/ -o ' . escapeshellarg($sortie) . ' ' . escapeshellarg($arbre));
    mkdir($dossier . '/restau-bar-pos', 0700);
    rename($dossier . '/installation', $dossier . '/restau-bar-pos/installation');
    $executer('cd ' . escapeshellarg($dossier) . ' && zip -qr ' . escapeshellarg($sortie) . ' restau-bar-pos/installation');
    $commit = trim((string) shell_exec('git -C ' . escapeshellarg(RACINE) . ' rev-parse --short HEAD'));
    printf("Archive : %s (%.1f Mo, commit %s)\n", $sortie, filesize($sortie) / 1048576, $commit);
} finally {
    exec('rm -rf ' . escapeshellarg($dossier));
}
