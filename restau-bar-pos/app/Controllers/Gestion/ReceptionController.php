<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Images;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AchatRepository;
use App\Repositories\EmballageRepository;
use App\Repositories\GestionStockRepository;
use App\Repositories\GestionTresorerieRepository;
use App\Repositories\StockRepository;

/**
 * Achats > Réceptions : livraisons (d'une commande ou libres) et achats au marché sans facture (photo du
 * ticket), dans un dépôt ou un comptoir, dans les formats d'achat ; écarts avec la commande ; emballages
 * rendus et consigne payée ; paiement tout de suite ou plus tard, en une ou plusieurs lignes (compte de
 * trésorerie + devise, comme une dépense ; découvert signalé sans bloquer). Recevoir : achats.recevoir ;
 * payer : achats.payer ; pour l'établissement du lieu.
 */
final class ReceptionController
{
    private const MAX_LIGNES = 40;
    private const MAX_PAIEMENTS = 6;

    private StockRepository $stock;
    private AchatRepository $achats;
    private GestionStockRepository $depot;
    private GestionTresorerieRepository $tresorerie;

    public function __construct()
    {
        $this->stock = new StockRepository();
        $this->tresorerie = new GestionTresorerieRepository();
        $this->achats = new AchatRepository($this->stock, $this->tresorerie);
        $this->depot = new GestionStockRepository($this->stock);
    }

    // ---------- Liste ----------

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $periode = TresorerieSaisie::periode('mois');
        $lieux = array_column($this->stock->lieux(StockAcces::etablissements($uid, 'achats.voir'), false), null, 'id');
        $lieu = StockAcces::id($_GET['lieu'] ?? null);
        $filtres = [
            'debut' => $periode['debut'], 'fin' => $periode['fin'],
            'fournisseur' => StockAcces::id($_GET['fournisseur'] ?? null),
            'lieu' => $lieu !== null && isset($lieux[$lieu]) ? $lieu : null,
            'paiement' => in_array($_GET['paiement'] ?? null, ['non_payee', 'partielle', 'payee'], true) ? $_GET['paiement'] : null,
        ];
        $receptions = $this->achats->receptions($filtres, StockAcces::etablissements($uid, 'achats.voir'));
        $totaux = [];
        foreach ($receptions as $r) {
            $d = (int) $r['devise_id'];
            $totaux[$d] ??= ['total' => 0.0, 'paye' => 0.0, 'reste' => 0.0];
            $totaux[$d]['total'] += (float) $r['total'];
            $totaux[$d]['paye'] += min((float) $r['paye'], (float) $r['total']);
            $totaux[$d]['reste'] += $r['reste'];
        }
        $ordre = array_flip(array_keys(VenteController::devises()));
        uksort($totaux, static fn ($a, $b) => ($ordre[$a] ?? 999) <=> ($ordre[$b] ?? 999));
        View::afficher('gestion/achats/receptions', [
            'titre' => t('achats.receptions_titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'periode' => $periode,
            'filtres' => $filtres,
            'receptions' => $receptions,
            'totaux' => $totaux,
            'lieux' => $lieux,
            'fournisseurs' => $this->achats->fournisseurs(),
            'devises' => VenteController::devises(),
            'peutRecevoir' => $this->lieuxReception($uid) !== [],
        ], 'gestion');
    }

    private function lieuxReception(int $uid): array
    {
        return StockAcces::filtrer($this->stock->lieux(StockAcces::etablissements($uid, 'achats.recevoir')), $uid, 'achats.recevoir');
    }

    // ---------- Nouvelle réception ----------

