<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Acces;
use App\Core\CaissePartagee;
use App\Core\Database;
use App\Core\Journal;
use App\Core\Plateforme;
use App\Core\PinSuperieur;
use App\Core\Reglement;
use App\Core\Remises;
use App\Core\Session;
use App\Core\View;
use App\Repositories\BilletterieRepository;
use App\Repositories\CashlessRepository;
use App\Repositories\DetteRepository;
use App\Repositories\FideliteRepository;
use App\Repositories\GestionTresorerieRepository;
use App\Repositories\NoteRepository;
use App\Repositories\PromoteurRepository;
use App\Repositories\RemiseRepository;
use App\Repositories\SessionCaisseRepository;
use App\Repositories\VestiaireRepository;

/**
 * Nuit à la caisse (réponses JSON, avec caisse-nuit.js) : cartes cashless (lire, créer, recharger, rembourser,
 * bloquer), contrôle des billets à la porte, invités (recherche, pointage), vestiaire (dépôt sans frais, retour,
 * objets restants), fidélité (client et points), invité lié à une note. Les ventes (remises, formules, minimum, entrées,
 * frais de vestiaire, paiement par carte) passent par CaisseController::encaisser().
 */
final class CaisseNuitController
{
    // ---------- Données de la caisse ----------

    /** Ce que caisse-nuit.js doit savoir : remises, cashless, fidélité, entrée, vestiaire, validations. */
    public static function donneesCaisse(array $etab, array $personne, string $poste, array $contexte, ?array $appareil): array
    {
        $etabId = (int) $etab['id'];
        $uid = (int) $personne['id'];
        $pret = RemiseRepository::disponible();
        $nuitIncluse = Plateforme::moduleInclus('nightclub');
        $donnees = [
            'poste' => $poste,
            'pret' => $pret,
            'textes' => self::textes(),
            'remise' => null, 'cashless' => null, 'fidelite' => null, 'entree' => null, 'vestiaire' => null, 'minimum' => null,
            // Taux du jour (unités pour 1 unité de référence) : équivalent d'une recharge dans les autres devises
            'taux' => array_column(array_map(static fn (array $d) => ['code' => $d['code'], 'taux' => $d['taux'] !== null ? (float) $d['taux'] : null], $contexte['acceptees']), 'taux', 'code'),
            'invites' => false,
        ];
        if (!$pret) {
            return $donnees;
        }
        $limite = RemiseRepository::limite($uid, $etabId);
        $donnees['remise'] = [
            'limite' => $limite,
            'motifs' => array_map(static fn (string $m) => ['code' => $m, 'texte' => t('nuit.motif_' . $m)], array_values(array_diff(Remises::MOTIFS, ['fidelite']))),
            'valideurs' => PinSuperieur::valideurs(static fn (int $id): bool => Acces::a($id, 'caisse.remise', $etabId) && $id !== $uid),
        ];
        if ($poste === 'caisse') {
            $donnees['minimum'] = [
                'lever' => Acces::a($uid, 'caisse.minimum', $etabId),
                'valideurs' => PinSuperieur::valideurs(static fn (int $id): bool => Acces::a($id, 'caisse.minimum', $etabId) && $id !== $uid),
            ];
        }
        if (Plateforme::moduleInclus('cashless') && CashlessRepository::disponible() && self::lieuDeNuit($etab)) {
            $donnees['cashless'] = [
                'recharger' => Acces::a($uid, 'cashless.recharger', $etabId),
                'gerer' => Acces::a($uid, 'cashless.gerer', $etabId),
                'payer' => true,
            ];
        }
        if ($poste === 'caisse' && ($reglages = (new FideliteRepository())->reglages($etabId)) !== null) {
            $donnees['fidelite'] = [
                'montant' => (float) $reglages['montant_point'], 'devise' => $reglages['devise_code'],
                'recompenses' => array_map(static fn (array $r) => [
                    'id' => (int) $r['id'], 'nom' => champ($r, 'nom'), 'points' => (int) $r['points'], 'type' => $r['type'],
                    'pourcent' => $r['pourcent'] !== null ? (float) $r['pourcent'] : null,
                    'format' => $r['conditionnement_id'] !== null ? (int) $r['conditionnement_id'] : null,
                ], (new FideliteRepository())->recompenses($etabId)),
            ];
        }
        if ($poste === 'entree' && $nuitIncluse) {
            $soiree = $contexte['soiree'] ?? (new BilletterieRepository())->soiree($etab);
            $donnees['entree'] = [
                'vendre' => Acces::a($uid, 'entrees.vendre', $etabId),
                'controler' => Acces::a($uid, 'entrees.controler', $etabId),
                'invites' => Acces::a($uid, 'invites.pointer', $etabId) && $soiree['evenement'] !== null,
                'soiree' => ['journee' => $soiree['journee'], 'titre' => $soiree['evenement']['titre'] ?? null],
                'compteurs' => (new BilletterieRepository())->compteurs($etabId, $soiree['journee']),
            ];
        }
        if ($poste === 'vestiaire' && $nuitIncluse) {
            $donnees['vestiaire'] = [
                'payant' => (new VestiaireRepository())->frais($etabId) !== [],
                'restants' => count((new VestiaireRepository())->deposes($etabId)),
            ];
        }
        // Invité d'une note (commission du promoteur) : invités pointés ce soir
        if ($poste === 'caisse' && $nuitIncluse && PromoteurRepository::disponible()) {
            $soiree = (new BilletterieRepository())->soiree($etab);
            $donnees['invites'] = $soiree['evenement'] !== null && Acces::a($uid, 'caisse.vendre', $etabId);
        }
        return $donnees;
    }

