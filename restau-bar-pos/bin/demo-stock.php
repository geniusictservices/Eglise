<?php

declare(strict_types=1);

/**
 * Stock et achats de démonstration, pour Le Comptoir du Lac et le Volcano Club :
 *  - dépôts et comptoirs (Réserve, Chambre froide, Bar, Cuisine ; Réserve du club, Bar principal, Bar VIP,
 *    Cuisine du club ; un dépôt central à Goma), routage (Plats et À grignoter → cuisine, Carré VIP → Bar VIP) ;
 *  - fournisseurs de Goma (Bralima, Brasimba, un distributeur de boissons, un importateur de spiritueux,
 *    le marché de Virunga), ingrédients et emballages consignés, fiches techniques des cocktails et des plats ;
 *  - stock d'ouverture la veille des premières ventes, réceptions payées (en partie) par la trésorerie,
 *    transferts validés des deux côtés, pertes, un inventaire validé avec des écarts et un autre à valider,
 *    seuils d'alerte (quelques articles finissent sous leur seuil : suggestions de commande).
 * Les quantités suivent les ventes de démonstration (bin/demo-ventes.php), dont les sorties de stock sont rejouées.
 *
 *   php bin/demo-stock.php demo                    crée la démonstration (rien si elle existe déjà)
 *   php bin/demo-stock.php demo --rejouer-ventes   refait seulement les sorties de stock de toutes les ventes
 *   php bin/demo-stock.php demo --reset            efface tout le stock et les achats, puis recrée la démonstration
 */

use App\Core\Config;
use App\Core\Database;
use App\Core\I18n;
use App\Core\Instance;
use App\Repositories\AchatRepository;
use App\Repositories\EmballageRepository;
use App\Repositories\GestionStockRepository;
use App\Repositories\GestionTresorerieRepository;
use App\Repositories\InventaireRepository;
use App\Repositories\RecetteRepository;
use App\Repositories\StockRepository;
use App\Repositories\TransfertStockRepository;

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
$rejouer = false;
$reset = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--rejouer-ventes') {
        $rejouer = true;
    } elseif ($argument === '--reset') {
        $reset = true;
    } elseif ($instance === null && !str_starts_with($argument, '--')) {
        $instance = $argument;
    } else {
        arreter("Option inconnue : $argument");
    }
}
if ($instance === null) {
    arreter('Usage : php bin/demo-stock.php <sous-domaine> [--rejouer-ventes] [--reset]');
}
if (Instance::charger($instance) === null) {
    arreter("Instance inconnue : $instance");
}
$parametres = array_column(Database::tous('SELECT cle, valeur FROM parametres'), 'valeur', 'cle');
date_default_timezone_set($parametres['fuseau_horaire'] ?? Config::get('fuseau_horaire'));
Database::executer('SET time_zone = ?', [date('P')]);
I18n::initialiser('fr');
if (Database::un("SHOW TABLES LIKE 'lieux_stock'") === null) {
    arreter("Le module de stock n'est pas installé : php bin/migrer.php $instance");
}

$stock = new StockRepository();
$tresorerie = new GestionTresorerieRepository();

if ($rejouer && !$reset) {
    $n = $stock->rejouerVentes();
    echo "Sorties de stock rejouées pour $n ventes.\n";
    exit(0);
}

$dejaFaite = Database::un("SELECT valeur FROM parametres WHERE cle = 'demo_stock'");
if ($dejaFaite !== null && !$reset) {
    echo "La démonstration du stock existe déjà (créée le {$dejaFaite['valeur']}). --rejouer-ventes refait les sorties des ventes, --reset recrée tout.\n";
    exit(0);
}

// ---------- Effacement (--reset) ----------

if ($reset) {
    $pdo = Database::pdo();
    $pdo->beginTransaction();
    foreach (array_column(Database::tous('SELECT id FROM paiements_fournisseurs'), 'id') as $id) {
        $tresorerie->supprimerMouvements('achat', (int) $id);
    }
    foreach ([
        'paiement_fournisseur_lignes', 'paiements_fournisseurs', 'emballage_mouvements', 'reception_lignes', 'receptions',
        'commande_lignes', 'commandes_achat', 'transfert_stock_lignes', 'transferts_stock', 'inventaire_lignes', 'inventaires',
        'pertes_stock', 'stock_mouvements', 'stock_niveaux', 'stock_couts', 'recette_ingredients', 'recettes',
        'conditionnement_emballages', 'emballages', 'fournisseurs', 'stock_routage_categories', 'stock_routage_zones',
        'stock_routage_appareils', 'stock_parametres', 'lieux_stock',
    ] as $table) {
        Database::executer("DELETE FROM $table");
    }
    $categories = array_column(Database::tous("SELECT id FROM categories WHERE nom_fr IN ('Ingrédients', 'Emballages') AND parent_id IS NULL"), 'id');
    foreach ($categories as $c) {
        Database::executer('DELETE FROM produits WHERE categorie_id = ?', [(int) $c]);
        Database::executer('DELETE FROM categories WHERE id = ?', [(int) $c]);
    }
    Database::executer("DELETE FROM parametres WHERE cle = 'demo_stock'");
    $pdo->commit();
    $stock->oublierCache();
    echo "Stock et achats effacés.\n";
}

// ---------- Repères de la démonstration ----------

mt_srand(20261027);

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
$patrick = $utilisateur('patrick@demo.test');
$grace = $utilisateur('grace@demo.test');
$devises = array_column(Database::tous('SELECT * FROM devises'), null, 'code');
$usd = (int) $devises['USD']['id'];
$cdf = (int) $devises['CDF']['id'];
$premiereVente = Database::un('SELECT MIN(cree_le) AS d FROM ventes')['d'] ?? date('Y-m-d H:i:s', strtotime('-21 days'));
$debut = new DateTimeImmutable(substr($premiereVente, 0, 10) . ' 07:30:00');
$debut = $debut->modify('-1 day');
$jour = static fn (int $n, string $heure = '09:00'): string => $debut->modify('+' . $n . ' days')->format('Y-m-d') . ' ' . $heure . ':00';
$aujourdhui = new DateTimeImmutable('today');
$joursPeriode = max(7, (int) $debut->diff($aujourdhui)->days);

// ---------- Lieux et routage ----------

