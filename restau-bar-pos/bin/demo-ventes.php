<?php

declare(strict_types=1);

/**
 * Ventes de démonstration : environ trois semaines de tickets pour les deux établissements de la démo
 * (Comptoir du Lac en journée et en soirée tous les jours, Volcano Club les nuits du jeudi au samedi),
 * vendus par les personnes qui ont le droit d'encaisser (caisse.encaisser), avec les produits et les prix
 * du vrai catalogue (CatalogueRepository::menu(), comme la caisse) et des paiements variés
 * (francs ou dollars à la caisse, M-Pesa, Airtel Money, carte, deux devises, monnaie rendue).
 *
 * Chaque vente passe par VenteRepository::enregistrer() : les soldes de la trésorerie restent justes.
 * Chaque paiement va sur un compte du bon type qui tient la devise payée (la caisse de l'établissement
 * reçoit les francs et les dollars), dans cette devise.
 *
 * Ardoises (clients et dettes) : une quinzaine de clients de Goma, environ 5 % des tickets mis à crédit
 * en tout ou en partie (clé « credits » de VenteRepository::enregistrer : les paiements couvrent le reste),
 * accordés par qui en a le droit (caisse.credit), servis par le vendeur ou un serveur, parfois avec un garant.
 * Puis, dans l'ordre des dates : réponses des garants (acceptée, refusée, ou encore en attente), remboursements
 * (DetteRepository::rembourser avec leur date, en francs ou en dollars, à la caisse ou par mobile money),
 * un remboursement annulé, une dette mise à la charge du responsable, une dette remise, et les rappels
 * inscrits au journal des messages.
 *
 *   php bin/demo-ventes.php demo              refuse si des ventes de démonstration existent déjà
 *   php bin/demo-ventes.php demo --reset      les supprime (avec leurs mouvements) et recommence
 *   php bin/demo-ventes.php demo --jours=14   nombre de jours avant aujourd'hui (21 par défaut)
 *
 * Les ventes de démonstration se reconnaissent à leur clé (« de305a1e-… ») et les clients de démonstration à leur
 * numéro (CLIENTS_DEMO) ; les autres ventes, clients et dettes ne sont jamais touchés.
 */

use App\Core\Acces;
use App\Core\Config;
use App\Core\Database;
use App\Core\I18n;
use App\Core\Instance;
use App\Core\Reglement;
use App\Repositories\CatalogueRepository;
use App\Repositories\DetteRepository;
use App\Repositories\DeviseRepository;
use App\Repositories\EtablissementRepository;
use App\Repositories\GestionTresorerieRepository;
use App\Repositories\VenteRepository;