    /** Lounge ou nightclub (le cashless et la nuit ne s'affichent pas dans un restaurant-bar). */
    public static function lieuDeNuit(array $etab): bool
    {
        return in_array($etab['type'] ?? '', ['lounge', 'nightclub'], true);
    }

    private static function textes(): array
    {
        $cles = ['remise', 'remise_titre', 'remise_ticket', 'remise_ligne', 'pourcent', 'montant', 'offert', 'motif', 'precision', 'appliquer', 'retirer',
            'remise_validation', 'remise_au_dela_court', 'valideur', 'pin', 'remises', 'sous_total', 'cible', 'tout_ticket', 'formule_titre', 'formule_choisir',
            'formule_valider', 'formule_reste', 'inclus', 'carte', 'carte_titre', 'carte_scanner', 'carte_code', 'carte_lire', 'carte_solde', 'carte_bloquee',
            'carte_payer', 'carte_payer_titre', 'carte_recharger', 'carte_rembourser', 'carte_bloquer', 'carte_debloquer', 'carte_nouvelle', 'carte_bracelet',
            'carte_porteur', 'carte_historique', 'carte_vide', 'carte_imprimer', 'montant_recharge', 'devise', 'compte', 'valider', 'fermer', 'annuler',
            'camera', 'camera_arreter', 'camera_absente', 'code_manuel', 'controle', 'controle_titre', 'controle_ok', 'controle_deja', 'controle_invalide',
            'controle_annule', 'controle_autre_soiree', 'controle_suivant', 'invites', 'invites_titre', 'invite_chercher', 'invite_pointer', 'invite_arrives',
            'invite_gratuite', 'invite_reduite', 'invite_complet', 'invite_aucun', 'invite_ajoute_ticket', 'vestiaire_deposer', 'vestiaire_description',
            'vestiaire_rendre', 'vestiaire_restants', 'vestiaire_rendu', 'vestiaire_deja', 'vestiaire_inconnu', 'vestiaire_numero', 'vestiaire_gratuit',
            'vestiaire_aucun', 'fidelite', 'fidelite_titre', 'fidelite_client', 'fidelite_points', 'fidelite_gagnes', 'fidelite_recompense', 'fidelite_aucune',
            'fidelite_retirer', 'fidelite_nouveau', 'fidelite_nom', 'fidelite_telephone', 'fidelite_creer', 'minimum', 'minimum_progres', 'minimum_atteint',
            'minimum_ajoute', 'minimum_avertir', 'minimum_lever', 'minimum_leve', 'minimum_garder', 'entrees_nuit', 'compteur_vendus', 'compteur_entres',
            'compteur_refus', 'erreur', 'soldes', 'invite_note', 'invite_note_titre', 'invite_note_aucun', 'invite_note_lie', 'ticket_remise', 'remise_fidelite',
            'pin_aide', 'carte_rembourser_titre', 'motif_blocage', 'recharge_faite', 'remboursement_fait', 'carte_creee', 'carte_ajoutee', 'carte_trop',
            'billets', 'imprimer'];
        $textes = [];
        foreach ($cles as $cle) {
            $textes[$cle] = t('nuit.c_' . $cle);
        }
        return $textes;
    }

    // ---------- Cashless ----------

    /** GET /caisse/nuit/carte?etab=&code= : la carte scannée, ses soldes et ses derniers mouvements. */
    public function carte(): void
    {
        ['personne' => $personne, 'etab' => $etab] = $this->acces(false, ['caisse.encaisser', 'cashless.recharger', 'cashless.gerer', 'entrees.vendre', 'vestiaire.gerer']);
        $this->exigerCashless($etab);
        $repo = new CashlessRepository();
        $carte = $repo->parCode($_GET['code'] ?? null);
        if ($carte === null && Acces::a((int) $personne['id'], 'cashless.gerer', (int) $etab['id']) && ctype_digit((string) ($_GET['numero'] ?? ''))) {
            // Carte perdue : le gérant la retrouve par son numéro, pour la bloquer ou rembourser son solde
            $c = Database::un('SELECT id FROM cartes_cashless WHERE numero = ?', [(int) $_GET['numero']]);
            $carte = $c !== null ? $repo->carte((int) $c['id']) : null;
        }
        if ($carte === null) {
            $this->repondre(404, ['erreur' => t('nuit.carte_inconnue'), 'code' => 'carte_inconnue']);
        }
        $this->repondre(200, ['carte' => $this->carteJson($carte, $etab, Acces::a((int) $personne['id'], 'cashless.gerer', (int) $etab['id']))]);
    }