$stock->assurerComptoirs();
$lieu = static function (?int $etab, string $type, string $nom, ?string $description = null): int {
    $l = Database::un('SELECT id FROM lieux_stock WHERE etablissement_id <=> ? AND nom = ?', [$etab, $nom]);
    if ($l !== null) {
        return (int) $l['id'];
    }
    return (new GestionStockRepository())->creerLieu($etab, $type, $nom, $description);
};
$barCdl = $stock->comptoirParDefaut($cdl);
$barVc = $stock->comptoirParDefaut($vc);
Database::executer("UPDATE lieux_stock SET nom = 'Bar principal' WHERE id = ? AND nom = 'Bar'", [$barVc]);
$L = [
    'cdl_reserve' => $lieu($cdl, 'depot', 'Réserve', 'Boissons en casiers et cartons, derrière le bar'),
    'cdl_froid' => $lieu($cdl, 'depot', 'Chambre froide', 'Poisson, viande, légumes'),
    'cdl_bar' => $barCdl,
    'cdl_cuisine' => $lieu($cdl, 'comptoir', 'Cuisine', 'Grill et friteuse'),
    'vc_reserve' => $lieu($vc, 'depot', 'Réserve du club'),
    'vc_bar' => $barVc,
    'vc_vip' => $lieu($vc, 'comptoir', 'Bar VIP', 'Carré VIP : bouteilles et champagne'),
    'vc_cuisine' => $lieu($vc, 'comptoir', 'Cuisine du club', 'Ailes et frites'),
    'central' => $lieu(null, 'depot', 'Dépôt central Goma', 'Avenue du Port, livré par Bralima'),
];
$categorie = static fn (string $nom): ?int => ($c = Database::un('SELECT id FROM categories WHERE nom_fr = ?', [$nom])) ? (int) $c['id'] : null;
$zone = static fn (int $etab, string $code): ?int => ($z = Database::un('SELECT id FROM zones WHERE etablissement_id = ? AND code = ?', [$etab, $code])) ? (int) $z['id'] : null;
$depot = new GestionStockRepository($stock);
$depot->definirRoutage($cdl, array_filter([$categorie('Plats') => $L['cdl_cuisine'], $categorie('À grignoter') => $L['cdl_cuisine']]), [], []);
$depot->definirRoutage($vc, array_filter([$categorie('À grignoter') => $L['vc_cuisine']]), array_filter([$zone($vc, 'vip') => $L['vc_vip']]), []);
$stock->definirParametres($cdl, 'vendre', true, $admin);
$stock->definirParametres($vc, 'vendre', true, $admin);
$stock->oublierCache();

// ---------- Articles : ingrédients et emballages ----------

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
$catIngredients = $categorie('Ingrédients');
if ($catIngredients === null) {
    $position = (int) Database::un('SELECT COALESCE(MAX(position), 0) + 1 AS p FROM categories WHERE parent_id IS NULL')['p'];
    Database::executer("INSERT INTO categories (nom_fr, nom_en, position, visible_public) VALUES ('Ingrédients', 'Ingredients', ?, 0)", [$position]);
    $catIngredients = Database::dernierId();
}
/** Ingrédient : produit caché de la vitrine et de la caisse (aucun format vendable), avec ses formats d'achat. */
$ingredient = static function (string $fr, string $en, string $unite, array $formats) use ($catIngredients): int {
    $p = Database::un('SELECT id FROM produits WHERE nom_fr = ? AND categorie_id = ?', [$fr, $catIngredients]);
    if ($p !== null) {
        return (int) $p['id'];
    }
    $position = (int) Database::un('SELECT COALESCE(MAX(position), 0) + 1 AS p FROM produits WHERE categorie_id = ?', [$catIngredients])['p'];
    Database::executer('INSERT INTO produits (categorie_id, nom_fr, nom_en, unite_base, visible_public, position) VALUES (?, ?, ?, ?, 0, ?)', [$catIngredients, $fr, $en, $unite, $position]);
    $id = Database::dernierId();
    foreach ($formats as $i => [$nomFr, $nomEn, $base, $achetable]) {
        Database::executer('INSERT INTO conditionnements (produit_id, nom_fr, nom_en, quantite_base, vendable, achetable, position) VALUES (?, ?, ?, ?, 0, ?, ?)', [$id, $nomFr, $nomEn, $base, (int) $achetable, $i + 1]);
    }
    return $id;
};
$I = [
    'rhum' => $ingredient('Rhum blanc', 'White rum', 'cl', [['Bouteille 70 cl', '70 cl bottle', 70, true]]),
    'menthe' => $ingredient('Menthe fraîche', 'Fresh mint', 'botte', [['Botte', 'Bunch', 1, true]]),
    'citron' => $ingredient('Citron vert', 'Lime', 'piece', [['Pièce', 'Piece', 1, false], ['Sac de 50', 'Bag of 50', 50, true]]),
    'sucre' => $ingredient('Sucre de canne', 'Cane sugar', 'g', [['Paquet 1 kg', '1 kg pack', 1000, true]]),
    'gazeuse' => $ingredient('Eau gazeuse', 'Soda water', 'cl', [['Bouteille 1 l', '1 l bottle', 100, true]]),
    'maracuja' => $ingredient('Purée de maracuja', 'Passion fruit purée', 'cl', [['Bouteille 1 l', '1 l bottle', 100, true]]),
    'grenadine' => $ingredient('Sirop de grenadine', 'Grenadine syrup', 'cl', [['Bouteille 75 cl', '75 cl bottle', 75, true]]),
    'tilapia' => $ingredient('Tilapia frais', 'Fresh tilapia', 'kg', [['Kilo', 'Kilo', 1, true], ['Carton 10 kg', '10 kg carton', 10, true]]),
    'poulet' => $ingredient('Poulet entier', 'Whole chicken', 'piece', [['Pièce', 'Piece', 1, true]]),
    'chevre' => $ingredient('Viande de chèvre', 'Goat meat', 'kg', [['Kilo', 'Kilo', 1, true]]),
    'ailes' => $ingredient('Ailes de poulet crues', 'Raw chicken wings', 'kg', [['Kilo', 'Kilo', 1, false], ['Carton 5 kg', '5 kg carton', 5, true]]),
    'pdt' => $ingredient('Pommes de terre', 'Potatoes', 'kg', [['Kilo', 'Kilo', 1, false], ['Sac 25 kg', '25 kg sack', 25, true]]),
    'legumes' => $ingredient('Légumes (oignons, tomates, piment)', 'Vegetables (onions, tomatoes, chilli)', 'kg', [['Kilo', 'Kilo', 1, true]]),
    'huile' => $ingredient('Huile végétale', 'Vegetable oil', 'l', [['Litre', 'Litre', 1, false], ['Bidon 5 l', '5 l jerrycan', 5, true]]),
];
$P = [
    'primus_g' => $produit('Primus Grande'), 'primus_p' => $produit('Primus Petite'),
    'mutzig_g' => $produit('Mützig Grande'), 'mutzig_p' => $produit('Mützig Petite'),
    'heineken' => $produit('Heineken'), 'guinness' => $produit('Guinness'), 'coca' => $produit('Coca-Cola'), 'eau' => $produit('Eau minérale'),
    'vin' => $produit('Vin rouge de la maison'), 'moet' => $produit('Moët & Chandon Brut'), 'jw' => $produit('Johnnie Walker Black Label'), 'hennessy' => $produit('Hennessy VS'),
    'jus' => $produit('Jus de maracuja'), 'kivu' => $produit('Kivu Sunset'), 'mojito' => $produit('Mojito'),
    'tilapia_plat' => $produit('Tilapia grillé du lac'), 'poulet_plat' => $produit('Poulet braisé'), 'brochettes' => $produit('Brochettes de chèvre'),
    'frites' => $produit('Frites'), 'ailes_plat' => $produit('Ailes de poulet'),
];
// Le Guinness et l'eau s'achètent aussi en cartons de 24
foreach (['guinness', 'eau'] as $cle) {
    if (Database::un("SELECT id FROM conditionnements WHERE produit_id = ? AND nom_fr = 'Carton'", [$P[$cle]]) === null) {
        Database::executer("INSERT INTO conditionnements (produit_id, nom_fr, nom_en, quantite_base, vendable, achetable, position) VALUES (?, 'Carton', 'Carton', 24, 0, 1, 9)", [$P[$cle]]);
    }
}

