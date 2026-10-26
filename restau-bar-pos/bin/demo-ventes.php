<?php

declare(strict_types=1);

/**
 * Ventes de démonstration : environ trois semaines de tickets pour les deux établissements de la démo
 * (Comptoir du Lac en journée et en soirée tous les jours, Volcano Club les nuits du jeudi au samedi),
 * vendus par les personnes qui ont le droit d'encaisser (caisse.encaisser), avec les produits et les prix
 * du vrai catalogue (CatalogueRepository::menu(), comme la caisse) et des paiements variés
 * (francs ou dollars en espèces, M-Pesa, Airtel Money, carte, deux devises, monnaie rendue).
 *
 * Chaque vente passe par VenteRepository::enregistrer() : les soldes de la trésorerie restent justes.
 *
 *   php bin/demo-ventes.php demo              refuse si des ventes de démonstration existent déjà
 *   php bin/demo-ventes.php demo --reset      les supprime (avec leurs mouvements) et recommence
 *   php bin/demo-ventes.php demo --jours=14   nombre de jours avant aujourd'hui (21 par défaut)
 *
 * Les ventes de démonstration se reconnaissent à leur clé (« de305a1e-… ») ; les autres ventes ne sont jamais touchées.
 */

use App\Core\Acces;
use App\Core\Config;
use App\Core\Database;
use App\Core\I18n;
use App\Core\Instance;
use App\Core\Reglement;
use App\Repositories\CatalogueRepository;
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
const TYPE_CAISSE = 'especes';
const TYPE_MOBILE = 'mobile_money';
const TYPE_BANQUE = 'banque';

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
if ($existantes !== []) {
    if (!$reset) {
        arreter(count($existantes) . " ventes de démonstration existent déjà. Relancez avec --reset pour les remplacer.");
    }
    $pdo = Database::pdo();
    $pdo->beginTransaction();
    foreach (array_chunk($existantes, 500) as $paquet) {
        $marques = implode(',', array_fill(0, count($paquet), '?'));
        Database::executer("DELETE FROM mouvements_tresorerie WHERE nature = 'vente' AND source_id IN ($marques)", $paquet);
        Database::executer("DELETE FROM ventes WHERE id IN ($marques)", $paquet);
    }
    $pdo->commit();
    echo count($existantes) . " ventes de démonstration supprimées.\n";
}

mt_srand(20261026);

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
 * Seul endroit qui choisit un compte : un compte de ce type (caisse, mobile money, banque) qui reçoit
 * cette devise, parmi les comptes actifs de l'établissement (ou communs). Aujourd'hui un compte tient
 * une seule devise (comptes_tresorerie.devise_id) ; la devise du paiement reste toujours la sienne.
 */
function compteCaisse(array $comptes, string $type, ?array $devise): ?array
{
    if ($devise === null) {
        return null;
    }
    $liste = array_values(array_filter($comptes, static fn (array $c) =>
        $c['type'] === $type && (int) $c['devise_id'] === (int) $devise['id']));
    return $liste === [] ? null : $liste[mt_rand(0, count($liste) - 1)];
}

/** Arrondit un montant en espèces à ce qu'un client tend : billets de 500 FC, 1 000 FC… ou de 1, 5, 10 $. */
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
    $contextes[$etabId] = compact('etab', 'profil', 'acceptees', 'principale', 'comptes', 'zones', 'grilleDefaut', 'catalogues', 'appareils');

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

    // Paiements : francs en espèces, dollars en espèces, mobile money, carte, ou dollars + francs
    $compte = static fn (string $type, string $code): ?array => compteCaisse($c['comptes'], $type, $acceptees[$code] ?? null);
    $usd = $reference ? ($totaux[$reference['code']] ?? null) : null;
    $codeUsd = $reference['code'] ?? null;
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
        'mobile_fc' => $ajouter(TYPE_MOBILE, $codeP, $total),
        'mobile_usd' => $ajouter(TYPE_MOBILE, $codeUsd, $usd),
        'carte' => $ajouter(TYPE_BANQUE, $codeUsd, $usd),
        // Quelques billets de dollars, le reste en francs (ajouté plus bas)
        'mixte' => $ajouter(TYPE_CAISSE, $codeUsd, max(1.0, floor($usd * 0.7 / 5) * 5 ?: floor($usd * 0.6))),
        default => $ajouter(TYPE_CAISSE, $codeP, especesTendues($total, $principale)),
    };
    if (!$ok) {
        $ajouter(TYPE_CAISSE, $codeP, especesTendues($total, $principale));
    }
    $reglement = Reglement::calculer($totaux, $acceptees, array_map(static fn (array $x) => ['devise' => $x['devise']['code'], 'recu' => $x['recu']], $paiements), $codeP);
    if (!$reglement['regle']) {
        // Le reste en francs, en espèces (billets)
        $ajouter(TYPE_CAISSE, $codeP, especesTendues($reglement['reste'][$codeP], $principale));
        $reglement = Reglement::calculer($totaux, $acceptees, array_map(static fn (array $x) => ['devise' => $x['devise']['code'], 'recu' => $x['recu']], $paiements), $codeP);
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

    $ventes->enregistrer([
        'etablissement' => $c['etab'], 'cle' => cleDemo(), 'zone_id' => $zone ? (int) $zone['id'] : null,
        'grille_id' => $grille, 'utilisateur_id' => $p['vendeur'],
        'appareil_id' => $c['appareils'] !== [] && mt_rand(0, 9) < 7 ? $c['appareils'][array_rand($c['appareils'])] : null,
        'devise' => $principale, 'total' => $total,
        'reference' => $reference, 'total_reference' => $reference ? ($totaux[$reference['code']] ?? null) : null,
        'totaux' => array_map(static fn (string $code) => ['devise' => $acceptees[$code], 'total' => $totaux[$code], 'taux' => $tauxEnVigueur($acceptees[$code])], array_keys($totaux)),
        'lignes' => $lignes, 'paiements' => $paiements, 'rendu' => $rendu,
        'date' => $p['date'],
    ]);
    $compteur[$c['etab']['nom']] = ($compteur[$c['etab']['nom']] ?? 0) + 1;
}

foreach ($compteur as $nom => $n) {
    echo "$nom : $n ventes de démonstration.\n";
}
echo array_sum($compteur) . " ventes enregistrées sur " . ($jours + 1) . " jours.\n";
