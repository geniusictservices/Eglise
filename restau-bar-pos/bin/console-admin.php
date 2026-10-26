<?php

declare(strict_types=1);

/**
 * Console super admin en ligne de commande (base dans config/console.php, voir config/console.php.dist).
 *
 *   php bin/console-admin.php installer                       tables de la console (database/console/schema.sql)
 *                                                             puis ses migrations ; la base doit exister et être vide
 *   php bin/console-admin.php migrer                          migrations en attente (database/console/migrations)
 *   php bin/console-admin.php admin --email=moi@exemple.com [--nom="Prénom Nom"] [--mot-de-passe=...]
 *                                                             crée un super admin, ou change son mot de passe
 *                                                             (12 caractères au moins ; tiré au hasard et affiché si absent)
 *   php bin/console-admin.php desactiver --email=moi@exemple.com
 *
 * Sans accès SSH (hébergement mutualisé), la page https://admin.<domaine>/installation fait la même chose
 * que « installer » et « admin », avec le jeton_installation de config/console.php.
 */

use App\Console\Console;
use App\Core\BaseConsole;

if (PHP_SAPI !== 'cli') {
    exit("À lancer en ligne de commande.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

function arreter(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$commande = $argv[1] ?? null;
$options = [];
foreach (array_slice($argv, 2) as $argument) {
    if (!preg_match('/^--([a-z-]+)=(.*)$/s', $argument, $m)) {
        arreter("Argument inattendu : $argument");
    }
    $options[$m[1]] = $m[2];
}
if (!in_array($commande, ['installer', 'migrer', 'admin', 'desactiver'], true)) {
    arreter("Usage : php bin/console-admin.php installer | migrer | admin --email=… [--nom=…] [--mot-de-passe=…] | desactiver --email=…");
}
if (!BaseConsole::configuree()) {
    arreter('Console non configurée : copiez config/console.php.dist en config/console.php et renseignez la base (' . BaseConsole::fichier() . ').');
}
date_default_timezone_set(App\Core\Config::get('fuseau_horaire'));
App\Core\I18n::initialiser('fr');
try {
    $pdo = BaseConsole::pdo();
} catch (Throwable $e) {
    arreter('Base de la console injoignable : ' . $e->getMessage());
}

switch ($commande) {
    case 'installer':
        try {
            $appliquees = Console::installerTables();
        } catch (RuntimeException $e) {
            arreter($e->getMessage());
        }
        echo "Tables de la console installées" . ($appliquees ? ' (migrations : ' . implode(', ', $appliquees) . ')' : '') . ".\n"
            . "Créez ensuite le premier super admin : php bin/console-admin.php admin --email=…\n";
        break;

    case 'migrer':
        $appliquees = Console::migrer();
        echo 'Console : ' . ($appliquees ? implode(', ', $appliquees) : 'à jour') . "\n";
        break;

    case 'admin':
        $email = strtolower(trim($options['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            arreter('Indiquez une adresse e-mail valide : --email=moi@exemple.com');
        }
        $motDePasse = $options['mot-de-passe'] ?? App\Core\Installation::motDePasse(16);
        if (($erreur = Console::erreurMotDePasse($motDePasse)) !== null) {
            arreter($erreur);
        }
        $existait = Console::enregistrerAdmin($email, $options['nom'] ?? null, $motDePasse);
        echo ($existait ? "Mot de passe changé pour $email.\n" : "Super admin créé : $email\n")
            . (isset($options['mot-de-passe']) ? '' : "Mot de passe : $motDePasse   (affiché une seule fois)\n");
        break;

    case 'desactiver':
        $n = BaseConsole::executer('UPDATE super_admins SET actif = 0 WHERE email = ?', [strtolower(trim($options['email'] ?? ''))]);
        echo $n ? "Compte désactivé.\n" : "Aucun compte avec cette adresse.\n";
        break;
}
