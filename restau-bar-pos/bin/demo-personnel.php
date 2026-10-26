<?php

declare(strict_types=1);

/**
 * Personnel et paie de démonstration (idempotent : relancer ne crée pas de doublon) :
 *  - position GPS des deux établissements de Goma (Comptoir du Lac au bord du lac Kivu, Volcano Club en ville),
 *    si elle n'est pas déjà réglée ;
 *  - une fiche employé pour chaque employé de démonstration (pas l'administrateur), et deux personnes sans compte
 *    au Volcano Club (agent de sécurité, hôtesse) avec un PIN de pointage ;
 *  - les plannings de la semaine en cours et de la semaine précédente, et les pointages des créneaux passés
 *    (quelques retards, une absence non justifiée, une absence justifiée, un pointage de secours) ;
 *  - une session de caisse clôturée avec un manquant (Esther), une avance (Esther) ;
 *  - le bulletin du mois dernier de Patrick, validé et payé (Rawbank en dollars, caisse en francs),
 *    et le brouillon du mois en cours d'Esther avec les retenues proposées (avance, dette du personnel, manquant).
 *
 *   php bin/demo-personnel.php demo
 */

use App\Core\Config;
use App\Core\Database;
use App\Core\I18n;
use App\Core\Instance;
use App\Repositories\EtablissementRepository;
use App\Repositories\GestionTresorerieRepository;
use App\Repositories\PaieRepository;
use App\Repositories\PersonnelRepository;
use App\Repositories\PlanningRepository;
use App\Repositories\SessionCaisseRepository;