$emb = new EmballageRepository($stock);
$existants = array_column(array_map(static fn (array $e): array => ['nom' => $e['nom_fr'], 'id' => (int) $e['produit_id']], $emb->emballages()), 'id', 'nom');
$E = [];
foreach ([
    'casier12' => ['Casier vide Bralima (12 grandes)', 'Empty Bralima case (12 large)', 8000],
    'casier24' => ['Casier vide Bralima (24 petites)', 'Empty Bralima case (24 small)', 10000],
    'bouteille72' => ['Bouteille vide 72 cl', 'Empty 72 cl bottle', 500],
    'bouteille33' => ['Bouteille vide 33 cl', 'Empty 33 cl bottle', 300],
] as $cle => [$fr, $en, $valeur]) {
    $E[$cle] = $existants[$fr] ?? $emb->creerArticle($fr, $en, $valeur, $cdf);
}
foreach (['primus_g', 'mutzig_g'] as $cle) {
    $emb->lier($format($P[$cle], 'Casier'), $E['casier12'], 1);
    $emb->lier($format($P[$cle], 'Casier'), $E['bouteille72'], 12);
    $emb->lier($format($P[$cle], 'Bouteille'), $E['bouteille72'], 1);
}
foreach (['primus_p', 'mutzig_p'] as $cle) {
    $emb->lier($format($P[$cle], 'Casier'), $E['casier24'], 1);
    $emb->lier($format($P[$cle], 'Casier'), $E['bouteille33'], 24);
    $emb->lier($format($P[$cle], 'Bouteille'), $E['bouteille33'], 1);
}

// ---------- Fiches techniques ----------

$recettes = new RecetteRepository($stock);
$fiches = [
    'mojito' => [1, 'Piler la menthe avec le sucre et le citron, ajouter le rhum, la glace et l\'eau gazeuse.',
        [['rhum', 5, 100], ['menthe', 0.1, 100], ['citron', 0.5, 100], ['sucre', 15, 100], ['gazeuse', 10, 100]]],
    'kivu' => [1, 'Rhum, purée de maracuja, un trait de grenadine, citron vert, glace pilée.',
        [['rhum', 4, 100], ['maracuja', 6, 100], ['grenadine', 2, 100], ['citron', 0.5, 100]]],
    'jus' => [1, 'Purée de maracuja allongée d\'eau, sucrée.', [['maracuja', 10, 100], ['sucre', 10, 100]]],
    'tilapia_plat' => [1, 'Tilapia vidé et écaillé (60 % utilisable), grillé ; frites et sauce aux légumes.',
        [['tilapia', 0.4, 60], ['pdt', 0.3, 85], ['huile', 0.05, 100], ['legumes', 0.1, 100]]],
    'poulet_plat' => [1, 'Un demi-poulet braisé au charbon, sauce aux légumes.', [['poulet', 0.5, 100], ['legumes', 0.1, 100], ['huile', 0.03, 100]]],
    'brochettes' => [1, 'Quatre brochettes de chèvre marinée (80 % utilisable après parage).', [['chevre', 0.25, 80], ['legumes', 0.05, 100], ['huile', 0.02, 100]]],
    'frites' => [1, 'Pommes de terre épluchées (85 % utilisable), deux bains.', [['pdt', 0.3, 85], ['huile', 0.05, 100]]],
    'ailes_plat' => [1, 'Ailes marinées et frites, sauce pimentée.', [['ailes', 0.35, 100], ['huile', 0.03, 100], ['legumes', 0.03, 100]]],
];
foreach ($fiches as $cle => [$rendement, $notes, $ingredients]) {
    $recettes->enregistrer($P[$cle], $rendement, $notes, true, array_map(static fn (array $i): array => [
        'produit_id' => $I[$i[0]], 'quantite' => (float) $i[1], 'rendement_pct' => (float) $i[2],
    ], $ingredients), $admin);
}
$stock->oublierCache();

// ---------- Fournisseurs ----------

$achats = new AchatRepository($stock, $tresorerie);
$fournisseur = static function (array $v) use ($achats): int {
    $f = Database::un('SELECT id FROM fournisseurs WHERE nom = ?', [$v['nom']]);
    return $f ? (int) $f['id'] : $achats->enregistrerFournisseur(null, $v + ['actif' => true, 'email' => null, 'notes' => null]);
};
$F = [
    'bralima' => $fournisseur(['nom' => 'Bralima Goma', 'type' => 'brasserie', 'contact' => 'Service commercial', 'telephone' => '+243810100200',
        'adresse' => 'Avenue du Port, Goma', 'delai_paiement' => 15, 'devise_id' => $usd, 'notes' => 'Primus, Mützig, Turbo King. Livraison le mardi ; casiers et bouteilles consignés.']),
    'brasimba' => $fournisseur(['nom' => 'Brasimba', 'type' => 'brasserie', 'contact' => 'Dépôt de Goma', 'telephone' => '+243820300400',
        'adresse' => 'Boulevard Kanyamuhanga, Goma', 'delai_paiement' => 30, 'devise_id' => $usd, 'notes' => 'Heineken, Guinness.']),
    'kivu' => $fournisseur(['nom' => 'Kivu Boissons', 'type' => 'distributeur', 'contact' => 'Jeanine', 'telephone' => '+243970500600',
        'adresse' => 'Quartier Les Volcans, Goma', 'delai_paiement' => 7, 'devise_id' => $cdf, 'notes' => 'Coca-Cola, eaux, eau gazeuse, purée de fruits.']),
    'spiritueux' => $fournisseur(['nom' => 'Grands Lacs Spiritueux', 'type' => 'grossiste', 'contact' => 'M. Habimana', 'telephone' => '+250788123456',
        'adresse' => 'Gisenyi (Rwanda) et Goma', 'delai_paiement' => 30, 'devise_id' => $usd, 'notes' => 'Importateur : whisky, cognac, champagne, vins, rhum, sirops.']),
    'marche' => $fournisseur(['nom' => 'Marché de Virunga', 'type' => 'marche', 'contact' => null, 'telephone' => null,
        'adresse' => 'Marché Virunga, Goma', 'delai_paiement' => 0, 'devise_id' => $cdf, 'notes' => 'Achats au comptant, sans facture : poisson, viande, légumes, pommes de terre, citrons.']),
];

