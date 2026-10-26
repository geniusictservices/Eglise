<?php

declare(strict_types=1);

/**
 * Salle et cuisine de démonstration (on peut le relancer : rien n'est créé deux fois) :
 * - plan de salle : « Le Comptoir du Lac » (Salle, Terrasse) et « Volcano Club » (Carré VIP, Bar) ;
 * - postes de cuisine (Grill, Friteuse, Bar à cocktails ; Cuisine snack et Bar VIP au club) et leur routage ;
 * - services : réglages des services des horaires, un service « Lounge » (jeudi à samedi, 18 h – 22 h) ajouté
 *   au club avant le « Club », où la cuisine est fermée (« À grignoter » disparaît de la caisse) et en mode
 *   comptoir rapide ; fin de la journée d'exploitation à 7 h au club ; happy hour au club (18 h – 20 h) avec une
 *   grille « Happy hour » ;
 * - quelques notes ouvertes avec des plats en cuisine (reçu, en préparation, prêt, à lancer), une addition
 *   demandée, une note nommée « Bar — Patrick » (seulement s'il n'y a aucune note ouverte) ;
 * - des réservations pour ce soir (seulement s'il n'y en a pas encore pour la journée).
 *
 *   php bin/demo-salle.php demo
 */

use App\Controllers\CaisseController;
use App\Core\Config;
use App\Core\Database;
use App\Core\I18n;
use App\Core\Instance;
use App\Core\Journee;
use App\Repositories\CuisineRepository;
use App\Repositories\EtablissementRepository;
use App\Repositories\NoteRepository;
use App\Repositories\ServiceRepository;

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
if ($instance === null || str_starts_with($instance, '--')) {
    arreter('Usage : php bin/demo-salle.php <sous-domaine>');
}
if (Instance::charger($instance) === null) {
    arreter("Instance inconnue : $instance");
}
$parametres = array_column(Database::tous('SELECT cle, valeur FROM parametres'), 'valeur', 'cle');
date_default_timezone_set($parametres['fuseau_horaire'] ?? Config::get('fuseau_horaire'));
Database::executer('SET time_zone = ?', [date('P')]);
I18n::initialiser('fr');

if (Database::un("SHOW TABLES LIKE 'salle_tables'") === null) {
    arreter('Migration 019 manquante : php bin/migrer.php ' . $instance);
}

$etablissements = new EtablissementRepository();
$lac = $etablissements->parSlug('comptoir-du-lac');
$club = $etablissements->parSlug('volcano-club');
if ($lac === null || $club === null) {
    echo "Établissements de démonstration absents : rien à faire.\n";
    exit(0);
}
$utilisateur = static fn (string $nom): ?int => ($u = Database::un('SELECT id FROM utilisateurs WHERE nom = ? AND actif = 1', [$nom])) ? (int) $u['id'] : null;
$admin = (int) (Database::un('SELECT u.id FROM utilisateurs u JOIN utilisateur_roles ur ON ur.utilisateur_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.code = ? ORDER BY u.id LIMIT 1', ['proprietaire'])['id'] ?? 1);
$aline = $utilisateur('Aline Mukeba') ?? $admin;
$esther = $utilisateur('Esther Kahindo') ?? $admin;
$christian = $utilisateur('Christian Mugisho') ?? $admin;
$jeanPaul = $utilisateur('Jean-Paul Bahati') ?? $admin;
$compte = ['tables' => 0, 'postes' => 0, 'notes' => 0, 'reservations' => 0];

// ---------- Zones et tables ----------

/** Zone de l'établissement (créée si besoin). */
$zone = static function (array $etab, string $code, string $fr, string $en): int {
    $z = Database::un('SELECT id FROM zones WHERE etablissement_id = ? AND code = ?', [(int) $etab['id'], $code]);
    if ($z !== null) {
        return (int) $z['id'];
    }
    Database::executer('INSERT INTO zones (etablissement_id, code, nom_fr, nom_en) VALUES (?, ?, ?, ?)', [(int) $etab['id'], $code, $fr, $en]);
    return Database::dernierId();
};
$table = static function (array $etab, int $zoneId, string $nom, int $places, string $forme, int $x, int $y, int $l, int $h) use (&$compte): int {
    $t = Database::un('SELECT id FROM salle_tables WHERE etablissement_id = ? AND nom = ? AND actif = 1', [(int) $etab['id'], $nom]);
    if ($t !== null) {
        return (int) $t['id'];
    }
    Database::executer(
        'INSERT INTO salle_tables (etablissement_id, zone_id, nom, places, forme, x, y, largeur, hauteur) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [(int) $etab['id'], $zoneId, $nom, $places, $forme, $x, $y, $l, $h]
    );
    $compte['tables']++;
    return Database::dernierId();
};

