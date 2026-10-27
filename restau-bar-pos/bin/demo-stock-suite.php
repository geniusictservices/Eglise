<?php

declare(strict_types=1);

/**
 * Stock, suite : démonstration (après bin/demo-stock.php) pour Le Comptoir du Lac et le Volcano Club :
 *  - le Volcano Club bloque la vente d'un article épuisé (stock négatif « bloquer ») ;
 *  - préparations intermédiaires (sauce pili-pili, marinade des brochettes) utilisées par les fiches des plats,
 *    produites tous les trois jours en cuisine avec une date de péremption ;
 *  - caisse de la cuisine du Comptoir du Lac (fonds remis au chef) : transferts de trésorerie et achats au marché
 *    payés depuis ce fonds, avec photo et lots datés (un lot de crème fraîche périmé, du lait qui périme bientôt) ;
 *  - vente en gros à la Réserve du club : prix de gros des casiers, deux revendeurs, trois ventes (comptant,
 *    ardoise avec consigne facturée, vides rapportés) ;
 *  - sorties de stock de toutes les ventes rejouées (les plats consomment maintenant les préparations).
 *
 *   php bin/demo-stock-suite.php demo             crée cette démonstration (rien si elle existe déjà)
 *   php bin/demo-stock-suite.php demo --effacer   efface ce qu'elle a créé (bin/demo-stock.php --reset l'appelle)
 */

use App\Core\Config;
use App\Core\Database;
use App\Core\I18n;
use App\Core\Instance;
use App\Repositories\AchatRepository;
use App\Repositories\DetteRepository;
use App\Repositories\EmballageRepository;
use App\Repositories\GestionStockRepository;
use App\Repositories\GestionTresorerieRepository;
use App\Repositories\ProductionRepository;
use App\Repositories\RecetteRepository;
use App\Repositories\StockRepository;
use App\Repositories\VenteGrosRepository;
use App\Repositories\VenteRepository;