// Articles achetés : fournisseur, format d'achat, prix par format (devise du fournisseur)
$A = [
    'primus_g' => ['bralima', $format($P['primus_g'], 'Casier'), 11.0], 'primus_p' => ['bralima', $format($P['primus_p'], 'Casier'), 14.0],
    'mutzig_g' => ['bralima', $format($P['mutzig_g'], 'Casier'), 13.0], 'mutzig_p' => ['bralima', $format($P['mutzig_p'], 'Casier'), 16.0],
    'heineken' => ['brasimba', $format($P['heineken'], 'Carton'), 30.0], 'guinness' => ['brasimba', $format($P['guinness'], 'Carton'), 34.0],
    'coca' => ['kivu', $format($P['coca'], 'Pack'), 28000.0], 'eau' => ['kivu', $format($P['eau'], 'Carton'), 19000.0],
    'gazeuse' => ['kivu', $format($I['gazeuse'], 'Bouteille 1 l'), 2000.0], 'maracuja' => ['kivu', $format($I['maracuja'], 'Bouteille 1 l'), 6000.0],
    'vin' => ['spiritueux', $format($P['vin'], 'Bouteille 75 cl'), 6.0], 'moet' => ['spiritueux', $format($P['moet'], 'Bouteille'), 45.0],
    'jw' => ['spiritueux', $format($P['jw'], 'Bouteille 70 cl'), 28.0], 'hennessy' => ['spiritueux', $format($P['hennessy'], 'Bouteille 70 cl'), 35.0],
    'rhum' => ['spiritueux', $format($I['rhum'], 'Bouteille 70 cl'), 9.0], 'grenadine' => ['spiritueux', $format($I['grenadine'], 'Bouteille 75 cl'), 4.0],
    'tilapia' => ['marche', $format($I['tilapia'], 'Kilo'), 12000.0], 'poulet' => ['marche', $format($I['poulet'], 'Pièce'), 15000.0],
    'chevre' => ['marche', $format($I['chevre'], 'Kilo'), 14000.0], 'ailes' => ['marche', $format($I['ailes'], 'Carton 5 kg'), 40000.0],
    'pdt' => ['marche', $format($I['pdt'], 'Sac 25 kg'), 45000.0], 'legumes' => ['marche', $format($I['legumes'], 'Kilo'), 2500.0],
    'huile' => ['marche', $format($I['huile'], 'Bidon 5 l'), 22000.0], 'citron' => ['marche', $format($I['citron'], 'Sac de 50'), 10000.0],
    'menthe' => ['marche', $format($I['menthe'], 'Botte'), 500.0], 'sucre' => ['marche', $format($I['sucre'], 'Paquet 1 kg'), 3500.0],
];
$deviseFournisseur = ['bralima' => $usd, 'brasimba' => $usd, 'kivu' => $cdf, 'spiritueux' => $usd, 'marche' => $cdf];
$base = static fn (int $condId): float => (float) Database::un('SELECT quantite_base FROM conditionnements WHERE id = ?', [$condId])['quantite_base'];
$produitDe = static fn (int $condId): int => (int) Database::un('SELECT produit_id FROM conditionnements WHERE id = ?', [$condId])['produit_id'];
$tauxCdf = (float) (Database::un('SELECT taux FROM taux_change WHERE devise_id = ? ORDER BY valable_depuis DESC, id DESC LIMIT 1', [$cdf])['taux'] ?? 2850);
$coutBaseUsd = static function (string $cle) use ($A, $deviseFournisseur, $usd, $tauxCdf, $base): float {
    [$f, $cond, $prix] = $A[$cle];
    return $prix / $base($cond) / ($deviseFournisseur[$f] === $usd ? 1 : $tauxCdf);
};

// ---------- Consommation des ventes (essai à blanc, annulé) ----------

$pdo = Database::pdo();
$pdo->beginTransaction();
$stock->oublierCache();
Database::executer("DELETE FROM stock_mouvements WHERE type IN ('vente', 'recette', 'annulation_vente', 'vide_recupere')");
foreach (Database::tous('SELECT id FROM ventes ORDER BY cree_le, id') as $v) {
    $stock->sortieVente((int) $v['id']);
}
$conso = [];
foreach (Database::tous("SELECT lieu_id, produit_id, -SUM(quantite) AS q FROM stock_mouvements WHERE type IN ('vente', 'recette') GROUP BY lieu_id, produit_id") as $c) {
    $conso[(int) $c['lieu_id']][(int) $c['produit_id']] = (float) $c['q'];
}
$pdo->rollBack();
$stock->oublierCache();
$parJour = static fn (int $lieu, int $produitId): float => ($conso[$lieu][$produitId] ?? 0.0) / $joursPeriode;
echo "Consommation des ventes calculée sur $joursPeriode jours.\n";

// Clé d'article (dans $A ou $I) → produit
$cleProduit = static fn (string $cle): int => $P[$cle] ?? $I[$cle];
$arrondi = static fn (float $q, float $pas): float => max($pas, ceil($q / $pas - 0.0001) * $pas);

// ---------- Stock d'ouverture (la veille des premières ventes) ----------

