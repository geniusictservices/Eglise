<?php

declare(strict_types=1);

/**
 * Relances automatiques des dettes (cahier des charges, section 7.2), par SMS (Africa's Talking) ou WhatsApp Business.
 * À lancer chaque heure par le planificateur du serveur (cron) :
 *
 *   5 * * * *  php /chemin/restau-bar-pos/bin/relances.php --toutes
 *
 *   php bin/relances.php demo                  une instance
 *   php bin/relances.php --toutes              toutes les instances de config/instances/
 *   php bin/relances.php demo --date=2026-11-02 --maintenant   simuler un jour donné, sans attendre l'heure d'envoi
 *
 * À chaque passage :
 * - garants : la personne désignée garante est prévenue (une fois) qu'elle doit accepter ou refuser ;
 * - à partir de l'heure réglée (relances.heure, 10 h par défaut) : pour chaque dette ouverte qui atteint une
 *   étape (relances.etapes : la veille de l'échéance, puis J+1, J+7, J+30), le client est relancé et, si
 *   relances.responsable vaut 1, le responsable de la dette aussi (le serveur, ou le garant qui a accepté).
 * Chaque message ne part qu'une fois (journal « messages ») ; sans messagerie réglée, il est seulement inscrit (« simule »).
 */

use App\Core\Config;
use App\Core\Database;
use App\Core\I18n;
use App\Core\Instance;
use App\Core\Messagerie;
use App\Core\Parametres;
use App\Repositories\DetteRepository;

if (PHP_SAPI !== 'cli') {
    exit("À lancer en ligne de commande.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

$cible = null;
$date = null;
$maintenant = false;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $argument, $m)) {
        $date = $m[1];
    } elseif ($argument === '--maintenant') {
        $maintenant = true;
    } elseif ($cible === null && ($argument === '--toutes' || !str_starts_with($argument, '--'))) {
        $cible = $argument;
    } else {
        fwrite(STDERR, "Option inconnue : $argument\n");
        exit(1);
    }
}
if ($cible === null) {
    fwrite(STDERR, "Usage : php bin/relances.php <sous-domaine>|--toutes [--date=AAAA-MM-JJ] [--maintenant]\n");
    exit(1);
}
$instances = $cible === '--toutes'
    ? array_map(static fn (string $f) => basename($f, '.php'), glob(RACINE . '/config/instances/*.php') ?: [])
    : [$cible];

/** « 45 000 FC », « $12 » dans la langue du destinataire, avec des espaces simples (un SMS reste en GSM 7 bits). */
function montant(float $montant, array $devise, string $langue): string
{
    $decimales = abs($montant - round($montant)) < 0.005 ? 0 : (int) $devise['decimales'];
    $nombre = $langue === 'fr' ? number_format($montant, $decimales, ',', ' ') : number_format($montant, $decimales, '.', ',');
    return $devise['symbole_avant'] ? $devise['symbole'] . $nombre : $nombre . ' ' . $devise['symbole'];
}

function jour(string $date, string $langue): string
{
    return $langue === 'fr' ? date('d/m/Y', strtotime($date)) : date('M j, Y', strtotime($date));
}

/** Prénom pour un SMS plus chaleureux et plus court : « Esther » plutôt que « Esther Kahindo ». */
function prenom(string $nom): string
{
    return explode(' ', trim($nom))[0] ?: $nom;
}

