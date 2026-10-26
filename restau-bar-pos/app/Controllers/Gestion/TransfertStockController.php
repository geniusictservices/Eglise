<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Session;
use App\Core\View;
use App\Repositories\GestionStockRepository;
use App\Repositories\StockRepository;
use App\Repositories\TransfertStockRepository;

/**
 * Stock > Transferts : liste, nouveau transfert (brouillon ou sortie confirmée tout de suite), fiche avec
 * la confirmation de sortie (côté source) et la confirmation de réception avec la quantité reçue (côté
 * destination), annulation. Droit stock.transferer pour l'établissement du lieu concerné.
 */
final class TransfertStockController
{
    private const MAX_LIGNES = 40;

    private StockRepository $stock;
    private GestionStockRepository $depot;
    private TransfertStockRepository $transferts;

    public function __construct()
    {
        $this->stock = new StockRepository();
        $this->depot = new GestionStockRepository($this->stock);
        $this->transferts = new TransfertStockRepository($this->stock);
    }

    private static function etab(array $lieu, string $prefixe = ''): ?int
    {
        $cle = $prefixe . 'etablissement_id';
        return $lieu[$cle] !== null ? (int) $lieu[$cle] : null;
    }

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $lieux = array_column($this->stock->lieux(StockAcces::etablissements($uid, 'stock.voir'), false), null, 'id');
        $periode = TresorerieSaisie::periode('mois');
        $statut = in_array($_GET['statut'] ?? null, TransfertStockRepository::STATUTS, true) ? $_GET['statut'] : null;
        $liste = $this->transferts->liste(array_keys($lieux), $statut, $periode['debut'], $periode['fin']);
        foreach ($liste as &$t) {
            $t['a_recevoir'] = $t['statut'] === 'expedie' && StockAcces::peut($uid, 'stock.transferer', self::etab($t, 'destination_'));
            $t['a_expedier'] = $t['statut'] === 'brouillon' && StockAcces::peut($uid, 'stock.transferer', self::etab($t, 'source_'));
        }
        unset($t);
        View::afficher('gestion/stock/transferts', [
            'titre' => t('stock.transferts_titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'periode' => $periode,
            'statut' => $statut,
            'transferts' => $liste,
            'peutCreer' => StockAcces::filtrer(array_values($lieux), $uid, 'stock.transferer') !== [],
        ], 'gestion');
    }

    public function nouveau(): void
    {
        $utilisateur = Session::exigerConnexion();
        $sources = $this->sources($utilisateur);
        $lignes = [];
        foreach (is_array($_GET['lignes'] ?? null) ? array_slice($_GET['lignes'], 0, self::MAX_LIGNES) : [] as $l) {
            if (is_array($l)) {
                $lignes[] = ['conditionnement_id' => StockAcces::id($l['conditionnement_id'] ?? null), 'quantite' => TresorerieSaisie::texte($l['quantite'] ?? null)];
            }
        }
        $this->afficherFormulaire($utilisateur, $sources, [
            'source' => StockAcces::id($_GET['source'] ?? null), 'destination' => StockAcces::id($_GET['destination'] ?? null), 'notes' => '',
        ], $lignes ?: [['conditionnement_id' => null, 'quantite' => '']]);
    }