    private function carteJson(array $carte, array $etab, bool $historique): array
    {
        $acceptees = CaisseController::devisesCaisse($etab)['acceptees'];
        $soldes = [];
        foreach ($acceptees as $id => $d) {
            $soldes[$d['code']] = round((float) ($carte['soldes'][$id] ?? 0), 3);
        }
        return [
            'id' => (int) $carte['id'], 'numero' => (int) $carte['numero'], 'code' => $carte['code'], 'statut' => $carte['statut'],
            'support' => $carte['support'], 'porteur' => $carte['porteur'] ?? $carte['client_nom'] ?? null, 'soldes' => $soldes,
            'motif' => $carte['motif_blocage'] ?? null,
            'historique' => $historique ? array_map(static fn (array $m) => [
                'type' => $m['type'], 'libelle' => t('nuit.mouvement_' . $m['type']), 'montant' => (float) $m['montant'], 'devise' => $m['devise_code'], 'solde' => (float) $m['solde_apres'],
                'le' => date('d/m H:i', strtotime($m['cree_le'])), 'vente' => $m['vente_numero'], 'par' => $m['par_nom'],
            ], (new CashlessRepository())->historique((int) $carte['id'], 12)) : [],
        ];
    }

    /** POST /caisse/nuit/carte/nouvelle : une carte (ou un bracelet) créée à la caisse, à imprimer. */
    public function nouvelleCarte(): void
    {
        ['personne' => $personne, 'etab' => $etab, 'appareil' => $appareil] = $this->acces(true, ['cashless.recharger']);
        $this->exigerCashless($etab);
        $porteur = CaisseNoteController::texteLibre($_POST['porteur'] ?? null);
        $cle = is_string($_POST['cle'] ?? null) && preg_match('/^[0-9a-f-]{36}$/', $_POST['cle']) ? $_POST['cle'] : null;
        $deja = $cle !== null ? ($_SESSION['cartes_creees'][$cle] ?? null) : null;
        $repo = new CashlessRepository();
        $carte = $deja !== null ? $repo->carte((int) $deja) : $repo->creer(1, ($_POST['support'] ?? '') === 'bracelet' ? 'bracelet' : 'carte', $porteur, (int) $personne['id'])[0];
        if ($cle !== null) {
            $_SESSION['cartes_creees'] = array_slice(($_SESSION['cartes_creees'] ?? []) + [$cle => (int) $carte['id']], -20, null, true);
        }
        $this->repondre(200, [
            'carte' => $this->carteJson($carte, $etab, false),
            'impression' => View::rendre('caisse/_cartes', ['cartes' => [$carte], 'etablissement' => $etab]),
            'csrf' => Session::jetonCsrf(),
        ]);
    }

