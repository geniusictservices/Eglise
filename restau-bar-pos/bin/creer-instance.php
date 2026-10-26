<?php

declare(strict_types=1);

/**
 * Crée une instance client : base de données, utilisateur MySQL dédié, tables,
 * compte administrateur et fichier config/instances/<sous-domaine>.php.
 *
 *   php bin/creer-instance.php <sous-domaine> --nom="Nom du client" --email=admin@exemple.com [--demo]
 *       [--db-hote=127.0.0.1] [--db-port=3306] [--db-admin=root] [--db-admin-mdp=...]
 *
 * --demo charge les données de démonstration (database/demo.sql, puis demo-illustrations.sql et demo-tresorerie.sql),
 * les employés (demo-utilisateurs.sql) et trois semaines de ventes (bin/demo-ventes.php), dont quelques ardoises de clients
 * avec leurs remboursements, garants et rappels.
 * Le mot de passe MySQL administrateur peut aussi venir de la variable RBP_DB_ADMIN_MDP.
 */

use App\Core\Config;
use App\Core\Database;
use App\Core\Installation;

if (PHP_SAPI !== 'cli') {
    exit("À lancer en ligne de commande.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

function arreter(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

// Arguments : un sous-domaine et des options --cle=valeur (ou --drapeau), dans n'importe quel ordre
$options = [];
$sousDomaine = null;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $argument, $m)) {
        $options[$m[1]] = $m[2] ?? true;
    } elseif ($sousDomaine === null) {
        $sousDomaine = $argument;
    } else {
        arreter("Argument inattendu : $argument");
    }
}
$inconnues = array_diff(array_keys($options), ['nom', 'email', 'demo', 'db-hote', 'db-port', 'db-admin', 'db-admin-mdp']);
if ($inconnues) {
    arreter('Option inconnue : --' . implode(', --', $inconnues));
}

if ($sousDomaine === null || empty($options['email'])) {
    arreter("Usage : php bin/creer-instance.php <sous-domaine> --email=admin@exemple.com [--nom=\"Nom du client\"] [--demo]");
}
$erreur = Installation::erreurSousDomaine($sousDomaine);
if ($erreur === 'invalide') {
    arreter('Sous-domaine invalide : lettres minuscules, chiffres et tirets uniquement.');
}
if ($erreur === 'reserve') {
    arreter("Le sous-domaine « $sousDomaine » est réservé.");
}
if (!filter_var($options['email'], FILTER_VALIDATE_EMAIL)) {
    arreter('Adresse e-mail invalide.');
}

$fichierConfig = Installation::fichierConfig($sousDomaine);
if (is_file($fichierConfig)) {
    arreter("L'instance « $sousDomaine » existe déjà ($fichierConfig).");
}

$hote = $options['db-hote'] ?? '127.0.0.1';
$port = (int) ($options['db-port'] ?? 3306);
$demo = isset($options['demo']);

// Connexion administrateur (sans base) pour créer la base et son utilisateur
$adminDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $hote, $port);
try {
    $admin = new PDO($adminDsn, $options['db-admin'] ?? 'root', $options['db-admin-mdp'] ?? (getenv('RBP_DB_ADMIN_MDP') ?: ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $configDb = Installation::creerBaseEtUtilisateur($admin, $sousDomaine, $hote, $port);
} catch (PDOException $e) {
    arreter('Connexion MySQL impossible : ' . $e->getMessage());
} catch (RuntimeException $e) {
    arreter($e->getMessage());
}
$nomBase = $configDb['nom'];
$utilisateurBase = $configDb['utilisateur'];
$pdo = Database::connecter($configDb);

// Tables (schéma et toutes les migrations), puis la démonstration ou les réglages de départ
// (une démonstration sans --nom garde le nom de database/demo.sql)
Installation::installerTables($pdo, $options['nom'] ?? ($demo ? null : $sousDomaine), $demo);

// Compte administrateur du client (propriétaire : tous les droits, dans tous les établissements)
$proprietaire = Installation::creerProprietaire($pdo, $options['email']);
$motDePasseAdmin = $proprietaire['mot_de_passe'];
// Employés de démonstration (serveurs, barmen, gérant, comptable…), après l'administrateur
if ($demo) {
    Installation::installerUtilisateursDemo($pdo);
}

try {
    Installation::ecrireConfig($sousDomaine, $configDb, 'bin/creer-instance.php');
} catch (RuntimeException $e) {
    arreter($e->getMessage());
}

// Ventes de démonstration (après les employés : ce sont eux qui vendent), enregistrées comme à la caisse
if ($demo && is_file(RACINE . '/bin/demo-ventes.php')) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(RACINE . '/bin/demo-ventes.php') . ' ' . escapeshellarg($sousDomaine), $code);
    if ($code !== 0) {
        fwrite(STDERR, "Ventes de démonstration non créées (php bin/demo-ventes.php $sousDomaine pour réessayer).\n");
    }
}

// Soirées de démonstration du Volcano Club (module Événements), après les autres démonstrations : leurs recettes
// sont les ventes de démonstration de ces nuits, leurs coûts des dépenses rattachées
if ($demo && is_file(RACINE . '/bin/demo-evenements.php')) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(RACINE . '/bin/demo-evenements.php') . ' ' . escapeshellarg($sousDomaine), $code);
    if ($code !== 0) {
        fwrite(STDERR, "Soirées de démonstration non créées (php bin/demo-evenements.php $sousDomaine pour réessayer).\n");
    }
}

// Salle et cuisine de démonstration (plan de salle, postes, services, notes ouvertes, réservations), en dernier
if ($demo && is_file(RACINE . '/bin/demo-salle.php')) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(RACINE . '/bin/demo-salle.php') . ' ' . escapeshellarg($sousDomaine), $code);
    if ($code !== 0) {
        fwrite(STDERR, "Salle et cuisine de démonstration non créées (php bin/demo-salle.php $sousDomaine pour réessayer).\n");
    }
}

// Stock et achats de démonstration (dépôts, fournisseurs, réceptions, transferts, inventaires), à la fin :
// les sorties de stock de toutes les ventes de démonstration sont rejouées dans l'ordre
if ($demo && is_file(RACINE . '/bin/demo-stock.php')) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(RACINE . '/bin/demo-stock.php') . ' ' . escapeshellarg($sousDomaine), $code);
    if ($code !== 0) {
        fwrite(STDERR, "Stock de démonstration non créé (php bin/demo-stock.php $sousDomaine pour réessayer).\n");
    }
}

// Personnel et paie de démonstration (fiches des employés, plannings, pointages, avance, bulletins), après les ventes
if ($demo && is_file(RACINE . '/bin/demo-personnel.php')) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(RACINE . '/bin/demo-personnel.php') . ' ' . escapeshellarg($sousDomaine), $code);
    if ($code !== 0) {
        fwrite(STDERR, "Personnel de démonstration non créé (php bin/demo-personnel.php $sousDomaine pour réessayer).\n");
    }
}

$domaine = Config::get('domaine_base');
echo <<<TXT
Instance « $sousDomaine » créée.

  Adresse         : https://$sousDomaine.$domaine
  Base de données : $nomBase (utilisateur $utilisateurBase)
  Configuration   : config/instances/$sousDomaine.php

  Espace de gestion : https://$sousDomaine.$domaine/gestion
  E-mail            : {$options['email']}
  Mot de passe      : $motDePasseAdmin   (à changer après la première connexion)

TXT;