$comptoirs = [
    $L['cdl_bar'] => ['depot' => $L['cdl_reserve'], 'etab' => $cdl],
    $L['cdl_cuisine'] => ['depot' => $L['cdl_froid'], 'etab' => $cdl],
    $L['vc_bar'] => ['depot' => $L['vc_reserve'], 'etab' => $vc],
    $L['vc_vip'] => ['depot' => $L['vc_reserve'], 'etab' => $vc],
    $L['vc_cuisine'] => ['depot' => $L['vc_reserve'], 'etab' => $vc],
];
$pdo->beginTransaction();
$ouverture = $jour(0, '07:30');
$valeurOuverture = [];
foreach ($comptoirs as $comptoir => $info) {
    foreach (array_keys($A) as $cle) {
        $pid = $cleProduit($cle);
        $c = $parJour($comptoir, $pid);
        if ($c <= 0) {
            continue;
        }
        $q = $arrondi($c * 3, $base($A[$cle][1]) >= 24 ? 6 : 1);
        $valeurOuverture[$pid] = ($valeurOuverture[$pid] ?? 0) + $q;
        $stock->mouvement($comptoir, $pid, $q, 'ouverture', null, $ouverture, 'Stock d\'ouverture compté', $info['etab'] === $cdl ? $patrick : $admin);
    }
}
// Dépôts : environ une semaine de ventes de leurs comptoirs, en formats d'achat
$parDepot = [];
foreach ($comptoirs as $comptoir => $info) {
    foreach (array_keys($A) as $cle) {
        $parDepot[$info['depot']][$cle] = ($parDepot[$info['depot']][$cle] ?? 0) + $parJour($comptoir, $cleProduit($cle));
    }
}
foreach ($parDepot as $depotId => $besoins) {
    foreach ($besoins as $cle => $c) {
        if ($c <= 0) {
            continue;
        }
        $taille = $base($A[$cle][1]);
        $q = $arrondi($c * 7, $taille);
        $pid = $cleProduit($cle);
        $valeurOuverture[$pid] = ($valeurOuverture[$pid] ?? 0) + $q;
        $stock->mouvement($depotId, $pid, $q, 'ouverture', null, $ouverture, 'Stock d\'ouverture compté', $depotId === $L['vc_reserve'] ? $admin : $patrick, $A[$cle][1], $q / $taille);
    }
}
// Dépôt central : bières Bralima en casiers
foreach (['primus_g' => 60, 'mutzig_g' => 40, 'primus_p' => 20] as $cle => $casiers) {
    $q = $casiers * $base($A[$cle][1]);
    $valeurOuverture[$P[$cle]] = ($valeurOuverture[$P[$cle]] ?? 0) + $q;
    $stock->mouvement($L['central'], $P[$cle], $q, 'ouverture', null, $ouverture, 'Stock d\'ouverture compté', $admin, $A[$cle][1], $casiers);
}
// Vides en stock au départ (réserves)
foreach ([[$L['cdl_reserve'], 'casier12', 40], [$L['cdl_reserve'], 'bouteille72', 300], [$L['cdl_reserve'], 'casier24', 10], [$L['cdl_reserve'], 'bouteille33', 120],
          [$L['vc_reserve'], 'casier12', 30], [$L['vc_reserve'], 'bouteille72', 200], [$L['central'], 'casier12', 30], [$L['central'], 'bouteille72', 300]] as [$l, $cle, $q]) {
    $stock->mouvement($l, $E[$cle], $q, 'ouverture', null, $ouverture, 'Vides comptés à l\'ouverture', $admin);
}
// Coût de départ : prix d'achat habituels
Database::executer('DELETE FROM stock_couts');
foreach (array_keys($A) as $cle) {
    $stock->majCump($cleProduit($cle), 1, $coutBaseUsd($cle), $usd);
}
$pdo->commit();
echo "Stock d'ouverture saisi.\n";

// ---------- Réceptions et paiements ----------

$comptes = array_column($tresorerie->comptesActifs(), null, 'nom');
$compte = static fn (string $nom): array => $comptes[$nom] ?? arreter("Compte de trésorerie introuvable : $nom");
$deviseRow = static fn (int $id): array => $tresorerie->devises()[$id];
/**
 * Réception d'un fournisseur dans un lieu, à une date, avec ses lignes [clé => nombre de formats], les vides
 * rendus et les paiements [compte, devise, montant | 'tout' | fraction].
 */
$recevoir = static function (string $f, int $lieuId, string $date, array $lignes, array $paiements = [], array $vides = [], ?string $facture = null, int $par = 0)
    use ($achats, $F, $A, $deviseFournisseur, $base, $produitDe, $compte, $deviseRow, $L): int {
    $devise = $deviseRow($deviseFournisseur[$f]);
    $etab = Database::un('SELECT etablissement_id FROM lieux_stock WHERE id = ?', [$lieuId])['etablissement_id'];
    $l = [];
    $total = 0.0;
    foreach ($lignes as $cle => $n) {
        [, $cond, $prix] = $A[$cle];
        // [nombre, prix] : prix différent de l'habituel (hausse de la brasserie)
        if (is_array($n)) {
            [$n, $prix] = $n;
        }
        $l[] = ['produit_id' => $produitDe($cond), 'conditionnement_id' => $cond, 'commande_ligne_id' => null, 'quantite_commandee' => null,
                'quantite' => (float) $n, 'quantite_base' => $n * $base($cond), 'prix_unitaire' => $prix];
        $total += $n * $prix;
    }
    $p = [];
    foreach ($paiements as [$nomCompte, $deviseId, $montant]) {
        $d = $deviseRow($deviseId);
        if ($montant === 'tout' || is_float($montant) && $montant <= 1) {
            $part = $montant === 'tout' ? 1.0 : $montant;
            $contre = round($total * $part, (int) $devise['decimales']);
            $montant = (int) $d['id'] === (int) $devise['id'] ? $contre : round($achats->convertir($contre, (int) $devise['id'], $d, $date), (int) $d['decimales']);
            if ((float) $d['arrondi'] > 0) {
                $montant = round($montant / (float) $d['arrondi']) * (float) $d['arrondi'];
            }
        }
        $p[] = ['compte' => $compte($nomCompte), 'devise' => $d, 'montant' => (float) $montant,
                'contre_valeur' => round($achats->convertir((float) $montant, (int) $d['id'], $devise, $date), 3)];
    }
    $couvert = array_sum(array_column($p, 'contre_valeur'));
    if ($couvert > $total && $couvert > 0) {
        foreach ($p as &$x) {
            $x['contre_valeur'] = round($x['contre_valeur'] * $total / $couvert, 3);
        }
        unset($x);
    }
    $fournisseur = $achats->fournisseur($F[$f]);
    $echeance = (int) $fournisseur['delai_paiement'] > 0 ? (new DateTimeImmutable($date))->modify('+' . (int) $fournisseur['delai_paiement'] . ' days')->format('Y-m-d') : null;
    return $achats->creerReception([
        'fournisseur_id' => $F[$f], 'commande_id' => null, 'lieu_id' => $lieuId, 'etablissement_id' => $etab !== null ? (int) $etab : null,
        'devise' => $devise, 'date' => $date, 'facture_numero' => $facture, 'sans_facture' => $facture === null, 'justificatif' => null,
        'echeance' => $echeance, 'notes' => null,
    ], $l, array_map(static fn (array $v): array => ['produit_id' => $v[0], 'rendus' => (float) $v[1], 'payes' => 0.0], $vides), $p, $par);
};

