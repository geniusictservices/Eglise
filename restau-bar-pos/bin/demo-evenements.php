<?php

declare(strict_types=1);

/**
 * Soirées de démonstration du Volcano Club (module Événements) : trois soirées passées, aux dates des ventes de
 * démonstration (les trois derniers week-ends), chacune avec ses coûts enregistrés comme des dépenses
 * (cachets, publicité, location de matériel, payés depuis la Caisse du Club, M-Pesa ou Airtel Money, en dollars
 * ou en francs) rattachées à la soirée, et une soirée à venir avec un acompte déjà versé au DJ.
 *
 *   php bin/demo-evenements.php demo
 *
 * Sans effet sur ce qui existe : une soirée de démonstration déjà là (même titre, même établissement) est gardée
 * telle quelle, avec ses dépenses. Le script peut donc être relancé sans risque.
 */

use App\Core\Config;
use App\Core\Database;
use App\Core\I18n;
use App\Core\Instance;
use App\Repositories\GestionDepenseRepository;
use App\Repositories\GestionTresorerieRepository;

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
    arreter('Usage : php bin/demo-evenements.php <sous-domaine>');
}
if (Instance::charger($instance) === null) {
    arreter("Instance inconnue : $instance");
}
$parametres = array_column(Database::tous('SELECT cle, valeur FROM parametres'), 'valeur', 'cle');
date_default_timezone_set($parametres['fuseau_horaire'] ?? Config::get('fuseau_horaire'));
Database::executer('SET time_zone = ?', [date('P')]);
I18n::initialiser('fr');

if (Database::un("SELECT 1 AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'evenements'") === null) {
    arreter('La table « evenements » manque : php bin/migrer.php ' . $instance);
}
$club = Database::un("SELECT id, nom FROM etablissements WHERE slug = 'volcano-club'")
    ?? Database::un("SELECT id, nom FROM etablissements WHERE type IN ('nightclub', 'lounge') AND actif = 1 ORDER BY position, id LIMIT 1");