if (PHP_SAPI !== 'cli') {
    exit("À lancer en ligne de commande.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

function arreter(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$instance = $argv[1] ?? null;
if ($instance === null || Instance::charger($instance) === null) {
    arreter('Usage : php bin/demo-personnel.php <sous-domaine>');
}
$parametres = array_column(Database::tous('SELECT cle, valeur FROM parametres'), 'valeur', 'cle');
date_default_timezone_set($parametres['fuseau_horaire'] ?? Config::get('fuseau_horaire'));
Database::executer('SET time_zone = ?', [date('P')]);
I18n::initialiser('fr');
mt_srand(20261026);

if (Database::un("SHOW TABLES LIKE 'employes'") === null) {
    arreter('Migration 018 absente : php bin/migrer.php ' . $instance);
}

$etabs = [];
foreach ((new EtablissementRepository())->actifs() as $e) {
    $etabs[$e['slug']] = $e;
}
$comptoir = $etabs['comptoir-du-lac'] ?? null;
$club = $etabs['volcano-club'] ?? null;
if ($comptoir === null || $club === null) {
    arreter('Établissements de démonstration introuvables (comptoir-du-lac, volcano-club).');
}
$admin = (int) (Database::un("SELECT u.id FROM utilisateurs u JOIN utilisateur_roles ur ON ur.utilisateur_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.code = 'proprietaire' ORDER BY u.id LIMIT 1")['id'] ?? 1);
$devises = (new GestionTresorerieRepository())->devises();
$usd = (int) (Database::un("SELECT id FROM devises WHERE code = 'USD'")['id'] ?? 0);
$cdf = (int) (Database::un("SELECT id FROM devises WHERE code = 'CDF'")['id'] ?? 0);

// ---------- Position des établissements (Goma) ----------

$positions = [
    // Au bord du lac Kivu (Himbi), et en ville (boulevard Kanyamuhanga)
    (int) $comptoir['id'] => [-1.691230, 29.234610, 100],
    (int) $club['id'] => [-1.676890, 29.225470, 80],
];
foreach ($positions as $id => [$lat, $lng, $rayon]) {
    Database::executer('UPDATE etablissements SET latitude = ?, longitude = ?, pointage_rayon = ? WHERE id = ? AND latitude IS NULL', [$lat, $lng, $rayon, $id]);
}
$comptoir = (new EtablissementRepository())->parId((int) $comptoir['id']);
$club = (new EtablissementRepository())->parId((int) $club['id']);

// ---------- Employés ----------

$personnel = new PersonnelRepository();
// téléphone du compte => [poste, établissement principal, autres, contrat, période, salaires, commission, pourboires, entrée]
$fiches = [
    '+243810000101' => ['Gérant', $comptoir, [], 'cdi', 'mois', [$usd => 450, $cdf => 150000], null, false, '2024-03-01'],
    '+243810000102' => ['Comptable', $comptoir, [$club], 'cdi', 'mois', [$usd => 400], null, false, '2024-06-15'],
    '+243810000103' => ['Serveuse', $comptoir, [], 'cdi', 'mois', [$usd => 120, $cdf => 80000], 2.0, true, '2025-01-10'],
    '+243810000104' => ['Caissière', $comptoir, [], 'cdd', 'mois', [$usd => 150, $cdf => 100000], null, false, '2025-04-01'],
    '+243810000105' => ['Barman', $club, [], 'cdi', 'mois', [$usd => 180], null, true, '2024-09-01'],
    '+243810000106' => ['Serveur', $club, [], 'cdd', 'mois', [$usd => 100, $cdf => 60000], 1.5, true, '2025-07-01'],
    '+243810000107' => ['Superviseure', $club, [], 'cdi', 'mois', [$usd => 300], null, false, '2024-02-01'],
];
$employes = [];
foreach ($fiches as $telephone => [$poste, $etab, $autres, $contrat, $periode, $salaires, $commission, $pourboires, $debut]) {
    $u = Database::un('SELECT id, nom FROM utilisateurs WHERE telephone = ? AND actif = 1', [$telephone]);
    if ($u === null) {
        continue;
    }
    $existant = $personnel->parUtilisateur((int) $u['id']);
    $id = $existant ? (int) $existant['id'] : $personnel->enregistrer(null, [
        'utilisateur_id' => (int) $u['id'], 'nom' => $u['nom'], 'telephone' => null, 'poste' => $poste, 'etablissement_id' => (int) $etab['id'],
        'etablissements' => array_map(static fn ($e) => (int) $e['id'], $autres), 'contrat' => $contrat, 'debut' => $debut,
        'fin' => $contrat === 'cdd' ? date('Y-12-31') : null, 'periode' => $periode, 'commission_pct' => $commission, 'pourboires' => $pourboires,
        'notes' => null, 'salaires' => array_filter($salaires, static fn ($m, $d) => $d > 0, ARRAY_FILTER_USE_BOTH),
    ], $admin);
    $employes[$telephone] = $personnel->employe($id);
}
// Sans compte : ils pointent avec leur téléphone et un PIN de pointage (ou au secours, à la caisse)
$sansCompte = [
    '+243990000201' => ['Baraka Mutombo', 'Agent de sécurité', [$usd => 15], '258046', 'Sait lire l\'heure mais n\'a pas de smartphone : pointage de secours à la caisse.'],
    '+243990000202' => ['Neema Furaha', 'Hôtesse', [$cdf => 25000], '371946', null],
];
foreach ($sansCompte as $telephone => [$nom, $poste, $salaires, $pin, $notes]) {
    $existant = Database::un('SELECT id FROM employes WHERE telephone = ?', [$telephone]);
    if ($existant === null) {
        $id = $personnel->enregistrer(null, [
            'utilisateur_id' => null, 'nom' => $nom, 'telephone' => $telephone, 'poste' => $poste, 'etablissement_id' => (int) $club['id'], 'etablissements' => [],
            'contrat' => 'journalier', 'debut' => '2025-11-01', 'fin' => null, 'periode' => 'soiree', 'commission_pct' => null, 'pourboires' => false,
            'notes' => $notes, 'salaires' => $salaires,
        ], $admin);
        $personnel->definirPinPointage($id, $pin, $admin);
    } else {
        $id = (int) $existant['id'];
    }
    $employes[$telephone] = $personnel->employe($id);
}
echo count($employes) . " employés.\n";

// ---------- Plannings (semaine en cours et précédente) ----------

$plannings = new PlanningRepository();
[$lundi] = PaieRepository::semaine(date('Y-m-d'));
$semaines = [date('Y-m-d', strtotime($lundi . ' -7 days')), $lundi];
// téléphone => [établissement, jours (1 = lundi), début, fin, poste, service, pause]
$horaires = [
    '+243810000101' => [$comptoir, [1, 2, 3, 4, 5, 6], '10:00', '18:00', 'direction', 'Restaurant', 30],
    '+243810000102' => [$comptoir, [1, 2, 3, 4, 5], '09:00', '16:00', 'direction', null, 30],
    '+243810000103' => [$comptoir, [2, 3, 4, 5, 6, 7], '17:00', '23:59', 'salle', 'Bar & terrasse', 0],
    '+243810000104' => [$comptoir, [1, 2, 3, 4, 5, 6], '11:00', '19:00', 'caisse', 'Restaurant', 30],
    '+243810000105' => [$club, [4, 5, 6], '21:30', '04:30', 'bar', 'Club', 0],
    '+243810000106' => [$club, [4, 5, 6], '22:00', '04:00', 'salle', 'Club', 0],
    '+243810000107' => [$club, [4, 5, 6], '21:00', '05:00', 'direction', 'Club', 0],
    '+243990000201' => [$club, [4, 5, 6], '21:30', '05:00', 'securite', 'Club', 0],
    '+243990000202' => [$club, [4, 5, 6], '22:00', '03:00', 'hotesse', 'Club', 0],
];
$creneaux = 0;
foreach ($semaines as $semaine) {
    foreach ($horaires as $telephone => [$etab, $jours, $debut, $fin, $poste, $service, $pause]) {
        $employe = $employes[$telephone] ?? null;
        if ($employe === null || $plannings->creneaux((int) $etab['id'], $semaine, date('Y-m-d', strtotime($semaine . ' +6 days')), [(int) $employe['id']]) !== []) {
            continue;
        }
        $journees = array_map(static fn (int $j) => date('Y-m-d', strtotime($semaine . ' +' . ($j - 1) . ' days')), $jours);
        $creneaux += $plannings->ajouter((int) $etab['id'], (int) $employe['id'], $journees, $debut, $fin, $service, $poste, $pause, null, $admin)['ajoutes'];
    }
}
echo "$creneaux créneaux ajoutés.\n";

// ---------- Pointages des créneaux passés ----------

$patrick = $employes['+243810000101'] ?? null;
$maintenant = time();
$aline = $employes['+243810000103'] ?? null;
$christian = $employes['+243810000106'] ?? null;
$baraka = $employes['+243990000201'] ?? null;
// Absence justifiée (maladie) d'Aline le mardi de la semaine dernière, acceptée par Patrick
$mardiDernier = date('Y-m-d', strtotime($semaines[0] . ' +1 day'));
if ($aline && Database::un("SELECT id FROM absences WHERE employe_id = ? AND debut = ?", [(int) $aline['id'], $mardiDernier]) === null) {
    $id = $plannings->demanderAbsence((int) $aline['id'], 'maladie', $mardiDernier, $mardiDernier, 'Paludisme, certificat du centre de santé de Himbi.', $patrick ? (int) $patrick['utilisateur_id'] : $admin);
    $plannings->deciderAbsence($id, true, null, $patrick ? (int) $patrick['utilisateur_id'] : $admin);
}
// Une demande de congé en attente (Esther, la semaine prochaine)
$esther = $employes['+243810000104'] ?? null;
$prochain = date('Y-m-d', strtotime($lundi . ' +7 days'));
if ($esther && Database::un("SELECT id FROM absences WHERE employe_id = ? AND debut = ?", [(int) $esther['id'], $prochain]) === null) {
    $plannings->demanderAbsence((int) $esther['id'], 'conge', $prochain, date('Y-m-d', strtotime($prochain . ' +2 days')), 'Mariage de sa sœur à Bukavu.', $admin);
}
$pointages = 0;
$absenceChristian = date('Y-m-d', strtotime($semaines[0] . ' +4 days')); // vendredi dernier
foreach ($plannings->creneaux(null, $semaines[0], date('Y-m-d', strtotime($lundi . ' +6 days'))) as $c) {
    $employe = null;
    foreach ($employes as $e) {
        if ((int) $e['id'] === (int) $c['employe_id']) {
            $employe = $e;
        }
    }
    if ($employe === null || strtotime($c['debut']) > $maintenant
        || Database::un('SELECT id FROM pointages WHERE employe_id = ? AND journee = ?', [(int) $employe['id'], $c['journee']]) !== null) {
        continue;
    }
    if (($aline && (int) $employe['id'] === (int) $aline['id'] && $c['journee'] === $mardiDernier)
        || ($christian && (int) $employe['id'] === (int) $christian['id'] && $c['journee'] === $absenceChristian)) {
        continue; // absence (justifiée pour Aline, non justifiée pour Christian)
    }
    $tirage = mt_rand(1, 100);
    $retard = $tirage <= 14 ? mt_rand(12, 38) : mt_rand(-12, 3);
    $arrivee = strtotime($c['debut']) + $retard * 60;
    $finPrevue = strtotime($c['fin']);
    $depart = $finPrevue + ($tirage > 90 ? mt_rand(35, 80) : mt_rand(-4, 15)) * 60;
    $etab = (int) $c['etablissement_id'] === (int) $comptoir['id'] ? $comptoir : $club;
    $secours = $baraka && (int) $employe['id'] === (int) $baraka['id'];
    $distance = $secours ? null : mt_rand(4, 65);
    // Position plausible : quelques mètres autour de l'établissement
    $lat = $secours ? null : (float) $etab['latitude'] + mt_rand(-40, 40) / 100000;
    $lng = $secours ? null : (float) $etab['longitude'] + mt_rand(-40, 40) / 100000;
    $source = $secours ? 'secours' : 'qr';
    $valide = $secours ? (int) ($employes['+243810000107']['utilisateur_id'] ?? $admin) : null;
    $enCours = $finPrevue > $maintenant;
    Database::executer(
        'INSERT INTO pointages (employe_id, etablissement_id, journee, arrivee, depart, arrivee_source, depart_source, arrivee_lat, arrivee_lng, arrivee_precision,
                                arrivee_distance, depart_lat, depart_lng, depart_precision, depart_distance, arrivee_valide_par, depart_valide_par)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [(int) $employe['id'], (int) $etab['id'], $c['journee'], date('Y-m-d H:i:s', $arrivee), $enCours ? null : date('Y-m-d H:i:s', $depart),
            $source, $enCours ? null : $source, $lat, $lng, $secours ? null : mt_rand(6, 25), $distance,
            $enCours ? null : $lat, $enCours ? null : $lng, $enCours || $secours ? null : mt_rand(6, 25), $enCours ? null : $distance, $valide, $enCours ? null : $valide]
    );
    $pointages++;
}
echo "$pointages pointages ajoutés.\n";

// ---------- Manquant de caisse (Esther, mercredi dernier) ----------

$sessions = new SessionCaisseRepository();
if ($esther && $esther['utilisateur_id'] && Database::un("SELECT id FROM sessions_caisse WHERE note = 'Démo personnel : manquant'") === null) {
    $acceptees = array_intersect_key((new App\Repositories\DeviseRepository())->actives(), array_flip((new EtablissementRepository())->deviseIds((int) $comptoir['id'])));
    $caisses = $sessions->caissesACompter((int) $comptoir['id'], $acceptees);
    if ($caisses !== [] && $sessions->ouverte((int) $comptoir['id'], (int) $esther['utilisateur_id']) === null) {
        $fonds = [];
        $comptes = [];
        foreach ($caisses as $c) {
            $fonds[$c['compte_id']][$c['devise_id']] = $c['devise_id'] === $cdf ? 50000.0 : 20.0;
            // Il manque 5 000 FC à la clôture
            $comptes[$c['compte_id']][$c['devise_id']] = $c['devise_id'] === $cdf ? 45000.0 : 20.0;
        }
        $id = $sessions->ouvrir((int) $comptoir['id'], (int) $esther['utilisateur_id'], null, $caisses, $fonds);
        $sessions->cloturer($id, $comptes, (int) $esther['utilisateur_id'], 'Démo personnel : manquant');
        $mercredi = date('Y-m-d', strtotime($semaines[0] . ' +2 days'));
        Database::executer('UPDATE sessions_caisse SET ouverte_le = ?, fermee_le = ? WHERE id = ?', [$mercredi . ' 11:02:00', $mercredi . ' 19:05:00', $id]);
        Database::executer("UPDATE mouvements_tresorerie SET date_operation = ? WHERE nature = 'ajustement' AND source_id = ?", [$mercredi . ' 19:05:00', $id]);
        echo "Session de caisse avec un manquant de 5 000 FC.\n";
    }
}

// ---------- Avance (Esther) ----------

$paie = new PaieRepository();
$tresorerie = $paie->tresorerie();
$comptes = $tresorerie->comptesActifs();
$caisseComptoir = null;
$rawbank = null;
foreach ($comptes as $c) {
    if ($c['type'] === 'caisse' && (int) $c['etablissement_id'] === (int) $comptoir['id'] && GestionTresorerieRepository::tient($c, $cdf)) {
        $caisseComptoir ??= $c;
    }
    if ($c['type'] === 'banque' && GestionTresorerieRepository::tient($c, $usd)) {
        $rawbank ??= $c;
    }
}
$caisseComptoir ??= $rawbank;
if ($esther && $caisseComptoir && Database::un("SELECT id FROM avances WHERE employe_id = ? AND motif = 'Frais médicaux de son fils'", [(int) $esther['id']]) === null) {
    $lignes = [['compte' => $caisseComptoir, 'devise' => $devises[$usd], 'montant' => 20.0]];
    if (GestionTresorerieRepository::tient($caisseComptoir, $cdf)) {
        $lignes[] = ['compte' => $caisseComptoir, 'devise' => $devises[$cdf], 'montant' => 20000.0];
    }
    $paie->creerAvance($esther, date('Y-m-d 18:30:00', strtotime($semaines[0] . ' +3 days')), 'Frais médicaux de son fils', $lignes, 'Avance sur salaire ' . $esther['nom'], (int) ($patrick['utilisateur_id'] ?? $admin));
    echo "Avance versée à Esther.\n";
}

// ---------- Bulletins ----------

[$moisDernierDebut, $moisDernierFin] = PaieRepository::mois(date('Y-m', strtotime('first day of last month')));
if ($patrick && $paie->chevauchement((int) $patrick['id'], $moisDernierDebut, $moisDernierFin) === null && $rawbank && $caisseComptoir) {
    $id = $paie->preparer($patrick, $moisDernierDebut, $moisDernierFin, $admin);
    $paie->valider($id, $admin);
    $b = $paie->bulletin($id);
    $lignes = [];
    foreach (PaieRepository::resteAPayer($b) as $deviseId => $montant) {
        $compte = $deviseId === $usd ? $rawbank : $caisseComptoir;
        if (GestionTresorerieRepository::tient($compte, $deviseId)) {
            $lignes[] = ['compte' => $compte, 'devise' => $devises[$deviseId], 'montant' => $montant];
        }
    }
    $paie->payer($id, $lignes, $moisDernierFin . ' 17:30:00', 'Virement et espèces', 'Salaire ' . $patrick['nom'] . ' — ' . $moisDernierDebut, $admin);
    Database::executer('UPDATE bulletins SET cree_le = ?, valide_le = ? WHERE id = ?', [$moisDernierFin . ' 15:00:00', $moisDernierFin . ' 16:00:00', $id]);
    echo "Bulletin du mois dernier de Patrick validé et payé.\n";
}
[$moisDebut, $moisFin] = PaieRepository::mois(date('Y-m'));
if ($esther && $paie->chevauchement((int) $esther['id'], $moisDebut, $moisFin) === null) {
    $id = $paie->preparer($esther, $moisDebut, $moisFin, $admin);
    echo "Brouillon du mois d'Esther préparé (retenues proposées).\n";
}
echo "Personnel de démonstration prêt.\n";