if (PHP_SAPI !== 'cli') {
    exit("À lancer en ligne de commande.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

function arreter(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$instance = null;
$effacer = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--effacer') {
        $effacer = true;
    } elseif ($instance === null && !str_starts_with($argument, '--')) {
        $instance = $argument;
    } else {
        arreter("Option inconnue : $argument");
    }
}
if ($instance === null) {
    arreter('Usage : php bin/demo-stock-suite.php <sous-domaine> [--effacer]');
}
if (Instance::charger($instance) === null) {
    arreter("Instance inconnue : $instance");
}
$parametres = array_column(Database::tous('SELECT cle, valeur FROM parametres'), 'valeur', 'cle');
date_default_timezone_set($parametres['fuseau_horaire'] ?? Config::get('fuseau_horaire'));
Database::executer('SET time_zone = ?', [date('P')]);
I18n::initialiser('fr');
if (!StockRepository::suite()) {
    arreter("La migration 021 (stock, suite) n'est pas appliquée : php bin/migrer.php $instance");
}

$stock = new StockRepository();
$tresorerie = new GestionTresorerieRepository();

// ---------- Effacement ----------

if ($effacer) {
    $pdo = Database::pdo();
    $pdo->beginTransaction();
    // Ventes en gros : leurs mouvements de trésorerie, ardoises et sorties de stock, puis la vente
    foreach (array_column(Database::tous('SELECT vente_id FROM ventes_gros'), 'vente_id') as $venteId) {
        $tresorerie->supprimerMouvements('vente', (int) $venteId);
        Database::executer('DELETE FROM dettes WHERE vente_id = ? AND NOT EXISTS (SELECT 1 FROM remboursement_dettes r WHERE r.dette_id = dettes.id)', [(int) $venteId]);
        Database::executer('DELETE FROM stock_mouvements WHERE source_id = ? AND type IN (\'vente\', \'recette\', \'vide_recupere\', \'annulation_vente\')', [(int) $venteId]);
        Database::executer('DELETE FROM ventes WHERE id = ?', [(int) $venteId]);
    }
    Database::executer("DELETE FROM stock_mouvements WHERE type = 'production'");
    Database::executer('DELETE FROM productions');
    Database::executer('DELETE FROM stock_lots');
    Database::executer("UPDATE lieux_stock SET vente_gros = 0");
    $grille = (new VenteGrosRepository())->grille();
    if ($grille !== null) {
        Database::executer('DELETE FROM prix WHERE grille_prix_id = ?', [$grille]);
    }
    // Caisse de la cuisine : achats au marché (réceptions payées par ce fonds), transferts, compte
    $fonds = Database::un("SELECT id FROM comptes_tresorerie WHERE nom = 'Caisse cuisine (chef)'");
    if ($fonds !== null) {
        Database::executer('UPDATE stock_parametres SET compte_marche_id = NULL WHERE compte_marche_id = ?', [(int) $fonds['id']]);
        foreach (Database::tous('SELECT DISTINCT pf.id, pf.reception_id FROM paiements_fournisseurs pf JOIN paiement_fournisseur_lignes pl ON pl.paiement_id = pf.id WHERE pl.compte_id = ?', [(int) $fonds['id']]) as $p) {
            $tresorerie->supprimerMouvements('achat', (int) $p['id']);
            Database::executer('DELETE FROM stock_mouvements WHERE type = \'reception\' AND source_id = ?', [(int) $p['reception_id']]);
            Database::executer('DELETE FROM paiement_fournisseur_lignes WHERE paiement_id = ?', [(int) $p['id']]);
            Database::executer('DELETE FROM paiements_fournisseurs WHERE id = ?', [(int) $p['id']]);
            Database::executer('DELETE FROM reception_lignes WHERE reception_id = ?', [(int) $p['reception_id']]);
            Database::executer('DELETE FROM receptions WHERE id = ?', [(int) $p['reception_id']]);
        }
        foreach (Database::tous('SELECT id FROM transferts_tresorerie WHERE compte_source_id = ? OR compte_destination_id = ?', [(int) $fonds['id'], (int) $fonds['id']]) as $t) {
            $tresorerie->supprimerTransfert((int) $t['id']);
        }
        Database::executer('DELETE FROM mouvements_tresorerie WHERE compte_id = ?', [(int) $fonds['id']]);
        Database::executer('DELETE FROM compte_devises WHERE compte_id = ?', [(int) $fonds['id']]);
        Database::executer('DELETE FROM comptes_tresorerie WHERE id = ?', [(int) $fonds['id']]);
    }
    // Préparations : leurs fiches, puis les produits de la catégorie « Préparations »
    $categorie = Database::un("SELECT id FROM categories WHERE nom_fr = 'Préparations' AND parent_id IS NULL");
    if ($categorie !== null) {
        $ids = array_map('intval', array_column(Database::tous('SELECT id FROM produits WHERE categorie_id = ?', [(int) $categorie['id']]), 'id'));
        if ($ids !== []) {
            $liste = implode(',', $ids);
            Database::executer("DELETE FROM recette_ingredients WHERE produit_id IN ($liste)");
            Database::executer("DELETE FROM recettes WHERE produit_id IN ($liste)");
            Database::executer("DELETE FROM stock_mouvements WHERE produit_id IN ($liste)");
            Database::executer("DELETE FROM stock_niveaux WHERE produit_id IN ($liste)");
            Database::executer("DELETE FROM stock_couts WHERE produit_id IN ($liste)");
            Database::executer("DELETE FROM produits WHERE id IN ($liste)");
        }
        Database::executer('DELETE FROM categories WHERE id = ?', [(int) $categorie['id']]);
    }
    Database::executer("DELETE FROM parametres WHERE cle = 'demo_stock_suite'");
    $pdo->commit();
    $stock->oublierCache();
    echo "Démonstration « stock, suite » effacée.\n";
    exit(0);
}

$dejaFaite = Database::un("SELECT valeur FROM parametres WHERE cle = 'demo_stock_suite'");
if ($dejaFaite !== null) {
    echo "La démonstration « stock, suite » existe déjà (créée le {$dejaFaite['valeur']}).\n";
    exit(0);
}
if (Database::un("SELECT valeur FROM parametres WHERE cle = 'demo_stock'") === null) {
    arreter("Lancez d'abord la démonstration du stock : php bin/demo-stock.php $instance");
}

// ---------- Repères ----------

mt_srand(20261028);
$etabs = array_column(Database::tous('SELECT id, slug FROM etablissements'), 'id', 'slug');
$cdl = (int) ($etabs['comptoir-du-lac'] ?? 0);
$vc = (int) ($etabs['volcano-club'] ?? 0);
if (!$cdl || !$vc) {
    arreter('Établissements de démonstration introuvables (comptoir-du-lac, volcano-club).');
}
$utilisateur = static function (string $email): int {
    $u = Database::un('SELECT id FROM utilisateurs WHERE email = ?', [$email]);
    return $u ? (int) $u['id'] : (int) Database::un('SELECT MIN(id) AS id FROM utilisateurs')['id'];
};
$admin = (int) Database::un("SELECT ur.utilisateur_id AS id FROM utilisateur_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.code = 'proprietaire' ORDER BY ur.utilisateur_id LIMIT 1")['id'];
$chef = (int) (Database::un("SELECT ur.utilisateur_id AS id FROM utilisateur_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.code = 'chef_cuisine' ORDER BY ur.utilisateur_id LIMIT 1")['id'] ?? $admin);
$gerant = (int) (Database::un("SELECT ur.utilisateur_id AS id FROM utilisateur_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.code = 'gerant' ORDER BY ur.utilisateur_id LIMIT 1")['id'] ?? $admin);
$devises = array_column(Database::tous('SELECT * FROM devises'), null, 'code');
$actives = (new App\Repositories\DeviseRepository())->actives();
$usd = $actives[(int) $devises['USD']['id']];
$cdf = $actives[(int) $devises['CDF']['id']];
$aujourdhui = new DateTimeImmutable('today');
$jour = static fn (int $decalage, string $heure = '09:00'): string => $aujourdhui->modify(($decalage >= 0 ? '+' : '') . $decalage . ' days')->format('Y-m-d') . ' ' . $heure . ':00';
$lieu = static function (int $etab, string $nom): int {
    $l = Database::un('SELECT id FROM lieux_stock WHERE etablissement_id = ? AND nom = ?', [$etab, $nom]);
    if ($l === null) {
        arreter("Lieu introuvable : $nom");
    }
    return (int) $l['id'];
};
$produit = static function (string $nom): int {
    $p = Database::un('SELECT id FROM produits WHERE nom_fr = ? ORDER BY id LIMIT 1', [$nom]);
    if ($p === null) {
        arreter("Produit introuvable : $nom");
    }
    return (int) $p['id'];
};
$format = static function (int $produitId, string $nom): int {
    $c = Database::un('SELECT id FROM conditionnements WHERE produit_id = ? AND nom_fr = ?', [$produitId, $nom]);
    if ($c === null) {
        arreter("Format introuvable : $nom du produit $produitId");
    }
    return (int) $c['id'];
};
$L = [
    'cdl_cuisine' => $lieu($cdl, 'Cuisine'), 'cdl_froid' => $lieu($cdl, 'Chambre froide'),
    'vc_cuisine' => $lieu($vc, 'Cuisine du club'), 'vc_reserve' => $lieu($vc, 'Réserve du club'),
];

// ---------- Volcano Club : un article épuisé ne se vend plus ----------

$stock->definirParametres($vc, 'bloquer', true, $admin);
$stock->definirReglagesCuisine($vc, 2, null, $admin);

// ---------- Préparations intermédiaires ----------

$recettes = new RecetteRepository($stock);
$preparation = static function (string $fr, string $en, string $unite) use ($recettes): int {
    $p = Database::un('SELECT p.id FROM produits p JOIN categories c ON c.id = p.categorie_id WHERE p.nom_fr = ? AND c.nom_fr = \'Préparations\'', [$fr]);
    return $p !== null ? (int) $p['id'] : $recettes->creerPreparation($fr, $en, $unite);
};
$I = [
    'legumes' => $produit('Légumes (oignons, tomates, piment)'), 'huile' => $produit('Huile végétale'), 'citron' => $produit('Citron vert'),
];
$sauce = $preparation('Sauce pili-pili maison', 'House pili-pili sauce', 'cl');
$marinade = $preparation('Marinade des brochettes', 'Skewer marinade', 'portion');
// Sauce : 1 kg de légumes et 30 cl d'huile donnent 150 cl ; marinade : 600 g de légumes et 20 cl d'huile pour 12 portions
$recettes->enregistrer($sauce, 150, 'Mixer oignons, tomates et piment, cuire à l\'huile 20 minutes. Se garde 4 jours au froid.', true, [
    ['produit_id' => $I['legumes'], 'quantite' => 1.0, 'rendement_pct' => 90.0], ['produit_id' => $I['huile'], 'quantite' => 0.3, 'rendement_pct' => 100.0],
], $chef);
$recettes->enregistrer($marinade, 12, 'Oignons et piment hachés, huile et épices ; la viande y repose une nuit.', true, [
    ['produit_id' => $I['legumes'], 'quantite' => 0.6, 'rendement_pct' => 90.0], ['produit_id' => $I['huile'], 'quantite' => 0.2, 'rendement_pct' => 100.0],
], $chef);
// Les plats utilisent les préparations (à la place d'une partie des légumes)
$ajouter = static function (int $plat, int $prep, float $quantite, array $retirer = []) use ($recettes, $chef): void {
    $r = $recettes->recette($plat);
    if ($r === null) {
        return;
    }
    $ingredients = [];
    foreach ($r['ingredients'] as $i) {
        if ((int) $i['produit_id'] === $prep) {
            continue;
        }
        $q = (float) $i['quantite'] - ($retirer[(int) $i['produit_id']] ?? 0);
        if ($q > 0.0005) {
            $ingredients[] = ['produit_id' => (int) $i['produit_id'], 'quantite' => $q, 'rendement_pct' => (float) $i['rendement_pct']];
        }
    }
    $ingredients[] = ['produit_id' => $prep, 'quantite' => $quantite, 'rendement_pct' => 100.0];
    $recettes->enregistrer($plat, (float) $r['rendement'], $r['notes'], (bool) $r['actif'], $ingredients, $chef);
};
$plats = ['tilapia' => $produit('Tilapia grillé du lac'), 'ailes' => $produit('Ailes de poulet'), 'brochettes' => $produit('Brochettes de chèvre')];
$ajouter($plats['tilapia'], $sauce, 5, [$I['legumes'] => 0.05]);
$ajouter($plats['ailes'], $sauce, 4, [$I['legumes'] => 0.03]);
$ajouter($plats['brochettes'], $marinade, 1, [$I['legumes'] => 0.05]);
$stock->oublierCache();

// Productions tous les trois jours, de quoi tenir jusqu'à la suivante (plus une marge) : consommation réelle des ventes
$premiere = Database::un('SELECT MIN(cree_le) AS d FROM ventes')['d'] ?? $jour(-21);
$debut = (new DateTimeImmutable(substr($premiere, 0, 10)));
$besoin = static function (int $etab, int $plat, string $de, string $a): float {
    return (float) Database::un(
        "SELECT COALESCE(SUM(l.quantite * COALESCE(c.quantite_base, 1)), 0) AS q FROM vente_lignes l JOIN ventes v ON v.id = l.vente_id
      LEFT JOIN conditionnements c ON c.id = l.conditionnement_id
          WHERE v.etablissement_id = ? AND v.statut = 'payee' AND COALESCE(c.produit_id, l.produit_id) = ? AND v.cree_le >= ? AND v.cree_le < ?",
        [$etab, $plat, $de, $a]
    )['q'];
};
$productions = new ProductionRepository($stock);
// [établissement, lieu, préparation, plats => quantité de préparation par plat, conservation en jours]
$plan = [
    [$cdl, $L['cdl_cuisine'], $sauce, [$plats['tilapia'] => 5, $plats['ailes'] => 4], 4, 1.15],
    [$cdl, $L['cdl_cuisine'], $marinade, [$plats['brochettes'] => 1], 3, 1.15],
    [$vc, $L['vc_cuisine'], $sauce, [$plats['ailes'] => 4], 4, 1.0],
];
$n = 0;
for ($d = $debut; $d <= $aujourdhui; $d = $d->modify('+3 days')) {
    $fin = $d->modify('+3 days');
    $dernier = $fin > $aujourdhui;
    foreach ($plan as [$etab, $lieuId, $prep, $parPlat, $conservation, $marge]) {
        $q = 0.0;
        foreach ($parPlat as $plat => $parPortion) {
            $q += $besoin($etab, $plat, $d->format('Y-m-d') . ' 00:00:00', $fin->format('Y-m-d') . ' 00:00:00') * $parPortion;
        }
        // La dernière production couvre aussi les jours qui viennent ; arrondi à la marmite (10 cl, 1 portion)
        $q = $dernier ? max($q * 1.2, $prep === $marinade ? 12 : 40) + ($prep === $marinade ? 12 : 40) : $q * $marge;
        $q = $prep === $marinade ? ceil($q) : ceil($q / 10) * 10;
        if ($q <= 0) {
            continue;
        }
        $productions->produire($lieuId, $prep, $q, $d->format('Y-m-d') . ' 07:30:00', $d->modify('+' . $conservation . ' days')->format('Y-m-d'),
            $prep === $sauce ? 'Marmite du matin' : null, $chef);
        $n++;
    }
}
echo "Préparations : sauce pili-pili et marinade, $n productions.\n";

// ---------- Caisse de la cuisine (fonds remis au chef) et achats au marché ----------

$fondsId = (int) (Database::un("SELECT id FROM comptes_tresorerie WHERE nom = 'Caisse cuisine (chef)'")['id'] ?? 0);
if ($fondsId === 0) {
    $fondsId = $tresorerie->creerCompte([
        'nom' => 'Caisse cuisine (chef)', 'type' => 'caisse', 'operateur' => null, 'numero' => null, 'etablissement_id' => $cdl,
        'solde_initial_le' => $jour(-12, '07:00'), 'position' => $tresorerie->positionSuivante('caisse'), 'devises' => [(int) $cdf['id'] => 0.0],
    ], $admin);
}
$stock->definirReglagesCuisine($cdl, 3, $fondsId, $admin);
$caisseComptoir = Database::un("SELECT id FROM comptes_tresorerie WHERE etablissement_id = ? AND type = 'caisse' AND id <> ? ORDER BY position, id LIMIT 1", [$cdl, $fondsId]);
$comptes = $tresorerie->comptesActifs();
if ($caisseComptoir !== null) {
    $tresorerie->creerTransfert($comptes[(int) $caisseComptoir['id']], (int) $cdf['id'], $comptes[$fondsId], (int) $cdf['id'], 400000, 400000, $jour(-11, '07:15'), 'Fonds du marché remis au chef', $gerant);
    $tresorerie->creerTransfert($comptes[(int) $caisseComptoir['id']], (int) $cdf['id'], $comptes[$fondsId], (int) $cdf['id'], 250000, 250000, $jour(-5, '07:10'), 'Complément du fonds du marché', $gerant);
}
$ingredients = (int) Database::un("SELECT id FROM categories WHERE nom_fr = 'Ingrédients' AND parent_id IS NULL")['id'];
$nouvelIngredient = static function (string $fr, string $en, string $unite, string $formatFr, string $formatEn) use ($ingredients): int {
    $p = Database::un('SELECT id FROM produits WHERE nom_fr = ? AND categorie_id = ?', [$fr, $ingredients]);
    if ($p !== null) {
        return (int) $p['id'];
    }
    $position = (int) Database::un('SELECT COALESCE(MAX(position), 0) + 1 AS p FROM produits WHERE categorie_id = ?', [$ingredients])['p'];
    Database::executer('INSERT INTO produits (categorie_id, nom_fr, nom_en, unite_base, visible_public, position) VALUES (?, ?, ?, ?, 0, ?)', [$ingredients, $fr, $en, $unite, $position]);
    $id = Database::dernierId();
    Database::executer('INSERT INTO conditionnements (produit_id, nom_fr, nom_en, quantite_base, vendable, achetable, position) VALUES (?, ?, ?, 1, 0, 1, 1)', [$id, $formatFr, $formatEn]);
    return $id;
};
$I['tilapia'] = $produit('Tilapia frais');
$I['poulet'] = $produit('Poulet entier');
$I['creme'] = $nouvelIngredient('Crème fraîche', 'Fresh cream', 'piece', 'Pot 20 cl', '20 cl pot');
$I['lait'] = $nouvelIngredient('Lait frais', 'Fresh milk', 'l', 'Litre', 'Litre');
$marche = Database::un("SELECT id FROM fournisseurs WHERE type = 'marche' AND actif = 1 ORDER BY id LIMIT 1");
$achats = new AchatRepository($stock, $tresorerie);
$marcheId = $marche !== null ? (int) $marche['id'] : $achats->enregistrerFournisseur(null, [
    'nom' => 'Marché de Virunga', 'type' => 'marche', 'contact' => null, 'telephone' => null, 'email' => null, 'adresse' => 'Goma',
    'delai_paiement' => 0, 'devise_id' => (int) $cdf['id'], 'notes' => null, 'actif' => true,
]);
/** Photo de ticket de marché (JPEG dessiné) dans les envois de l'instance. */
$photo = static function (string $texte): ?string {
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }
    $relatif = '/uploads/' . Instance::courante()->sousDomaine . '/achats';
    $dossier = RACINE . '/public' . $relatif;
    if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
        return null;
    }
    $image = imagecreatetruecolor(640, 860);
    imagefill($image, 0, 0, imagecolorallocate($image, 246, 240, 226));
    $encre = imagecolorallocate($image, 60, 52, 44);
    imagefilledrectangle($image, 40, 40, 600, 820, imagecolorallocate($image, 255, 252, 244));
    foreach (explode("\n", $texte) as $i => $ligne) {
        imagestring($image, 5, 70, 80 + $i * 34, iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $ligne) ?: $ligne, $encre);
    }
    $nom = 'mar-' . bin2hex(random_bytes(8)) . '.jpg';
    imagejpeg($image, $dossier . '/' . $nom, 80);
    imagedestroy($image);
    return $relatif . '/' . $nom;
};
$articles = (new GestionStockRepository($stock))->articles();
$ligne = static function (int $produitId, float $quantite, float $prix, ?string $peremption = null) use ($articles): array {
    $f = GestionStockRepository::formatUnite($articles[$produitId]);
    return ['produit_id' => $produitId, 'conditionnement_id' => (int) $f['id'], 'quantite' => $quantite, 'quantite_base' => $quantite * (float) $f['quantite_base'],
            'prix' => $prix, 'prix_unitaire' => $prix, 'commande_ligne_id' => null, 'quantite_commandee' => null, 'peremption' => $peremption, 'lot_numero' => null];
};
// [jour, lieu, lignes [produit, quantité, prix FC, péremption (jours)]]
$courses = [
    [-10, 'cdl_cuisine', [['tilapia', 8, 9000, 2], ['legumes', 6, 2500, 5]]],
    [-8, 'cdl_cuisine', [['poulet', 6, 14000, 3], ['creme', 4, 3500, 4]]],
    [-6, 'cdl_cuisine', [['legumes', 5, 2600, 5], ['tilapia', 6, 9500, 2]]],
    [-3, 'cdl_cuisine', [['legumes', 6, 2600, 5], ['huile', 3, 4500, 90], ['citron', 30, 300, 10], ['lait', 3, 2200, 2]]],
    [-1, 'cdl_cuisine', [['tilapia', 5, 9200, 2], ['lait', 6, 2000, 3], ['poulet', 4, 14500, 3]]],
];
$fonds = $tresorerie->comptesActifs()[$fondsId];
foreach ($courses as [$decalage, $lieuCle, $lignes]) {
    $date = $jour($decalage, '06:45');
    $liste = [];
    $total = 0.0;
    $texte = "MARCHE DE VIRUNGA\n" . substr($date, 0, 10) . "\n\n";
    foreach ($lignes as [$cle, $q, $prix, $conservation]) {
        $liste[] = $ligne($I[$cle], $q, $prix, $aujourdhui->modify(($decalage + $conservation >= 0 ? '+' : '') . ($decalage + $conservation) . ' days')->format('Y-m-d'));
        $total += $q * $prix;
        $texte .= sprintf("%-18s %4s x %6d\n", champ($articles[$I[$cle]], 'nom'), (string) $q, $prix);
    }
    $texte .= "\nTOTAL " . number_format($total, 0, ',', ' ') . ' FC';
    $achats->creerReception([
        'fournisseur_id' => $marcheId, 'commande_id' => null, 'lieu_id' => $L[$lieuCle], 'etablissement_id' => $cdl,
        'devise' => $cdf, 'date' => $date, 'facture_numero' => null, 'sans_facture' => true, 'justificatif' => $photo($texte),
        'echeance' => null, 'notes' => 'Courses du matin (chef)',
    ], $liste, [], [['compte' => $fonds, 'devise' => $cdf, 'montant' => $total, 'contre_valeur' => $total]], $chef);
    $fonds = $tresorerie->comptesActifs()[$fondsId];
}
echo "Caisse de la cuisine : 2 remises de fonds, " . count($courses) . " achats au marché.\n";