// Simulation jour par jour (consommation moyenne des ventes) : chaque livraison commande de quoi tenir
// jusqu'à la suivante du même fournisseur (plus une réserve), chaque comptoir est réapprovisionné tous les
// trois jours quand il descend sous quatre jours de ventes ; certaines dernières livraisons laissent peu de
// marge, pour que des articles finissent sous leur seuil (suggestions de commande).
$niv = [];
foreach (Database::tous('SELECT lieu_id, produit_id, quantite FROM stock_niveaux') as $n) {
    $niv[(int) $n['lieu_id']][(int) $n['produit_id']] = (float) $n['quantite'];
}
$parJourDepot = static fn (int $depotId, string $cle): float => $parDepot[$depotId][$cle] ?? 0.0;
// [jour, fournisseur, dépôt, paiements, vides rendus, facture, par, réserve en jours à la fin, hausse de prix]
$plan = [
    [1, 'marche', 'cdl_froid', [['Caisse du Comptoir', $cdf, 'tout']], [], null, $patrick, 4, 1.0],
    [2, 'bralima', 'cdl_reserve', [['Rawbank', $usd, 'tout']], [['casier12', 10], ['bouteille72', 120]], 'BRL-2026-4411', $patrick, 4, 1.0],
    [3, 'kivu', 'cdl_reserve', [['Caisse du Comptoir', $cdf, 'tout']], [], 'KB-0932', $patrick, 4, 1.0],
    [3, 'marche', 'vc_reserve', [['Caisse du Club', $cdf, 'tout']], [], null, $admin, 4, 1.0],
    [3, 'bralima', 'vc_reserve', [['Rawbank', $usd, 'tout']], [['casier12', 8], ['bouteille72', 96]], 'BRL-2026-4433', $admin, 4, 1.0],
    [3, 'spiritueux', 'vc_reserve', [['Rawbank', $usd, 0.7]], [], 'GLS-121', $admin, 4, 1.0],
    [4, 'spiritueux', 'cdl_reserve', [['Equity BCDC', $usd, 0.5]], [], 'GLS-118', $patrick, 4, 1.0],
    [4, 'kivu', 'vc_reserve', [['Airtel Money', $cdf, 'tout']], [], 'KB-0951', $admin, 4, 1.0],
    [4, 'brasimba', 'vc_reserve', [], [], 'BSB-77830', $admin, 4, 1.0],
    [5, 'brasimba', 'cdl_reserve', [], [], 'BSB-77812', $patrick, 4, 1.0],
    [8, 'marche', 'cdl_froid', [['Caisse du Comptoir', $cdf, 'tout']], [], null, $patrick, 4, 1.0],
    [9, 'marche', 'cdl_reserve', [['Caisse du Comptoir', $cdf, 'tout']], [], null, $patrick, 4, 1.0],
    [16, 'marche', 'cdl_reserve', [['Caisse du Comptoir', $usd, 'tout']], [], null, $patrick, 5, 1.0],
    [9, 'bralima', 'cdl_reserve', [['M-Pesa', $cdf, 0.6]], [['casier12', 15], ['casier24', 4], ['bouteille72', 180]], 'BRL-2026-4502', $patrick, 4, 1.0],
    [10, 'marche', 'vc_reserve', [['Caisse du Club', $cdf, 'tout']], [], null, $admin, 4, 1.0],
    [11, 'spiritueux', 'vc_reserve', [], [], 'GLS-140', $admin, 5, 1.0],
    [12, 'kivu', 'cdl_reserve', [], [], 'KB-0977', $patrick, 4, 1.0],
    [12, 'bralima', 'vc_reserve', [['Equity BCDC', $cdf, 0.5]], [['casier12', 10], ['bouteille72', 100]], 'BRL-2026-4588', $admin, 5, 1.0],
    [13, 'spiritueux', 'cdl_reserve', [['Equity BCDC', $usd, 'tout']], [], 'GLS-133', $patrick, 5, 1.0],
    [13, 'kivu', 'vc_reserve', [['Airtel Money', $cdf, 'tout']], [], 'KB-0990', $admin, 5, 1.0],
    [15, 'marche', 'cdl_froid', [['Caisse du Comptoir', $cdf, 'tout']], [], null, $patrick, 5, 1.0],
    [15, 'brasimba', 'cdl_reserve', [], [], 'BSB-78004', $patrick, 1.5, 1.0],
    [16, 'bralima', 'cdl_reserve', [], [['casier12', 12], ['bouteille72', 144]], 'BRL-2026-4619', $patrick, 1, 1.05],
    [17, 'marche', 'vc_reserve', [['Caisse du Club', $cdf, 'tout']], [], null, $admin, 2, 1.0],
    [19, 'kivu', 'cdl_reserve', [['Caisse du Comptoir', $cdf, 'tout']], [], 'KB-1012', $patrick, 5, 1.0],
];
$transferts = new TransfertStockRepository($stock);
$recus = 0;
$nbTransferts = 0;
$casiersBoissons = ['primus_g', 'primus_p', 'mutzig_g', 'mutzig_p', 'heineken', 'guinness', 'coca', 'eau'];
$formatTransfert = static function (string $cle, int $pid, float $besoin) use ($A, $base, $casiersBoissons): array {
    $cond = $A[$cle][1];
    $taille = $base($cond);
    $unite = Database::un('SELECT id FROM conditionnements WHERE produit_id = ? AND ABS(quantite_base - 1) < 0.001 ORDER BY id LIMIT 1', [$pid]);
    if (in_array($cle, $casiersBoissons, true) && $besoin >= $taille * 0.75) {
        return [$cond, $taille];
    }
    if ($unite !== null && $taille > 1) {
        return [(int) $unite['id'], 1.0];
    }
    return [$cond, $taille];
};

