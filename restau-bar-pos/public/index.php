<?php

declare(strict_types=1);

use App\Controllers\CaisseController;
use App\Controllers\Gestion\ConnexionController;
use App\Controllers\Gestion\ModulesController;
use App\Controllers\Gestion\QrController;
use App\Controllers\Gestion\TableauController;
use App\Controllers\VitrineController;
use App\Core\Config;
use App\Core\Database;
use App\Core\I18n;
use App\Core\Instance;
use App\Core\Router;
use App\Core\View;

require dirname(__DIR__) . '/app/bootstrap.php';

// Serveur de développement PHP : laisser passer les fichiers statiques
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

$sousDomaine = Instance::sousDomaineDepuisHote($_SERVER['HTTP_HOST'] ?? '');
if ($sousDomaine === null) {
    // admin.<domaine> : console super admin, prévue en phase 2
    http_response_code(503);
    View::afficher('erreurs/console', [], null);
    exit;
}

$instance = Instance::charger($sousDomaine);
if ($instance === null) {
    I18n::initialiser();
    pageIntrouvable();
}

$parametres = array_column(Database::tous('SELECT cle, valeur FROM parametres'), 'valeur', 'cle');
date_default_timezone_set($parametres['fuseau_horaire'] ?? Config::get('fuseau_horaire'));
// Même fuseau pour NOW() et CURRENT_TIMESTAMP que pour PHP
Database::executer('SET time_zone = ?', [date('P')]);
I18n::initialiser($parametres['langue_defaut'] ?? null);
Config::definir('parametres', $parametres);

$routeur = new Router();

// Vitrine publique
$routeur->get('/', [VitrineController::class, 'accueil']);
$routeur->get('/e/{slug}', [VitrineController::class, 'etablissement']);
$routeur->get('/q/{code}', [VitrineController::class, 'scanQr']);

// Caisse
$routeur->get('/caisse', [CaisseController::class, 'index']);
$routeur->post('/caisse/pin', [CaisseController::class, 'pin']);
$routeur->post('/caisse/verrouiller', [CaisseController::class, 'verrouiller']);
$routeur->post('/caisse/photo', [CaisseController::class, 'photo']);
$routeur->post('/caisse/appareil', [CaisseController::class, 'autoriserAppareil']);

// Espace de gestion
$routeur->get('/gestion', [ModulesController::class, 'index']);
$routeur->get('/gestion/tableau-de-bord', [TableauController::class, 'index']);
$routeur->get('/gestion/connexion', [ConnexionController::class, 'formulaire']);
$routeur->post('/gestion/connexion', [ConnexionController::class, 'connecter']);
$routeur->post('/gestion/deconnexion', [ConnexionController::class, 'deconnecter']);
$routeur->get('/gestion/qr', [QrController::class, 'index']);
$routeur->post('/gestion/qr', [QrController::class, 'creer']);
$routeur->get('/gestion/qr/{code}/imprimer', [QrController::class, 'imprimer']);

// Autres modules : un fichier par module, qui reçoit $routeur
foreach (glob(RACINE . '/app/routes/*.php') ?: [] as $fichierRoutes) {
    require $fichierRoutes;
}

$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$chemin = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
// Droits de la personne connectée sur l'écran demandé (rôles et permissions), avant toute action
App\Core\Acces::garder($methode, $chemin);
$routeur->traiter($methode, $chemin);