if (PHP_SAPI !== 'cli') {
    exit("À lancer en ligne de commande.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

const PREFIXE_CLE = 'de305a1e';
// Types de comptes (comptes_tresorerie.type), enregistrés tels quels dans vente_paiements.moyen
const TYPE_CAISSE = 'caisse';
const TYPE_MOBILE = 'mobile_money';
const TYPE_BANQUE = 'banque';
// Clients de démonstration : nom, téléphone, type, canal, langue, plafond [montant, code], e-mail, notes, bloqué
const CLIENTS_DEMO = [
    ['Jean-Marie Bisimwa', '+243971234501', 'habitue', 'whatsapp', 'fr', null, null, 'Primus bien fraîche, table près de la fenêtre.', false],
    ['Furaha Mapendo', '+243812345602', 'particulier', 'sms', 'fr', null, null, null, false],
    ['Moïse Paluku', '+243893456703', 'vip', 'whatsapp', 'fr', [500, 'USD'], 'moise.paluku@exemple.cd', 'Carré VIP le samedi, champagne.', false],
    ['Neema Kavira', '+243974567804', 'habitue', 'sms', 'fr', [300000, 'CDF'], null, 'Allergique aux arachides.', false],
    ['Kivu Logistique SARL', '+243815678905', 'entreprise', 'sms', 'fr', [1500, 'USD'], 'compta@kivulogistique.exemple.cd', 'Repas d\'affaires, facture mensuelle au service comptable.', false],
    ['Dieudonné Mumbere', '+243996789006', 'particulier', 'sms', 'fr', null, null, 'Ardoise de mars jamais réglée.', true],
    ['Sifa Masika', '+243897890107', 'habitue', 'whatsapp', 'fr', [200000, 'CDF'], null, null, false],
    ['Claude Muhindo', '+243978901208', 'vip', 'sms', 'fr', [400, 'USD'], 'claude.muhindo@exemple.cd', null, false],
    ['Aimée Nabintu', '+243819012309', 'particulier', 'sms', 'fr', null, null, null, false],
    ['Patient Bahati', '+243820123410', 'revendeur', 'sms', 'fr', [800000, 'CDF'], null, 'Achète des casiers de Primus pour sa boutique de Birere.', false],
    ['Joséphine Kahambu', '+243971122311', 'particulier', 'whatsapp', 'fr', null, null, null, false],
    ['Innocent Sikuli', '+243892233412', 'habitue', 'whatsapp', 'fr', [250000, 'CDF'], null, 'Supporter de l\'AS Dauphins Noirs, vient les soirs de match.', false],
    ['Espérance Kasoki', '+243813344513', 'particulier', 'sms', 'fr', null, null, null, false],
    ['Olivier Ndungo', '+243854455614', 'habitue', 'whatsapp', 'en', null, 'o.ndungo@exemple.cd', 'Speaks English (works for an NGO).', false],
    ['Rachel Kanyere', '+243975566715', 'vip', 'whatsapp', 'fr', [600, 'USD'], null, 'Anniversaires au club.', false],
];

function arreter(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$instance = null;
$reset = false;
$jours = 21;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--reset') {
        $reset = true;
    } elseif (preg_match('/^--jours=(\d{1,3})$/', $argument, $m)) {
        $jours = max(1, (int) $m[1]);
    } elseif ($instance === null && !str_starts_with($argument, '--')) {
        $instance = $argument;
    } else {
        arreter("Option inconnue : $argument");
    }
}
if ($instance === null) {
    arreter('Usage : php bin/demo-ventes.php <sous-domaine> [--reset] [--jours=21]');
}
if (Instance::charger($instance) === null) {
    arreter("Instance inconnue : $instance");
}

// Même fuseau que l'application (index.php)
$parametres = array_column(Database::tous('SELECT cle, valeur FROM parametres'), 'valeur', 'cle');
date_default_timezone_set($parametres['fuseau_horaire'] ?? Config::get('fuseau_horaire'));
Database::executer('SET time_zone = ?', [date('P')]);
I18n::initialiser('fr');

// ---------- Ventes de démonstration déjà là ----------

$existantes = array_map('intval', array_column(
    Database::tous('SELECT id FROM ventes WHERE cle LIKE ?', [PREFIXE_CLE . '-%']),
    'id'
));
if ($existantes !== [] && !$reset) {
    arreter(count($existantes) . " ventes de démonstration existent déjà. Relancez avec --reset pour les remplacer.");
}
if ($reset) {
    supprimerArdoisesDemo($existantes);
}

/**
 * Supprime les ardoises de démonstration : dettes des ventes de démonstration et des clients de démonstration
 * (et les dettes du personnel nées de leur mise à charge), leurs remboursements (avec leurs mouvements de
 * trésorerie), leurs messages, puis les clients de démonstration et les clients « personnel » restés sans dette.
 */
function supprimerArdoisesDemo(array $ventes): void
{
    $marques = static fn (array $ids): string => implode(',', array_fill(0, count($ids), '?'));
    $telephones = array_column(CLIENTS_DEMO, 1);
    $clients = array_map('intval', array_column(Database::tous('SELECT id FROM clients WHERE telephone IN (' . $marques($telephones) . ')', $telephones), 'id'));
    $dettes = [];
    foreach (array_chunk($ventes, 500) as $paquet) {
        $dettes = array_merge($dettes, array_map('intval', array_column(Database::tous('SELECT id FROM dettes WHERE vente_id IN (' . $marques($paquet) . ')', $paquet), 'id')));
    }
    if ($clients !== []) {
        $dettes = array_merge($dettes, array_map('intval', array_column(Database::tous('SELECT id FROM dettes WHERE client_id IN (' . $marques($clients) . ')', $clients), 'id')));
    }
    // Dettes du personnel nées de la mise à charge d'une de ces dettes (et ainsi de suite)
    for ($nouvelles = $dettes; $nouvelles !== [];) {
        $nouvelles = array_values(array_diff(array_map('intval', array_column(
            Database::tous('SELECT id FROM dettes WHERE origine_id IN (' . $marques($nouvelles) . ')', $nouvelles), 'id')), $dettes));
        $dettes = array_merge($dettes, $nouvelles);
    }
    $dettes = array_values(array_unique($dettes));
    if ($dettes === [] && $clients === []) {
        return;
    }
    $pdo = Database::pdo();
    $pdo->beginTransaction();
    $remboursements = [];
    if ($dettes !== []) {
        $remboursements = array_map('intval', array_column(Database::tous('SELECT DISTINCT remboursement_id FROM remboursement_dettes WHERE dette_id IN (' . $marques($dettes) . ')', $dettes), 'remboursement_id'));
    }
    if ($clients !== []) {
        $remboursements = array_values(array_unique(array_merge($remboursements, array_map('intval', array_column(
            Database::tous('SELECT id FROM remboursements WHERE client_id IN (' . $marques($clients) . ')', $clients), 'id')))));
    }
    if ($remboursements !== []) {
        Database::executer("DELETE FROM mouvements_tresorerie WHERE nature = 'remboursement' AND source_id IN (" . $marques($remboursements) . ')', $remboursements);
        Database::executer('DELETE FROM remboursements WHERE id IN (' . $marques($remboursements) . ')', $remboursements);
    }
    if ($dettes !== []) {
        Database::executer('DELETE FROM messages WHERE dette_id IN (' . $marques($dettes) . ')', $dettes);
        Database::executer('UPDATE dettes SET origine_id = NULL WHERE id IN (' . $marques($dettes) . ')', $dettes);
        Database::executer('DELETE FROM dettes WHERE id IN (' . $marques($dettes) . ')', $dettes);
    }
    if ($clients !== []) {
        Database::executer('DELETE FROM messages WHERE client_id IN (' . $marques($clients) . ')', $clients);
        Database::executer('DELETE FROM clients WHERE id IN (' . $marques($clients) . ')', $clients);
    }
    Database::executer("DELETE FROM clients WHERE type = 'personnel' AND NOT EXISTS (SELECT 1 FROM dettes d WHERE d.client_id = clients.id)
                        AND NOT EXISTS (SELECT 1 FROM remboursements r WHERE r.client_id = clients.id)");
    $pdo->commit();
    echo count($dettes) . " dettes, " . count($remboursements) . " remboursements et " . count($clients) . " clients de démonstration supprimés.\n";
}

if ($existantes !== []) {
    $pdo = Database::pdo();
    $pdo->beginTransaction();
    foreach (array_chunk($existantes, 500) as $paquet) {
        $marques = implode(',', array_fill(0, count($paquet), '?'));
        Database::executer("DELETE FROM mouvements_tresorerie WHERE nature = 'vente' AND source_id IN ($marques)", $paquet);
        // Sorties de stock de ces ventes (module Stock), le stock est recalculé ensuite
        if (Database::un("SHOW TABLES LIKE 'stock_mouvements'") !== null) {
            Database::executer("DELETE FROM stock_mouvements WHERE type IN ('vente', 'recette', 'vide_recupere', 'annulation_vente') AND source_id IN ($marques)", $paquet);
        }
        Database::executer("DELETE FROM ventes WHERE id IN ($marques)", $paquet);
    }
    $pdo->commit();
    if (Database::un("SHOW TABLES LIKE 'stock_mouvements'") !== null) {
        (new App\Repositories\StockRepository())->recalculer();
    }
    echo count($existantes) . " ventes de démonstration supprimées.\n";
}

mt_srand(20261026);

// ---------- Clients de démonstration (retrouvés par leur numéro s'ils existent déjà) ----------

$dettesRepo = new DetteRepository();
$clientsDemo = [];
// Créés par le propriétaire (le journal note toujours qui a fait quoi)
$createur = Database::un('SELECT ur.utilisateur_id AS id FROM utilisateur_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.tout = 1 ORDER BY ur.utilisateur_id LIMIT 1');
foreach (CLIENTS_DEMO as [$nomClient, $telephone, $typeClient, $canal, $langue, $plafond, $email, $notes, $bloque]) {
    $existant = $dettesRepo->clientParTelephone($telephone);
    if ($existant === null) {
        $plafondDevise = $plafond ? Database::un('SELECT id FROM devises WHERE code = ?', [$plafond[1]]) : null;
        $id = $dettesRepo->creerClient([
            'nom' => $nomClient, 'telephone' => $telephone, 'email' => $email, 'langue' => $langue, 'type' => $typeClient,
            'notes' => $notes, 'canal' => $canal, 'relances' => true,
            'plafond' => $plafondDevise ? (float) $plafond[0] : null, 'plafond_devise_id' => $plafondDevise ? (int) $plafondDevise['id'] : null,
        ], $createur ? (int) $createur['id'] : null);
        Database::executer('UPDATE clients SET bloque = ?, cree_le = ? WHERE id = ?', [(int) $bloque, date('Y-m-d H:i:s', strtotime('-' . ($jours + 30) . ' days')), $id]);
        $existant = $dettesRepo->client($id);
    }
    if (!$bloque) {
        $clientsDemo[(int) $existant['id']] = $existant;
    }
}
// Les habitués, VIP et entreprises prennent plus souvent à crédit
$poidsClients = [];
foreach ($clientsDemo as $id => $client) {
    $poidsClients[$id] = ['habitue' => 4, 'vip' => 3, 'entreprise' => 3, 'revendeur' => 2][$client['type']] ?? 1;
}

$devisesRepo = new DeviseRepository();
$etablissementsRepo = new EtablissementRepository();
$tresorerie = new GestionTresorerieRepository($devisesRepo);
$ventes = new VenteRepository();
$reference = $devisesRepo->reference();
$locale = $devisesRepo->locale();

/** Tirage pondéré : [valeur => poids]. */
function tirer(array $poids): mixed
{
    $total = array_sum($poids);
    $r = mt_rand() / mt_getrandmax() * $total;
    foreach ($poids as $valeur => $p) {
        $r -= $p;
        if ($r <= 0) {
            return $valeur;
        }
    }
    return array_key_last($poids);
}

function cleDemo(): string
{
    $hex = static fn (int $n): string => substr(bin2hex(random_bytes(8)), 0, $n);
    return PREFIXE_CLE . '-' . $hex(4) . '-' . $hex(4) . '-' . $hex(4) . '-' . $hex(12);
}

/**
 * Catalogue de la caisse pour un établissement et une grille : même source et même calcul que
 * CaisseController::donnees() (CatalogueRepository::menu() dans chaque devise acceptée).
 * @return array<int, array{id: int, produit: string, categorie: string, nom: string, taille: string, tailleId: int,
 *                          unite: bool, freres: int, disponible: bool, prix: array<string, float>}>
 */
function catalogueCaisse(CatalogueRepository $catalogue, int $etabId, int $grille, int $grilleDefaut, array $acceptees): array
{
    $formats = [];
    foreach ($acceptees as $devise) {
        foreach ($catalogue->menu($etabId, $grille, $grilleDefaut, $devise, false) as $categorie) {
            $groupes = [$categorie, ...$categorie['sous_categories']];
            foreach ($groupes as $groupe) {
                foreach ($groupe['produits'] as $produit) {
                    $sources = [[$produit, '', (bool) $produit['disponible']]];
                    foreach ($produit['tailles'] as $taille) {
                        $sources[] = [$taille, $taille['libelle_taille'], (bool) $taille['disponible']];
                    }
                    foreach ($sources as [$source, $libelleTaille, $disponible]) {
                        foreach ($source['conditionnements'] as $cond) {
                            $id = (int) $cond['id'];
                            $formats[$id] ??= [
                                'id' => $id,
                                'produit' => champ($produit, 'nom'),
                                'categorie' => $categorie['nom_fr'],
                                'nom' => champ($cond, 'nom'),
                                'taille' => $libelleTaille,
                                'tailleId' => (int) $source['id'],
                                'unite' => (float) $cond['quantite_base'] === 1.0,
                                'freres' => count($source['conditionnements']),
                                'disponible' => $disponible,
                                'prix' => [],
                            ];
                            $formats[$id]['prix'][$devise['code']] = (float) $cond['montant'];
                        }
                    }
                }
            }
        }
    }
    return $formats;
}

/**
 * Seul endroit qui choisit un compte : un compte actif de ce type (caisse, mobile money, banque) qui tient
 * cette devise, parmi ceux de l'établissement ($comptes : les siens et les communs). Le compte propre à
 * l'établissement passe avant un compte commun ; entre deux comptes du même rang, tirage au sort.
 * Le paiement est ensuite enregistré dans cette devise (VenteRepository : $p['devise']).
 */
function compteCaisse(array $comptes, string $type, ?array $devise): ?array
{
    if ($devise === null) {
        return null;
    }
    $liste = array_values(array_filter($comptes, static fn (array $c) =>
        $c['type'] === $type && GestionTresorerieRepository::tient($c, (int) $devise['id'])));
    $propres = array_values(array_filter($liste, static fn (array $c) => $c['etablissement_id'] !== null));
    $liste = $propres ?: $liste;
    return $liste === [] ? null : $liste[mt_rand(0, count($liste) - 1)];
}

/** Arrondit un montant payé en liquide à ce qu'un client tend : billets de 500 FC, 1 000 FC… ou de 1, 5, 10 $. */
function especesTendues(float $montant, array $devise): float
{
    if ((int) $devise['decimales'] === 0) {
        $pas = $montant > 20000 ? (mt_rand(0, 2) ? 5000 : 1000) : ($montant > 5000 ? 1000 : 500);
        return mt_rand(0, 3) === 0 ? Reglement::versLeHaut($montant, $devise) : ceil($montant / $pas) * $pas;
    }
    $pas = $montant > 20 ? (mt_rand(0, 1) ? 10 : 5) : (mt_rand(0, 1) ? 5 : 1);
    return mt_rand(0, 4) === 0 ? Reglement::versLeHaut($montant, $devise) : ceil($montant / $pas) * $pas;
}

// ---------- Établissements, vendeurs, horaires ----------

$profils = [
    'restaurant_bar' => [
        // Tous les jours, du matin au soir : creux l'après-midi, pics à midi et le soir
        'jours' => [1, 2, 3, 4, 5, 6, 7],
        'heures' => [7 => 2, 8 => 3, 9 => 2, 10 => 2, 11 => 3, 12 => 7, 13 => 7, 14 => 4, 15 => 2, 16 => 2, 17 => 3, 18 => 6, 19 => 7, 20 => 6, 21 => 4, 22 => 2],
        'tickets' => [26, 42],
        'categories' => ['Bières' => 30, 'Sucreries et eaux' => 22, 'Plats' => 16, 'À grignoter' => 12, 'Cocktails' => 6, 'Vins et champagnes' => 5, 'Spiritueux' => 3],
        'zones' => ['salle' => 6, 'terrasse' => 3, '' => 1],
    ],
    'nightclub' => [
        // Nuits du jeudi, vendredi et samedi, de 21 h à 4 h du matin
        'jours' => [4, 5, 6],
        'heures' => [21 => 2, 22 => 5, 23 => 8, 24 => 9, 25 => 8, 26 => 5, 27 => 3],
        'tickets' => [45, 75],
        'categories' => ['Bières' => 30, 'Spiritueux' => 16, 'Cocktails' => 16, 'Sucreries et eaux' => 16, 'Vins et champagnes' => 7, 'À grignoter' => 8],
        'zones' => ['piste' => 7, 'vip' => 3],
    ],
];

$utilisateurs = Database::tous('SELECT id, nom FROM utilisateurs WHERE actif = 1 ORDER BY id');
$comptesActifs = $tresorerie->comptesActifs();
$plan = [];
$contextes = [];
$catalogue = new CatalogueRepository($devisesRepo);

foreach ($etablissementsRepo->actifs() as $etab) {
    $etabId = (int) $etab['id'];
    $profil = $profils[$etab['type']] ?? $profils['restaurant_bar'];
    if ($etab['type'] === 'lounge') {
        $profil = $profils['nightclub'];
    }
    // Personnes qui encaissent ici (pas le propriétaire, qui a tous les droits) : la première vend le plus
    $vendeurs = [];
    foreach ($utilisateurs as $u) {
        if (Acces::a((int) $u['id'], 'caisse.encaisser', $etabId) && !Acces::droits((int) $u['id'])['tout']) {
            $vendeurs[(int) $u['id']] = $u['nom'];
        }
    }
    if ($vendeurs === []) {
        echo "{$etab['nom']} : personne ne peut encaisser, aucune vente.\n";
        continue;
    }
    // Le caissier ou le barman vend plus que le gérant ou la superviseure
    $poidsVendeurs = [];
    foreach ($vendeurs as $id => $nom) {
        $poidsVendeurs[$id] = Acces::a($id, 'ventes.voir', $etabId) ? 2 : 3;
    }

    // Devises acceptées et devise du ticket (devise locale, comme la caisse)
    $acceptees = $devisesRepo->accepteesParmi($etablissementsRepo->deviseIds($etabId));
    $principale = ($locale ? ($acceptees[(int) $locale['id']] ?? null) : null)
        ?? $acceptees[(int) $etab['devise_affichage_id']] ?? $reference ?? reset($acceptees);
    $comptes = array_values(array_filter($comptesActifs, static fn (array $compte) =>
        $compte['etablissement_id'] === null || (int) $compte['etablissement_id'] === $etabId));
    // Zones et leur grille ; catalogue de chaque grille
    $zones = ['' => null];
    foreach ($etablissementsRepo->zones($etabId) as $zone) {
        $zones[$zone['code']] = $zone;
    }
    $grilleDefaut = (int) $etab['grille_prix_id'];
    $catalogues = [];
    foreach ($zones as $code => $zone) {
        $grille = $zone && $zone['grille_prix_id'] ? (int) $zone['grille_prix_id'] : $grilleDefaut;
        $catalogues[$grille] ??= array_filter(
            catalogueCaisse($catalogue, $etabId, $grille, $grilleDefaut, $acceptees),
            static fn (array $f) => $f['disponible'] && isset($f['prix'][$principale['code']])
        );
    }
    $appareils = array_map('intval', array_column(Database::tous('SELECT id FROM appareils_caisse WHERE etablissement_id = ? AND actif = 1', [$etabId]), 'id'));
    // Ardoises : accordées par qui a le droit (caisse.credit), servies par le vendeur ou un serveur de l'établissement
    $accordeurs = [];
    $serveurs = [];
    foreach ($utilisateurs as $u) {
        $uid = (int) $u['id'];
        if (Acces::droits($uid)['tout']) {
            continue;
        }
        if (Acces::a($uid, 'caisse.credit', $etabId)) {
            $accordeurs[] = $uid;
        } elseif (Acces::a($uid, 'caisse.vendre', $etabId)) {
            $serveurs[] = $uid;
        }
    }
    $contextes[$etabId] = compact('etab', 'profil', 'acceptees', 'principale', 'comptes', 'zones', 'grilleDefaut', 'catalogues', 'appareils', 'accordeurs', 'serveurs');

    // Heures des ventes sur la période, jusqu'à maintenant
    $maintenant = time();
    for ($j = $jours; $j >= 0; $j--) {
        $jour = new DateTimeImmutable("today -$j days");
        if (!in_array((int) $jour->format('N'), $profil['jours'], true)) {
            continue;
        }
        $n = mt_rand($profil['tickets'][0], $profil['tickets'][1]);
        if ((int) $jour->format('N') >= 5) {
            $n = (int) round($n * 1.25); // vendredi et samedi : plus de monde
        }
        $vendeurDuJour = $poidsVendeurs;
        // Jours de repos : chacun est absent environ un jour sur huit (il reste toujours quelqu'un)
        foreach (array_keys($vendeurDuJour) as $id) {
            if (count($vendeurDuJour) > 1 && mt_rand(0, 7) === 0) {
                unset($vendeurDuJour[$id]);
            }
        }
        for ($i = 0; $i < $n; $i++) {
            $heure = (int) tirer($profil['heures']);
            $moment = $jour->setTime(0, 0)->modify('+' . ($heure * 3600 + mt_rand(0, 3599)) . ' seconds');
            if ($moment->getTimestamp() > $maintenant) {
                continue;
            }
            $plan[] = ['etab' => $etabId, 'date' => $moment->format('Y-m-d H:i:s'), 'vendeur' => (int) tirer($vendeurDuJour)];
        }
    }
}

usort($plan, static fn (array $a, array $b) => [$a['date'], $a['etab']] <=> [$b['date'], $b['etab']]);

// ---------- Enregistrement ----------

$compteur = [];
$tauxEnVigueur = static function (array $devise) use ($devisesRepo): ?float {
    $taux = $devisesRepo->actives()[(int) $devise['id']]['taux'] ?? null;
    return $taux !== null && (float) $taux > 0 ? (float) $taux : null;
};

foreach ($plan as $p) {
    $c = $contextes[$p['etab']];
    $principale = $c['principale'];
    $codeP = $principale['code'];
    $acceptees = array_column($c['acceptees'], null, 'code');
    $codeZone = (string) tirer($c['profil']['zones']);
    $zone = $c['zones'][$codeZone] ?? null;
    $grille = $zone && $zone['grille_prix_id'] ? (int) $zone['grille_prix_id'] : $c['grilleDefaut'];
    $formats = $c['catalogues'][$grille];
    if ($formats === []) {
        continue;
    }
    // Catégories proposées à cette heure : pas de plat avant 11 h, café-croissant non plus au club
    $heure = (int) substr($p['date'], 11, 2);
    $poidsCategories = $c['profil']['categories'];
    if ($heure < 11) {
        unset($poidsCategories['Plats'], $poidsCategories['Spiritueux'], $poidsCategories['Cocktails']);
        $poidsCategories['Sucreries et eaux'] = ($poidsCategories['Sucreries et eaux'] ?? 10) * 2;
    }
    $parCategorie = [];
    foreach ($formats as $f) {
        $parCategorie[$f['categorie']][] = $f;
    }
    $poidsCategories = array_intersect_key($poidsCategories, $parCategorie) ?: array_fill_keys(array_keys($parCategorie), 1);

    // Lignes du ticket
    $nbLignes = (int) tirer([1 => 30, 2 => 32, 3 => 20, 4 => 12, 5 => 6]);
    $choisis = [];
    for ($i = 0; $i < $nbLignes; $i++) {
        $candidats = $parCategorie[tirer($poidsCategories)];
        // Le verre ou la bouteille se vendent plus que le casier ou la bouteille entière de whisky
        $poidsFormats = [];
        foreach ($candidats as $k => $f) {
            $poidsFormats[$k] = $f['unite'] ? 6 : 1;
        }
        $f = $candidats[tirer($poidsFormats)];
        $max = str_contains($f['categorie'], 'Bi') && $f['unite'] ? 6 : ($f['unite'] ? 3 : 1);
        $quantite = (int) tirer(array_slice([1 => 10, 2 => 6, 3 => 3, 4 => 2, 5 => 1, 6 => 1], 0, $max, true));
        $choisis[$f['id']] = ['format' => $f, 'quantite' => ($choisis[$f['id']]['quantite'] ?? 0) + $quantite];
    }

    $totaux = array_fill_keys(array_keys($acceptees), 0.0);
    $lignes = [];
    foreach ($choisis as ['format' => $f, 'quantite' => $quantite]) {
        foreach (array_keys($totaux) as $code) {
            $prix = $f['prix'][$code] ?? null;
            $totaux[$code] = $prix === null || $totaux[$code] === null ? null : $totaux[$code] + $prix * $quantite;
        }
        $lignes[] = [
            'produit_id' => $f['tailleId'], 'conditionnement_id' => $f['id'],
            'libelle' => $f['taille'] !== '' ? $f['produit'] . ' ' . $f['taille'] : $f['produit'],
            'format' => (!$f['unite'] || $f['freres'] > 1) ? $f['nom'] : null,
            'quantite' => $quantite, 'prix' => (float) $f['prix'][$codeP],
            'prix_reference' => $reference && isset($f['prix'][$reference['code']]) ? (float) $f['prix'][$reference['code']] : null,
        ];
    }
    $totaux = array_filter($totaux, static fn ($t) => $t !== null);
    $total = $totaux[$codeP];

    // Ardoise : environ 5 % des tickets, en entier (parfois en dollars pour une entreprise ou un VIP) ou en partie
    // (en francs, le reste payé) ; les paiements ne couvrent que ce qui n'est pas à crédit ($aRegler)
    $codeUsd = $reference['code'] ?? null;
    $credits = [];
    $aRegler = $totaux;
    $vendeur = $p['vendeur'];
    if ($c['accordeurs'] !== [] && $clientsDemo !== [] && mt_rand(1, 1000) <= 55) {
        $client = $clientsDemo[(int) tirer($poidsClients)];
        $vendeur = $c['accordeurs'][mt_rand(0, count($c['accordeurs']) - 1)];
        $servi = $c['serveurs'] !== [] && mt_rand(0, 2) > 0 ? $c['serveurs'][mt_rand(0, count($c['serveurs']) - 1)] : $vendeur;
        $garant = null;
        if (mt_rand(1, 100) <= 25) {
            $candidats = array_values(array_diff(array_unique(array_merge($c['accordeurs'], $c['serveurs'])), [$servi]));
            $garant = $candidats !== [] ? $candidats[mt_rand(0, count($candidats) - 1)] : null;
        }
        $delai = $client['type'] === 'entreprise' ? 30 : (int) $c['etab']['credit_delai_jours'];
        $echeance = date('Y-m-d', strtotime(substr($p['date'], 0, 10) . ' +' . $delai . ' days'));
        $code = $codeP;
        $montantCredit = 0.0;
        if (mt_rand(1, 10) <= 4) {
            if ($codeUsd !== null && isset($totaux[$codeUsd]) && in_array($client['type'], ['entreprise', 'vip'], true) && mt_rand(0, 1) === 1) {
                $code = $codeUsd;
            }
            $montantCredit = (float) $totaux[$code];
            $aRegler = [];
        } else {
            $pas = Reglement::pas($principale);
            $montantCredit = round($total * mt_rand(30, 70) / 100 / $pas) * $pas;
            if ($montantCredit > 0 && $montantCredit < $total) {
                $part = 1 - $montantCredit / $total;
                foreach ($aRegler as $k => $v) {
                    $aRegler[$k] = $k === $codeP ? $total - $montantCredit : round($v * $part, (int) $acceptees[$k]['decimales']);
                }
            } else {
                $montantCredit = 0.0;
            }
        }
        if ($montantCredit > 0) {
            $credits[] = ['client_id' => (int) $client['id'], 'devise' => $acceptees[$code], 'montant' => $montantCredit, 'echeance' => $echeance,
                          'servi_par' => $servi, 'garant_id' => $garant, 'note' => null];
        } else {
            $vendeur = $p['vendeur'];
        }
    }

    // Tout à crédit : aucun paiement
    $paiements = [];
    $rendu = null;
    if ($aRegler !== []) {
        // Paiements : francs ou dollars à la caisse, mobile money, carte, ou dollars + francs
        $compte = static fn (string $type, string $code): ?array => compteCaisse($c['comptes'], $type, $acceptees[$code] ?? null);
        $usd = $reference ? ($aRegler[$reference['code']] ?? null) : null;
        $mode = (string) tirer([
            'fc' => 42, 'usd' => $usd !== null ? 20 : 0, 'mobile_fc' => 18, 'mobile_usd' => $usd !== null ? 5 : 0,
            'mixte' => $usd !== null && $usd >= 6 ? 10 : 0, 'carte' => $usd !== null && $usd >= 15 ? 5 : 0,
        ]);
        $paiements = [];
        $ajouter = static function (string $moyen, string $code, float $recu) use (&$paiements, $compte, $acceptees, $tauxEnVigueur): bool {
            $cpt = $compte($moyen, $code);
            if ($cpt === null || $recu <= 0) {
                return false;
            }
            $paiements[] = ['moyen' => $moyen, 'compte' => $cpt, 'devise' => $acceptees[$code], 'recu' => round($recu, (int) $acceptees[$code]['decimales']), 'taux' => $tauxEnVigueur($acceptees[$code])];
            return true;
        };
        $ok = match ($mode) {
            'usd' => $ajouter(TYPE_CAISSE, $codeUsd, especesTendues($usd, $acceptees[$codeUsd])),
            'mobile_fc' => $ajouter(TYPE_MOBILE, $codeP, $aRegler[$codeP]),
            'mobile_usd' => $ajouter(TYPE_MOBILE, $codeUsd, $usd),
            'carte' => $ajouter(TYPE_BANQUE, $codeUsd, $usd),
            // Quelques billets de dollars, le reste en francs (ajouté plus bas)
            'mixte' => $ajouter(TYPE_CAISSE, $codeUsd, max(1.0, floor($usd * 0.7 / 5) * 5 ?: floor($usd * 0.6))),
            default => $ajouter(TYPE_CAISSE, $codeP, especesTendues($aRegler[$codeP], $principale)),
        };
        if (!$ok) {
            $ajouter(TYPE_CAISSE, $codeP, especesTendues($aRegler[$codeP], $principale));
        }
        $reglement = Reglement::calculer($aRegler, $acceptees, array_map(static fn (array $x) => ['devise' => $x['devise']['code'], 'recu' => $x['recu']], $paiements), $codeP);
        if (!$reglement['regle']) {
            // Le reste en francs, à la caisse (billets)
            $ajouter(TYPE_CAISSE, $codeP, especesTendues($reglement['reste'][$codeP], $principale));
            $reglement = Reglement::calculer($aRegler, $acceptees, array_map(static fn (array $x) => ['devise' => $x['devise']['code'], 'recu' => $x['recu']], $paiements), $codeP);
        }
        if (!$reglement['regle'] || $paiements === []) {
            continue;
        }

        // Monnaie : en francs le plus souvent ; en dollars quand l'excédent fait des dollars ronds (pas de pièces)
        $rendu = null;
        if (array_sum($reglement['rendu']) > 0) {
            $enDollars = $reglement['rendu'][$codeUsd] ?? 0;
            $codeRendu = $codeUsd !== null && $enDollars >= 1 && abs($enDollars - round($enDollars)) < 0.005 && mt_rand(0, 1) === 0 ? $codeUsd : $codeP;
            $caisse = $compte(TYPE_CAISSE, $codeRendu);
            if ($caisse === null) {
                $codeRendu = $codeP;
                $caisse = $compte(TYPE_CAISSE, $codeP);
            }
            if ($caisse !== null && ($reglement['rendu'][$codeRendu] ?? 0) > 0) {
                $rendu = ['montant' => $reglement['rendu'][$codeRendu], 'devise' => $acceptees[$codeRendu], 'compte' => $caisse, 'taux' => $tauxEnVigueur($acceptees[$codeRendu])];
            }
        }
    }

    $ventes->enregistrer([
        'etablissement' => $c['etab'], 'cle' => cleDemo(), 'zone_id' => $zone ? (int) $zone['id'] : null,
        'grille_id' => $grille, 'utilisateur_id' => $vendeur,
        'appareil_id' => $c['appareils'] !== [] && mt_rand(0, 9) < 7 ? $c['appareils'][array_rand($c['appareils'])] : null,
        'devise' => $principale, 'total' => $total,
        'reference' => $reference, 'total_reference' => $reference ? ($totaux[$reference['code']] ?? null) : null,
        'totaux' => array_map(static fn (string $code) => ['devise' => $acceptees[$code], 'total' => $totaux[$code], 'taux' => $tauxEnVigueur($acceptees[$code])], array_keys($totaux)),
        'lignes' => $lignes, 'paiements' => $paiements, 'rendu' => $rendu,
        'date' => $p['date'], 'credits' => $credits,
    ]);
    $compteur[$c['etab']['nom']] = ($compteur[$c['etab']['nom']] ?? 0) + 1;
}

foreach ($compteur as $nom => $n) {
    echo "$nom : $n ventes de démonstration.\n";
}
echo array_sum($compteur) . " ventes enregistrées sur " . ($jours + 1) . " jours.\n";

// ---------- Ardoises : garants, remboursements, décisions du gérant, rappels ----------

/** « 45 000 FC », « $12 » comme dans les SMS (espaces simples). */
function montantSms(float $montant, array $devise, string $langue): string
{
    $decimales = abs($montant - round($montant)) < 0.005 ? 0 : (int) $devise['decimales'];
    $nombre = $langue === 'fr' ? number_format($montant, $decimales, ',', ' ') : number_format($montant, $decimales, '.', ',');
    return $devise['symbole_avant'] ? $devise['symbole'] . $nombre : $nombre . ' ' . $devise['symbole'];
}

$maintenant = time();
$horodatage = static fn (int $t): string => date('Y-m-d H:i:s', $t);
$entre = static fn (int $min, int $max): int => $max <= $min ? $min : mt_rand($min, $max);
$devisesToutes = $tresorerie->devises();
$listeDettes = static fn (): array => Database::tous(
    "SELECT d.*, c.nom AS client_nom, c.telephone AS client_telephone, c.langue AS client_langue, c.canal AS client_canal, c.relances AS client_relances,
            r.nom AS responsable_nom, r.telephone AS responsable_telephone, r.langue AS responsable_langue,
            g.nom AS garant_nom, g.telephone AS garant_telephone, s.nom AS servi_nom, e.nom AS etablissement_nom
       FROM dettes d LEFT JOIN ventes v ON v.id = d.vente_id JOIN clients c ON c.id = d.client_id JOIN etablissements e ON e.id = d.etablissement_id
  LEFT JOIN utilisateurs r ON r.id = d.responsable_id LEFT JOIN utilisateurs g ON g.id = d.garant_id LEFT JOIN utilisateurs s ON s.id = d.servi_par
      WHERE (v.cle LIKE ? OR (d.vente_id IS NULL AND d.origine_id IS NULL AND c.telephone IN (" . implode(',', array_fill(0, count(CLIENTS_DEMO), '?')) . ")))
   ORDER BY d.cree_le, d.id",
    array_merge([PREFIXE_CLE . '-%'], array_column(CLIENTS_DEMO, 1))
);
$proprietaire = null;
$gerants = [];
$encaisseurs = [];
foreach ($utilisateurs as $u) {
    $uid = (int) $u['id'];
    if (Acces::droits($uid)['tout']) {
        $proprietaire ??= $uid;
        continue;
    }
    foreach (array_keys($contextes) as $etabId) {
        if (Acces::a($uid, 'dettes.gerer', $etabId)) {
            $gerants[$etabId] ??= $uid;
        }
        if (Acces::a($uid, 'caisse.rembourser', $etabId)) {
            $encaisseurs[$etabId][] = $uid;
        }
    }
}
$gerantDe = static fn (int $etabId): int => $gerants[$etabId] ?? (int) $proprietaire;

// Deux ardoises reprises du cahier papier, plus anciennes que les ventes de démonstration (ancienneté > 30 et > 60 jours)
$premier = array_key_first($contextes);
if ($premier !== null) {
    $c = $contextes[$premier];
    $servi = $c['serveurs'][0] ?? ($c['accordeurs'][0] ?? null);
    $locale = $devisesRepo->locale() ?? $reference;
    foreach ([['+243996789006', 75, 185000], ['+243820123410', 45, 420000]] as [$telephone, $age, $montant]) {
        $client = $dettesRepo->clientParTelephone($telephone);
        if ($client === null || $locale === null) {
            continue;
        }
        $date = date('Y-m-d 19:30:00', strtotime("-$age days"));
        $dettesRepo->creer([
            'client_id' => (int) $client['id'], 'etablissement_id' => (int) $c['etab']['id'], 'devise_id' => (int) $locale['id'],
            'montant' => (float) $montant, 'echeance' => date('Y-m-d', strtotime($date . ' +' . (int) $c['etab']['credit_delai_jours'] . ' days')),
            'servi_par' => $servi, 'accorde_par' => $gerantDe((int) $c['etab']['id']), 'note' => 'Reprise du cahier d\'ardoises', 'date' => $date,
        ]);
    }
}

// Garants : la plupart répondent dans les deux jours (acceptent souvent) ; les plus récents attendent encore
$garanties = ['accepte' => 0, 'refuse' => 0, 'en_attente' => 0];
foreach ($listeDettes() as $d) {
    if ($d['garant_statut'] !== 'en_attente') {
        continue;
    }
    $cree = strtotime($d['cree_le']);
    Database::executer(
        "INSERT IGNORE INTO messages (canal, destinataire, utilisateur_id, dette_id, evenement, etape, texte, statut, cree_le)
         VALUES ('sms', ?, ?, ?, 'garant_demande', 'demande', ?, 'simule', ?)",
        [(string) $d['garant_telephone'], (int) $d['garant_id'], (int) $d['id'], I18n::dans('fr', 'msg.garant_demande', [
            'etab' => $d['etablissement_nom'], 'servi' => $d['servi_nom'] ?? '—', 'client' => $d['client_nom'],
            'montant' => montantSms((float) $d['montant'], $devisesToutes[(int) $d['devise_id']], 'fr'),
        ]), $horodatage($cree + 300)]
    );
    $tirage = mt_rand(1, 100);
    if ($maintenant - $cree < 2 * 86400 || $tirage > 80) {
        $garanties['en_attente']++;
        continue;
    }
    $accepte = $tirage <= 60;
    $dettesRepo->repondreGarant((int) $d['id'], (int) $d['garant_id'], $accepte);
    Database::executer('UPDATE dettes SET garant_repondu_le = ? WHERE id = ?', [$horodatage(min($maintenant - 3600, $cree + $entre(2, 40) * 3600)), (int) $d['id']]);
    $garanties[$accepte ? 'accepte' : 'refuse']++;
}

// Remboursements : près de la moitié des ardoises sont soldées avant l'échéance ou peu après, d'autres en partie
$evenements = [];
foreach ($listeDettes() as $d) {
    $cree = strtotime($d['cree_le']);
    $limite = min($maintenant - 3600, strtotime($d['echeance'] . ' 20:00') + 4 * 86400);
    if ($limite <= $cree + 3 * 3600) {
        continue;
    }
    $tirage = mt_rand(1, 100);
    if ($tirage <= 45) {
        $evenements[] = ['dette' => (int) $d['id'], 'date' => $entre($cree + 3 * 3600, $limite), 'part' => 1.0];
    } elseif ($tirage <= 65) {
        $evenements[] = ['dette' => (int) $d['id'], 'date' => $entre($cree + 3 * 3600, $limite), 'part' => mt_rand(30, 60) / 100];
    }
}
usort($evenements, static fn (array $a, array $b) => $a['date'] <=> $b['date']);
$remboursements = [];
foreach ($evenements as $ev) {
    $d = $dettesRepo->dette($ev['dette']);
    if ($d === null || $d['statut'] !== 'ouverte') {
        continue;
    }
    $etabId = (int) $d['etablissement_id'];
    $deviseDette = $devisesToutes[(int) $d['devise_id']];
    $date = $horodatage($ev['date']);
    // Payé en francs le plus souvent ; parfois en dollars (converti au taux du jour par DetteRepository)
    $devisePaiement = $deviseDette;
    if ($reference && (int) $deviseDette['id'] !== (int) $reference['id'] && mt_rand(1, 100) <= 25) {
        $devisePaiement = $devisesToutes[(int) $reference['id']];
    }
    $du = (float) $d['reste'] * $ev['part'];
    $enPaiement = (int) $devisePaiement['id'] === (int) $deviseDette['id'] ? $du : ($dettesRepo->convertir($du, (int) $deviseDette['id'], (int) $devisePaiement['id'], $date) ?? 0.0);
    if ($ev['part'] < 1) {
        $pas = (int) $devisePaiement['decimales'] === 0 ? 500 : 1;
        $enPaiement = floor($enPaiement / $pas) * $pas;
    } else {
        $facteur = 10 ** (int) $devisePaiement['decimales'];
        $enPaiement = (int) $devisePaiement['id'] === (int) $deviseDette['id'] ? $enPaiement : floor($enPaiement * $facteur) / $facteur;
    }
    if ($enPaiement <= 0) {
        continue;
    }
    $compte = compteCaisse($contextes[$etabId]['comptes'], mt_rand(1, 100) <= 70 ? TYPE_CAISSE : TYPE_MOBILE, $devisePaiement)
        ?? compteCaisse($contextes[$etabId]['comptes'], TYPE_CAISSE, $devisePaiement);
    if ($compte === null) {
        continue;
    }
    $parQui = $encaisseurs[$etabId] ?? [$gerantDe($etabId)];
    try {
        $id = $dettesRepo->rembourser([
            'client_id' => (int) $d['client_id'], 'etablissement_id' => $etabId, 'compte' => ['id' => (int) $compte['id'], 'type' => $compte['type']],
            'devise_id' => (int) $devisePaiement['id'], 'montant' => $enPaiement, 'utilisateur_id' => $parQui[mt_rand(0, count($parQui) - 1)],
            'cle' => cleDemo(), 'dettes' => [(int) $d['id']], 'date' => $date,
        ]);
        $remboursements[] = ['id' => $id, 'part' => $ev['part'], 'etablissement' => $etabId];
    } catch (DomainException) {
        continue;
    }
}

// Un remboursement saisi deux fois, annulé par le gérant
$annule = null;
foreach (array_reverse($remboursements) as $r) {
    if ($r['part'] < 1) {
        try {
            $dettesRepo->annulerRemboursement($r['id'], $gerantDe($r['etablissement']), 'Saisi deux fois par erreur à la caisse');
            $annule = $r['id'];
        } catch (DomainException) {
        }
        break;
    }
}

// Décisions du gérant sur deux ardoises échues sans remboursement : une mise à la charge du serveur, une remise
$aujourdhui = date('Y-m-d');
$decisions = ['a_charge' => null, 'remise' => null];
foreach ($listeDettes() as $d) {
    if ($d['statut'] !== 'ouverte' || $d['vente_id'] === null || $d['echeance'] >= date('Y-m-d', strtotime('-4 days')) || $dettesRepo->aDesRemboursements((int) $d['id'])) {
        continue;
    }
    $etabId = (int) $d['etablissement_id'];
    if ($decisions['a_charge'] === null && $d['responsable_id'] !== null && in_array((int) $d['responsable_id'], $contextes[$etabId]['serveurs'], true)) {
        $decisions['a_charge'] = $dettesRepo->mettreALaCharge((int) $d['id'], $gerantDe($etabId),
            'Client injoignable depuis l\'échéance ; ardoise accordée sans garant', $aujourdhui);
        continue;
    }
    if ($decisions['remise'] === null && $decisions['a_charge'] !== null) {
        $dettesRepo->cloturer((int) $d['id'], 'remise', $gerantDe($etabId), 'Client fidèle : geste commercial après la coupure de courant');
        $decisions['remise'] = (int) $d['id'];
    }
    if ($decisions['remise'] !== null) {
        break;
    }
}

// Rappels inscrits au journal (comme bin/relances.php : la veille de l'échéance, puis J+1, J+7, J+30), au client
// et au responsable, tant que l'ardoise était ouverte ; la plupart sans envoi (messagerie pas encore réglée)
$etapes = array_map('intval', explode(',', (string) ($parametres['relances.etapes'] ?? '-1,1,7,30')));
$nbMessages = 0;
foreach ($listeDettes() as $d) {
    $devise = $devisesToutes[(int) $d['devise_id']];
    $close = $d['statut'] !== 'ouverte' ? strtotime((string) $d['cloturee_le']) : PHP_INT_MAX;
    foreach ($etapes as $etape) {
        $moment = strtotime($d['echeance'] . ' 10:05') + $etape * 86400 + mt_rand(0, 900);
        if ($moment > $maintenant || $moment <= strtotime($d['cree_le']) || $moment >= $close) {
            continue;
        }
        $libelle = DetteRepository::libelleEtape($etape);
        $retard = $etape >= 0;
        $reste = (float) ($d['statut'] === 'ouverte' ? $d['reste'] : $d['montant']);
        $statut = mt_rand(1, 100) <= 80 ? 'simule' : 'envoye';
        $destinataires = [];
        if ($d['client_telephone'] && $d['client_relances']) {
            $langue = $d['client_langue'] ?: 'fr';
            $destinataires[] = ['rappel_client', $d['client_telephone'], (int) $d['client_id'], null, $d['client_canal'] ?: 'sms', I18n::dans($langue, 'msg.rappel_client.' . ($retard ? 'retard' : 'avant'), [
                'etab' => $d['etablissement_nom'], 'client' => explode(' ', $d['client_nom'])[0], 'reste' => montantSms($reste, $devise, $langue),
                'date' => $langue === 'fr' ? date('d/m/Y', strtotime($d['echeance'])) : date('M j, Y', strtotime($d['echeance'])), 'jours' => $etape,
            ])];
        }
        if ($d['responsable_telephone']) {
            $langue = $d['responsable_langue'] ?: 'fr';
            $destinataires[] = ['rappel_responsable', $d['responsable_telephone'], null, (int) $d['responsable_id'], 'sms', I18n::dans($langue, 'msg.rappel_responsable.' . ($retard ? 'retard' : 'avant'), [
                'etab' => $d['etablissement_nom'], 'responsable' => explode(' ', (string) $d['responsable_nom'])[0], 'client' => $d['client_nom'],
                'reste' => montantSms($reste, $devise, $langue), 'date' => date('d/m/Y', strtotime($d['echeance'])), 'jours' => $etape,
            ])];
        }
        foreach ($destinataires as [$evenement, $telephone, $clientId, $utilisateurId, $canal, $texte]) {
            $nbMessages += Database::executer(
                'INSERT IGNORE INTO messages (canal, destinataire, client_id, utilisateur_id, dette_id, evenement, etape, texte, statut, fournisseur_id, cree_le)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$canal, $telephone, $clientId, $utilisateurId, (int) $d['id'], $evenement, $libelle, $texte, $statut,
                 $statut === 'envoye' ? ($canal === 'whatsapp' ? 'wamid.demo' : 'ATXid_demo') . substr(md5((string) mt_rand()), 0, 10) : null, $horodatage($moment)]
            );
        }
    }
}
// Un échec d'envoi (solde du compte SMS épuisé), pour l'exemple
Database::executer(
    "UPDATE messages SET statut = 'echec', fournisseur_id = NULL, erreur = 'sms : HTTP 201 InsufficientBalance'
      WHERE id = (SELECT id FROM (SELECT m.id FROM messages m JOIN dettes d ON d.id = m.dette_id JOIN ventes v ON v.id = d.vente_id
                                   WHERE v.cle LIKE ? AND m.statut = 'envoye' AND m.canal = 'sms' ORDER BY m.cree_le DESC LIMIT 1) x)",
    [PREFIXE_CLE . '-%']
);

$compteDettes = ['n' => count($listeDettes()), 'ouvertes' => count(array_filter($listeDettes(), static fn (array $d) => $d['statut'] === 'ouverte'))];
echo sprintf(
    "%d clients, %d ardoises (%d encore ouvertes), %d remboursements%s, garants : %d acceptés, %d refusés, %d en attente ; %s%s%d rappels inscrits.\n",
    count(CLIENTS_DEMO), (int) $compteDettes['n'], (int) $compteDettes['ouvertes'], count($remboursements), $annule ? ' (1 annulé)' : '',
    $garanties['accepte'], $garanties['refuse'], $garanties['en_attente'],
    $decisions['a_charge'] ? '1 mise à la charge du responsable, ' : '', $decisions['remise'] ? '1 remise, ' : '', $nbMessages
);