$salle = $zone($lac, 'salle', 'Salle', 'Dining room');
$terrasse = $zone($lac, 'terrasse', 'Terrasse', 'Terrace');
$vip = $zone($club, 'vip', 'Carré VIP', 'VIP area');
$bar = $zone($club, 'bar', 'Bar', 'Bar');
$tables = [];
// Comptoir du Lac, salle : deux rangées de tables, une grande table familiale
foreach ([['T1', 2, 'carree', 1, 1, 3, 3], ['T2', 2, 'carree', 6, 1, 3, 3], ['T3', 4, 'ronde', 11, 1, 3, 3], ['T4', 4, 'ronde', 16, 1, 3, 3],
          ['T5', 8, 'rectangle', 1, 7, 7, 3], ['T6', 4, 'carree', 11, 7, 3, 3], ['T7', 6, 'banquette', 16, 7, 6, 3]] as $t) {
    $tables[$t[0]] = $table($lac, $salle, ...$t);
}
// Terrasse face au lac
foreach ([['T8', 4, 'ronde', 1, 2, 3, 3], ['T9', 4, 'ronde', 6, 2, 3, 3], ['T10', 2, 'ronde', 11, 2, 3, 3], ['T11', 6, 'rectangle', 16, 2, 6, 3],
          ['T12', 4, 'carree', 1, 9, 3, 3], ['T13', 4, 'carree', 6, 9, 3, 3]] as $t) {
    $tables[$t[0]] = $table($lac, $terrasse, ...$t);
}
// Volcano Club : banquettes du Carré VIP, tabourets du bar
foreach ([['VIP 1', 10, 'banquette', 1, 1, 7, 4], ['VIP 2', 8, 'banquette', 9, 1, 6, 4], ['VIP 3', 8, 'banquette', 16, 1, 6, 4], ['VIP 4', 6, 'banquette', 1, 9, 6, 4]] as $t) {
    $tables[$t[0]] = $table($club, $vip, ...$t);
}
foreach ([['Bar 1', 2, 'ronde', 1, 2, 3, 3], ['Bar 2', 2, 'ronde', 5, 2, 3, 3], ['Bar 3', 2, 'ronde', 9, 2, 3, 3], ['Bar 4', 2, 'ronde', 13, 2, 3, 3],
          ['Haute 1', 4, 'carree', 3, 8, 3, 3], ['Haute 2', 4, 'carree', 9, 8, 3, 3]] as $t) {
    $tables[$t[0]] = $table($club, $bar, ...$t);
}

// ---------- Postes de cuisine et routage ----------

$categorie = static fn (string $nom): ?int => ($c = Database::un('SELECT id FROM categories WHERE nom_fr = ? AND parent_id IS NULL', [$nom])) ? (int) $c['id'] : null;
$poste = static function (array $etab, string $nom, int $alerte) use (&$compte): int {
    $p = Database::un('SELECT id FROM postes_cuisine WHERE etablissement_id = ? AND nom = ?', [(int) $etab['id'], $nom]);
    if ($p !== null) {
        return (int) $p['id'];
    }
    $compte['postes']++;
    return (new CuisineRepository())->creerPoste((int) $etab['id'], $nom, null, $alerte);
};
$grill = $poste($lac, 'Grill', 20);
$friteuse = $poste($lac, 'Friteuse', 10);
$cocktails = $poste($lac, 'Bar à cocktails', 8);
$snack = $poste($club, 'Cuisine snack', 12);
$barVip = $poste($club, 'Bar VIP', 8);
// Postes rattachés aux comptoirs du module stock (s'il est installé) : Grill → Cuisine, Bar VIP → Bar VIP…
$rattacher = static function (int $posteId, array $etab, string $comptoir): void {
    try {
        $lieu = Database::un("SELECT id FROM lieux_stock WHERE etablissement_id = ? AND type = 'comptoir' AND nom = ?", [(int) $etab['id'], $comptoir]);
        if ($lieu !== null) {
            Database::executer('UPDATE postes_cuisine SET lieu_id = ? WHERE id = ? AND lieu_id IS NULL', [(int) $lieu['id'], $posteId]);
        }
    } catch (\Throwable) {
        // pas de module stock
    }
};
$rattacher($grill, $lac, 'Cuisine');
$rattacher($friteuse, $lac, 'Cuisine');
$rattacher($snack, $club, 'Cuisine du club');
$rattacher($barVip, $club, 'Bar VIP');
$router = static function (array $etab, array $routage): void {
    foreach ($routage as $cat => $posteId) {
        if ($cat !== null) {
            Database::executer('INSERT IGNORE INTO cuisine_routage (etablissement_id, categorie_id, poste_id) VALUES (?, ?, ?)', [(int) $etab['id'], $cat, $posteId]);
        }
    }
};
$plats = $categorie('Plats');
$grignoter = $categorie('À grignoter');
$catCocktails = $categorie('Cocktails');
$router($lac, [$plats => $grill, $grignoter => $friteuse, $catCocktails => $cocktails]);
$router($club, [$grignoter => $snack, $catCocktails => $barVip]);

