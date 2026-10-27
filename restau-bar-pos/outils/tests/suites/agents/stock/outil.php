<?php
// Outils des tests du stock sur l'instance essaistock : php outil.php <action> [json]
declare(strict_types=1);
require '/home/user/Eglise/restau-bar-pos/app/bootstrap.php';
use App\Core\Database;
use App\Core\Instance;
use App\Repositories\StockRepository;
use App\Repositories\VenteRepository;
Instance::charger('integ');
date_default_timezone_set('Africa/Lubumbashi');
Database::executer('SET time_zone = ?', [date('P')]);
App\Core\I18n::initialiser('fr');
$action = $argv[1] ?? '';
$p = json_decode($argv[2] ?? '{}', true) ?: [];
switch ($action) {
    case 'vente':
        // {"etab":1,"zone":1,"appareil":null,"lignes":[[cond, qte], ...]}
        $etab = Database::un('SELECT * FROM etablissements WHERE id = ?', [$p['etab']]);
        $devise = Database::un("SELECT * FROM devises WHERE code = 'CDF'");
        $lignes = [];
        foreach ($p['lignes'] as [$cond, $q]) {
            $c = Database::un('SELECT * FROM conditionnements WHERE id = ?', [$cond]);
            $lignes[] = ['produit_id' => (int) $c['produit_id'], 'conditionnement_id' => (int) $cond, 'libelle' => 'Test', 'format' => $c['nom_fr'], 'quantite' => $q, 'prix' => 1000.0, 'prix_reference' => null];
        }
        $id = (new VenteRepository())->enregistrer([
            'etablissement' => $etab, 'cle' => sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), random_int(0, 0xffffffffffff)),
            'zone_id' => $p['zone'] ?? null, 'grille_id' => (int) $etab['grille_prix_id'], 'utilisateur_id' => 1, 'appareil_id' => $p['appareil'] ?? null,
            'devise' => $devise, 'total' => 1000.0 * count($lignes), 'reference' => null, 'total_reference' => null, 'totaux' => [],
            'lignes' => $lignes, 'paiements' => [], 'rendu' => null,
        ]);
        echo $id;
        break;
    case 'epuises':
        echo json_encode((new StockRepository())->epuises((int) $p['etab'], $p['appareil'] ?? null, $p['zone'] ?? null));
        break;
    case 'vide':
        // Établissement sans aucun lieu ni stock : la vente passe et crée le comptoir « Bar », stock négatif
        Database::executer("INSERT INTO etablissements (slug, nom, type, grille_prix_id) VALUES ('essai-vide', 'Essai vide', 'restaurant_bar', 1)");
        $etab = Database::dernierId();
        $devise = Database::un("SELECT * FROM devises WHERE code = 'CDF'");
        $id = (new VenteRepository())->enregistrer([
            'etablissement' => Database::un('SELECT * FROM etablissements WHERE id = ?', [$etab]), 'cle' => sprintf('%08x-1111-4000-8000-%012x', random_int(0, 0xffffffff), random_int(0, 0xffffffffffff)),
            'zone_id' => null, 'grille_id' => 1, 'utilisateur_id' => 1, 'appareil_id' => null, 'devise' => $devise, 'total' => 1000.0,
            'reference' => null, 'total_reference' => null, 'totaux' => [],
            'lignes' => [['produit_id' => 6, 'conditionnement_id' => 10, 'libelle' => 'Eau', 'format' => 'Bouteille', 'quantite' => 2, 'prix' => 500.0, 'prix_reference' => null]],
            'paiements' => [], 'rendu' => null,
        ]);
        $lieux = Database::tous('SELECT * FROM lieux_stock WHERE etablissement_id = ?', [$etab]);
        $n = Database::un('SELECT n.quantite FROM stock_niveaux n JOIN lieux_stock l ON l.id = n.lieu_id WHERE l.etablissement_id = ? AND n.produit_id = 6', [$etab]);
        echo json_encode(['vente' => $id, 'lieux' => count($lieux), 'nom' => $lieux[0]['nom'] ?? null, 'quantite' => $n['quantite'] ?? null]);
        // Nettoyage
        Database::executer('DELETE FROM stock_mouvements WHERE source_id = ? AND type = \'vente\'', [$id]);
        Database::executer('DELETE FROM ventes WHERE id = ?', [$id]);
        Database::executer('DELETE FROM lieux_stock WHERE etablissement_id = ?', [$etab]);
        Database::executer('DELETE FROM etablissements WHERE id = ?', [$etab]);
        break;
    case 'utilisateur':
        // {"nom", "email", "mdp", "role", "etab"}
        Database::executer('INSERT INTO utilisateurs (nom, email, mot_de_passe) VALUES (?, ?, ?)', [$p['nom'], $p['email'], password_hash($p['mdp'], PASSWORD_DEFAULT)]);
        $id = Database::dernierId();
        Database::executer('INSERT INTO utilisateur_roles (utilisateur_id, role_id, etablissement_id) SELECT ?, id, ? FROM roles WHERE code = ?', [$id, $p['etab'] ?? null, $p['role']]);
        echo $id;
        break;
    default:
        exit("Action inconnue\n");
}
