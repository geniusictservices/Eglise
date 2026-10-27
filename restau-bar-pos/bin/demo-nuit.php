<?php

declare(strict_types=1);

/**
 * Lounge et nightclub de démonstration (on peut le relancer : rien n'est créé deux fois), au Volcano Club :
 * - softs (Fanta, Sprite, Tonic, Red Bull) et formules : Johnnie Walker 70 cl + 4 softs, Hennessy VS 70 cl + 4 softs,
 *   Formule anniversaire (Moët + une bouteille au choix + 6 softs) ;
 * - minimum de consommation du Carré VIP (300 000 FC ou 100 $ par note) ;
 * - tarifs d'entrée (normal, VIP, gratuit ; early bird pour Halloween), billets vendus et contrôlés aux soirées passées,
 *   préventes pour la prochaine ;
 * - deux promoteurs (dont Kevin Mutombo, avec un compte « promoteur »), leurs invités pointés et leurs commissions ;
 * - un agent d'entrée et un vestiaire (PIN de caisse), frais de vestiaire et tickets (dont quelques-uns encore déposés) ;
 * - 10 cartes cashless, rechargées (argent dans la trésorerie) et utilisées pour payer ;
 * - fidélité au Comptoir du Lac (1 point par dollar, deux récompenses) et des points pour quelques clients.
 *
 *   php bin/demo-nuit.php demo
 */

use App\Controllers\CaisseController;
use App\Core\CodeQr;
use App\Core\Config;
use App\Core\Database;
use App\Core\I18n;
use App\Core\Instance;
use App\Core\Journee;
use App\Repositories\BilletterieRepository;
use App\Repositories\CashlessRepository;
use App\Repositories\EtablissementRepository;
use App\Repositories\FideliteRepository;
use App\Repositories\FormuleRepository;
use App\Repositories\GestionTresorerieRepository;
use App\Repositories\MinimumRepository;
use App\Repositories\PromoteurRepository;
use App\Repositories\StockRepository;
use App\Repositories\VenteRepository;
use App\Repositories\VestiaireRepository;

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
    arreter('Usage : php bin/demo-nuit.php <sous-domaine>');
}
if (Instance::charger($instance) === null) {
    arreter("Instance inconnue : $instance");
}
$parametres = array_column(Database::tous('SELECT cle, valeur FROM parametres'), 'valeur', 'cle');
date_default_timezone_set($parametres['fuseau_horaire'] ?? Config::get('fuseau_horaire'));
Database::executer('SET time_zone = ?', [date('P')]);
I18n::initialiser('fr');

if (Database::un("SHOW TABLES LIKE 'cartes_cashless'") === null) {
    arreter('Migration 022 manquante : php bin/migrer.php ' . $instance);
}
$etablissements = new EtablissementRepository();
$club = $etablissements->parSlug('volcano-club');
$lac = $etablissements->parSlug('comptoir-du-lac');
if ($club === null) {
    echo "Établissement de démonstration absent : rien à faire.\n";
    exit(0);
}
mt_srand(2207);
$clubId = (int) $club['id'];
$utilisateur = static fn (string $nom): ?int => ($u = Database::un('SELECT id FROM utilisateurs WHERE nom = ?', [$nom])) ? (int) $u['id'] : null;
$admin = (int) (Database::un("SELECT u.id FROM utilisateurs u JOIN utilisateur_roles ur ON ur.utilisateur_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.code = 'proprietaire' ORDER BY u.id LIMIT 1")['id'] ?? 1);
$jeanPaul = $utilisateur('Jean-Paul Bahati') ?? $admin;
$compte = ['produits' => 0, 'formules' => 0, 'tarifs' => 0, 'billets' => 0, 'promoteurs' => 0, 'invites' => 0, 'tickets' => 0, 'cartes' => 0, 'ventes' => 0, 'points' => 0];
$usd = Database::un("SELECT * FROM devises WHERE code = 'USD'");
$cdf = Database::un("SELECT * FROM devises WHERE code = 'CDF'");
$normal = (int) (Database::un("SELECT id FROM grilles_prix WHERE code = 'normal'")['id'] ?? $club['grille_prix_id']);
$vipGrille = Database::un("SELECT id FROM grilles_prix WHERE code = 'vip'");

// ---------- Personnes : agent d'entrée, vestiaire, promoteur ----------