    public function creer(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $uid = (int) $utilisateur['id'];
        $sources = $this->sources($utilisateur);
        $valeurs = [
            'source' => StockAcces::id($_POST['source'] ?? null),
            'destination' => StockAcces::id($_POST['destination'] ?? null),
            'notes' => mb_substr(TresorerieSaisie::post('notes'), 0, 255),
        ];
        $saisies = [];
        foreach (is_array($_POST['lignes'] ?? null) ? array_slice($_POST['lignes'], 0, self::MAX_LIGNES) : [] as $l) {
            if (is_array($l)) {
                $saisies[] = ['conditionnement_id' => StockAcces::id($l['conditionnement_id'] ?? null), 'quantite' => TresorerieSaisie::texte($l['quantite'] ?? null)];
            }
        }
        if (($_POST['action'] ?? '') === 'ajouter_ligne') {
            $saisies[] = ['conditionnement_id' => null, 'quantite' => ''];
            $this->afficherFormulaire($utilisateur, $sources, $valeurs, $saisies);
            return;
        }
        $erreurs = [];
        $source = null;
        foreach ($sources as $s) {
            if ((int) $s['id'] === $valeurs['source']) {
                $source = $s;
            }
        }
        if ($source === null) {
            $erreurs['source'] = t('stock.erreur_source');
        }
        $destination = $valeurs['destination'] !== null ? $this->stock->lieu($valeurs['destination']) : null;
        if ($destination === null || !$destination['actif']) {
            $erreurs['destination'] = t('stock.erreur_destination');
        } elseif ($source !== null && (int) $destination['id'] === (int) $source['id']) {
            $erreurs['destination'] = t('stock.erreur_meme_lieu');
        }
        [$lignes, $erreursLignes] = $this->lireLignes($saisies);
        $erreurs += $erreursLignes;
        if ($lignes === [] && $erreursLignes === []) {
            $erreurs['lignes'] = t('stock.erreur_aucune_ligne');
        }
        if ($erreurs !== []) {
            $this->afficherFormulaire($utilisateur, $sources, $valeurs, $saisies ?: [['conditionnement_id' => null, 'quantite' => '']], $erreurs, [], 422);
            return;
        }
        $expedier = ($_POST['action'] ?? '') === 'expedier';
        // Plus que le stock de la source : prévenir sans bloquer (le stock affiché peut être faux)
        if ($expedier && empty($_POST['confirme'])) {
            $avertissements = [];
            $articles = $this->depot->articles();
            $besoins = [];
            foreach ($lignes as $l) {
                $besoins[$l['produit_id']] = ($besoins[$l['produit_id']] ?? 0) + $l['quantite_base'];
            }
            foreach ($besoins as $produitId => $besoin) {
                $dispo = $this->stock->quantite((int) $source['id'], $produitId);
                if ($besoin > $dispo + 0.0005 && isset($articles[$produitId])) {
                    $avertissements[] = t('stock.avertissement_source', [
                        'article' => champ($articles[$produitId], 'nom'), 'lieu' => $source['nom'],
                        'dispo' => StockAffichage::quantite($dispo, $articles[$produitId]), 'envoi' => StockAffichage::quantite($besoin, $articles[$produitId]),
                    ]);
                }
            }
            if ($avertissements !== []) {
                $this->afficherFormulaire($utilisateur, $sources, $valeurs, $saisies, [], $avertissements);
                return;
            }
        }
        $id = $this->transferts->creer((int) $source['id'], (int) $destination['id'], $lignes, $valeurs['notes'] !== '' ? $valeurs['notes'] : null, $uid, $expedier);
        Session::flash(t($expedier ? 'stock.transfert_expedie' : 'stock.transfert_brouillon', ['numero' => sprintf('TR-%06d', $id)]));
        rediriger('/gestion/stock/transferts/' . $id);
    }

    /**
     * Lignes saisies (format + quantité) ; les lignes vides sont ignorées.
     * @return array{0: list<array>, 1: array<string, string>}
     */
    private function lireLignes(array $saisies): array
    {
        $articles = $this->depot->articles();
        $lignes = [];
        $erreurs = [];
        foreach ($saisies as $i => $s) {
            if ($s['conditionnement_id'] === null && $s['quantite'] === '') {
                continue;
            }
            $trouve = null;
            foreach ($articles as $article) {
                if (($format = GestionStockRepository::format($article, $s['conditionnement_id'])) !== null) {
                    $trouve = [$article, $format];
                    break;
                }
            }
            if ($trouve === null) {
                $erreurs['lignes-' . $i . '-format'] = t('stock.ligne_n', ['n' => $i + 1]) . ' : ' . t('stock.erreur_article');
                continue;
            }
            [$quantite, $erreur] = StockAcces::quantite($s['quantite']);
            if ($erreur !== null) {
                $erreurs['lignes-' . $i . '-quantite'] = t('stock.ligne_n', ['n' => $i + 1]) . ' : ' . $erreur;
                continue;
            }
            $lignes[] = ['produit_id' => (int) $trouve[0]['id'], 'conditionnement_id' => (int) $trouve[1]['id'], 'quantite' => $quantite,
                         'quantite_base' => $quantite * (float) $trouve[1]['quantite_base']];
        }
        return [$lignes, $erreurs];
    }

    public function fiche(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $this->afficherFiche($utilisateur, $this->transfertOu404($id, $utilisateur));
    }

    public function expedier(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $t = $this->transfertOu404($id, $utilisateur);
        if (!StockAcces::peut((int) $utilisateur['id'], 'stock.transferer', self::etab($t, 'source_'))) {
            StockAcces::interdit($utilisateur);
        }
        if (!$this->transferts->expedier((int) $t['id'], (int) $utilisateur['id'])) {
            $this->afficherFiche($utilisateur, $t, [t('stock.erreur_statut_transfert')], 409);
            return;
        }
        Session::flash(t('stock.transfert_expedie', ['numero' => $t['numero']]));
        rediriger('/gestion/stock/transferts/' . (int) $t['id']);
    }

