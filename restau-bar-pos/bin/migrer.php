<?php

declare(strict_types=1);

/**
 * Applique les migrations en attente (database/migrations/*.sql).
 *
 *   php bin/migrer.php demo        une instance
 *   php bin/migrer.php --toutes    toutes les instances de config/instances/
 */

use App\Core\Database;
use App\Core\Migrations;

if (PHP_SAPI !== 'cli') {
    exit("À lancer en ligne de commande.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

$cible = $argv[1] ?? null;
if ($cible === null) {
    fwrite(STDERR, "Usage : php bin/migrer.php <sous-domaine> | --toutes\n");
    exit(1);
}

$instances = $cible === '--toutes'
    ? array_map(static fn (string $f) => basename($f, '.php'), glob(RACINE . '/config/instances/*.php') ?: [])
    : [$cible];

foreach ($instances as $instance) {
    if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $instance) || !is_file(RACINE . "/config/instances/$instance.php")) {
        fwrite(STDERR, "Instance inconnue : $instance\n");
        exit(1);
    }
    $config = require RACINE . "/config/instances/$instance.php";
    $appliquees = Migrations::appliquer(Database::connecter($config['db']));
    echo $instance . ' : ' . ($appliquees ? implode(', ', $appliquees) : 'à jour') . "\n";
}