    public function nouvelle(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $lieux = $this->lieuxReception($uid);
        if ($lieux === []) {
            StockAcces::interdit($utilisateur);
        }
        $devises = VenteController::devises();
        $reference = $this->tresorerie->reference();
        $commande = null;
        $lignes = [];
        if (($cid = StockAcces::id($_GET['commande'] ?? null)) !== null) {
            $commande = $this->achats->commande($cid);
            if ($commande === null || !in_array($commande['statut'], ['brouillon', 'envoyee', 'partielle'], true)
                || !StockAcces::peut($uid, 'achats.recevoir', $commande['etablissement_id'] !== null ? (int) $commande['etablissement_id'] : null)) {
                $commande = null;
            }
        }
        if ($commande !== null) {
            foreach ($commande['lignes'] as $l) {
                $reste = max(0.0, (float) $l['quantite'] - (float) $l['quantite_recue']);
                if ($reste <= 0.0005) {
                    continue;
                }
                $lignes[] = [
                    'conditionnement_id' => $l['conditionnement_id'] !== null ? (int) $l['conditionnement_id'] : null,
                    'quantite' => StockAffichage::saisie($reste), 'prix' => $l['prix_unitaire'] !== null ? TresorerieAffichage::saisie($l['prix_unitaire']) : '',
                    'commande_ligne_id' => (int) $l['id'], 'commandee' => StockAffichage::saisie($reste),
                ];
            }
        }
        $fournisseur = $commande ? $this->achats->fournisseur((int) $commande['fournisseur_id'])
            : (($fid = StockAcces::id($_GET['fournisseur'] ?? null)) !== null ? $this->achats->fournisseur($fid) : null);
        $devise = $commande ? (int) $commande['devise_id'] : ($fournisseur && $fournisseur['devise_id'] !== null ? (int) $fournisseur['devise_id'] : (int) ($reference['id'] ?? 0));
        $this->afficherFormulaire($utilisateur, $lieux, [
            'fournisseur_id' => $fournisseur ? (int) $fournisseur['id'] : null,
            'commande_id' => $commande ? (int) $commande['id'] : null,
            'lieu_id' => $commande ? (int) $commande['lieu_id'] : StockAcces::id($_GET['lieu'] ?? null),
            'devise_id' => $devise, 'date' => date('Y-m-d\TH:i'), 'facture_numero' => '', 'sans_facture' => false,
            'echeance' => '', 'notes' => '', 'payer' => false,
        ], $lignes ?: [['conditionnement_id' => null, 'quantite' => '', 'prix' => '']], [], [['compte_id' => null, 'devise_id' => null, 'montant' => '']], $commande);
    }

    public function creer(): void
    {
        $utilisateur = Session::exigerConnexion();
        if (Images::requeteTropLourde()) {
            Session::flash(t('achats.erreur_photo_lourde'));
            rediriger('/gestion/achats/receptions/nouvelle');
        }
        Session::verifierCsrf();
        $uid = (int) $utilisateur['id'];
        $lieux = $this->lieuxReception($uid);
        if ($lieux === []) {
            StockAcces::interdit($utilisateur);
        }
        $devises = VenteController::devises();
        $valeurs = [
            'fournisseur_id' => StockAcces::id($_POST['fournisseur_id'] ?? null),
            'commande_id' => StockAcces::id($_POST['commande_id'] ?? null),
            'lieu_id' => StockAcces::id($_POST['lieu_id'] ?? null),
            'devise_id' => StockAcces::id($_POST['devise_id'] ?? null),
            'date' => TresorerieSaisie::post('date'),
            'facture_numero' => mb_substr(TresorerieSaisie::post('facture_numero'), 0, 64),
            'sans_facture' => !empty($_POST['sans_facture']),
            'echeance' => TresorerieSaisie::post('echeance'),
            'notes' => mb_substr(TresorerieSaisie::post('notes', true), 0, 2000),
            'payer' => !empty($_POST['payer']),
        ];
        $saisies = self::lireSaisies(self::MAX_LIGNES);
        $emballagesSaisis = is_array($_POST['emballages'] ?? null) ? $_POST['emballages'] : [];
        $paiementsSaisis = $this->saisiesPaiements();
        $commande = $valeurs['commande_id'] !== null ? $this->achats->commande($valeurs['commande_id']) : null;
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'ajouter_ligne' || $action === 'ajouter_paiement') {
            if ($action === 'ajouter_ligne') {
                $saisies[] = ['conditionnement_id' => null, 'quantite' => '', 'prix' => ''];
            } elseif (count($paiementsSaisis) < self::MAX_PAIEMENTS) {
                $paiementsSaisis[] = ['compte_id' => null, 'devise_id' => null, 'montant' => ''];
            }
            $this->afficherFormulaire($utilisateur, $lieux, $valeurs, $saisies, $emballagesSaisis, $paiementsSaisis, $commande);
            return;
        }

