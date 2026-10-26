<?php

declare(strict_types=1);

/**
 * Console super admin (admin.<domaine>), appelée par public/index.php : clients, instances, formules,
 * abonnements et paiements, API de messagerie. Sa base est séparée de celles des instances (config/console.php).
 *
 * Tant que la console n'est pas installée (pas de config/console.php, base vide ou aucun super admin), seule la page
 * /installation répond ; ensuite elle n'existe plus. Toutes les autres pages demandent un super admin connecté.
 */

use App\Console\Console;
use App\Console\SessionConsole;
use App\Controllers\Console\ConnexionController;
use App\Controllers\Console\InstallationController;
use App\Core\BaseConsole;
use App\Core\Config;
use App\Core\I18n;
use App\Core\Router;
use App\Core\View;

date_default_timezone_set(Config::get('fuseau_horaire'));
I18n::initialiser();
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$chemin = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
$installation = $chemin === '/installation';

// État de la console : non configurée, base injoignable, vide, sans super admin, prête
$etat = 'non_configuree';
if (BaseConsole::configuree()) {
    try {
        $etat = Console::etat();
    } catch (Throwable $e) {
        error_log('Console : base injoignable : ' . $e->getMessage());
        $etat = 'injoignable';
    }
}
if ($etat === 'injoignable') {
    http_response_code(503);
    View::afficher('console/injoignable', ['titre' => t('console.injoignable.titre')], 'console');
    exit;
}
if ($etat !== 'prete') {
    if (!$installation) {
        rediriger('/installation');
    }
    $controleur = new InstallationController($etat);
    $methode === 'POST' ? $controleur->enregistrer() : $controleur->index();
    exit;
}
if ($installation) {
    pageIntrouvable();   // installée : la page d'installation n'existe plus
}

$routeur = new Router();
foreach (glob(RACINE . '/app/routes/console/*.php') ?: [] as $fichierRoutes) {
    require $fichierRoutes;
}
// Toutes les pages, sauf la connexion, demandent un super admin connecté
if (!in_array($chemin, ['/connexion'], true) && SessionConsole::admin() === null) {
    rediriger('/connexion');
}
$routeur->traiter($methode, $chemin);
