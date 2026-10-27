<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Session;
use App\Core\View;
use App\Repositories\DeviseRepository;
use App\Repositories\GestionStockRepository;
use App\Repositories\StockRepository;

/**
 * Stock > Péremptions : lots datés (réceptions, productions) déjà périmés ou qui périment dans les N jours
 * (réglage de chaque établissement, ou choisi à l'écran), le plus urgent d'abord ; un lot périmé se déclare
 * en perte (motif « périmé ») d'un clic, en sortant d'abord de ce lot. Déclarer : stock.ajuster pour le lieu.
 */
final class PeremptionController
{
    private const JOURS = [1, 3, 7, 14, 30];

    private StockRepository $stock;
    private GestionStockRepository $depot;

    public function __construct()
    {
        $this->stock = new StockRepository();
        $this->depot = new GestionStockRepository($this->stock);
    }

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $lieux = array_column($this->stock->lieux(StockAcces::etablissements($uid, 'stock.voir')), null, 'id');
        $lieuId = StockAcces::id($_GET['lieu'] ?? null);
        $lieu = $lieuId !== null ? ($lieux[$lieuId] ?? null) : null;
        $jours = isset($_GET['jours']) && in_array((int) $_GET['jours'], self::JOURS, true) ? (int) $_GET['jours'] : null;
        $tous = ($_GET['tous'] ?? '') === '1';
        $lots = $this->depot->lotsAlerte($lieu !== null ? [(int) $lieu['id']] : array_keys($lieux), $jours, $tous);
        $articles = $this->depot->articles(true);
        $cumps = $this->depot->cumps();
        $peutPerte = [];
        foreach ($lieux as $id => $l) {
            $peutPerte[$id] = StockAcces::peut($uid, 'stock.ajuster', $l['etablissement_id'] !== null ? (int) $l['etablissement_id'] : null);
        }
        View::afficher('gestion/stock/peremptions', [
            'titre' => t('stock.peremptions_titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'lieux' => $lieux,
            'lieu' => $lieu,
            'jours' => $jours,
            'choixJours' => self::JOURS,
            'tous' => $tous,
            'perimes' => array_values(array_filter($lots, static fn (array $l): bool => (int) $l['jours_restants'] < 0)),
            'bientot' => array_values(array_filter($lots, static fn (array $l): bool => (int) $l['jours_restants'] >= 0)),
            'articles' => $articles,
            'cumps' => $cumps,
            'peutPerte' => $peutPerte,
            'voirValeur' => Acces::a($uid, 'rapports.marges'),
            'reference' => (new DeviseRepository())->reference(),
        ], 'gestion');
    }

    /** Déclare en perte (motif « périmé ») ce qui reste d'un lot. */
    public function perte(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $uid = (int) $utilisateur['id'];
        $lot = ($i = StockAcces::id($id)) !== null ? $this->depot->lot($i) : null;
        if ($lot === null) {
            pageIntrouvable();
        }
        if (!StockAcces::peut($uid, 'stock.ajuster', $lot['etablissement_id'] !== null ? (int) $lot['etablissement_id'] : null)) {
            StockAcces::interdit($utilisateur);
        }
        $restant = (float) $lot['quantite_restante'];
        $article = $this->depot->articles(true)[(int) $lot['produit_id']] ?? null;
        $format = $article !== null ? GestionStockRepository::formatUnite($article) : null;
        if ($restant <= 0.0005 || $article === null || $format === null) {
            Session::flash(t('stock.lot_deja_vide'));
            rediriger('/gestion/stock/peremptions');
        }
        $commentaire = trim(t('stock.lot_perime_commentaire', [
            'lot' => (string) ($lot['numero'] ?? '#' . $lot['id']),
            'date' => $lot['peremption'] !== null ? TresorerieAffichage::jour($lot['peremption']) : '—',
        ]));
        $this->depot->creerPerte([
            'lieu_id' => (int) $lot['lieu_id'], 'produit_id' => (int) $lot['produit_id'], 'conditionnement_id' => (int) $format['id'],
            'quantite' => round($restant / max(0.001, (float) $format['quantite_base']), 3), 'quantite_base' => $restant,
            'motif' => 'perime', 'commentaire' => mb_substr($commentaire, 0, 255), 'date' => date('Y-m-d H:i:s'), 'lot_id' => (int) $lot['id'],
        ], $uid);
        Session::flash(t('stock.lot_perte_enregistree', [
            'quantite' => StockAffichage::quantite($restant, $article), 'article' => champ($article, 'nom'), 'lieu' => $lot['lieu_nom'],
        ]));
        rediriger('/gestion/stock/peremptions' . (isset($_POST['retour_lieu']) && StockAcces::id($_POST['retour_lieu']) !== null ? '?lieu=' . (int) $_POST['retour_lieu'] : ''));
    }
}