// ---------- Vente en gros à la Réserve du club ----------

$gros = new VenteGrosRepository();
(new GestionStockRepository($stock))->definirVenteGros($L['vc_reserve'], true);
$P = ['primus_g' => $produit('Primus Grande'), 'primus_p' => $produit('Primus Petite'), 'mutzig_g' => $produit('Mützig Grande'), 'mutzig_p' => $produit('Mützig Petite')];
$prixGros = [];
// Casier et bouteille, en francs et en dollars
foreach ([['primus_g', 54000, 19.0, 4700], ['mutzig_g', 72000, 25.5, 6200], ['primus_p', 67000, 23.5, 2900], ['mutzig_p', 75000, 26.5, 3200]] as [$cle, $casier, $casierUsd, $bouteille]) {
    $prixGros[$format($P[$cle], 'Casier')] = [(int) $cdf['id'] => (float) $casier, (int) $usd['id'] => $casierUsd];
    $prixGros[$format($P[$cle], 'Bouteille')] = [(int) $cdf['id'] => (float) $bouteille];
}
$gros->definirPrix($prixGros, $admin);
$dettes = new DetteRepository();
$clients = [
    'bahati' => (int) (Database::un("SELECT id FROM clients WHERE nom = 'Patient Bahati'")['id'] ?? 0),
    'furaha' => (int) (Database::un("SELECT id FROM clients WHERE nom = 'Dépôt Maman Furaha'")['id'] ?? 0),
];
if ($clients['bahati'] === 0) {
    $clients['bahati'] = $dettes->creerClient(['nom' => 'Patient Bahati', 'telephone' => '+243 820 123 410', 'type' => 'revendeur', 'plafond' => 800000, 'plafond_devise_id' => (int) $cdf['id'],
        'notes' => 'Achète des casiers de Primus pour sa boutique de Birere.'], $admin);
}
if ($clients['furaha'] === 0) {
    $clients['furaha'] = $dettes->creerClient(['nom' => 'Dépôt Maman Furaha', 'telephone' => '+243 997 552 180', 'type' => 'revendeur', 'plafond' => 1500000,
        'plafond_devise_id' => (int) $cdf['id'], 'notes' => 'Dépôt de boissons du quartier Katindo ; paie en fin de semaine.'], $admin);
}
$emballages = new EmballageRepository($stock);
$catalogueEmb = $emballages->emballages();
$liens = $emballages->liens();
$etabVc = Database::un('SELECT * FROM etablissements WHERE id = ?', [$vc]);
$comptesVc = array_filter($tresorerie->comptesActifs(), static fn (array $c): bool => (int) $c['etablissement_id'] === $vc && GestionTresorerieRepository::tient($c, (int) $cdf['id']));
$caisseClub = array_values(array_filter($comptesVc, static fn (array $c): bool => $c['type'] === 'caisse'))[0] ?? null;
$mobileClub = array_values(array_filter($comptesVc, static fn (array $c): bool => $c['type'] === 'mobile_money'))[0] ?? $caisseClub;
if ($caisseClub === null) {
    arreter('Pas de caisse en francs au Volcano Club.');
}
$venteGros = static function (string $client, string $date, array $lignes, bool $facturer, array $retours, array $paiements, ?string $echeance, ?string $notes)
    use ($gros, $catalogueEmb, $liens, $emballages, $clients, $cdf, $usd, $etabVc, $L, $gerant, $articles, $achats, $prixGros, $format, $P): int {
    $taux = (float) $cdf['taux'];
    $venteLignes = [];
    $total = 0.0;
    $sortants = [];
    foreach ($lignes as [$cle, $nomFormat, $q]) {
        $cond = $format($P[$cle], $nomFormat);
        $prix = $prixGros[$cond][(int) $cdf['id']];
        $venteLignes[] = ['produit_id' => $P[$cle], 'conditionnement_id' => $cond, 'libelle' => champ($articles[$P[$cle]], 'nom'), 'format' => $nomFormat,
                          'quantite' => $q, 'prix' => $prix, 'prix_reference' => round($prix / $taux, 3)];
        $total += $q * $prix;
        foreach ($liens[$cond] ?? [] as $emb => $parFormat) {
            $sortants[$emb] = ($sortants[$emb] ?? 0) + $parFormat * $q;
        }
    }
    $mouvements = [];
    foreach ($sortants as $emb => $q) {
        $valeur = $achats->convertir((float) $catalogueEmb[$emb]['valeur_consigne'], (int) $catalogueEmb[$emb]['devise_id'], $cdf, $date);
        if ($facturer) {
            $venteLignes[] = ['produit_id' => null, 'conditionnement_id' => null, 'libelle' => 'Consigne · ' . champ($catalogueEmb[$emb], 'nom'), 'format' => null,
                              'quantite' => $q, 'prix' => $valeur, 'prix_reference' => round($valeur / $taux, 3)];
            $total += $q * $valeur;
        }
        $mouvements[] = ['produit_id' => $emb, 'type' => $facturer ? 'consigne_facturee' : 'pret', 'quantite' => $q, 'valeur' => $facturer ? $q * $valeur : null, 'devise_id' => $facturer ? (int) $cdf['id'] : null];
    }
    foreach ($retours as $emb => $q) {
        $mouvements[] = ['produit_id' => $emb, 'type' => 'retour', 'quantite' => -$q, 'valeur' => null, 'devise_id' => null];
    }
    $total = round($total);
    $payes = [];
    $paye = 0.0;
    foreach ($paiements as [$compte, $montant]) {
        $montant = $montant ?? $total - $paye;
        $payes[] = ['moyen' => $compte['type'], 'compte' => $compte, 'devise' => $cdf, 'recu' => $montant, 'taux' => $taux];
        $paye += $montant;
    }
    $credits = $total - $paye > 0.5 ? [['client_id' => $clients[$client], 'devise' => $cdf, 'montant' => $total - $paye, 'echeance' => $echeance,
                                         'servi_par' => $gerant, 'garant_id' => null, 'note' => 'Vente en gros']] : [];
    $cle = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
    StockRepository::prevoirVenteGros($cle, $L['vc_reserve'], $retours);
    return (new VenteRepository())->enregistrer([
        'etablissement' => $etabVc, 'cle' => $cle, 'zone_id' => null, 'grille_id' => $gros->grille(), 'utilisateur_id' => $gerant, 'appareil_id' => null,
        'devise' => $cdf, 'total' => $total, 'reference' => $usd, 'total_reference' => round($total / $taux, 3),
        'totaux' => [['devise' => $cdf, 'total' => $total, 'taux' => $taux], ['devise' => $usd, 'total' => round($total / $taux, 3), 'taux' => 1.0]],
        'lignes' => $venteLignes, 'paiements' => $payes, 'rendu' => null, 'credits' => $credits, 'session_id' => null, 'date' => $date,
        'apres' => static fn (int $venteId) => $gros->rattacher($venteId, $L['vc_reserve'], $clients[$client], $facturer, $notes, $mouvements, $date, $gerant),
    ]);
};
$embId = array_column(array_map(static fn (array $e): array => ['nom' => $e['nom_fr'], 'id' => (int) $e['produit_id']], $catalogueEmb), 'id', 'nom');
$venteGros('bahati', $jour(-6, '10:20'), [['primus_g', 'Casier', 5]], false, [], [[$caisseClub, null]], null, 'Livraison à Birere');
$venteGros('furaha', $jour(-4, '11:05'), [['mutzig_g', 'Casier', 4], ['primus_p', 'Casier', 2]], true, [], [[$caisseClub, 250000.0]], substr($jour(10), 0, 10), 'Paie le samedi');
$venteGros('bahati', $jour(-2, '16:40'), [['primus_g', 'Casier', 6]], false,
    array_filter([($embId['Casier vide Bralima (12 grandes)'] ?? 0) => 5.0, ($embId['Bouteille vide 72 cl'] ?? 0) => 60.0], static fn ($q, $id) => $id > 0, ARRAY_FILTER_USE_BOTH),
    [[$mobileClub, null]], null, 'A rapporté les vides de la dernière fois');
echo "Vente en gros : Réserve du club, 2 revendeurs, 3 ventes.\n";

// ---------- Sorties de stock de toutes les ventes, avec les préparations ----------

$n = $stock->rejouerVentes();
Database::executer("REPLACE INTO parametres (cle, valeur) VALUES ('demo_stock_suite', ?)", [date('Y-m-d H:i')]);
echo "Sorties de stock rejouées pour $n ventes. Démonstration « stock, suite » prête.\n";