$statut = 0;
foreach ($instances as $instance) {
    if (Instance::charger($instance) === null) {
        fwrite(STDERR, "Instance inconnue : $instance\n");
        $statut = 1;
        continue;
    }
    Parametres::oublier();
    date_default_timezone_set(Parametres::get('fuseau_horaire') ?? Config::get('fuseau_horaire'));
    Database::executer('SET time_zone = ?', [date('P')]);
    I18n::initialiser('fr');
    $compte = ['envoye' => 0, 'simule' => 0, 'echec' => 0, 'deja' => 0];
    $noter = static function (array $r) use (&$compte): void {
        $compte[$r['statut']]++;
    };

    if (Parametres::get('relances.actives', '1') !== '1') {
        echo "$instance : relances désactivées\n";
        continue;
    }
    $aujourdhui = $date ?? date('Y-m-d');
    $devises = array_column(Database::tous('SELECT * FROM devises'), null, 'id');
    $dettes = new DetteRepository();

    // 1. Garants à prévenir (à toute heure : la réponse est attendue)
    foreach ($dettes->garantiesAAnnoncer() as $d) {
        if (!$d['garant_telephone']) {
            continue;
        }
        $langue = $d['garant_langue'] ?: 'fr';
        $valeurs = [
            'etab' => $d['etablissement_nom'], 'servi' => $d['servi_nom'] ?? '—', 'client' => $d['client_nom'],
            'montant' => montant((float) $d['montant'], $devises[$d['devise_id']], $langue),
        ];
        $noter(Messagerie::envoyer($d['garant_telephone'], I18n::dans($langue, 'msg.garant_demande', $valeurs),
            ['evenement' => 'garant_demande', 'etape' => 'demande', 'dette_id' => (int) $d['id'], 'utilisateur_id' => (int) $d['garant_id'], 'langue' => $langue],
            Messagerie::canaux(), [prenom((string) $d['garant_nom']), $valeurs['servi'], $valeurs['client'], $valeurs['montant']]));
    }

    // 2. Rappels d'échéance, à partir de l'heure d'envoi
    if ($maintenant || $date !== null || (int) date('G') >= (int) Parametres::get('relances.heure', '10')) {
        $etapes = array_values(array_unique(array_map('intval', array_filter(
            explode(',', Parametres::get('relances.etapes', '-1,1,7,30')), static fn (string $e) => preg_match('/^\s*[+-]?\d{1,3}\s*$/', $e) === 1
        ))));
        $responsables = Parametres::get('relances.responsable', '1') === '1';
        foreach ($dettes->aRelancer($aujourdhui, $etapes) as $d) {
            if ($d['client_type'] === 'personnel') {
                continue;   // dette du personnel : retenue sur la paie, pas de relance
            }
            $etape = DetteRepository::libelleEtape((int) $d['etape']);
            $retard = (int) $d['etape'] >= 0;
            $jours = (int) round((strtotime($aujourdhui . ' 12:00') - strtotime($d['echeance'] . ' 12:00')) / 86400);
            if ($d['client_relances'] && $d['client_telephone']) {
                $langue = $d['client_langue'] ?: 'fr';
                $valeurs = [
                    'etab' => $d['etablissement_nom'], 'client' => prenom($d['client_nom']),
                    'reste' => montant((float) $d['reste'], $devises[$d['devise_id']], $langue), 'date' => jour($d['echeance'], $langue), 'jours' => $jours,
                ];
                $noter(Messagerie::envoyer($d['client_telephone'], I18n::dans($langue, 'msg.rappel_client.' . ($retard ? 'retard' : 'avant'), $valeurs),
                    ['evenement' => 'rappel_client', 'etape' => $etape, 'dette_id' => (int) $d['id'], 'client_id' => (int) $d['client_id'], 'langue' => $langue],
                    Messagerie::canaux($d['client_canal']), [$valeurs['client'], $valeurs['etab'], $valeurs['reste'], $valeurs['date']]));
            }
            if ($responsables && $d['responsable_id'] && $d['responsable_telephone']) {
                $langue = $d['responsable_langue'] ?: 'fr';
                $valeurs = [
                    'etab' => $d['etablissement_nom'], 'responsable' => prenom($d['responsable_nom']), 'client' => $d['client_nom'],
                    'reste' => montant((float) $d['reste'], $devises[$d['devise_id']], $langue), 'date' => jour($d['echeance'], $langue), 'jours' => $jours,
                ];
                $noter(Messagerie::envoyer($d['responsable_telephone'], I18n::dans($langue, 'msg.rappel_responsable.' . ($retard ? 'retard' : 'avant'), $valeurs),
                    ['evenement' => 'rappel_responsable', 'etape' => $etape, 'dette_id' => (int) $d['id'], 'utilisateur_id' => (int) $d['responsable_id'], 'langue' => $langue],
                    Messagerie::canaux(), [$valeurs['responsable'], $valeurs['client'], $valeurs['reste'], $valeurs['date']]));
            }
        }
    }
    Parametres::definir('relances.dernier_passage', date('Y-m-d H:i:s'));
    echo sprintf("%s : %d envoyé(s), %d inscrit(s) sans envoi (messagerie non réglée), %d échec(s), %d déjà fait(s)\n",
        $instance, $compte['envoye'], $compte['simule'], $compte['echec'], $compte['deja']);
    if ($compte['echec'] > 0) {
        $statut = 2;
    }
}
exit($statut);