    /**
     * POST /caisse/nuit/carte/recharger : vente = {cle, etab, carte (code), devise, montant, paiements, rendu} (fenêtre de
     * paiement de la caisse). L'argent entre dans la trésorerie maintenant ; le solde de la carte augmente.
     */
    public function recharger(): void
    {
        ['personne' => $personne, 'etab' => $etab, 'appareil' => $appareil] = $this->acces(true, ['cashless.recharger']);
        $this->exigerCashless($etab);
        $demande = json_decode(is_string($_POST['vente'] ?? null) ? $_POST['vente'] : '', true);
        $extra = is_array($demande['extra'] ?? null) ? $demande['extra'] : [];
        $cle = is_string($demande['cle'] ?? null) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $demande['cle']) ? $demande['cle'] : null;
        if (!is_array($demande) || $cle === null) {
            $this->repondre(422, ['erreur' => t('encaisser.illisible')]);
        }
        $etabId = (int) $etab['id'];
        $session = CaisseController::sessionExigee($etab, $personne);
        CaissePointageController::exigerPointage($etab, $personne);
        $repo = new CashlessRepository();
        if (($deja = Database::un('SELECT id FROM carte_mouvements WHERE cle = ?', [$cle])) !== null) {
            $this->repondreRecharge((int) $deja['id'], $etab, $appareil);
        }
        ['acceptees' => $accepteesIds, 'principale' => $principale] = CaisseController::devisesCaisse($etab);
        $acceptees = array_column($accepteesIds, null, 'code');
        $carte = $repo->parCode($extra['carte'] ?? null);
        $code = is_string($extra['devise'] ?? null) ? $extra['devise'] : '';
        $montant = is_numeric($extra['montant'] ?? null) ? round((float) $extra['montant'], (int) ($acceptees[$code]['decimales'] ?? 2)) : 0.0;
        if ($carte === null || !isset($acceptees[$code]) || $montant <= 0 || $montant > 1e9) {
            $this->repondre(422, ['erreur' => t('nuit.carte_invalide'), 'code' => 'carte']);
        }
        if ($carte['statut'] !== 'active') {
            $this->repondre(409, ['erreur' => t('nuit.carte_bloquee', ['carte' => $carte['code']]), 'code' => 'carte_bloquee']);
        }
        $totaux = self::totauxRecharge($montant, $acceptees[$code], $acceptees);
        $comptes = array_column(CaisseController::comptesCaisse($etabId, $accepteesIds), null, 'id');
        $paiements = [];
        foreach (is_array($demande['paiements'] ?? null) ? $demande['paiements'] : [] as $pa) {
            $c = is_string($pa['devise'] ?? null) ? $pa['devise'] : '';
            $compte = $comptes[(int) ($pa['compte'] ?? 0)] ?? null;
            $recu = is_numeric($pa['recu'] ?? null) ? round((float) $pa['recu'], (int) ($acceptees[$c]['decimales'] ?? 2)) : 0.0;
            if (!isset($totaux[$c]) || $compte === null || !in_array($c, $compte['devises'], true) || $recu <= 0 || $recu > 1e12) {
                $this->repondre(422, ['erreur' => t('encaisser.paiement_invalide')]);
            }
            $paiements[] = ['compte' => $compte, 'devise' => $acceptees[$c], 'recu' => $recu, 'taux' => self::taux($acceptees[$c])];
        }
        if ($paiements === []) {
            $this->repondre(422, ['erreur' => t('encaisser.sans_paiement')]);
        }
        $reglement = Reglement::calculer($totaux, $acceptees, array_map(static fn (array $pa) => ['devise' => $pa['devise']['code'], 'recu' => $pa['recu']], $paiements), $code);
        if (!$reglement['regle']) {
            $this->repondre(422, ['erreur' => t('encaisser.reste', ['reste' => (new \App\Repositories\DeviseRepository())->formater($reglement['reste'][$code], $acceptees[$code])])]);
        }
        $rendu = null;
        if (array_sum($reglement['rendu']) > 0) {
            $codeRendu = is_string($demande['rendu'] ?? null) && isset($reglement['rendu'][$demande['rendu']]) && $reglement['rendu'][$demande['rendu']] > 0 ? $demande['rendu'] : $code;
            $caisse = null;
            foreach (array_merge(array_column($paiements, 'compte'), array_values($comptes)) as $compte) {
                if ($compte['type'] === 'caisse' && in_array($codeRendu, $compte['devises'], true)) {
                    $caisse = $compte;
                    break;
                }
            }
            if ($caisse === null) {
                $this->repondre(422, ['erreur' => t('encaisser.rendu_impossible', ['devise' => $codeRendu])]);
            }
            if ($reglement['rendu'][$codeRendu] > 0) {
                $rendu = ['montant' => $reglement['rendu'][$codeRendu], 'devise' => $acceptees[$codeRendu], 'compte' => $caisse];
            }
        }
        try {
            $id = $repo->recharger([
                'carte_id' => (int) $carte['id'], 'devise' => $acceptees[$code], 'montant' => $montant, 'paiements' => $paiements, 'rendu' => $rendu,
                'etablissement_id' => $etabId, 'utilisateur_id' => (int) $personne['id'], 'appareil_id' => $appareil ? (int) $appareil['id'] : null,
                'session_id' => $session !== null ? (int) $session['id'] : null, 'cle' => $cle,
            ]);
        } catch (\DomainException) {
            $this->repondre(409, ['erreur' => t('nuit.carte_bloquee', ['carte' => $carte['code']]), 'code' => 'carte_bloquee']);
        }
        $this->repondreRecharge($id, $etab, $appareil);
    }

    /**
     * Totaux d'une recharge dans chaque devise acceptée : le montant dans la devise de la carte, l'équivalent ailleurs
     * au taux du jour, arrondi vers le haut au pas de la devise (on peut payer une recharge en dollars avec des francs).
     * @return array<string, float>
     */
    public static function totauxRecharge(float $montant, array $devise, array $acceptees): array
    {
        $totaux = [$devise['code'] => $montant];
        $taux = (float) ($devise['taux'] ?? 0);
        foreach ($acceptees as $c => $d) {
            if ($c !== $devise['code'] && $taux > 0 && (float) ($d['taux'] ?? 0) > 0) {
                $totaux[$c] = Reglement::versLeHaut($montant / $taux * (float) $d['taux'], $d);
            }
        }
        return $totaux;
    }

    private static function taux(array $devise): ?float
    {
        return isset($devise['taux']) && (float) $devise['taux'] > 0 ? (float) $devise['taux'] : null;
    }

    private function repondreRecharge(int $mouvementId, array $etab, ?array $appareil): never
    {
        $recharge = (new CashlessRepository())->recharge($mouvementId);
        $verrouille = false;
        if ($appareil !== null && $etab['verrou_apres_encaissement']) {
            CaissePartagee::verrouiller();
            $verrouille = true;
        }
        $this->repondre(200, [
            'numero' => $recharge['carte']['code'] ?? '',
            'recu' => View::rendre('caisse/_recu-recharge', ['recharge' => $recharge, 'etablissement' => $etab,
                'devises' => array_column((new \App\Repositories\GestionDeviseRepository())->toutes(), null, 'id')]),
            'carte' => $recharge !== null ? $this->carteJson($recharge['carte'], $etab, false) : null,
            'verrouille' => $verrouille,
            'csrf' => Session::jetonCsrf(),
        ]);
    }

    /** POST /caisse/nuit/carte/rembourser : carte, devise, montant (≤ solde), compte, cle. Sortie de trésorerie. */
    public function rembourser(): void
    {
        ['personne' => $personne, 'etab' => $etab, 'appareil' => $appareil] = $this->acces(true, ['cashless.gerer']);
        $this->exigerCashless($etab);
        $repo = new CashlessRepository();
        $carte = $repo->carte((int) ($_POST['carte'] ?? 0));
        ['acceptees' => $accepteesIds] = CaisseController::devisesCaisse($etab);
        $acceptees = array_column($accepteesIds, null, 'code');
        $code = is_string($_POST['devise'] ?? null) ? $_POST['devise'] : '';
        $montant = is_numeric($_POST['montant'] ?? null) ? round((float) $_POST['montant'], (int) ($acceptees[$code]['decimales'] ?? 2)) : 0.0;
        $comptes = array_column(CaisseController::comptesCaisse((int) $etab['id'], $accepteesIds), null, 'id');
        $compte = $comptes[(int) ($_POST['compte'] ?? 0)] ?? null;
        $cle = is_string($_POST['cle'] ?? null) && preg_match('/^[0-9a-f-]{36}$/', $_POST['cle']) ? $_POST['cle'] : null;
        if ($carte === null || !isset($acceptees[$code]) || $compte === null || !in_array($code, $compte['devises'], true) || $montant <= 0 || $cle === null) {
            $this->repondre(422, ['erreur' => t('nuit.carte_invalide'), 'code' => 'carte']);
        }
        $session = CaisseController::sessionExigee($etab, $personne);
        try {
            $repo->rembourser((int) $carte['id'], (int) $acceptees[$code]['id'], $montant, (new GestionTresorerieRepository())->compte((int) $compte['id']) ?? $compte,
                (int) $etab['id'], (int) $personne['id'], $appareil ? (int) $appareil['id'] : null, $session !== null ? (int) $session['id'] : null, $cle);
        } catch (\DomainException $e) {
            $this->repondre(409, ['erreur' => t($e->getMessage() === 'devise' ? 'encaisser.paiement_invalide' : 'nuit.carte_solde', ['carte' => $carte['code']]), 'code' => 'carte_solde']);
        }
        $this->repondre(200, ['carte' => $this->carteJson($repo->carte((int) $carte['id']), $etab, true), 'csrf' => Session::jetonCsrf()]);
    }

    /** POST /caisse/nuit/carte/bloquer : carte, bloquer (1 ou 0), motif. Une carte bloquée ne paie plus et ne se recharge plus. */
    public function bloquer(): void
    {
        ['personne' => $personne, 'etab' => $etab, 'appareil' => $appareil] = $this->acces(true, ['cashless.gerer']);
        $this->exigerCashless($etab);
        $repo = new CashlessRepository();
        $carte = $repo->carte((int) ($_POST['carte'] ?? 0));
        if ($carte === null) {
            $this->repondre(404, ['erreur' => t('nuit.carte_inconnue'), 'code' => 'carte_inconnue']);
        }
        $repo->bloquer((int) $carte['id'], ($_POST['bloquer'] ?? '1') === '1', CaisseNoteController::texteLibre($_POST['motif'] ?? null), (int) $personne['id']);
        $this->repondre(200, ['carte' => $this->carteJson($repo->carte((int) $carte['id']), $etab, true), 'csrf' => Session::jetonCsrf()]);
    }

    private function exigerCashless(array $etab): void
    {
        if (!CashlessRepository::disponible() || !Plateforme::moduleInclus('cashless') || !self::lieuDeNuit($etab)) {
            $this->repondre(403, ['erreur' => t('nuit.module_absent')]);
        }
    }

    // ---------- Entrée : contrôle et invités ----------

    /** POST /caisse/entree/controle : code scanné ou tapé. Réponse : ok, deja, invalide, annule, autre_soiree. */
    public function controle(): void
    {
        ['personne' => $personne, 'etab' => $etab, 'appareil' => $appareil] = $this->acces(true, ['entrees.controler']);
        $billetterie = new BilletterieRepository();
        $soiree = $billetterie->soiree($etab);
        $r = $billetterie->controler($etab, $_POST['code'] ?? '', $soiree['journee'], (int) $personne['id'], $appareil ? (int) $appareil['id'] : null);
        $b = $r['billet'];
        $this->repondre(200, [
            'resultat' => $r['resultat'],
            'billet' => $b !== null ? [
                'categorie' => t('nuit.categorie_' . $b['categorie']), 'tarif' => $b['tarif_fr'] !== null ? champ($b, 'tarif') : null,
                'utilise_le' => $b['utilise_le'] !== null ? date('H:i', strtotime($b['utilise_le'])) : null, 'utilise_par' => $b['utilise_par_nom'] ?? null,
                'journee' => date('d/m/Y', strtotime($b['journee'])),
            ] : null,
            'compteurs' => $billetterie->compteurs((int) $etab['id'], $soiree['journee']),
            'csrf' => Session::jetonCsrf(),
        ]);
    }

    /** GET /caisse/entree/compteurs */
    public function compteurs(): void
    {
        ['etab' => $etab] = $this->acces(false, ['entrees.vendre', 'entrees.controler', 'invites.pointer']);
        $billetterie = new BilletterieRepository();
        $this->repondre(200, ['compteurs' => $billetterie->compteurs((int) $etab['id'], $billetterie->soiree($etab)['journee'])]);
    }

    /** GET /caisse/entree/invites?q= : invités de la soirée en cours (recherche par nom ou téléphone). */
    public function invites(): void
    {
        ['etab' => $etab] = $this->acces(false, ['invites.pointer', 'caisse.vendre']);
        $soiree = (new BilletterieRepository())->soiree($etab);
        if ($soiree['evenement'] === null) {
            $this->repondre(200, ['invites' => [], 'soiree' => null]);
        }
        $q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 60) : '';
        $arrives = ($_GET['arrives'] ?? '') === '1';
        $liste = (new PromoteurRepository())->invites((int) $soiree['evenement']['id'], null, $q);
        if ($arrives) {
            $liste = array_values(array_filter($liste, static fn (array $i) => (int) $i['arrives'] > 0));
        }
        $acceptees = CaisseController::devisesCaisse($etab)['acceptees'];
        $this->repondre(200, ['soiree' => $soiree['evenement']['titre'], 'invites' => array_map(fn (array $i) => $this->inviteJson($i, $acceptees), array_slice($liste, 0, 60))]);
    }

    private function inviteJson(array $i, array $acceptees): array
    {
        return [
            'id' => (int) $i['id'], 'nom' => $i['nom'], 'telephone' => $i['telephone'] !== null ? afficherTelephone($i['telephone']) : null,
            'places' => 1 + (int) $i['accompagnants'], 'arrives' => (int) $i['arrives'], 'entree' => $i['entree'],
            'tarif' => $i['tarif_id'] !== null ? ['id' => (int) $i['tarif_id'], 'nom' => champ($i, 'tarif'), 'prix' => (new BilletterieRepository())->prix((int) $i['tarif_id'], $acceptees)] : null,
            'promoteur' => $i['promoteur_nom'], 'note' => $i['note'],
        ];
    }

    /**
     * POST /caisse/entree/invites/{id}/pointer : nombre de personnes qui entrent. Entrée gratuite : billets gratuits
     * déjà utilisés ; entrée réduite : la caisse ajoute le tarif réduit au ticket (à payer).
     */
    public function pointer(string $id): void
    {
        ['personne' => $personne, 'etab' => $etab, 'appareil' => $appareil] = $this->acces(true, ['invites.pointer']);
        $repo = new PromoteurRepository();
        $invite = ctype_digit($id) ? $repo->invite((int) $id) : null;
        $soiree = (new BilletterieRepository())->soiree($etab);
        if ($invite === null || (int) $invite['etablissement_id'] !== (int) $etab['id'] || $soiree['evenement'] === null || (int) $invite['evenement_id'] !== (int) $soiree['evenement']['id']) {
            $this->repondre(404, ['erreur' => t('nuit.invite_introuvable')]);
        }
        try {
            $r = $repo->pointer((int) $invite['id'], max(1, (int) ($_POST['nombre'] ?? 1)), $etab, (int) $personne['id']);
        } catch (\DomainException) {
            $this->repondre(409, ['erreur' => t('nuit.c_invite_complet'), 'code' => 'complet']);
        }
        Journal::ecrire('invite_pointe', $invite['nom'], ['nombre' => (int) ($_POST['nombre'] ?? 1), 'soiree' => (int) $invite['evenement_id']], (int) $personne['id'], $appareil ? (int) $appareil['id'] : null);
        $acceptees = CaisseController::devisesCaisse($etab)['acceptees'];
        $this->repondre(200, [
            'invite' => $this->inviteJson($this->inviteLigne((int) $invite['id']), $acceptees),
            'billets' => $r['billets'] !== [] ? View::rendre('caisse/_billets', ['etablissement' => $etab, 'billets' => (new BilletterieRepository())->parIds($r['billets']), 'depots' => [], 'seul' => true]) : null,
            'compteurs' => (new BilletterieRepository())->compteurs((int) $etab['id'], $soiree['journee']),
            'csrf' => Session::jetonCsrf(),
        ]);
    }

    private function inviteLigne(int $id): array
    {
        return Database::un('SELECT i.*, p.nom AS promoteur_nom, t.nom_fr AS tarif_fr, t.nom_en AS tarif_en FROM invites i
            LEFT JOIN promoteurs p ON p.id = i.promoteur_id LEFT JOIN entree_tarifs t ON t.id = i.tarif_id WHERE i.id = ?', [$id]);
    }

    /** POST /caisse/notes/{id}/invite : invité (pointé ce soir) lié à une note, pour la commission de son promoteur ; invite = 0 retire. */
    public function inviteNote(string $id): void
    {
        ['personne' => $personne, 'etab' => $etab, 'appareil' => $appareil] = $this->acces(true, ['caisse.vendre']);
        $note = ctype_digit($id) ? (new NoteRepository())->note((int) $id) : null;
        if ($note === null || (int) $note['etablissement_id'] !== (int) $etab['id'] || $note['statut'] !== 'ouverte') {
            $this->repondre(404, ['erreur' => t('note.introuvable')]);
        }
        $inviteId = (int) ($_POST['invite'] ?? 0);
        if ($inviteId > 0) {
            $invite = (new PromoteurRepository())->invite($inviteId);
            $soiree = (new BilletterieRepository())->soiree($etab);
            if ($invite === null || $soiree['evenement'] === null || (int) $invite['evenement_id'] !== (int) $soiree['evenement']['id']) {
                $this->repondre(422, ['erreur' => t('nuit.invite_introuvable')]);
            }
        }
        Database::executer('UPDATE notes SET invite_id = ?, version = version + 1 WHERE id = ?', [$inviteId > 0 ? $inviteId : null, (int) $note['id']]);
        NoteRepository::journal('note_invite', $note, ['invite' => $inviteId ?: null], (int) $personne['id'], $appareil ? (int) $appareil['id'] : null);
        $this->repondre(200, ['note' => (new CaisseNoteController())->noteJson($etab, (new NoteRepository())->note((int) $note['id'])), 'csrf' => Session::jetonCsrf()]);
    }

    // ---------- Vestiaire ----------

    /** POST /caisse/vestiaire/deposer : dépôt sans frais (description, objets, cle). */
    public function deposer(): void
    {
        ['personne' => $personne, 'etab' => $etab, 'appareil' => $appareil] = $this->acces(true, ['vestiaire.gerer']);
        $repo = new VestiaireRepository();
        if ($repo->frais((int) $etab['id']) !== []) {
            $this->repondre(409, ['erreur' => t('nuit.vestiaire_payant'), 'code' => 'payant']);
        }
        $cle = is_string($_POST['cle'] ?? null) && preg_match('/^[0-9a-f-]{36}$/', $_POST['cle']) ? $_POST['cle'] : null;
        $ticket = $repo->deposer($etab, CaisseNoteController::texteLibre($_POST['description'] ?? null), (int) ($_POST['objets'] ?? 1), null, (int) $personne['id'], $cle);
        Journal::ecrire('vestiaire_depot', (string) $ticket['numero'], [], (int) $personne['id'], $appareil ? (int) $appareil['id'] : null);
        $this->repondre(200, [
            'recu' => View::rendre('caisse/_billets', ['etablissement' => $etab, 'billets' => [], 'depots' => [$ticket], 'seul' => true]),
            'restants' => count($repo->deposes((int) $etab['id'])),
            'csrf' => Session::jetonCsrf(),
        ]);
    }

    /** POST /caisse/vestiaire/rendre : code scanné ou numéro du ticket (de la soirée). */
    public function rendre(): void
    {
        ['personne' => $personne, 'etab' => $etab] = $this->acces(true, ['vestiaire.gerer']);
        $repo = new VestiaireRepository();
        $ticket = $repo->trouver($etab, $_POST['code'] ?? '');
        if ($ticket === null) {
            $this->repondre(404, ['erreur' => t('nuit.c_vestiaire_inconnu'), 'code' => 'inconnu']);
        }
        if (!$repo->rendre((int) $ticket['id'], (int) $personne['id'])) {
            $t = Database::un('SELECT v.rendu_le, u.nom FROM vestiaire_tickets v LEFT JOIN utilisateurs u ON u.id = v.rendu_par WHERE v.id = ?', [(int) $ticket['id']]);
            $this->repondre(409, ['erreur' => t('nuit.vestiaire_deja_rendu', ['numero' => $ticket['numero'], 'heure' => date('H:i', strtotime((string) $t['rendu_le'])), 'par' => $t['nom'] ?? '']), 'code' => 'deja']);
        }
        $this->repondre(200, [
            'ticket' => ['numero' => (int) $ticket['numero'], 'description' => $ticket['description'], 'objets' => (int) $ticket['objets']],
            'restants' => count($repo->deposes((int) $etab['id'])),
            'csrf' => Session::jetonCsrf(),
        ]);
    }

    /** GET /caisse/vestiaire/liste : objets encore au vestiaire. */
    public function restants(): void
    {
        ['etab' => $etab] = $this->acces(false, ['vestiaire.gerer']);
        $this->repondre(200, ['tickets' => array_map(static fn (array $t) => [
            'numero' => (int) $t['numero'], 'description' => $t['description'], 'objets' => (int) $t['objets'],
            'depose' => date('d/m H:i', strtotime($t['depose_le'])), 'journee' => $t['journee'], 'par' => $t['depose_par_nom'],
        ], (new VestiaireRepository())->deposes((int) $etab['id']))]);
    }

    // ---------- Fidélité ----------

    /** GET /caisse/nuit/clients?q= : clients (nom ou téléphone) et leurs points. */
    public function clients(): void
    {
        ['etab' => $etab] = $this->acces(false, ['caisse.encaisser']);
        $repo = new FideliteRepository();
        if ($repo->reglages((int) $etab['id']) === null) {
            $this->repondre(403, ['erreur' => t('nuit.fidelite_invalide')]);
        }
        $q = is_string($_GET['q'] ?? null) ? mb_substr($_GET['q'], 0, 60) : '';
        $this->repondre(200, ['clients' => array_map(static fn (array $c) => [
            'id' => (int) $c['id'], 'nom' => $c['nom'], 'telephone' => $c['telephone'] !== null ? afficherTelephone($c['telephone']) : null,
            'points' => $repo->solde((int) $c['id']),
        ], (new DetteRepository())->rechercher($q))]);
    }

    /** POST /caisse/nuit/clients : nouveau client de la fidélité (nom, téléphone). */
    public function creerClient(): void
    {
        ['personne' => $personne, 'etab' => $etab] = $this->acces(true, ['caisse.encaisser']);
        if ((new FideliteRepository())->reglages((int) $etab['id']) === null) {
            $this->repondre(403, ['erreur' => t('nuit.fidelite_invalide')]);
        }
        try {
            $id = (new DetteRepository())->creerClient(['nom' => is_string($_POST['nom'] ?? null) ? $_POST['nom'] : '',
                'telephone' => is_string($_POST['telephone'] ?? null) ? $_POST['telephone'] : '', 'type' => 'habitue'], (int) $personne['id']);
        } catch (\InvalidArgumentException $e) {
            $this->repondre(422, ['erreur' => t('ardoise.client_' . $e->getMessage())]);
        }
        $c = (new DetteRepository())->client($id);
        $this->repondre(201, ['client' => ['id' => $id, 'nom' => $c['nom'], 'telephone' => $c['telephone'] !== null ? afficherTelephone($c['telephone']) : null, 'points' => 0],
            'csrf' => Session::jetonCsrf()]);
    }

    // ---------- Outils ----------

    /**
     * Personne qui vend ici (caisse partagée ou appareil personnel), avec au moins une des permissions.
     * @return array{personne: array, etab: array, appareil: ?array}
     */
    private function acces(bool $ecriture, array $permissions): array
    {
        Session::demarrer();
        if ($ecriture) {
            $jeton = $_POST['csrf'] ?? '';
            if (!is_string($jeton) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $jeton)) {
                $this->repondre(419, ['erreur' => t('encaisser.expire')]);
            }
        }
        if (!RemiseRepository::disponible()) {
            $this->repondre(403, ['erreur' => t('nuit.module_absent')]);
        }
        $slug = $ecriture ? ($_POST['etab'] ?? null) : ($_GET['etab'] ?? null);
        if ($ecriture && $slug === null && is_string($_POST['vente'] ?? null)) {
            // Fenêtre de paiement de la caisse (recharge) : l'établissement est dans la demande, comme pour une vente
            $demande = json_decode($_POST['vente'], true);
            $slug = is_array($demande) ? ($demande['etab'] ?? null) : null;
        }
        $vendeur = CaisseController::vendeur(is_string($slug) ? $slug : null);
        if ($vendeur === null) {
            $this->repondre(401, ['erreur' => t('encaisser.connexion')]);
        }
        foreach ($permissions as $permission) {
            if (Acces::a((int) $vendeur['personne']['id'], $permission, (int) $vendeur['etab']['id'])) {
                return $vendeur;
            }
        }
        $this->repondre(403, ['erreur' => t('acces.interdit_titre')]);
    }

    private function repondre(int $code, array $donnees): never
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($code);
        echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
