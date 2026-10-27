<?php
// Tests du cœur des dettes (DetteRepository, Messagerie, bin/relances.php) sur la base de démo, restaurée ensuite.
declare(strict_types=1);
require '/home/user/Eglise/restau-bar-pos/app/bootstrap.php';
use App\Core\Database; use App\Core\Instance; use App\Core\Parametres; use App\Core\I18n;
use App\Repositories\DetteRepository; use App\Repositories\GestionTresorerieRepository;
Instance::charger('integ'); date_default_timezone_set('Africa/Lubumbashi'); Database::executer('SET time_zone = ?', [date('P')]); I18n::initialiser('fr');
$nb = 0; $ko = [];
function v(bool $c, string $m): void { global $nb, $ko; $nb++; if (!$c) $ko[] = $m; echo ($c ? 'ok    ' : 'ÉCHEC ') . $m . "\n"; }
function exc(callable $f): ?string { try { $f(); return null; } catch (\DomainException|\InvalidArgumentException $e) { return $e->getMessage(); } }
$r = new DetteRepository(); $t = new GestionTresorerieRepository();
const CDF = 2, USD = 1, PATRICK = 2, ALINE = 4, ESTHER = 5, RIZIKI = 8, COMPTOIR = 1;

// Téléphones
v(DetteRepository::normaliserTelephone('0970 123 456') === '+243970123456', 'téléphone local 0970… → +243970123456');
v(DetteRepository::normaliserTelephone('+250 788 123 456') === '+250788123456', 'numéro étranger gardé');
v(DetteRepository::normaliserTelephone('243970123456') === '+243970123456', 'indicatif sans + reconnu');
v(DetteRepository::normaliserTelephone('12') === null, 'numéro trop court refusé');

// Client
$c = $r->creerClient(['nom' => '  Maman   Furaha ', 'telephone' => '0970 111 222', 'langue' => 'fr', 'plafond' => 50000, 'plafond_devise_id' => CDF], PATRICK);
$client = $r->client($c);
v($client['nom'] === 'Maman Furaha' && $client['telephone'] === '+243970111222', 'client créé, nom nettoyé, téléphone normalisé');
v(exc(fn () => $r->creerClient(['nom' => 'Autre', 'telephone' => '+243970111222'], PATRICK)) === 'telephone_pris', 'même téléphone refusé');
// La démo a d'autres « Furaha » : le nouveau client est trouvé, avec exactement les clients dont un mot commence par « furaha »
$trouves = array_map('intval', array_column($r->rechercher('furaha'), 'id'));
$attendus = (int) Database::un("SELECT COUNT(*) n FROM clients WHERE actif = 1 AND type <> 'personnel' AND (nom LIKE 'furaha%' OR nom LIKE '% furaha%')")['n'];
v(in_array($c, $trouves, true) && count($trouves) === $attendus && count($r->rechercher('111 22')) === 1 && $r->rechercher('f') === [], 'recherche par nom, par chiffres ; 1 lettre : rien');

// Crédit : plafond 50 000 FC
v($r->empechements($client, CDF, 30000) === [], '30 000 FC : sous le plafond');
v(array_column($r->empechements($client, USD, 20), 'code') === ['plafond'], '20 $ = 57 000 FC : plafond dépassé');
$d1 = $r->creer(['client_id' => $c, 'etablissement_id' => COMPTOIR, 'devise_id' => CDF, 'montant' => 30000, 'echeance' => date('Y-m-d', strtotime('-3 days')),
    'servi_par' => ALINE, 'accorde_par' => RIZIKI, 'garant_id' => PATRICK, 'date' => date('Y-m-d H:i:s', strtotime('-10 days'))]);
$d = $r->dette($d1);
v((int) $d['responsable_id'] === ALINE && $d['garant_statut'] === 'en_attente' && (float) $d['reste'] === 30000.0, 'dette : Aline responsable, garant Patrick en attente');
v(in_array('retard', array_column($r->empechements($client, CDF, 1000), 'code'), true), 'échéance passée : nouveau crédit bloqué (retard)');
$d2 = $r->creer(['client_id' => $c, 'etablissement_id' => COMPTOIR, 'devise_id' => USD, 'montant' => 4, 'echeance' => date('Y-m-d', strtotime('+5 days')),
    'servi_par' => ESTHER, 'accorde_par' => RIZIKI, 'garant_id' => ESTHER]);
v($r->dette($d2)['garant_id'] === null, 'garant = la personne qui a servi : pas de garant');
v($r->encours($c) == [CDF => 30000.0, USD => 4.0], 'encours : 30 000 FC et 4 $');

// Garant
v(exc(fn () => $r->repondreGarant($d1, ESTHER, true)) === 'garantie_introuvable', 'une autre personne ne peut pas répondre à la place du garant');
v(count($r->garantiesEnAttente(PATRICK)) === 1, 'Patrick a une garantie à confirmer');
$r->repondreGarant($d1, PATRICK, true);
$d = $r->dette($d1);
v((int) $d['responsable_id'] === PATRICK && $d['garant_statut'] === 'accepte', 'garant accepte : Patrick devient responsable');
v(exc(fn () => $r->repondreGarant($d1, PATRICK, false)) === 'garantie_introuvable', 'on ne répond qu\'une fois');

