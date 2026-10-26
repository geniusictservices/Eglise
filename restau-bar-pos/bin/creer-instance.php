<?php

declare(strict_types=1);

/**
 * Crée une instance client : base de données, utilisateur MySQL dédié, tables,
 * compte administrateur et fichier config/instances/<sous-domaine>.php.
 *
 *   php bin/creer-instance.php <sous-domaine> --nom="Nom du client" --email=admin@exemple.com [--demo]
 *       [--db-hote=127.0.0.1] [--db-port=3306] [--db-admin=root] [--db-admin-mdp=...]
 *
 * --demo charge les données de démonstration (database/demo.sql, puis demo-illustrations.sql et demo-tresorerie.sql).
 * Le mot de passe MySQL administrateur peut aussi venir de la variable RBP_DB_ADMIN_MDP.
 */

use App\Core\Config;
use App\Core\Database;
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
if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $sousDomaine)) {
    arreter('Sous-domaine invalide : lettres minuscules, chiffres et tirets uniquement.');
}
if (in_array($sousDomaine, Config::get('sous_domaines_reserves', []), true)) {
    arreter("Le sous-domaine « $sousDomaine » est réservé.");
}
if (!filter_var($options['email'], FILTER_VALIDATE_EMAIL)) {
    arreter('Adresse e-mail invalide.');
}

$fichierConfig = RACINE . '/config/instances/' . $sousDomaine . '.php';
if (is_file($fichierConfig)) {
    arreter("L'instance « $sousDomaine » existe déjà ($fichierConfig).");
}

$hote = $options['db-hote'] ?? '127.0.0.1';
$port = (int) ($options['db-port'] ?? 3306);
$nomBase = 'rbp_' . str_replace('-', '_', $sousDomaine);
$utilisateurBase = substr($nomBase, 0, 32);
$motDePasseBase = bin2hex(random_bytes(16));

// Connexion administrateur (sans base) pour créer la base et son utilisateur
$adminDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $hote, $port);
try {
    $admin = new PDO($adminDsn, $options['db-admin'] ?? 'root', $options['db-admin-mdp'] ?? (getenv('RBP_DB_ADMIN_MDP') ?: ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    arreter('Connexion MySQL impossible : ' . $e->getMessage());
}

if ($admin->query('SHOW DATABASES LIKE ' . $admin->quote($nomBase))->fetch()) {
    arreter("La base « $nomBase » existe déjà.");
}

$admin->exec("CREATE DATABASE `$nomBase` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
// L'utilisateur se connecte depuis la même machine ; adapter l'hôte si la base est sur un autre serveur
foreach (['localhost', '127.0.0.1'] as $origine) {
    $admin->exec(sprintf("CREATE USER %s@%s IDENTIFIED BY %s", $admin->quote($utilisateurBase), $admin->quote($origine), $admin->quote($motDePasseBase)));
    $admin->exec(sprintf("GRANT ALL PRIVILEGES ON `%s`.* TO %s@%s", $nomBase, $admin->quote($utilisateurBase), $admin->quote($origine)));
}

$configDb = ['hote' => $hote, 'port' => $port, 'nom' => $nomBase, 'utilisateur' => $utilisateurBase, 'mot_de_passe' => $motDePasseBase];
$pdo = Database::connecter($configDb);

Migrations::executerFichier($pdo, RACINE . '/database/schema.sql');
Migrations::appliquer($pdo);
if (isset($options['demo'])) {
    Migrations::executerFichier($pdo, RACINE . '/database/demo.sql');
    // Illustrations SVG des produits et des établissements de démonstration
    if (is_file(RACINE . '/database/demo-illustrations.sql')) {
        Migrations::executerFichier($pdo, RACINE . '/database/demo-illustrations.sql');
    }
    // Trésorerie : comptes, transferts et dépenses de démonstration
    if (is_file(RACINE . '/database/demo-tresorerie.sql')) {
        Migrations::executerFichier($pdo, RACINE . '/database/demo-tresorerie.sql');
    }
} else {
    $pdo->prepare('INSERT INTO parametres (cle, valeur) VALUES (?, ?), (?, ?), (?, ?)')->execute([
        'nom_client', $options['nom'] ?? $sousDomaine,
        'langue_defaut', Config::get('langue_defaut'),
        'fuseau_horaire', Config::get('fuseau_horaire'),
    ]);
}
if (isset($options['nom'])) {
    $pdo->prepare('REPLACE INTO parametres (cle, valeur) VALUES (?, ?)')->execute(['nom_client', $options['nom']]);
}

// Compte administrateur du client
$motDePasseAdmin = rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'Kx'), '=');
$pdo->prepare('INSERT INTO utilisateurs (nom, email, mot_de_passe) VALUES (?, ?, ?)')->execute([
    'Administrateur',
    $options['email'],
    password_hash($motDePasseAdmin, PASSWORD_DEFAULT),
]);
// L'administrateur est propriétaire : tous les droits, dans tous les établissements
$pdo->prepare("INSERT INTO utilisateur_roles (utilisateur_id, role_id) SELECT ?, id FROM roles WHERE code = 'proprietaire'")
    ->execute([(int) $pdo->lastInsertId()]);
// Employés de démonstration (serveurs, barmen, gérant, comptable…), après l'administrateur
if (isset($options['demo']) && is_file(RACINE . '/database/demo-utilisateurs.sql')) {
    Migrations::executerFichier($pdo, RACINE . '/database/demo-utilisateurs.sql');
}

$contenu = "<?php\n\n// Instance « $sousDomaine », créée le " . date('Y-m-d H:i') . " par bin/creer-instance.php\nreturn "
    . var_export(['actif' => true, 'db' => $configDb], true) . ";\n";
if (file_put_contents($fichierConfig, $contenu) === false) {
    arreter("Impossible d'écrire $fichierConfig");
}
chmod($fichierConfig, 0600);

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