if ($club === null) {
    echo "Aucun lounge ni nightclub : pas de soirée de démonstration.\n";
    exit(0);
}
$clubId = (int) $club['id'];
$auteur = (int) (Database::un("SELECT u.id FROM utilisateurs u JOIN utilisateur_roles ur ON ur.utilisateur_id = u.id JOIN roles r ON r.id = ur.role_id
                                WHERE r.tout = 1 AND u.actif = 1 ORDER BY u.id LIMIT 1")['id'] ?? 0) ?: null;

$tresorerie = new GestionTresorerieRepository();
$depenses = new GestionDepenseRepository($tresorerie);
$devises = [];
foreach ($tresorerie->devises() as $devise) {
    $devises[$devise['code']] = $devise;
}
if (!isset($devises['USD'], $devises['CDF'])) {
    arreter('Il faut les devises USD et CDF.');
}
$comptes = $tresorerie->comptes();
/** Premier compte actif du type, de l'établissement ou commun, qui tient la devise. */
$compte = static function (string $type, string $code) use ($comptes, $devises, $clubId): ?array {
    foreach ([true, false] as $propre) {
        foreach ($comptes as $c) {
            if ($c['active'] && $c['type'] === $type && GestionTresorerieRepository::tient($c, (int) $devises[$code]['id'])
                && ($propre ? (int) $c['etablissement_id'] === $clubId : $c['etablissement_id'] === null)) {
                return $c;
            }
        }
    }
    return null;
};
$categorie = static function (string $nom): int {
    $ligne = Database::un('SELECT id FROM categories_depenses WHERE nom_fr = ?', [$nom]);
    if ($ligne === null) {
        Database::executer('INSERT INTO categories_depenses (nom_fr, nom_en, position) VALUES (?, ?, 99)', [$nom, $nom]);
        return Database::dernierId();
    }
    return (int) $ligne['id'];
};

// Dates : les trois derniers week-ends (vendredi, samedi, vendredi) et le prochain samedi
$aujourdhui = new DateTimeImmutable('today');
$dernierVendredi = $aujourdhui->modify('last friday');
$soirees = [
    [
        'titre' => 'Afro Night', 'jour' => $dernierVendredi->modify('-14 days'),
        'theme' => 'Afrobeats et amapiano, entrée libre avant minuit', 'artistes' => "DJ Kivu\nMC Lava",
        'budget' => [600, 'USD'], 'description' => 'Première soirée de la saison, annoncée sur Facebook et Instagram.',
        'couts' => [
            ['Cachet DJ Kivu', 'Artistes et DJ', 'DJ Kivu', [['caisse', 'USD', 300]]],
            ['Affiches et publicité Facebook', 'Publicité', 'Imprimerie du Lac', [['mobile_money', 'CDF', 150000]]],
            ['Location des jeux de lumière', 'Location de matériel', 'Goma Sono Light', [['caisse', 'USD', 120]]],
        ],
    ],
    [
        'titre' => 'Soirée blanche', 'jour' => $dernierVendredi->modify('-6 days'),
        'theme' => 'Dress code blanc, bouteilles au carré VIP', 'artistes' => "DJ Neema\nInvité : Fally Kivu Live",
        'budget' => [900, 'USD'], 'description' => 'Décor blanc, deux tables VIP réservées par Kivu Logistique.',
        'couts' => [
            ['Cachet DJ Neema', 'Artistes et DJ', 'DJ Neema', [['caisse', 'USD', 400]]],
            ['Cachet de l\'artiste invité', 'Artistes et DJ', 'Fally Kivu Live', [['caisse', 'USD', 150], ['caisse', 'CDF', 285000]]],
            ['Spots radio et affiches', 'Publicité', 'Radio Kivu FM', [['mobile_money', 'CDF', 200000]]],
            ['Location de la sono et du décor', 'Location de matériel', 'Goma Sono Light', [['caisse', 'USD', 150]]],
        ],
    ],
    [
        'titre' => 'Rumba & Ndombolo Live', 'jour' => $dernierVendredi,
        'theme' => 'Orchestre en live, rumba congolaise jusqu\'à l\'aube', 'artistes' => 'Orchestre Nyiragongo Stars',
        'budget' => [700, 'USD'], 'description' => null,
        'couts' => [
            ['Cachet de l\'orchestre', 'Artistes et DJ', 'Orchestre Nyiragongo Stars', [['caisse', 'USD', 500]]],
            ['Affiches', 'Publicité', 'Imprimerie du Lac', [['mobile_money', 'CDF', 100000]]],
        ],
    ],
    [
        'titre' => 'Halloween Volcano', 'jour' => $aujourdhui->modify('next saturday'),
        'theme' => 'Costumes et masques, cocktail du volcan offert aux 50 premiers', 'artistes' => "DJ Kivu\nDJ Neema",
        'budget' => [1000, 'USD'], 'description' => 'Acompte versé à DJ Kivu ; le reste du cachet le soir même.',
        'couts' => [
            ['Acompte DJ Kivu (Halloween)', 'Artistes et DJ', 'DJ Kivu', [['caisse', 'USD', 200]], $aujourdhui->setTime(12, 0)],
        ],
    ],
];

$creees = 0;
foreach ($soirees as $s) {
    if (Database::un('SELECT id FROM evenements WHERE etablissement_id = ? AND titre = ?', [$clubId, $s['titre']]) !== null) {
        echo "Soirée « {$s['titre']} » déjà là : gardée.\n";
        continue;
    }
    $debut = $s['jour']->setTime(22, 0);
    $fin = $s['jour']->modify('+1 day')->setTime(5, 0);
    Database::executer(
        'INSERT INTO evenements (etablissement_id, titre, theme, artistes, debut, fin, budget, budget_devise_id, description, cree_par, cree_le)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$clubId, $s['titre'], $s['theme'], $s['artistes'], $debut->format('Y-m-d H:i:s'), $fin->format('Y-m-d H:i:s'),
            number_format($s['budget'][0], 3, '.', ''), (int) $devises[$s['budget'][1]]['id'], $s['description'], $auteur,
            min($debut->modify('-10 days'), new DateTimeImmutable())->format('Y-m-d H:i:s')]
    );
    $evenementId = Database::dernierId();
    foreach ($s['couts'] as $i => $cout) {
        [$libelle, $nomCategorie, $beneficiaire, $lignes] = $cout;
        // Payé quelques jours avant la soirée (le cachet le soir même), jamais dans le futur
        $date = min($cout[4] ?? ($i === 0 ? $debut->setTime(21, 0) : $debut->modify('-' . (2 + $i) . ' days')->setTime(11, 30)), new DateTimeImmutable());
        $paiements = [];
        foreach ($lignes as [$type, $code, $montant]) {
            $c = $compte($type, $code) ?? $compte('caisse', $code);
            if ($c === null) {
                continue 2;
            }
            $paiements[] = ['compte' => $c, 'devise' => $devises[$code], 'montant' => (float) $montant];
        }
        $id = $depenses->creer([
            'date_depense' => $date->format('Y-m-d H:i:s'), 'categorie_id' => $categorie($nomCategorie), 'etablissement_id' => $clubId,
            'libelle' => $libelle, 'beneficiaire' => $beneficiaire, 'notes' => null,
        ], $paiements, (int) $auteur);
        Database::executer('UPDATE depenses SET evenement_id = ? WHERE id = ?', [$evenementId, $id]);
    }
    $creees++;
    echo "Soirée « {$s['titre']} » du " . $debut->format('d/m/Y') . ' créée (' . count($s['couts']) . " coûts).\n";
}
echo $creees > 0 ? "$creees soirées de démonstration créées pour {$club['nom']}.\n" : "Rien à faire.\n";