$pdo->beginTransaction();
for ($d = 0; $d <= $joursPeriode; $d++) {
    // Livraisons du matin
    foreach ($plan as $i => [$j, $f, $cleDepot, $paiements, $vides, $facture, $par, $reserve, $hausse]) {
        if ($j !== $d) {
            continue;
        }
        $depotId = $L[$cleDepot];
        $suivante = $joursPeriode;
        foreach ($plan as $k => $autre) {
            if ($k !== $i && $autre[1] === $f && $autre[2] === $cleDepot && $autre[0] > $d) {
                $suivante = min($suivante, $autre[0]);
            }
        }
        $jours = ($suivante - $d) + ($suivante === $joursPeriode ? $reserve : 3);
        $lignes = [];
        foreach ($A as $cle => [$fournisseur, $cond, $prix]) {
            $c = $parJourDepot($depotId, $cle);
            if ($fournisseur !== $f || $c <= 0) {
                continue;
            }
            $pid = $cleProduit($cle);
            $taille = $base($cond);
            $manque = $c * $jours - ($niv[$depotId][$pid] ?? 0);
            $n = (int) ceil($manque / $taille - 0.0001);
            if ($n > 0) {
                $lignes[$cle] = $hausse !== 1.0 ? [$n, round($prix * $hausse, 2)] : $n;
                $niv[$depotId][$pid] = ($niv[$depotId][$pid] ?? 0) + $n * $taille;
            }
        }
        if ($lignes !== []) {
            $recevoir($f, $depotId, $jour($d, sprintf('%02d:%02d', 8 + $i % 8, 10 * ($i % 6))), $lignes, $paiements,
                array_map(static fn (array $v): array => [$E[$v[0]], $v[1]], $vides), $facture, $par);
            $recus++;
        }
    }
    // Dépôt central → Réserve du club (réassort du week-end)
    if ($d === 6) {
        $recevoir('bralima', $L['central'], $jour(6, '09:00'), ['primus_g' => 50, 'mutzig_g' => 30], [['Equity BCDC', $usd, 'tout']],
            [[$E['casier12'], 20], [$E['bouteille72'], 240]], 'BRL-2026-4470', $admin);
        $recus++;
    }
    if ($d === 13) {
        $id = $transferts->creer($L['central'], $L['vc_reserve'], [
            ['produit_id' => $P['primus_g'], 'conditionnement_id' => $A['primus_g'][1], 'quantite' => 20.0, 'quantite_base' => 240.0],
            ['produit_id' => $P['mutzig_g'], 'conditionnement_id' => $A['mutzig_g'][1], 'quantite' => 10.0, 'quantite_base' => 120.0],
        ], 'Réassort du week-end', $admin, true, $jour(13, '10:00'));
        $transferts->recevoir($id, [], null, $admin, $jour(13, '15:00'));
        $niv[$L['vc_reserve']][$P['primus_g']] = ($niv[$L['vc_reserve']][$P['primus_g']] ?? 0) + 240;
        $niv[$L['vc_reserve']][$P['mutzig_g']] = ($niv[$L['vc_reserve']][$P['mutzig_g']] ?? 0) + 120;
        $nbTransferts++;
    }
    // Transferts de l'après-midi, tous les trois jours
    if ($d >= 2 && ($d - 2) % 3 === 0 && $d <= $joursPeriode - 1) {
        foreach ($comptoirs as $comptoir => $info) {
            $lignes = [];
            foreach (array_keys($A) as $cle) {
                $pid = $cleProduit($cle);
                $c = $parJour($comptoir, $pid);
                $niveau = $niv[$comptoir][$pid] ?? 0;
                if ($c <= 0 || $niveau >= 4 * $c) {
                    continue;
                }
                // Le champagne n'est plus monté au Bar VIP la dernière semaine : il finit en négatif (vendu quand même, avec alerte)
                if ($comptoir === $L['vc_vip'] && $cle === 'moet' && $d > $joursPeriode - 9) {
                    continue;
                }
                [$cond, $taille] = $formatTransfert($cle, $pid, 6 * $c - $niveau);
                $n = (int) ceil((6 * $c - $niveau) / $taille - 0.0001);
                $n = min($n, (int) floor(($niv[$info['depot']][$pid] ?? 0) / $taille + 0.0001));
                if ($n <= 0) {
                    continue;
                }
                $lignes[] = ['produit_id' => $pid, 'conditionnement_id' => $cond, 'quantite' => (float) $n, 'quantite_base' => $n * $taille];
                $niv[$comptoir][$pid] = $niveau + $n * $taille;
                $niv[$info['depot']][$pid] -= $n * $taille;
            }
            if ($lignes === []) {
                continue;
            }
            $par = $info['etab'] === $cdl ? $patrick : $admin;
            $id = $transferts->creer($info['depot'], $comptoir, $lignes, null, $par, true, $jour($d, '16:30'));
            $recues = [];
            // Un écart : deux bouteilles cassées dans l'escalier
            if ($comptoir === $L['cdl_bar'] && $nbTransferts > 4 && !isset($ecartFait)) {
                foreach ($transferts->transfert($id)['lignes'] as $l) {
                    if ((int) $l['produit_id'] === $P['primus_g'] && (float) $l['quantite_base'] > 2) {
                        $recues[(int) $l['id']] = (float) $l['quantite_base'] - 2;
                        $niv[$comptoir][$P['primus_g']] -= 2;
                        $ecartFait = true;
                    }
                }
            }
            $transferts->recevoir($id, $recues, $recues !== [] ? '2 bouteilles de Primus Grande cassées dans l\'escalier' : null, $admin, $jour($d, '17:00'));
            $nbTransferts++;
        }
    }
    // Ventes de la journée (moyenne)
    foreach ($comptoirs as $comptoir => $info) {
        foreach (array_keys($A) as $cle) {
            $pid = $cleProduit($cle);
            if (($c = $parJour($comptoir, $pid)) > 0) {
                $niv[$comptoir][$pid] = ($niv[$comptoir][$pid] ?? 0) - $c;
            }
        }
    }
}
// Envoi du dépôt central en route (à recevoir), et un brouillon
$transferts->creer($L['central'], $L['vc_reserve'], [
    ['produit_id' => $P['primus_g'], 'conditionnement_id' => $A['primus_g'][1], 'quantite' => 15.0, 'quantite_base' => 180.0],
    ['produit_id' => $P['primus_p'], 'conditionnement_id' => $A['primus_p'][1], 'quantite' => 5.0, 'quantite_base' => 120.0],
], 'Pour le jeudi', $admin, true, $aujourdhui->format('Y-m-d') . ' 09:15:00');
$transferts->creer($L['cdl_reserve'], $L['cdl_bar'], [
    ['produit_id' => $P['coca'], 'conditionnement_id' => $A['coca'][1], 'quantite' => 1.0, 'quantite_base' => 24.0],
], 'À envoyer ce soir', $patrick, false, $aujourdhui->format('Y-m-d') . ' 10:00:00');
$pdo->commit();
echo "$recus réceptions et " . ($nbTransferts + 2) . " transferts enregistrés.\n";

// Bon de commande envoyé, pas encore livré (Bralima, Comptoir du Lac)
$idCommande = $achats->enregistrerCommande(null, [
    'fournisseur_id' => $F['bralima'], 'lieu_id' => $L['cdl_reserve'], 'etablissement_id' => $cdl, 'devise_id' => $usd,
    'date_commande' => $aujourdhui->modify('-1 day')->format('Y-m-d'), 'date_prevue' => $aujourdhui->modify('+1 day')->format('Y-m-d'), 'notes' => 'Livraison du mardi',
], array_map(static fn (string $cle, int $n): array => ['produit_id' => $produitDe($A[$cle][1]), 'conditionnement_id' => $A[$cle][1], 'quantite' => $n,
    'quantite_base' => $n * $base($A[$cle][1]), 'prix_unitaire' => $A[$cle][2]], ['primus_g', 'primus_p', 'mutzig_g'], [20, 10, 15]), $patrick);
$achats->changerStatutCommande($idCommande, 'brouillon', 'envoyee');