// ---------- Services horaires, happy hour, journée d'exploitation ----------

$services = new ServiceRepository();
$regler = static function (array $etab, string $nom, ?int $grille, array $modules, array $categories) use ($services): void {
    if (Database::un('SELECT id FROM services_horaires WHERE etablissement_id = ? AND nom_fr = ?', [(int) $etab['id'], $nom]) === null) {
        $services->enregistrer((int) $etab['id'], $nom, $grille, $modules, array_values(array_filter($categories)));
    }
};
$tout = ['tables' => true, 'cuisine' => true, 'partage' => true, 'rapide' => false];
$regler($lac, 'Restaurant', null, $tout, []);
$regler($lac, 'Bar & terrasse', null, $tout, []);
$regler($lac, 'Brunch', null, $tout, []);
// Le club : un Lounge avant le Club (mêmes nuits, de 18 h à 22 h), ajouté aux horaires s'il n'y est pas
if (Database::un("SELECT id FROM horaires WHERE etablissement_id = ? AND service_fr = 'Lounge'", [(int) $club['id']]) === null) {
    foreach ([4, 5, 6] as $jour) {
        Database::executer(
            "INSERT INTO horaires (etablissement_id, service_fr, service_en, jour, ouverture, fermeture) VALUES (?, 'Lounge', 'Lounge', ?, '18:00:00', '22:00:00')",
            [(int) $club['id'], $jour]
        );
    }
}
$regler($club, 'Lounge', null, ['tables' => true, 'cuisine' => true, 'partage' => true, 'rapide' => false], []);
// Club : cuisine fermée, les « À grignoter » disparaissent de la caisse ; mode comptoir rapide, tables du Carré VIP
$autres = array_map('intval', array_column(Database::tous('SELECT id FROM categories WHERE parent_id IS NULL AND id <> ? ORDER BY position', [(int) $grignoter]), 'id'));
$regler($club, 'Club', null, ['tables' => true, 'cuisine' => false, 'partage' => false, 'rapide' => true], $autres);
Database::executer("UPDATE etablissements SET fin_journee = '07:00:00' WHERE id = ? AND fin_journee = '05:00:00'", [(int) $club['id']]);

// Grille « Happy hour » : cocktails et bières moins chers (prix en dollars, les francs sont convertis)
$happy = Database::un("SELECT id FROM grilles_prix WHERE code = 'happy'");
if ($happy === null) {
    Database::executer("INSERT INTO grilles_prix (code, nom_fr, nom_en) VALUES ('happy', 'Happy hour', 'Happy hour')");
    $happyId = Database::dernierId();
    $usd = (int) Database::un("SELECT id FROM devises WHERE code = 'USD'")['id'];
    foreach (['Mojito' => 5, 'Kivu Sunset' => 6, 'Primus Grande' => 1.5, 'Mützig Grande' => 1.5, 'Heineken' => 2] as $nom => $montant) {
        foreach (Database::tous('SELECT c.id FROM conditionnements c JOIN produits p ON p.id = c.produit_id WHERE p.nom_fr = ? AND c.quantite_base = 1 AND c.vendable = 1', [$nom]) as $c) {
            Database::executer('INSERT IGNORE INTO prix (conditionnement_id, grille_prix_id, devise_id, montant) VALUES (?, ?, ?, ?)', [(int) $c['id'], $happyId, $usd, $montant]);
        }
    }
} else {
    $happyId = (int) $happy['id'];
}
if (Database::un('SELECT id FROM happy_hours WHERE etablissement_id = ?', [(int) $club['id']]) === null) {
    Database::executer(
        "INSERT INTO happy_hours (etablissement_id, nom_fr, nom_en, jours, debut, fin, grille_prix_id) VALUES (?, 'Happy hour', 'Happy hour', '4,5,6', '18:00:00', '20:00:00', ?)",
        [(int) $club['id'], $happyId]
    );
}