        $erreurs = [];
        $fournisseur = $valeurs['fournisseur_id'] !== null ? $this->achats->fournisseur($valeurs['fournisseur_id']) : null;
        if ($fournisseur === null || !$fournisseur['actif']) {
            $erreurs['fournisseur_id'] = t('achats.erreur_fournisseur');
        }
        $lieu = null;
        foreach ($lieux as $l) {
            if ((int) $l['id'] === $valeurs['lieu_id']) {
                $lieu = $l;
            }
        }
        if ($lieu === null) {
            $erreurs['lieu_id'] = t('stock.erreur_lieu');
        }
        if ($commande !== null && ((int) $commande['fournisseur_id'] !== $valeurs['fournisseur_id'] || !in_array($commande['statut'], ['brouillon', 'envoyee', 'partielle'], true))) {
            $erreurs['commande_id'] = t('achats.erreur_commande');
        }
        $devise = $valeurs['devise_id'] !== null ? ($devises[$valeurs['devise_id']] ?? null) : null;
        if ($devise === null || !$devise['active']) {
            $erreurs['devise_id'] = t('emballages.erreur_devise');
            $devise = null;
        }
        [$date, $erreurDate] = TresorerieSaisie::dateOperation($valeurs['date']);
        if ($erreurDate !== null) {
            $erreurs['date'] = $erreurDate;
        }
        $echeance = null;
        if ($valeurs['echeance'] !== '') {
            $e = \DateTimeImmutable::createFromFormat('!Y-m-d', $valeurs['echeance']);
            if ($e === false) {
                $erreurs['echeance'] = t('tresorerie.erreur_date');
            } else {
                $echeance = $e->format('Y-m-d');
            }
        } elseif ($fournisseur !== null && $date !== null && (int) $fournisseur['delai_paiement'] > 0) {
            $echeance = (new \DateTimeImmutable($date))->modify('+' . (int) $fournisseur['delai_paiement'] . ' days')->format('Y-m-d');
        }
        if (!$valeurs['sans_facture'] && $valeurs['facture_numero'] === '' && empty($_FILES['justificatif']['name'])) {
            $erreurs['facture_numero'] = t('achats.erreur_facture');
        }
        [$lignes, $erreursLignes] = self::lireLignes($this->depot->articles(), $saisies, $devise, true);
        $erreurs += $erreursLignes;
        if ($lignes === [] && $erreursLignes === []) {
            $erreurs['lignes'] = t('stock.erreur_aucune_ligne');
        }
        if ($commande !== null) {
            $ids = array_map('intval', array_column($commande['lignes'], 'id'));
            foreach ($lignes as &$l) {
                if ($l['commande_ligne_id'] !== null && !in_array($l['commande_ligne_id'], $ids, true)) {
                    $l['commande_ligne_id'] = null;
                    $l['quantite_commandee'] = null;
                }
            }
            unset($l);
        }
        // Emballages : vides rendus et consigne payée
        $catalogue = (new EmballageRepository($this->stock))->emballages();
        $emballages = [];
        foreach ($catalogue as $id => $emb) {
            $saisie = is_array($emballagesSaisis[$id] ?? null) ? $emballagesSaisis[$id] : [];
            $rendus = $payes = 0.0;
            foreach (['rendus', 'payes'] as $cle) {
                $texte = TresorerieSaisie::texte($saisie[$cle] ?? null);
                if ($texte === '') {
                    continue;
                }
                [$q, $erreur] = StockAcces::quantite($texte, true);
                if ($erreur !== null) {
                    $erreurs['emballages-' . $id . '-' . $cle] = champ($emb, 'nom') . ' : ' . $erreur;
                } elseif ($cle === 'rendus') {
                    $rendus = $q;
                } else {
                    $payes = $q;
                }
            }
            if ($rendus > 0 || $payes > 0) {
                $emballages[] = ['produit_id' => $id, 'rendus' => $rendus, 'payes' => $payes];
            }
        }
        // Total de la facture (lignes + consigne payée), pour contrôler les paiements
        $total = 0.0;
        foreach ($lignes as $l) {
            $total += $l['quantite'] * $l['prix'];
        }
        if ($devise !== null && $date !== null) {
            foreach ($emballages as $e) {
                $total += $e['payes'] * $this->achats->convertir((float) $catalogue[$e['produit_id']]['valeur_consigne'], (int) $catalogue[$e['produit_id']]['devise_id'], $devise, $date);
            }
        }
        $paiements = [];
        $avertissements = [];
        if ($valeurs['payer'] && $devise !== null && $date !== null && $lieu !== null) {
            if (!StockAcces::peut($uid, 'achats.payer', $lieu['etablissement_id'] !== null ? (int) $lieu['etablissement_id'] : null)) {
                $erreurs['paiements'] = t('achats.erreur_droit_payer');
            } else {
                [$paiements, $erreursPaiements, $avertissements] = $this->lirePaiements($paiementsSaisis, $this->comptesPour($lieu['etablissement_id']), $devise, $date, $total);
                $erreurs += $erreursPaiements;
            }
        }
        $justificatif = null;
        if ($erreurs === [] && !empty($_FILES['justificatif']['name'])) {
            try {
                $justificatif = Images::enregistrer($_FILES['justificatif'], 'achats', 1600, 'rec');
            } catch (\RuntimeException $e) {
                $erreurs['justificatif'] = $e->getMessage();
            }
        }
        if ($erreurs !== []) {
            $this->afficherFormulaire($utilisateur, $lieux, $valeurs, $saisies ?: [['conditionnement_id' => null, 'quantite' => '', 'prix' => '']], $emballagesSaisis, $paiementsSaisis, $commande, $erreurs, [], 422);
            return;
        }
        if ($avertissements !== [] && empty($_POST['confirme'])) {
            $this->afficherFormulaire($utilisateur, $lieux, $valeurs, $saisies, $emballagesSaisis, $paiementsSaisis, $commande, [], $avertissements);
            return;
        }
        $id = $this->achats->creerReception([
            'fournisseur_id' => (int) $fournisseur['id'], 'commande_id' => $commande ? (int) $commande['id'] : null,
            'lieu_id' => (int) $lieu['id'], 'etablissement_id' => $lieu['etablissement_id'] !== null ? (int) $lieu['etablissement_id'] : null,
            'devise' => $devise, 'date' => $date, 'facture_numero' => $valeurs['facture_numero'] !== '' ? $valeurs['facture_numero'] : null,
            'sans_facture' => $valeurs['sans_facture'], 'justificatif' => $justificatif, 'echeance' => $echeance,
            'notes' => $valeurs['notes'] !== '' ? $valeurs['notes'] : null,
        ], array_map(static fn (array $l): array => $l + ['prix_unitaire' => $l['prix']], $lignes), $emballages, $paiements, $uid);
        Session::flash(t('achats.reception_enregistree', ['numero' => sprintf('REC-%06d', $id)]));
        rediriger('/gestion/achats/receptions/' . $id);
    }

    // ---------- Fiche et paiements ----------

    public function fiche(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $r = $this->receptionOu404($id, $utilisateur);
        $this->afficherFiche($utilisateur, $r, [['compte_id' => null, 'devise_id' => null, 'montant' => '']]);
    }

    public function payer(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $r = $this->receptionOu404($id, $utilisateur);
        $uid = (int) $utilisateur['id'];
        if (!StockAcces::peut($uid, 'achats.payer', $r['etablissement_id'] !== null ? (int) $r['etablissement_id'] : null)) {
            StockAcces::interdit($utilisateur);
        }
        $saisies = $this->saisiesPaiements();
        if (($_POST['action'] ?? '') === 'ajouter_paiement') {
            if (count($saisies) < self::MAX_PAIEMENTS) {
                $saisies[] = ['compte_id' => null, 'devise_id' => null, 'montant' => ''];
            }
            $this->afficherFiche($utilisateur, $r, $saisies);
            return;
        }
        $devise = VenteController::devises()[(int) $r['devise_id']];
        [$date, $erreurDate] = TresorerieSaisie::dateOperation(TresorerieSaisie::post('date'));
        $erreurs = [];
        if ($erreurDate !== null) {
            $erreurs['date'] = $erreurDate;
            $date = date('Y-m-d H:i:s');
        }
        if ($r['reste'] <= 0.0005) {
            $erreurs['paiements'] = t('achats.erreur_deja_payee');
        }
        [$paiements, $erreursPaiements, $avertissements] = $this->lirePaiements($saisies, $this->comptesPour($r['etablissement_id']), $devise, $date, (float) $r['reste']);
        $erreurs += $erreursPaiements;
        if ($paiements === [] && $erreurs === []) {
            $erreurs['paiements'] = t('depenses.erreur_aucun_paiement');
        }
        if ($erreurs !== []) {
            $this->afficherFiche($utilisateur, $r, $saisies, $erreurs, [], 422);
            return;
        }
        if ($avertissements !== [] && empty($_POST['confirme'])) {
            $this->afficherFiche($utilisateur, $r, $saisies, [], $avertissements);
            return;
        }
        $this->achats->payer((int) $r['id'], $paiements, $date, mb_substr(TresorerieSaisie::post('notes'), 0, 255) ?: null, $uid);
        Session::flash(t('achats.paiement_enregistre', ['montant' => implode(' + ', array_map(static fn (array $p): string => TresorerieAffichage::montant($p['montant'], $p['devise']), $paiements))]));
        rediriger('/gestion/achats/receptions/' . (int) $r['id']);
    }

    public function supprimerPaiement(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $paiement = ($i = StockAcces::id($id)) !== null ? $this->achats->paiement($i) : null;
        if ($paiement === null) {
            pageIntrouvable();
        }
        $r = $this->receptionOu404((string) $paiement['reception_id'], $utilisateur);
        if (!StockAcces::peut((int) $utilisateur['id'], 'achats.payer', $r['etablissement_id'] !== null ? (int) $r['etablissement_id'] : null)) {
            StockAcces::interdit($utilisateur);
        }
        $this->achats->supprimerPaiement((int) $paiement['id'], (int) $utilisateur['id']);
        Session::flash(t('achats.paiement_supprime'));
        rediriger('/gestion/achats/receptions/' . (int) $r['id']);
    }

    // ---------- Lecture des saisies (partagée avec les commandes) ----------

    /** @return list<array{conditionnement_id: ?int, quantite: string, prix: string, commande_ligne_id: ?int, commandee: string}> */
    public static function lireSaisies(int $max): array
    {
        $saisies = [];
        foreach (is_array($_POST['lignes'] ?? null) ? array_slice($_POST['lignes'], 0, $max) : [] as $l) {
            if (is_array($l)) {
                $saisies[] = [
                    'conditionnement_id' => StockAcces::id($l['conditionnement_id'] ?? null),
                    'quantite' => TresorerieSaisie::texte($l['quantite'] ?? null),
                    'prix' => TresorerieSaisie::texte($l['prix'] ?? null),
                    'commande_ligne_id' => StockAcces::id($l['commande_ligne_id'] ?? null),
                    'commandee' => TresorerieSaisie::texte($l['commandee'] ?? null),
                ];
            }
        }
        return $saisies;
    }

    /**
     * @return array{0: list<array{produit_id: int, conditionnement_id: int, quantite: float, quantite_base: float, prix: ?float,
     *                             commande_ligne_id: ?int, quantite_commandee: ?float}>, 1: array<string, string>}
     */
    public static function lireLignes(array $articles, array $saisies, ?array $devise, bool $prixObligatoire): array
    {
        $lignes = [];
        $erreurs = [];
        foreach ($saisies as $i => $s) {
            if ($s['conditionnement_id'] === null && $s['quantite'] === '' && $s['prix'] === '') {
                continue;
            }
            $n = ['n' => $i + 1];
            $trouve = null;
            foreach ($articles as $a) {
                if (($f = GestionStockRepository::format($a, $s['conditionnement_id'])) !== null) {
                    $trouve = [$a, $f];
                    break;
                }
            }
            if ($trouve === null) {
                $erreurs['lignes-' . $i . '-format'] = t('stock.ligne_n', $n) . ' : ' . t('stock.erreur_article');
                continue;
            }
            $avecCommande = ($s['commande_ligne_id'] ?? null) !== null;
            [$quantite, $erreur] = StockAcces::quantite($s['quantite'], $avecCommande);
            if ($erreur !== null) {
                $erreurs['lignes-' . $i . '-quantite'] = t('stock.ligne_n', $n) . ' : ' . $erreur;
                continue;
            }
            $prix = null;
            if ($s['prix'] !== '' || $prixObligatoire) {
                if ($devise === null) {
                    continue;
                }
                [$prix, $erreur] = TresorerieSaisie::montant($s['prix'], $devise, true);
                if ($erreur !== null) {
                    $erreurs['lignes-' . $i . '-prix'] = t('stock.ligne_n', $n) . ' : ' . $erreur;
                    continue;
                }
            }
            $commandee = null;
            if ($avecCommande && $s['commandee'] !== '' && ($c = DeviseAffichage::lireNombre($s['commandee'])) !== null) {
                $commandee = (float) $c;
            }
            $lignes[] = [
                'produit_id' => (int) $trouve[0]['id'], 'conditionnement_id' => (int) $trouve[1]['id'], 'quantite' => $quantite,
                'quantite_base' => $quantite * (float) $trouve[1]['quantite_base'], 'prix' => $prix,
                'commande_ligne_id' => $avecCommande ? (int) $s['commande_ligne_id'] : null, 'quantite_commandee' => $commandee,
            ];
        }
        return [$lignes, $erreurs];
    }

    private function saisiesPaiements(): array
    {
        $saisies = [];
        foreach (is_array($_POST['paiements'] ?? null) ? array_slice($_POST['paiements'], 0, self::MAX_PAIEMENTS) : [] as $l) {
            if (is_array($l)) {
                $saisies[] = ['compte_id' => StockAcces::id($l['compte_id'] ?? null), 'devise_id' => StockAcces::id($l['devise_id'] ?? null), 'montant' => TresorerieSaisie::texte($l['montant'] ?? null)];
            }
        }
        return $saisies ?: [['compte_id' => null, 'devise_id' => null, 'montant' => '']];
    }

    /** Comptes actifs utilisables pour un établissement : les siens et les comptes communs (tous pour un dépôt central). */
    private function comptesPour(mixed $etablissementId): array
    {
        return array_filter($this->tresorerie->comptesActifs(), static fn (array $c): bool =>
            $etablissementId === null || $c['etablissement_id'] === null || (int) $c['etablissement_id'] === (int) $etablissementId);
    }

    /**
     * Lignes de paiement : compte (parmi $comptes), devise tenue par le compte, montant. contre_valeur = part de
     * la facture couverte, dans sa devise (au taux du jour du paiement). Refus au-delà de $plafond ; découvert
     * signalé (avertissements).
     * @return array{0: list<array>, 1: array<string, string>, 2: list<string>}
     */
    private function lirePaiements(array $saisies, array $comptes, array $devise, string $date, float $plafond): array
    {
        $devises = $this->tresorerie->devises();
        $paiements = [];
        $erreurs = [];
        $vus = [];
        $couvert = 0.0;
        foreach ($saisies as $i => $s) {
            if ($s['compte_id'] === null && $s['montant'] === '') {
                continue;
            }
            $n = ['n' => $i + 1];
            $compte = $s['compte_id'] !== null ? ($comptes[$s['compte_id']] ?? null) : null;
            if ($compte === null) {
                $erreurs['paiements-' . $i . '-compte'] = t('depenses.erreur_compte', $n);
                continue;
            }
            $deviseId = $s['devise_id'];
            if ($deviseId === null) {
                $tenues = array_keys(array_filter($compte['devises'], static fn (array $d): bool => $d['tenue']));
                $deviseId = count($tenues) === 1 ? $tenues[0] : null;
            }
            if ($deviseId === null || !GestionTresorerieRepository::tient($compte, $deviseId) || !isset($devises[$deviseId])) {
                $erreurs['paiements-' . $i . '-devise'] = t('depenses.paiement_n', $n) . ' : ' . t('tresorerie.erreur_devise_compte', [
                    'compte' => $compte['nom'],
                    'codes' => implode(', ', array_map(static fn ($id): string => $devises[$id]['code'] ?? '?', array_keys(array_filter($compte['devises'], static fn (array $d): bool => $d['tenue'])))),
                ]);
                continue;
            }
            $cle = $compte['id'] . '-' . $deviseId;
            if (isset($vus[$cle])) {
                $erreurs['paiements-' . $i . '-compte'] = t('depenses.erreur_compte_double', $n + ['compte' => $compte['nom'], 'code' => $devises[$deviseId]['code']]);
                continue;
            }
            [$montant, $erreur] = TresorerieSaisie::montant($s['montant'], $devises[$deviseId]);
            if ($erreur !== null) {
                $erreurs['paiements-' . $i . '-montant'] = t('depenses.paiement_n', $n) . ' : ' . $erreur;
                continue;
            }
            $vus[$cle] = true;
            $contreValeur = round($this->achats->convertir($montant, $deviseId, $devise, $date), 3);
            $couvert += $contreValeur;
            $paiements[] = ['compte' => $compte, 'devise' => $devises[$deviseId], 'montant' => $montant, 'contre_valeur' => $contreValeur];
        }
        // Tolérance : un demi-pas d'arrondi de la devise de la facture, plus celui de chaque devise payée
        // (un paiement en francs d'une facture en dollars se fait au 100 FC près)
        $pas = static fn (array $d): float => max((float) $d['arrondi'], 10 ** -(int) $d['decimales']) / 2;
        $tolerance = $pas($devise);
        foreach ($paiements as $p) {
            if ((int) $p['devise']['id'] !== (int) $devise['id']) {
                $tolerance += $this->achats->convertir($pas($p['devise']), (int) $p['devise']['id'], $devise, $date);
            }
        }
        if ($paiements !== [] && $couvert > $plafond + $tolerance) {
            $erreurs['paiements'] = t('achats.erreur_trop_paye', [
                'paye' => TresorerieAffichage::montant($couvert, $devise), 'reste' => TresorerieAffichage::montant($plafond, $devise),
            ]);
        }
        foreach ($paiements as &$p) {
            if ($couvert > $plafond && $couvert > 0) {
                // Paiement juste au-dessus à cause des arrondis : la part couverte ne dépasse jamais le reste
                $p['contre_valeur'] = round($p['contre_valeur'] * $plafond / $couvert, 3);
            }
        }
        unset($p);
        $avertissements = [];
        foreach ($paiements as $p) {
            $solde = GestionTresorerieRepository::solde($p['compte'], (int) $p['devise']['id']);
            if ($solde - $p['montant'] < -0.0005) {
                $avertissements[] = t('tresorerie.avertissement_decouvert', [
                    'compte' => $p['compte']['nom'], 'code' => $p['devise']['code'],
                    'solde' => TresorerieAffichage::montant($solde, $p['devise']), 'apres' => TresorerieAffichage::montant($solde - $p['montant'], $p['devise']),
                ]);
            }
        }
        return [$paiements, $erreurs, $avertissements];
    }

    // ---------- Affichage ----------

    private function afficherFormulaire(array $utilisateur, array $lieux, array $valeurs, array $lignes, array $emballages, array $paiements,
                                        ?array $commande, array $erreurs = [], array $avertissements = [], int $code = 200): void
    {
        http_response_code($code);
        $devises = VenteController::devises();
        $devise = $devises[(int) $valeurs['devise_id']] ?? null;
        $etab = null;
        foreach ($lieux as $l) {
            if ((int) $l['id'] === (int) $valeurs['lieu_id']) {
                $etab = $l['etablissement_id'];
            }
        }
        View::afficher('gestion/achats/reception-form', [
            'titre' => t('achats.nouvelle_reception'),
            'utilisateur' => $utilisateur,
            'lieux' => $lieux,
            'fournisseurs' => $this->achats->fournisseurs(true),
            'devises' => array_filter($devises, static fn (array $d): bool => (bool) $d['active']),
            'devise' => $devise,
            'articles' => $this->depot->articles(),
            'emballages' => (new EmballageRepository($this->stock))->emballages(),
            'comptes' => $this->comptesPour($etab),
            'devisesTresorerie' => $this->tresorerie->devises(),
            'peutPayer' => Acces::a((int) $utilisateur['id'], 'achats.payer'),
            'commande' => $commande,
            'valeurs' => $valeurs,
            'lignes' => $lignes,
            'saisiesEmballages' => $emballages,
            'paiements' => $paiements,
            'erreurs' => $erreurs,
            'avertissements' => $avertissements,
            'max' => self::MAX_LIGNES,
            'maxPaiements' => self::MAX_PAIEMENTS,
            'octetsMax' => Images::octetsMax(),
        ], 'gestion');
    }

    private function afficherFiche(array $utilisateur, array $r, array $paiements, array $erreurs = [], array $avertissements = [], int $code = 200): void
    {
        http_response_code($code);
        $uid = (int) $utilisateur['id'];
        $etab = $r['etablissement_id'] !== null ? (int) $r['etablissement_id'] : null;
        View::afficher('gestion/achats/reception', [
            'titre' => $r['numero'],
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'r' => $r,
            'articles' => $this->depot->articles(true),
            'devises' => VenteController::devises(),
            'comptes' => $this->comptesPour($r['etablissement_id']),
            'devisesTresorerie' => $this->tresorerie->devises(),
            'peutPayer' => StockAcces::peut($uid, 'achats.payer', $etab),
            'voirComptes' => Acces::a($uid, 'finance.voir'),
            'paiements' => $paiements,
            'erreurs' => $erreurs,
            'avertissements' => $avertissements,
            'maxPaiements' => self::MAX_PAIEMENTS,
        ], 'gestion');
    }

    private function receptionOu404(string $id, array $utilisateur): array
    {
        $r = ($i = StockAcces::id($id)) !== null ? $this->achats->reception($i) : null;
        if ($r === null || !StockAcces::voit((int) $utilisateur['id'], 'achats.voir', $r['etablissement_id'] !== null ? (int) $r['etablissement_id'] : null)) {
            pageIntrouvable();
        }
        return $r;
    }
}