// ---------- Ventes : sorties de stock rejouées dans l'ordre ----------

$n = $stock->rejouerVentes();
echo "Sorties de stock de $n ventes enregistrées.\n";

// Vides du bar rapportés à la réserve (une fois par semaine)
$pdo->beginTransaction();
foreach ([7, 14] as $j) {
    $date = $jour($j, '23:00');
    $vides = [];
    foreach (['bouteille72', 'bouteille33'] as $cle) {
        $q = $stock->quantiteA($L['cdl_bar'], $E[$cle], $date);
        if ($q >= 1) {
            $produitId = $E[$cle];
            $vides[] = ['produit_id' => $produitId, 'conditionnement_id' => (int) Database::un('SELECT id FROM conditionnements WHERE produit_id = ? LIMIT 1', [$produitId])['id'], 'quantite' => floor($q), 'quantite_base' => floor($q)];
        }
    }
    if ($vides !== []) {
        $id = $transferts->creer($L['cdl_bar'], $L['cdl_reserve'], $vides, 'Vides de la semaine', $patrick, true, $date);
        $transferts->recevoir($id, [], null, $patrick, $jour($j + 1, '08:00'));
    }
}
$pdo->commit();

// ---------- Pertes ----------

$pdo->beginTransaction();
foreach ([
    [$L['cdl_bar'], 'primus_g', 'Bouteille', 2, 'casse', 'Casier renversé pendant le service', 6, $patrick],
    [$L['cdl_bar'], 'coca', 'Canette', 1, 'offert', 'Offert à un client fidèle', 9, $patrick],
    [$L['cdl_cuisine'], 'poulet', 'Pièce', 1, 'personnel', 'Repas du personnel du dimanche', 13, $patrick],
    [$L['cdl_froid'], 'legumes', 'Kilo', 2, 'perime', 'Tomates abîmées', 11, $patrick],
    [$L['cdl_cuisine'], 'tilapia', 'Kilo', 0.5, 'renvoye', 'Poisson trop cuit renvoyé en salle', 17, $patrick],
    [$L['vc_bar'], 'heineken', 'Bouteille', 3, 'casse', 'Bouteilles cassées sur la piste', 10, $admin],
    [$L['vc_vip'], 'moet', 'Bouteille', 1, 'offert', 'Anniversaire d\'un client VIP', 16, $admin],
] as [$l, $cle, $nomFormat, $q, $motif, $commentaire, $j, $par]) {
    $pid = $cleProduit($cle);
    $cond = $format($pid, $nomFormat);
    $depot->creerPerte(['lieu_id' => $l, 'produit_id' => $pid, 'conditionnement_id' => $cond, 'quantite' => (float) $q, 'quantite_base' => $q * $base($cond),
        'motif' => $motif, 'commentaire' => $commentaire, 'date' => $jour($j, '22:00')], $par);
}
$pdo->commit();
$stock->recalculer();

// ---------- Seuils d'alerte ----------

$articles = $depot->articles();
foreach ($comptoirs as $comptoir => $info) {
    foreach (array_keys($A) as $cle) {
        $pid = $cleProduit($cle);
        $c = $parJour($comptoir, $pid);
        if ($c > 0) {
            $depot->definirSeuils($comptoir, $pid, round($c * 1.5, 1), round($c * 4, 1));
        }
    }
}
foreach ($parDepot as $depotId => $besoins) {
    foreach ($besoins as $cle => $c) {
        if ($c > 0) {
            $depot->definirSeuils($depotId, $cleProduit($cle), round($c * 3, 1), round($c * 8, 1));
        }
    }
}

// ---------- Inventaires ----------

$inventaires = new InventaireRepository($stock);
$hier = $aujourdhui->modify('-1 day')->format('Y-m-d');
$niveaux = $depot->niveaux([$L['cdl_bar']])[$L['cdl_bar']] ?? [];
$produitsBar = array_values(array_filter(array_keys($niveaux), static fn (int $p): bool => isset($articles[$p])));
$idInv = $inventaires->creer($L['cdl_bar'], 'service', null, $produitsBar, 'Fin du service d\'hier soir', $patrick, $hier . ' 23:30:00');
$inv = $inventaires->inventaire($idInv);
$ecarts = [$P['primus_g'] => -2, $P['coca'] => -1, $P['eau'] => 1, $P['jw'] => -21, $I['citron'] => -3];
$comptes = [];
foreach ($inv['lignes'] as $pid => $l) {
    $comptes[$pid] = ['compte' => max(0, round((float) $l['attendu'] + ($ecarts[$pid] ?? 0), 3)), 'saisie' => null];
}
$inventaires->compter($idInv, $comptes, $patrick, true);
Database::executer('UPDATE inventaires SET compte_le = ? WHERE id = ?', [$hier . ' 23:50:00', $idInv]);
$inventaires->valider($idInv, [$P['primus_g'] => 'Deux bouteilles cassées non déclarées', $P['jw'] => 'Doses servies trop généreuses', $I['citron'] => 'Citrons abîmés jetés'], $admin, false, $hier . ' 23:55:00');
// Inventaire de la réserve du club : compté, en attente de validation
$niveaux = $depot->niveaux([$L['vc_reserve']])[$L['vc_reserve']] ?? [];
$produitsReserve = array_values(array_filter(array_keys($niveaux), static fn (int $p): bool => isset($articles[$p]) && !$articles[$p]['emballage']));
$idInv2 = $inventaires->creer($L['vc_reserve'], 'complet', null, $produitsReserve, 'Inventaire de fin de mois', $admin, $aujourdhui->format('Y-m-d') . ' 08:00:00');
$inv2 = $inventaires->inventaire($idInv2);
$comptes = [];
foreach ($inv2['lignes'] as $pid => $l) {
    $comptes[$pid] = ['compte' => max(0, round((float) $l['attendu'] + ($pid === $P['heineken'] ? -6 : ($pid === $P['moet'] ? -1 : 0)), 3)), 'saisie' => null];
}
$inventaires->compter($idInv2, $comptes, $admin, true);
$stock->recalculer();

// ---------- Fin ----------

Database::executer("REPLACE INTO parametres (cle, valeur) VALUES ('demo_stock', ?)", [date('Y-m-d H:i')]);
$negatifs = (int) Database::un('SELECT COUNT(*) AS n FROM stock_niveaux WHERE quantite < -0.0005')['n'];
$bas = (int) Database::un('SELECT COUNT(*) AS n FROM stock_niveaux WHERE seuil_alerte IS NOT NULL AND quantite <= seuil_alerte AND quantite >= 0')['n'];
echo "Démonstration du stock créée : " . count($L) . " lieux, " . count($F) . " fournisseurs, " . count($fiches) . " fiches techniques, "
    . "$bas articles au seuil d'alerte, $negatifs en négatif.\n";
