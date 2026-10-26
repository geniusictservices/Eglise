<?php

declare(strict_types=1);

define('RACINE', dirname(__DIR__));

// Chargement automatique des classes App\… depuis app/
spl_autoload_register(static function (string $classe): void {
    $prefixe = 'App\\';
    if (strncmp($classe, $prefixe, strlen($prefixe)) !== 0) {
        return;
    }
    $chemin = RACINE . '/app/' . str_replace('\\', '/', substr($classe, strlen($prefixe))) . '.php';
    if (is_file($chemin)) {
        require $chemin;
    }
});

require RACINE . '/app/helpers.php';

$config = require RACINE . '/config/app.php';
$fichierLocal = RACINE . '/config/app.local.php';
if (is_file($fichierLocal)) {
    $config = array_replace_recursive($config, require $fichierLocal);
}
App\Core\Config::charger($config);

error_reporting(E_ALL);
ini_set('display_errors', App\Core\Config::get('debug') ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', RACINE . '/storage/logs/php-erreurs.log');