    public function recevoir(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $t = $this->transfertOu404($id, $utilisateur);
        if (!StockAcces::peut((int) $utilisateur['id'], 'stock.transferer', self::etab($t, 'destination_'))) {
            StockAcces::interdit($utilisateur);
        }
        if ($t['statut'] !== 'expedie') {
            $this->afficherFiche($utilisateur, $t, [t('stock.erreur_statut_transfert')], 409);
            return;
        }
        $recues = [];
        $erreurs = [];
        $saisies = is_array($_POST['recues'] ?? null) ? $_POST['recues'] : [];
        foreach ($t['lignes'] as $i => $l) {
            $texte = TresorerieSaisie::texte($saisies[(int) $l['id']] ?? null);
            if ($texte === '') {
                $recues[(int) $l['id']] = (float) $l['quantite_base'];
                continue;
            }
            [$q, $erreur] = StockAcces::quantite($texte, true);
            if ($erreur !== null) {
                $erreurs['recue-' . (int) $l['id']] = t('stock.ligne_n', ['n' => $i + 1]) . ' : ' . $erreur;
                continue;
            }
            $taille = $l['format_base'] !== null ? (float) $l['format_base'] : 1.0;
            $recues[(int) $l['id']] = $q * $taille;
        }
        $note = mb_substr(TresorerieSaisie::post('note_reception'), 0, 255);
        $ecart = false;
        foreach ($t['lignes'] as $l) {
            if (isset($recues[(int) $l['id']]) && abs($recues[(int) $l['id']] - (float) $l['quantite_base']) > 0.0005) {
                $ecart = true;
            }
        }
        if ($ecart && $note === '' && $erreurs === []) {
            $erreurs['note_reception'] = t('stock.erreur_note_ecart');
        }
        if ($erreurs !== []) {
            $this->afficherFiche($utilisateur, $t, $erreurs, 422, $saisies, $note);
            return;
        }
        $this->transferts->recevoir((int) $t['id'], $recues, $note !== '' ? $note : null, (int) $utilisateur['id']);
        Session::flash(t($ecart ? 'stock.transfert_recu_ecart' : 'stock.transfert_recu', ['numero' => $t['numero']]));
        rediriger('/gestion/stock/transferts/' . (int) $t['id']);
    }

    public function annuler(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $t = $this->transfertOu404($id, $utilisateur);
        if (!StockAcces::peut((int) $utilisateur['id'], 'stock.transferer', self::etab($t, 'source_'))) {
            StockAcces::interdit($utilisateur);
        }
        if (!$this->transferts->annuler((int) $t['id'], (int) $utilisateur['id'])) {
            $this->afficherFiche($utilisateur, $t, [t('stock.erreur_statut_transfert')], 409);
            return;
        }
        Session::flash(t('stock.transfert_annule', ['numero' => $t['numero']]));
        rediriger('/gestion/stock/transferts/' . (int) $t['id']);
    }

    private function sources(array $utilisateur): array
    {
        $uid = (int) $utilisateur['id'];
        $sources = StockAcces::filtrer($this->stock->lieux(StockAcces::etablissements($uid, 'stock.transferer')), $uid, 'stock.transferer');
        if ($sources === []) {
            StockAcces::interdit($utilisateur);
        }
        return $sources;
    }

    private function afficherFormulaire(array $utilisateur, array $sources, array $valeurs, array $lignes, array $erreurs = [], array $avertissements = [], int $code = 200): void
    {
        http_response_code($code);
        $articles = $this->depot->articles();
        View::afficher('gestion/stock/transfert-form', [
            'titre' => t('stock.nouveau_transfert'),
            'utilisateur' => $utilisateur,
            'sources' => $sources,
            'destinations' => $this->stock->lieux(),
            'articles' => $articles,
            'niveaux' => $this->depot->niveaux(array_map('intval', array_column($sources, 'id'))),
            'valeurs' => $valeurs,
            'lignes' => $lignes,
            'erreurs' => $erreurs,
            'avertissements' => $avertissements,
            'max' => self::MAX_LIGNES,
        ], 'gestion');
    }

    private function afficherFiche(array $utilisateur, array $t, array $erreurs = [], int $code = 200, array $saisies = [], string $note = ''): void
    {
        http_response_code($code);
        $uid = (int) $utilisateur['id'];
        View::afficher('gestion/stock/transfert', [
            'titre' => $t['numero'],
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            't' => $t,
            'articles' => $this->depot->articles(true),
            'peutSource' => StockAcces::peut($uid, 'stock.transferer', self::etab($t, 'source_')),
            'peutDestination' => StockAcces::peut($uid, 'stock.transferer', self::etab($t, 'destination_')),
            'erreurs' => $erreurs,
            'saisies' => $saisies,
            'note' => $note,
        ], 'gestion');
    }

    private function transfertOu404(string $id, array $utilisateur): array
    {
        $t = ($i = StockAcces::id($id)) !== null ? $this->transferts->transfert($i) : null;
        $uid = (int) $utilisateur['id'];
        if ($t === null || (!StockAcces::voit($uid, 'stock.voir', self::etab($t, 'source_')) && !StockAcces::voit($uid, 'stock.voir', self::etab($t, 'destination_')))) {
            pageIntrouvable();
        }
        return $t;
    }
}