// Remboursements : 5 $ (= 14 250 FC) sur la dette la plus ancienne
$avant = $t->compte(1)['devises'][USD]['solde'];
$caisse = ['id' => 1, 'type' => 'caisse'];
$rb = $r->rembourser(['client_id' => $c, 'etablissement_id' => COMPTOIR, 'compte' => $caisse, 'devise_id' => USD, 'montant' => 5, 'utilisateur_id' => RIZIKI, 'cle' => '11111111-2222-4333-8444-555555555555']);
v((float) $r->dette($d1)['reste'] === 15750.0 && (float) $r->dette($d2)['reste'] === 4.0, '5 $ → 14 250 FC sur la plus ancienne (reste 15 750 FC), la dette en $ intacte');
$t2 = new GestionTresorerieRepository();
v(abs($t2->compte(1)['devises'][USD]['solde'] - $avant - 5) < 1e-6, 'caisse du Comptoir : +5 $ (mouvement « remboursement »)');
v($r->rembourser(['client_id' => $c, 'etablissement_id' => COMPTOIR, 'compte' => $caisse, 'devise_id' => USD, 'montant' => 5, 'utilisateur_id' => RIZIKI, 'cle' => '11111111-2222-4333-8444-555555555555']) === $rb
  && (float) $r->dette($d1)['reste'] === 15750.0, 'même clé envoyée deux fois : un seul remboursement');
v(exc(fn () => $r->rembourser(['client_id' => $c, 'etablissement_id' => COMPTOIR, 'compte' => $caisse, 'devise_id' => CDF, 'montant' => 60000, 'utilisateur_id' => RIZIKI])) === 'trop'
  && abs($r->maximum - 27200) < 1e-6, 'trop payé refusé, maximum 15 750 + 11 400 = 27 150 → 27 200 FC (arrondi vers le haut)');
// 15 750 FC + 4 $ (11 400 FC) = tout, en FC
$r->rembourser(['client_id' => $c, 'etablissement_id' => COMPTOIR, 'compte' => ['id' => 3, 'type' => 'mobile_money'], 'devise_id' => CDF, 'montant' => 15800, 'utilisateur_id' => RIZIKI]);
v($r->dette($d1)['statut'] === 'soldee' && (float) $r->dette($d1)['reste'] === 0.0, '15 800 FC : la première soldée (50 FC d\'écart vers la suivante)');
v(abs((float) $r->dette($d2)['reste'] - (4 - 50 / 2850)) < 0.001, 'la dette en $ reçoit le petit reste (50 FC = 0,02 $)');
$moyen = Database::un('SELECT moyen FROM remboursements ORDER BY id DESC LIMIT 1')['moyen'];
v($moyen === 'mobile_money', 'remboursement : type du compte enregistré');

// Annulation d'un remboursement
$rid = (int) Database::un('SELECT id FROM remboursements ORDER BY id DESC LIMIT 1')['id'];
v(exc(fn () => $r->annulerRemboursement($rid, PATRICK, ' ')) === 'motif', 'annuler un remboursement : motif obligatoire');
$r->annulerRemboursement($rid, PATRICK, 'Erreur de saisie');
v($r->dette($d1)['statut'] === 'ouverte' && (float) $r->dette($d1)['reste'] === 15750.0 && (float) $r->dette($d2)['reste'] === 4.0, 'annulé : les deux dettes retrouvent leur reste');

// Clôtures
v(exc(fn () => $r->cloturer($d1, 'annulee', PATRICK, 'test')) === 'deja_rembourse', 'annuler une dette déjà en partie remboursée : refusé (faire une remise)');
$r->cloturer($d2, 'remise', PATRICK, 'Client fidèle');
v($r->dette($d2)['statut'] === 'remise', 'remise de la dette en $');

// Mise à la charge
$d3 = $r->creer(['client_id' => $c, 'etablissement_id' => COMPTOIR, 'devise_id' => CDF, 'montant' => 9000, 'echeance' => date('Y-m-d', strtotime('+2 days')), 'servi_par' => ALINE, 'accorde_par' => RIZIKI]);
v(exc(fn () => $r->mettreALaCharge($d3, PATRICK, 'Client parti')) === 'pas_echue', 'mise à la charge avant l\'échéance : refusée');
$n = $r->mettreALaCharge($d1, PATRICK, 'Client injoignable');
$nouvelle = $r->dette($n); $perso = $r->client((int) $nouvelle['client_id']);
v($r->dette($d1)['statut'] === 'transferee' && $perso['type'] === 'personnel' && (int) $perso['utilisateur_id'] === PATRICK
  && (float) $nouvelle['reste'] === 15750.0 && (int) $nouvelle['origine_id'] === $d1, 'à la charge de Patrick (garant) : 15 750 FC sur sa fiche du personnel');
v($r->rechercher('Patrick') === [], 'le personnel n\'apparaît pas dans la recherche de la caisse');

// Relances : étapes
v(DetteRepository::etapeDuJour('2026-11-10', '2026-11-09', [-1, 1, 7, 30]) === -1, 'veille de l\'échéance : J-1');
v(DetteRepository::etapeDuJour('2026-11-10', '2026-11-10', [-1, 1, 7, 30]) === null, 'jour de l\'échéance : rien');
v(DetteRepository::etapeDuJour('2026-11-10', '2026-11-14', [-1, 1, 7, 30]) === 1, 'J+4 : étape J+1 (si elle n\'est pas encore partie)');
v(DetteRepository::etapeDuJour('2026-11-10', '2026-12-25', [-1, 1, 7, 30]) === 30, 'J+45 : étape J+30');
echo "\n" . ($nb - count($ko)) . "/$nb\n";