$personne = static function (string $nom, ?string $email, string $telephone, string $poste, ?string $motDePasse, ?string $pin, string $role, int $etab) use ($utilisateur): int {
    $id = $utilisateur($nom);
    if ($id === null) {
        Database::executer('INSERT INTO utilisateurs (nom, email, telephone, poste, mot_de_passe, pin, langue) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$nom, $email, $telephone, $poste, $motDePasse ? password_hash($motDePasse, PASSWORD_DEFAULT) : null, $pin ? password_hash($pin, PASSWORD_DEFAULT) : null, 'fr']);
        $id = Database::dernierId();
    }
    Database::executer('INSERT IGNORE INTO utilisateur_roles (utilisateur_id, role_id, etablissement_id) SELECT ?, id, ? FROM roles WHERE code = ?', [$id, $etab, $role]);
    return $id;
};
$sarah = $personne('Sarah Kavugho', null, '+243810000108', 'Agente d\'entrée', null, '735102', 'agent_entree', $clubId);
$didier = $personne('Didier Kasereka', null, '+243810000109', 'Vestiaire', null, '846295', 'vestiaire', $clubId);
$kevin = $personne('Kevin Mutombo', 'kevin@demo.test', '+243810000110', 'Promoteur', 'Promo-Goma-2026', null, 'promoteur', $clubId);

// ---------- Softs et formules ----------

$categorie = static fn (string $nom): ?int => ($c = Database::un('SELECT id FROM categories WHERE nom_fr = ?', [$nom])) ? (int) $c['id'] : null;
$produit = static function (string $fr, string $en, int $categorieId, string $format, float $quantite, float $prixUsd, ?float $prixVip = null) use ($clubId, $normal, $vipGrille, $usd, &$compte): array {
    $p = Database::un('SELECT id FROM produits WHERE nom_fr = ? AND parent_id IS NULL', [$fr]);
    if ($p === null) {
        Database::executer('INSERT INTO produits (categorie_id, nom_fr, nom_en, unite_base, position) VALUES (?, ?, ?, ?, ?)', [$categorieId, $fr, $en, 'piece', 50]);
        $id = Database::dernierId();
        Database::executer('INSERT INTO conditionnements (produit_id, nom_fr, nom_en, quantite_base, vendable, achetable) VALUES (?, ?, ?, ?, 1, 1)', [$id, $format, $format === 'Canette' ? 'Can' : ($format === 'Formule' ? 'Package' : $format), $quantite]);
        $cond = Database::dernierId();
        Database::executer('INSERT INTO prix (conditionnement_id, grille_prix_id, devise_id, montant) VALUES (?, ?, ?, ?)', [$cond, $normal, (int) $usd['id'], number_format($prixUsd, 2, '.', '')]);
        if ($prixVip !== null && $vipGrille) {
            Database::executer('INSERT INTO prix (conditionnement_id, grille_prix_id, devise_id, montant) VALUES (?, ?, ?, ?)', [$cond, (int) $vipGrille['id'], (int) $usd['id'], number_format($prixVip, 2, '.', '')]);
        }
        $compte['produits']++;
        $p = ['id' => $id];
    }
    Database::executer('INSERT IGNORE INTO produit_etablissements (produit_id, etablissement_id) VALUES (?, ?)', [(int) $p['id'], $clubId]);
    $c = Database::un('SELECT id FROM conditionnements WHERE produit_id = ? ORDER BY position, id LIMIT 1', [(int) $p['id']]);
    return ['produit' => (int) $p['id'], 'format' => (int) $c['id']];
};
$sucreries = $categorie('Sucreries et eaux') ?? $categorie('Boissons') ?? (int) Database::un('SELECT id FROM categories ORDER BY id LIMIT 1')['id'];
$fanta = $produit('Fanta Orange', 'Fanta Orange', $sucreries, 'Canette', 1, 1.5);
$sprite = $produit('Sprite', 'Sprite', $sucreries, 'Canette', 1, 1.5);
$tonic = $produit('Schweppes Tonic', 'Schweppes Tonic', $sucreries, 'Canette', 1, 2);
$redbull = $produit('Red Bull', 'Red Bull', $sucreries, 'Canette', 1, 4);
$formatDe = static fn (string $nom, string $format): ?int => ($c = Database::un('SELECT c.id FROM conditionnements c JOIN produits p ON p.id = c.produit_id WHERE p.nom_fr = ? AND c.nom_fr = ? LIMIT 1', [$nom, $format])) ? (int) $c['id'] : null;
$coca = $formatDe('Coca-Cola', 'Canette');
$eau = $formatDe('Eau minérale', 'Bouteille');
$jw = $formatDe('Johnnie Walker Black Label', 'Bouteille 70 cl');
$hennessy = $formatDe('Hennessy VS', 'Bouteille 70 cl');
$moet = $formatDe('Moët & Chandon Brut', 'Bouteille');
$softs = array_values(array_filter([$coca, $fanta['format'], $sprite['format'], $tonic['format'], $eau]));

$formules = new FormuleRepository();
$whisky = $categorie('Whisky') ?? $sucreries;
$cognac = $categorie('Cognac') ?? $whisky;
$champagnes = $categorie('Vins et champagnes') ?? $whisky;
$jw4 = $produit('Johnnie Walker Black 70 cl + 4 softs', 'Johnnie Walker Black 70 cl + 4 mixers', $whisky, 'Formule', 1, 85, 100);
$he4 = $produit('Hennessy VS 70 cl + 4 softs', 'Hennessy VS 70 cl + 4 mixers', $cognac, 'Formule', 1, 100, 120);
$anniv = $produit('Formule anniversaire', 'Birthday package', $champagnes, 'Formule', 1, 220, 250);
$nouvelleFormule = static function (int $produitId, array $groupes) use ($formules, $admin, &$compte): void {
    if ($formules->formule($produitId, true) === null) {
        $formules->enregistrer($produitId, true, $groupes, $admin);
        $compte['formules']++;
    }
};
if ($jw !== null) {
    $nouvelleFormule($jw4['produit'], [['nom_fr' => 'Bouteille', 'nom_en' => 'Bottle', 'choix' => 1, 'options' => [$jw]],
        ['nom_fr' => 'Softs au choix', 'nom_en' => 'Mixers to choose', 'choix' => 4, 'options' => $softs]]);
}
if ($hennessy !== null) {
    $nouvelleFormule($he4['produit'], [['nom_fr' => 'Bouteille', 'nom_en' => 'Bottle', 'choix' => 1, 'options' => [$hennessy]],
        ['nom_fr' => 'Softs au choix', 'nom_en' => 'Mixers to choose', 'choix' => 4, 'options' => array_merge($softs, [$redbull['format']])]]);
}
if ($moet !== null && $jw !== null && $hennessy !== null) {
    $nouvelleFormule($anniv['produit'], [['nom_fr' => 'Champagne', 'nom_en' => 'Champagne', 'choix' => 1, 'options' => [$moet]],
        ['nom_fr' => 'Bouteille au choix', 'nom_en' => 'Bottle to choose', 'choix' => 1, 'options' => [$jw, $hennessy]],
        ['nom_fr' => 'Softs au choix', 'nom_en' => 'Mixers to choose', 'choix' => 6, 'options' => array_merge($softs, [$redbull['format']])]]);
}
// Stock d'ouverture des nouveaux softs aux bars du club, Bar VIP compris (routage du Carré VIP) (module stock)
try {
    $stock = new StockRepository();
    $barsClub = array_map('intval', array_column(Database::tous("SELECT id FROM lieux_stock WHERE etablissement_id = ? AND type = 'comptoir' AND actif = 1 AND id IN (SELECT lieu_id FROM stock_routage_zones UNION SELECT ?)", [$clubId, $stock->comptoirParDefaut($clubId)]), 'id'));
    foreach ([$fanta, $sprite, $tonic, $redbull] as $s) {
        foreach ($barsClub as $lieu) {
            if (Database::un('SELECT 1 AS n FROM stock_mouvements WHERE lieu_id = ? AND produit_id = ? LIMIT 1', [$lieu, $s['produit']]) === null) {
                $stock->mouvement($lieu, $s['produit'], 96, 'ouverture', null, date('Y-m-d H:i:s', strtotime('-25 days')), 'Stock d\'ouverture (démonstration)', $admin);
            }
        }
    }
} catch (\Throwable $e) {
    echo 'Stock des softs non créé : ' . $e->getMessage() . "\n";
}

// ---------- Minimum de consommation du Carré VIP ----------

$vip = Database::un("SELECT id FROM zones WHERE etablissement_id = ? AND code = 'vip'", [$clubId]);
if ($vip !== null && Database::un('SELECT id FROM minimums_conso WHERE etablissement_id = ? AND zone_id = ?', [$clubId, (int) $vip['id']]) === null) {
    (new MinimumRepository())->enregistrer(null, $clubId, (int) $vip['id'], null, 'ajouter', true, [(int) $cdf['id'] => 300000, (int) $usd['id'] => 100], $admin);
}

// ---------- Tarifs d'entrée ----------

$billetterie = new BilletterieRepository();
$tarif = static function (?int $evenement, string $categorie, string $fr, string $en, array $montants, ?string $finVente = null, int $position = 0) use ($billetterie, $clubId, $admin, &$compte): int {
    $t = Database::un('SELECT id FROM entree_tarifs WHERE etablissement_id = ? AND nom_fr = ? AND ' . ($evenement === null ? 'evenement_id IS NULL' : 'evenement_id = ?'),
        $evenement === null ? [$clubId, $fr] : [$clubId, $fr, $evenement]);
    if ($t !== null) {
        return (int) $t['id'];
    }
    $compte['tarifs']++;
    return $billetterie->enregistrerTarif(null, $clubId, ['evenement_id' => $evenement, 'categorie' => $categorie, 'nom_fr' => $fr, 'nom_en' => $en,
        'fin_vente' => $finVente, 'actif' => true, 'position' => $position], $montants, $admin);
};
$tNormal = $tarif(null, 'normal', 'Entrée', 'Entry', [(int) $cdf['id'] => 10000, (int) $usd['id'] => 4], null, 1);
$tVip = $tarif(null, 'vip', 'Entrée VIP', 'VIP entry', [(int) $cdf['id'] => 30000, (int) $usd['id'] => 10], null, 2);
$tGratuit = $tarif(null, 'gratuit', 'Ladies night (avant minuit)', 'Ladies night (before midnight)', [], null, 3);
$tReduit = $tarif(null, 'reduit', 'Invité (tarif réduit)', 'Guest (reduced)', [(int) $cdf['id'] => 5000, (int) $usd['id'] => 2], null, 4);
$evenements = Database::tous("SELECT * FROM evenements WHERE etablissement_id = ? AND statut = 'prevu' ORDER BY debut", [$clubId]);
$prochain = null;
foreach ($evenements as $ev) {
    if ($ev['fin'] > date('Y-m-d H:i:s')) {
        $prochain = $ev;
        break;
    }
}
if ($prochain !== null) {
    $tarif((int) $prochain['id'], 'early', 'Early bird ' . $prochain['titre'], 'Early bird ' . $prochain['titre'], [(int) $cdf['id'] => 7000, (int) $usd['id'] => 3],
        date('Y-m-d H:i:s', strtotime($prochain['debut'] . ' -2 hours')), 0);
    $tarif((int) $prochain['id'], 'normal', 'Entrée ' . $prochain['titre'], 'Entry ' . $prochain['titre'], [(int) $cdf['id'] => 15000, (int) $usd['id'] => 6], null, 1);
    $tarif((int) $prochain['id'], 'vip', 'VIP ' . $prochain['titre'], 'VIP ' . $prochain['titre'], [(int) $cdf['id'] => 40000, (int) $usd['id'] => 15], null, 2);
}

// ---------- Ventes de démonstration (entrées, vestiaire, cashless) ----------

$ventes = new VenteRepository();
['acceptees' => $accepteesIds, 'principale' => $principale] = CaisseController::devisesCaisse($club);
$acceptees = array_column($accepteesIds, null, 'code');
$caisseClub = (new GestionTresorerieRepository())->compte((int) (Database::un("SELECT id FROM comptes_tresorerie WHERE nom = 'Caisse du Club'")['id'] ?? 0));
$caisseCompte = $caisseClub !== null ? ['id' => (int) $caisseClub['id'], 'type' => 'caisse', 'devises' => array_map(static fn ($d) => $d['code'], array_filter($accepteesIds, static fn ($d) => GestionTresorerieRepository::tient($caisseClub, (int) $d['id'])))] : null;
$taux = static fn (string $code): ?float => isset($acceptees[$code]['taux']) && (float) $acceptees[$code]['taux'] > 0 ? (float) $acceptees[$code]['taux'] : null;
$cle = static fn (): string => sprintf('%08x-%04x-4%03x-a%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand() * 4096 + mt_rand(0, 4095));
/**
 * Une vente du club : lignes [libelle, nature, prix (par code de devise), quantité, produit, format, apres], payée en
 * francs dans la Caisse du Club ou avec une carte cashless.
 */
$vendre = static function (array $lignes, string $date, int $vendeur, ?array $carte = null, array $apres = []) use ($ventes, $club, $acceptees, $principale, $caisseCompte, $taux, $cle, &$compte): ?int {
    if ($caisseCompte === null) {
        return null;
    }
    $totaux = array_fill_keys(array_keys($acceptees), 0.0);
    $venteLignes = [];
    foreach ($lignes as $l) {
        foreach (array_keys($totaux) as $code) {
            $totaux[$code] += ($l['prix'][$code] ?? 0) * $l['qte'];
        }
        $venteLignes[] = ['produit_id' => $l['produit'] ?? null, 'conditionnement_id' => $l['format'] ?? null, 'libelle' => $l['libelle'], 'format' => null,
            'quantite' => $l['qte'], 'prix' => (float) $l['prix'][$principale['code']], 'prix_reference' => $l['prix']['USD'] ?? null, 'nature' => $l['nature'] ?? 'article'];
        foreach ($l['composants'] ?? [] as $c) {
            $venteLignes[] = ['produit_id' => $c['produit'], 'conditionnement_id' => $c['format'], 'libelle' => $c['libelle'], 'format' => null, 'quantite' => $c['qte'] * $l['qte'],
                'prix' => 0.0, 'prix_reference' => 0.0, 'nature' => 'composant', 'parent' => count($venteLignes) - 1];
        }
    }
    $p = $principale['code'];
    $paiements = [];
    $cashless = [];
    if ($carte !== null) {
        $cashless[] = ['carte_id' => $carte['id'], 'devise' => $acceptees['USD'], 'montant' => round($totaux['USD'], 2), 'taux' => $taux('USD')];
    } elseif ($totaux[$p] > 0) {
        $paiements[] = ['moyen' => 'caisse', 'compte' => $caisseCompte, 'devise' => $acceptees[$p], 'recu' => $totaux[$p], 'taux' => $taux($p)];
    }
    $id = $ventes->enregistrer([
        'etablissement' => $club, 'cle' => $cle(), 'zone_id' => null, 'grille_id' => (int) $club['grille_prix_id'], 'utilisateur_id' => $vendeur, 'appareil_id' => null,
        'devise' => $principale, 'total' => $totaux[$p], 'brut' => $totaux[$p], 'remise' => 0.0,
        'reference' => $acceptees['USD'], 'total_reference' => $totaux['USD'],
        'totaux' => array_map(static fn (string $code) => ['devise' => $acceptees[$code], 'total' => $totaux[$code], 'taux' => $taux($code)], array_keys($totaux)),
        'lignes' => $venteLignes, 'paiements' => $paiements, 'rendu' => null, 'cashless' => $cashless, 'date' => $date, 'apres' => $apres,
    ]);
    $compte['ventes']++;
    return $id;
};

// Billets des soirées passées (vendus, la plupart contrôlés à la porte) et préventes de la prochaine soirée
$prixTarif = static fn (int $id): array => $billetterie->prix($id, $accepteesIds);
foreach ($evenements as $ev) {
    if (Database::un('SELECT id FROM billets WHERE evenement_id = ? LIMIT 1', [(int) $ev['id']]) !== null) {
        continue;
    }
    $passee = $ev['fin'] < date('Y-m-d H:i:s');
    $journee = Journee::du($ev['debut'], $club);
    $ventesSoiree = $passee ? mt_rand(18, 30) : ($ev === $prochain ? 6 : 0);
    for ($k = 0; $k < $ventesSoiree; $k++) {
        $tarifId = $passee ? ($k % 6 === 0 ? $tVip : $tNormal) : (int) Database::un("SELECT id FROM entree_tarifs WHERE evenement_id = ? AND categorie = 'early'", [(int) $ev['id']])['id'];
        $qte = mt_rand(1, 3);
        $prix = $prixTarif($tarifId);
        $date = $passee ? date('Y-m-d H:i:s', strtotime($ev['debut']) + mt_rand(0, 3 * 3600)) : date('Y-m-d H:i:s', strtotime('-' . mt_rand(1, 4) . ' days'));
        $categorieTarif = Database::un('SELECT categorie, nom_fr FROM entree_tarifs WHERE id = ?', [$tarifId]);
        $vendre([['libelle' => 'Entrée — ' . $categorieTarif['nom_fr'], 'nature' => 'entree', 'prix' => $prix, 'qte' => $qte]], $date, $sarah, null, [
            static function (int $venteId, array $ligneIds) use ($billetterie, $clubId, $ev, $journee, $tarifId, $categorieTarif, $qte, $prix, $principale, $sarah, $passee, $date, &$compte): void {
                for ($q = 0; $q < $qte; $q++) {
                    $b = $billetterie->creer(['etablissement_id' => $clubId, 'evenement_id' => (int) $ev['id'], 'journee' => $journee, 'tarif_id' => $tarifId,
                        'categorie' => $categorieTarif['categorie'], 'vente_id' => $venteId, 'vente_ligne_id' => $ligneIds[0] ?? null, 'prix' => (float) $prix[$principale['code']],
                        'devise_id' => (int) $principale['id'], 'utilise' => $passee && mt_rand(1, 10) <= 9, 'par' => $sarah, 'date' => $date]);
                    $compte['billets']++;
                }
            },
        ]);
    }
    if ($passee) {
        // Entrées gratuites (ladies night), déjà utilisées
        for ($k = 0; $k < mt_rand(6, 12); $k++) {
            $billetterie->creer(['etablissement_id' => $clubId, 'evenement_id' => (int) $ev['id'], 'journee' => $journee, 'tarif_id' => $tGratuit, 'categorie' => 'gratuit',
                'vente_id' => null, 'vente_ligne_id' => null, 'prix' => 0.0, 'devise_id' => null, 'utilise' => true, 'par' => $sarah,
                'date' => date('Y-m-d H:i:s', strtotime($ev['debut']) + mt_rand(0, 2 * 3600))]);
            $compte['billets']++;
        }
        // Quelques refus au contrôle (billet déjà utilisé, code inconnu)
        foreach (['deja', 'invalide'] as $resultat) {
            Database::executer('INSERT INTO billet_controles (etablissement_id, billet_id, code_lu, resultat, utilisateur_id, cree_le) VALUES (?, NULL, ?, ?, ?, ?)',
                [$clubId, $resultat === 'deja' ? 'B000001-XXXXXXXXXX' : 'B999999-ABCDEFGHJK', $resultat, $sarah, date('Y-m-d H:i:s', strtotime($ev['debut']) + 5400)]);
        }
    }
}

// ---------- Promoteurs, invités, commissions ----------

$promoteurs = new PromoteurRepository();
$promoteur = static function (string $nom, ?int $compteId, string $telephone, ?float $parEntree, ?float $pourcent) use ($promoteurs, $usd, $clubId, $admin, &$compte): int {
    $p = Database::un('SELECT id FROM promoteurs WHERE nom = ?', [$nom]);
    if ($p !== null) {
        return (int) $p['id'];
    }
    $compte['promoteurs']++;
    return $promoteurs->enregistrerPromoteur(null, ['nom' => $nom, 'telephone' => $telephone, 'utilisateur_id' => $compteId, 'etablissement_id' => $clubId,
        'devise_id' => (int) $usd['id'], 'par_entree' => $parEntree, 'pourcent_notes' => $pourcent, 'actif' => true], $admin);
};
$pKevin = $promoteur('Kevin Mutombo', $kevin, '+243810000110', 1.0, 10.0);
$pPatou = $promoteur('Patou Events', null, '+243990001122', 2.0, null);
$noms = ['Gloire Kambale', 'Divine Masika', 'Josué Paluku', 'Ruth Kahindo', 'Daniel Mumbere', 'Esther Furaha', 'Benjamin Sikuli', 'Merveille Neema',
    'Samuel Bisimwa', 'Chloé Mapendo', 'Prince Muhindo', 'Grâce Nabintu', 'David Kasereka', 'Sarah Bahati', 'Elie Mbusa', 'Rachel Kavira'];
foreach ($evenements as $i => $ev) {
    if (Database::un('SELECT id FROM invites WHERE evenement_id = ? LIMIT 1', [(int) $ev['id']]) !== null) {
        continue;
    }
    $passee = $ev['fin'] < date('Y-m-d H:i:s');
    foreach (array_slice($noms, ($i * 4) % 12, 6) as $k => $nom) {
        $pid = $k % 2 === 0 ? $pKevin : $pPatou;
        $id = $promoteurs->enregistrerInvite(null, (int) $ev['id'], ['nom' => $nom, 'telephone' => null, 'accompagnants' => $k % 3, 'entree' => $k === 5 ? 'reduite' : 'gratuite',
            'tarif_id' => $k === 5 ? $tReduit : null, 'note' => $k === 0 ? 'Anniversaire' : null, 'promoteur_id' => $pid], $kevin);
        $compte['invites']++;
        if ($passee && $k !== 4) {
            // Pointés à la porte (billets gratuits, commission par entrée)
            $nombre = 1 + ($k % 3);
            $r = $promoteurs->pointer($id, $nombre, $club, $sarah);
            $arrivee = date('Y-m-d H:i:s', strtotime($ev['debut']) + 3600 + $k * 600);
            Database::executer('UPDATE invites SET arrive_le = ? WHERE id = ?', [$arrivee, $id]);
            if ($r['billets'] !== []) {
                Database::executer('UPDATE billets SET cree_le = ?, utilise_le = ? WHERE id IN (' . implode(',', $r['billets']) . ')', [$arrivee, $arrivee]);
            }
            Database::executer('UPDATE commissions SET cree_le = ? WHERE invite_id = ?', [$arrivee, $id]);
        }
    }
}
// Note d'une invitée de Kevin au Carré VIP, payée : 10 % de commission pour Kevin
$passees = array_values(array_filter($evenements, static fn (array $e) => $e['fin'] < date('Y-m-d H:i:s')));
$table = Database::un("SELECT t.id, t.zone_id FROM salle_tables t JOIN zones z ON z.id = t.zone_id WHERE t.etablissement_id = ? AND z.code = 'vip' ORDER BY t.id LIMIT 1", [$clubId]);
if ($passees !== [] && $table !== null && $jw !== null && Database::un("SELECT id FROM commissions WHERE type = 'note' LIMIT 1") === null) {
    $ev = end($passees);
    $invite = Database::un('SELECT id, nom FROM invites WHERE evenement_id = ? AND promoteur_id = ? AND arrives > 0 ORDER BY id LIMIT 1', [(int) $ev['id'], $pKevin]);
    if ($invite !== null) {
        $ouverte = date('Y-m-d H:i:s', strtotime($ev['debut']) + 5400);
        Database::executer("INSERT INTO notes (etablissement_id, type, table_id, zone_id, statut, serveur_id, ouverte_le, fermee_le, invite_id) VALUES (?, 'note', ?, ?, 'payee', ?, ?, ?, ?)",
            [$clubId, (int) $table['id'], (int) $table['zone_id'], $jeanPaul, $ouverte, date('Y-m-d H:i:s', strtotime($ouverte) + 7200), (int) $invite['id']]);
        $noteId = Database::dernierId();
        $prixJw = ['USD' => 100.0, 'CDF' => 285000.0];
        $composants = [['produit' => (int) Database::un('SELECT produit_id FROM conditionnements WHERE id = ?', [$jw])['produit_id'], 'format' => $jw, 'libelle' => 'Johnnie Walker Black Label', 'qte' => 1]];
        foreach ([$coca, $fanta['format'], $sprite['format'], $tonic['format']] as $f) {
            $composants[] = ['produit' => (int) Database::un('SELECT produit_id FROM conditionnements WHERE id = ?', [$f])['produit_id'], 'format' => $f,
                'libelle' => (string) Database::un('SELECT p.nom_fr FROM conditionnements c JOIN produits p ON p.id = c.produit_id WHERE c.id = ?', [$f])['nom_fr'], 'qte' => 1];
        }
        Database::executer("INSERT INTO note_lignes (note_id, produit_id, conditionnement_id, libelle, quantite, quantite_payee, grille_prix_id, prix, prix_devises, statut, ajoute_par, ajoute_le, composants)
            VALUES (?, ?, ?, 'Johnnie Walker Black 70 cl + 4 softs', 1, 1, ?, ?, ?, 'sans', ?, ?, ?)",
            [$noteId, $jw4['produit'], $jw4['format'], $vipGrille ? (int) $vipGrille['id'] : null, $prixJw['CDF'], json_encode($prixJw), $jeanPaul, $ouverte,
                json_encode(array_map(static fn ($c) => ['id' => $c['format'], 'qte' => $c['qte'], 'libelle' => $c['libelle'], 'produit_id' => $c['produit']], $composants))]);
        $ligneNote = Database::dernierId();
        $vendre([['libelle' => 'Johnnie Walker Black 70 cl + 4 softs', 'nature' => 'formule', 'prix' => $prixJw, 'qte' => 1, 'produit' => $jw4['produit'], 'format' => $jw4['format'], 'composants' => $composants]],
            date('Y-m-d H:i:s', strtotime($ouverte) + 7000), $jeanPaul, null, [
                static function (int $venteId) use ($noteId, $ligneNote, $promoteurs): void {
                    Database::executer('INSERT INTO note_paiements (vente_id, note_ligne_id, note_id, quantite) VALUES (?, ?, ?, 1)', [$venteId, $ligneNote, $noteId]);
                    $promoteurs->commissionNote($noteId);
                },
            ]);
    }
}
// Commissions de Patou payées pour la première soirée (dépense « Commissions »)
if ($passees !== [] && $caisseClub !== null && Database::un('SELECT id FROM commission_paiements LIMIT 1') === null) {
    $premiere = Journee::du($passees[0]['debut'], $club);
    try {
        $promoteurs->payer($pPatou, $premiere, $premiere, $caisseClub, $clubId, $cle(), $admin);
    } catch (\DomainException) {
        // rien de dû
    }
}

// ---------- Vestiaire ----------

$vestiaire = new VestiaireRepository();
if ($vestiaire->frais($clubId) === []) {
    $vestiaire->definirFrais($clubId, [(int) $cdf['id'] => 2000, (int) $usd['id'] => 1], $admin);
}
if (Database::un('SELECT id FROM vestiaire_tickets WHERE etablissement_id = ? LIMIT 1', [$clubId]) === null) {
    $objets = ['Veste noire', 'Casque de moto', 'Sac à dos gris', 'Manteau beige', 'Parapluie', 'Veste en jean', 'Sac à main rouge'];
    foreach ($passees as $ev) {
        $journee = Journee::du($ev['debut'], $club);
        for ($k = 0; $k < 6; $k++) {
            $depose = date('Y-m-d H:i:s', strtotime($ev['debut']) + 1800 + $k * 900);
            $numero = $k + 1;
            Database::executer("INSERT INTO vestiaire_tickets (etablissement_id, journee, numero, jeton, description, objets, statut, depose_par, depose_le, rendu_par, rendu_le)
                VALUES (?, ?, ?, ?, ?, 1, 'rendu', ?, ?, ?, ?)", [$clubId, $journee, $numero, CodeQr::jeton(), $objets[$k % count($objets)], $didier, $depose, $didier, date('Y-m-d H:i:s', strtotime($depose) + 5 * 3600)]);
            $compte['tickets']++;
        }
    }
    // Ce soir : trois objets encore au vestiaire (frais payés)
    foreach (['Veste en cuir', 'Casque blanc', 'Sac de sport'] as $description) {
        $prixFrais = \App\Repositories\BilletterieRepository::prixDevises($vestiaire->frais($clubId), $accepteesIds);
        $vendre([['libelle' => 'Dépôt au vestiaire', 'nature' => 'vestiaire', 'prix' => $prixFrais, 'qte' => 1]], date('Y-m-d H:i:s', strtotime('-' . mt_rand(10, 50) . ' minutes')), $didier, null, [
            static function (int $venteId) use ($vestiaire, $club, $description, $didier): void {
                $vestiaire->deposer($club, $description, 1, $venteId, $didier);
            },
        ]);
        $compte['tickets']++;
    }
}

// ---------- Cashless : 10 cartes, recharges et paiements ----------

$cashless = new CashlessRepository();
if (Database::un('SELECT id FROM cartes_cashless LIMIT 1') === null && $caisseClub !== null) {
    $cartes = $cashless->creer(10, 'carte', null, $admin);
    $compte['cartes'] = count($cartes);
    $porteurs = ['Gloire Kambale', 'Divine Masika', null, 'Ruth Kahindo', null, 'Samuel Bisimwa', null, null, 'Prince Muhindo', null];
    $prixCoca = ['USD' => 1.5, 'CDF' => 4300.0];
    $redbullFormat = $redbull['format'];
    foreach ($cartes as $i => $carte) {
        if ($porteurs[$i] !== null) {
            $cashless->definirPorteur((int) $carte['id'], $porteurs[$i], null);
        }
        $date = date('Y-m-d H:i:s', strtotime('-' . (10 - $i) . ' days 23:00'));
        $montant = [20, 50, 10, 30, 25, 100, 15, 40, 60, 20][$i];
        $cashless->recharger([
            'carte_id' => (int) $carte['id'], 'devise' => $acceptees['USD'], 'montant' => (float) $montant,
            'paiements' => [['compte' => $caisseClub, 'devise' => $acceptees['USD'], 'recu' => (float) $montant, 'taux' => 1.0]], 'rendu' => null,
            'etablissement_id' => $clubId, 'utilisateur_id' => $jeanPaul, 'appareil_id' => null, 'session_id' => null, 'cle' => $cle(), 'date' => $date,
        ]);
        // Quelques consommations payées avec la carte
        for ($k = 0; $k < ($i % 3) + 1; $k++) {
            $soldes = $cashless->soldes((int) $carte['id']);
            $qte = mt_rand(1, 3);
            if (($soldes[(int) $usd['id']] ?? 0) < $prixCoca['USD'] * $qte) {
                break;
            }
            $vendre([['libelle' => 'Coca-Cola', 'prix' => $prixCoca, 'qte' => $qte, 'produit' => 5, 'format' => $coca]], date('Y-m-d H:i:s', strtotime($date) + 1800 * ($k + 1)), $jeanPaul, $carte);
        }
    }
    // Une carte perdue, bloquée par le gérant
    $cashless->bloquer((int) $cartes[6]['id'], true, 'Carte perdue (démonstration)', $admin);
}

// ---------- Fidélité au Comptoir du Lac ----------

if ($lac !== null) {
    $fidelite = new FideliteRepository();
    if ($fidelite->reglages((int) $lac['id'], true) === null) {
        $fidelite->definirReglages((int) $lac['id'], true, 1, (int) $usd['id'], $admin);
        $fidelite->enregistrerRecompense(null, (int) $lac['id'], ['nom_fr' => '10 % sur le ticket', 'nom_en' => '10 % off the ticket', 'points' => 100, 'type' => 'remise',
            'pourcent' => 10.0, 'conditionnement_id' => null, 'actif' => true], $admin);
        $mojito = $formatDe('Mojito', 'Verre');
        if ($mojito !== null) {
            $fidelite->enregistrerRecompense(null, (int) $lac['id'], ['nom_fr' => 'Un Mojito offert', 'nom_en' => 'A free Mojito', 'points' => 60, 'type' => 'offert',
                'pourcent' => null, 'conditionnement_id' => $mojito, 'actif' => true], $admin);
        }
    }
    if (Database::un('SELECT id FROM fidelite_mouvements LIMIT 1') === null) {
        $clients = Database::tous("SELECT id FROM clients WHERE type <> 'personnel' AND actif = 1 ORDER BY id LIMIT 6");
        $ventesLac = Database::tous("SELECT id, total_reference, cree_le FROM ventes WHERE etablissement_id = ? AND statut = 'payee' AND client_id IS NULL ORDER BY cree_le DESC LIMIT 60", [(int) $lac['id']]);
        foreach ($ventesLac as $k => $v) {
            if ($clients === []) {
                break;
            }
            $client = (int) $clients[$k % count($clients)]['id'];
            Database::executer('UPDATE ventes SET client_id = ? WHERE id = ?', [$client, (int) $v['id']]);
            $points = FideliteRepository::pointsPour(['montant_point' => 1], (float) $v['total_reference']);
            $fidelite->mouvement($client, (int) $lac['id'], (int) $v['id'], 'gain', $points, null, $admin, $v['cree_le']);
            $compte['points'] += $points;
        }
    }
}

// ---------- Réassort du Volcano Club ----------
// Le club est en « bloquer » (stock négatif, bin/demo-stock-suite.php). Les ventes de la nuit (bouteilles et formules du
// Carré VIP, softs) et la vente en gros au dépôt arrivent après la simulation du stock (bin/demo-stock.php) : sans
// réassort, ces articles finiraient sous zéro et seraient « épuisés » à la caisse du club. Pour chaque article à zéro ou
// moins dans un lieu du club, le stock d'ouverture (la veille des premières ventes) est relevé jusqu'au stock visé (ou
// jusqu'à zéro s'il n'en a pas) ; les articles encore en stock, même sous leur seuil, gardent leurs suggestions. Idempotent.
try {
    $stock ??= new StockRepository();
    $releves = 0;
    foreach (Database::tous(
        'SELECT n.lieu_id, n.produit_id, n.quantite, GREATEST(COALESCE(n.stock_cible, n.seuil_alerte, 0), 0) AS cible
           FROM stock_niveaux n JOIN lieux_stock l ON l.id = n.lieu_id
          WHERE l.etablissement_id = ? AND n.quantite <= 0 AND (n.quantite < 0 OR COALESCE(n.stock_cible, n.seuil_alerte, 0) > 0)',
        [$clubId]
    ) as $n) {
        $stock->mouvement((int) $n['lieu_id'], (int) $n['produit_id'], round((float) $n['cible'] - (float) $n['quantite'], 3), 'ouverture', null,
            date('Y-m-d H:i:s', strtotime('-25 days')), 'Stock d\'ouverture (démonstration)', $admin);
        $releves++;
    }
    if ($releves > 0) {
        echo "Stock du Volcano Club : $releves articles épuisés relevés au stock visé.\n";
    }
} catch (\Throwable $e) {
    echo 'Réassort du club non fait : ' . $e->getMessage() . "\n";
}

echo "Nuit de démonstration : " . implode(', ', array_map(static fn ($k, $v) => "$v $k", array_keys($compte), $compte)) . ".\n";
