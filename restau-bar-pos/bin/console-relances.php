<?php

declare(strict_types=1);

/**
 * Relances d'abonnement au propriétaire de chaque bar (SMS et e-mail), avant l'échéance puis en retard.
 * À lancer chaque heure par le planificateur (cron de cPanel) :
 *
 *   20 * * * *  php /home/<compte>/restau-bar-pos/bin/console-relances.php
 *
 *   php bin/console-relances.php                         à partir de l'heure réglée (relances.heure, 9 h)
 *   php bin/console-relances.php --date=2026-11-20       simuler un jour donné (tout de suite)
 *   php bin/console-relances.php --maintenant            sans attendre l'heure d'envoi
 *
 * Voir App\Console\Relances. Code de sortie 2 s'il y a eu des échecs (le planificateur peut prévenir).
 */

use App\Console\Relances;
use App\Core\BaseConsole;
use App\Core\Config;
use App\Core\I18n;

if (PHP_SAPI !== 'cli') {
    exit("À lancer en ligne de commande.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

$date = null;
$maintenant = false;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $argument, $m)) {
        $date = $m[1];
    } elseif ($argument === '--maintenant') {
        $maintenant = true;
    } else {
        fwrite(STDERR, "Option inconnue : $argument\nUsage : php bin/console-relances.php [--date=AAAA-MM-JJ] [--maintenant]\n");
        exit(1);
    }
}
if (!BaseConsole::configuree()) {
    fwrite(STDERR, "Console non configurée (config/console.php).\n");
    exit(1);
}
date_default_timezone_set(Config::get('fuseau_horaire'));
I18n::initialiser('fr');
$compte = Relances::passer($date, $maintenant);
echo sprintf("Console : %d envoyé(s), %d inscrit(s) sans envoi (SMS ou e-mail non réglé), %d échec(s), %d déjà fait(s)\n",
    $compte['envoye'], $compte['simule'], $compte['echec'], $compte['deja']);
exit($compte['echec'] > 0 ? 2 : 0);