// ---------- Notes ouvertes et commandes en cuisine ----------

$notes = new NoteRepository();
$caisse = new CaisseController();
/** Lignes d'une note : [nom du produit, nom du format ou null, quantité, précision, allergie, temps, poste, statut, minutes depuis l'envoi] */
$ouvrir = static function (array $etab, ?int $tableId, ?string $nom, ?int $zoneId, int $serveur, int $depuis, array $lignes, bool $addition = false) use ($notes, $caisse, &$compte): void {
    $grille = (new ServiceRepository())->grille($etab, $zoneId ? Database::un('SELECT * FROM zones WHERE id = ?', [$zoneId]) : null)['grille'];
    $formats = $caisse->formatsDeGrille($etab, $grille);
    $principale = CaisseController::devisesCaisse($etab)['principale']['code'];
    $ouverte = date('Y-m-d H:i:s', time() - $depuis * 60);
    $id = $notes->creer(['etablissement_id' => (int) $etab['id'], 'table_id' => $tableId, 'nom' => $nom, 'zone_id' => $zoneId, 'serveur_id' => $serveur]);
    Database::executer('UPDATE notes SET ouverte_le = ? WHERE id = ?', [$ouverte, $id]);
    foreach ($lignes as [$produit, $format, $qte, $remarque, $allergie, $temps, $posteId, $statut, $minutes]) {
        $f = null;
        foreach ($formats as $candidat) {
            $libelle = $candidat['format']['taille'] !== '' ? $candidat['produit']['nom'] . ' ' . $candidat['format']['taille'] : $candidat['produit']['nom'];
            if (str_starts_with($libelle, $produit) && ($format === null || $candidat['format']['nom'] === $format)) {
                $f = $candidat;
                break;
            }
        }
        if ($f === null || ($f['format']['prix'][$principale] ?? null) === null) {
            continue;
        }
        $notes->ajouter($id, [[
            'produit_id' => (int) $f['format']['tailleId'], 'conditionnement_id' => (int) $f['format']['id'],
            'categorie_id' => (int) ($f['produit']['sous'] ?: $f['produit']['categorie']),
            'libelle' => $f['format']['taille'] !== '' ? $f['produit']['nom'] . ' ' . $f['format']['taille'] : $f['produit']['nom'],
            'format' => (!$f['format']['unite'] || $f['format']['freres'] > 1) ? $f['format']['nom'] : null,
            'quantite' => $qte, 'grille' => $grille, 'prix' => (float) $f['format']['prix'][$principale],
            'prix_devises' => array_filter($f['format']['prix'], static fn ($p) => $p !== null),
            'remarque' => $remarque, 'allergie' => $allergie, 'temps' => $temps, 'poste_id' => $posteId,
        ]], $serveur);
        // Heures de la démonstration : commandé il y a quelques minutes, statut en cuisine
        $ligne = (int) Database::un('SELECT MAX(id) AS id FROM note_lignes WHERE note_id = ?', [$id])['id'];
        $envoi = date('Y-m-d H:i:s', time() - $minutes * 60);
        Database::executer('UPDATE note_lignes SET ajoute_le = ? WHERE id = ?', [$envoi, $ligne]);
        if ($posteId !== null && $statut !== 'attente') {
            Database::executer(
                'UPDATE note_lignes SET statut = ?, envoye_le = ?, preparation_le = ?, pret_le = ? WHERE id = ?',
                [$statut, $envoi, in_array($statut, ['preparation', 'pret'], true) ? date('Y-m-d H:i:s', time() - max(0, $minutes - 3) * 60) : null,
                 $statut === 'pret' ? date('Y-m-d H:i:s', time() - 60) : null, $ligne]
            );
            Database::executer('UPDATE note_envois e JOIN note_lignes l ON l.envoi_id = e.id SET e.cree_le = ? WHERE l.id = ?', [$envoi, $ligne]);
        }
    }
    if ($addition) {
        Database::executer('UPDATE notes SET addition_le = ?, addition_par = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 120), $serveur, $id]);
    }
    $compte['notes']++;
};

$dejaOuvertes = (int) Database::un("SELECT COUNT(*) AS n FROM notes WHERE statut = 'ouverte' AND type = 'note' AND etablissement_id IN (?, ?)", [(int) $lac['id'], (int) $club['id']])['n'];
if ($dejaOuvertes === 0) {
    // Table 3 : entrées et plats, un plat « à lancer », une allergie
    $ouvrir($lac, $tables['T3'], null, $salle, $aline, 38, [
        ['Primus Grande', 'Bouteille', 2, null, null, 1, null, 'sans', 38],
        ['Brochettes de chèvre', null, 2, 'Sans piment', null, 1, $grill, 'pret', 24],
        ['Tilapia grillé du lac', null, 1, 'Bien cuit', 'arachides', 1, $grill, 'preparation', 22],
        ['Poulet braisé', null, 2, null, null, 2, $grill, 'attente', 22],
    ]);
    // Table 8 (terrasse) : cocktails et frites en cuisine
    $ouvrir($lac, $tables['T8'], null, $terrasse, $aline, 12, [
        ['Kivu Sunset', null, 2, 'Sans glaçons', null, 1, $cocktails, 'recu', 6],
        ['Frites', null, 2, null, null, 1, $friteuse, 'recu', 6],
        ['Coca-Cola', null, 1, null, null, 1, null, 'sans', 12],
    ]);
    // Table 11 : repas fini, addition demandée
    $ouvrir($lac, $tables['T11'], null, $terrasse, $esther, 95, [
        ['Poulet braisé', null, 3, null, null, 1, $grill, 'servi', 80],
        ['Mützig Grande', 'Bouteille', 4, null, null, 1, null, 'sans', 90],
        ['Eau minérale', null, 2, null, null, 1, null, 'sans', 90],
    ], true);
    // Note nommée au comptoir
    $ouvrir($lac, null, 'Bar — Patrick', null, $esther, 50, [
        ['Primus Petite', 'Bouteille', 3, null, null, 1, null, 'sans', 50],
    ]);
    // Volcano Club, Carré VIP : bouteille et ailes de poulet en cuisine
    $ouvrir($club, $tables['VIP 2'], null, $vip, $christian, 45, [
        ['Moët & Chandon Brut', null, 1, null, null, 1, null, 'sans', 45],
        ['Hennessy VS', null, 1, null, null, 1, null, 'sans', 30],
        ['Ailes de poulet', null, 2, 'Bien épicées', null, 1, $snack, 'preparation', 9],
        ['Mojito', null, 3, null, null, 1, $barVip, 'recu', 3],
    ]);
    $ouvrir($club, $tables['Bar 2'], null, $bar, $jeanPaul, 20, [
        ['Heineken', null, 2, null, null, 1, null, 'sans', 20],
    ]);
}

// ---------- Réservations de ce soir ----------

$reserver = static function (array $etab, string $heure, string $nom, string $telephone, int $personnes, ?int $tableId, ?string $notesResa) use ($admin, &$compte): void {
    $jour = Journee::aujourdhui($etab);
    $debut = $jour . ' ' . $heure . ':00';
    if ($heure . ':00' < Journee::fin($etab)) {
        $debut = date('Y-m-d H:i:s', strtotime($debut . ' +1 day'));
    }
    [$de, $a] = Journee::bornes($jour, $etab);
    if (Database::un('SELECT id FROM reservations WHERE etablissement_id = ? AND telephone = ? AND debut >= ? AND debut < ?', [(int) $etab['id'], $telephone, $de, $a]) !== null) {
        return;
    }
    Database::executer(
        'INSERT INTO reservations (etablissement_id, debut, nom, telephone, personnes, table_id, notes, cree_par) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [(int) $etab['id'], $debut, $nom, $telephone, $personnes, $tableId, $notesResa, $admin]
    );
    $compte['reservations']++;
};
$reserver($lac, '19:30', 'Famille Kambale', '+243971234501', 6, $tables['T5'], 'Anniversaire : gâteau à 21 h');
$reserver($lac, '20:30', 'Olivier Ndungo', '+243854455614', 2, $tables['T2'], 'Près de la fenêtre');
$reserver($lac, '21:00', 'Kivu Logistique SARL', '+243815678905', 8, null, 'Repas d\'affaires, facture à la société');
$reserver($club, '23:30', 'Rachel Kanyere', '+243975566715', 10, $tables['VIP 1'], 'Anniversaire, champagne à minuit');
$reserver($club, '00:30', 'Moïse Paluku', '+243893456703', 6, $tables['VIP 3'], null);

echo sprintf(
    "Salle et cuisine de démonstration : %d table(s), %d poste(s), %d note(s) ouverte(s), %d réservation(s) ajoutée(s).\n",
    $compte['tables'], $compte['postes'], $compte['notes'], $compte['reservations']
);
