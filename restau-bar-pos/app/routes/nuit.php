<?php

declare(strict_types=1);

/**
 * Lounge et nightclub : caisse de l'entrée et vestiaire (mêmes règles d'abonnement que la caisse), cashless, invités,
 * fidélité à la caisse (JSON) ; écrans de gestion du module Nuit (formules, minimums, billetterie, invités et
 * promoteurs, vestiaire, espace du promoteur), du cashless, des remises et de la fidélité.
 * @var App\Core\Router $routeur
 */

use App\Controllers\CaisseController;
use App\Controllers\CaisseNuitController;
use App\Controllers\Gestion\CashlessController;
use App\Controllers\Gestion\FideliteController;
use App\Controllers\Gestion\NuitController;
use App\Controllers\Gestion\RemiseController;

// Caisse : écrans de l'entrée et du vestiaire
$routeur->get('/caisse/entree', [CaisseController::class, 'entree']);
$routeur->get('/caisse/vestiaire', [CaisseController::class, 'vestiaire']);
$routeur->post('/caisse/entree/controle', [CaisseNuitController::class, 'controle']);
$routeur->get('/caisse/entree/compteurs', [CaisseNuitController::class, 'compteurs']);
$routeur->get('/caisse/entree/invites', [CaisseNuitController::class, 'invites']);
$routeur->post('/caisse/entree/invites/{id}/pointer', [CaisseNuitController::class, 'pointer']);
$routeur->post('/caisse/vestiaire/deposer', [CaisseNuitController::class, 'deposer']);
$routeur->post('/caisse/vestiaire/rendre', [CaisseNuitController::class, 'rendre']);
$routeur->get('/caisse/vestiaire/liste', [CaisseNuitController::class, 'restants']);
// Caisse : cashless, fidélité, invité d'une note
$routeur->get('/caisse/nuit/carte', [CaisseNuitController::class, 'carte']);
$routeur->post('/caisse/nuit/carte/nouvelle', [CaisseNuitController::class, 'nouvelleCarte']);
$routeur->post('/caisse/nuit/carte/recharger', [CaisseNuitController::class, 'recharger']);
$routeur->post('/caisse/nuit/carte/rembourser', [CaisseNuitController::class, 'rembourser']);
$routeur->post('/caisse/nuit/carte/bloquer', [CaisseNuitController::class, 'bloquer']);
$routeur->get('/caisse/nuit/clients', [CaisseNuitController::class, 'clients']);
$routeur->post('/caisse/nuit/clients', [CaisseNuitController::class, 'creerClient']);
$routeur->post('/caisse/notes/{id}/invite', [CaisseNuitController::class, 'inviteNote']);

// Gestion > Nuit (les chemins fixes passent avant ceux qui prennent un identifiant)

$routeur->get('/gestion/nuit/entrees', [NuitController::class, 'entrees']);
$routeur->post('/gestion/nuit/entrees/tarifs', [NuitController::class, 'tarif']);
$routeur->post('/gestion/nuit/entrees/tarifs/{id}', [NuitController::class, 'tarif']);
$routeur->post('/gestion/nuit/entrees/tarifs/{id}/supprimer', [NuitController::class, 'supprimerTarif']);
$routeur->get('/gestion/nuit/invites', [NuitController::class, 'invites']);
$routeur->post('/gestion/nuit/invites', [NuitController::class, 'enregistrerInvite']);
$routeur->post('/gestion/nuit/invites/{id}', [NuitController::class, 'enregistrerInvite']);
$routeur->post('/gestion/nuit/invites/{id}/retirer', [NuitController::class, 'retirerInvite']);
$routeur->get('/gestion/nuit/promoteurs', [NuitController::class, 'promoteurs']);
$routeur->post('/gestion/nuit/promoteurs', [NuitController::class, 'enregistrerPromoteur']);
$routeur->get('/gestion/nuit/promoteurs/{id}', [NuitController::class, 'promoteur']);
$routeur->post('/gestion/nuit/promoteurs/{id}', [NuitController::class, 'enregistrerPromoteur']);
$routeur->post('/gestion/nuit/promoteurs/{id}/payer', [NuitController::class, 'payerPromoteur']);
$routeur->get('/gestion/nuit/mon-espace', [NuitController::class, 'monEspace']);
$routeur->post('/gestion/nuit/mon-espace/invite', [NuitController::class, 'monEspaceInvite']);
$routeur->get('/gestion/nuit/formules', [NuitController::class, 'formules']);
$routeur->post('/gestion/nuit/formules', [NuitController::class, 'enregistrerFormule']);
$routeur->post('/gestion/nuit/formules/{id}/retirer', [NuitController::class, 'retirerFormule']);
$routeur->get('/gestion/nuit/minimums', [NuitController::class, 'minimums']);
$routeur->post('/gestion/nuit/minimums', [NuitController::class, 'enregistrerMinimum']);
$routeur->post('/gestion/nuit/minimums/{id}', [NuitController::class, 'enregistrerMinimum']);
$routeur->post('/gestion/nuit/minimums/{id}/supprimer', [NuitController::class, 'supprimerMinimum']);
$routeur->get('/gestion/nuit/vestiaire', [NuitController::class, 'vestiaire']);
$routeur->post('/gestion/nuit/vestiaire/frais', [NuitController::class, 'fraisVestiaire']);

// Gestion > Cashless
$routeur->get('/gestion/cashless', [CashlessController::class, 'index']);
$routeur->post('/gestion/cashless', [CashlessController::class, 'creer']);
$routeur->get('/gestion/cashless/imprimer', [CashlessController::class, 'imprimer']);
$routeur->get('/gestion/cashless/passif', [CashlessController::class, 'passif']);
$routeur->get('/gestion/cashless/{id}', [CashlessController::class, 'fiche']);
$routeur->post('/gestion/cashless/{id}/bloquer', [CashlessController::class, 'bloquer']);

// Gestion > Ventes : remises et offerts, fidélité
$routeur->get('/gestion/remises', [RemiseController::class, 'index']);
$routeur->post('/gestion/remises/limites', [RemiseController::class, 'limites']);
$routeur->get('/gestion/fidelite', [FideliteController::class, 'index']);
$routeur->post('/gestion/fidelite/reglages', [FideliteController::class, 'reglages']);
$routeur->post('/gestion/fidelite/recompenses', [FideliteController::class, 'recompense']);
$routeur->post('/gestion/fidelite/recompenses/{id}', [FideliteController::class, 'recompense']);
