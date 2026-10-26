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
 * Stock > Pertes : casse, offert, consommation du personnel, périmé, renvoyé (ou autre), toujours avec un
 * motif écrit ; la quantité sort du stock du lieu. Déclarer : stock.ajuster pour l'établissement du lieu.
 */
final class PerteController
{
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
        $this->afficher($utilisateur, [
            'lieu_id' => StockAcces::id($_GET['lieu'] ?? null), 'conditionnement_id' => null, 'quantite' => '', 'motif' => 'casse', 'commentaire' => '', 'date' => '',
        ]);
    }

    public function creer(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $uid = (int) $utilisateur['id'];
        $lieux = StockAcces::filtrer($this->stock->lieux(StockAcces::etablissements($uid, 'stock.ajuster')), $uid, 'stock.ajuster');
        if ($lieux === []) {
            StockAcces::interdit($utilisateur);
        }
        $valeurs = [
            'lieu_id' => StockAcces::id($_POST['lieu_id'] ?? null),
            'conditionnement_id' => StockAcces::id($_POST['conditionnement_id'] ?? null),
            'quantite' => TresorerieSaisie::post('quantite'),
            'motif' => in_array($_POST['motif'] ?? null, GestionStockRepository::MOTIFS_PERTE, true) ? $_POST['motif'] : '',
            'commentaire' => mb_substr(TresorerieSaisie::post('commentaire'), 0, 255),
            'date' => TresorerieSaisie::post('date'),
        ];
        $erreurs = [];
        $lieu = null;
        foreach ($lieux as $l) {
            if ((int) $l['id'] === $valeurs['lieu_id']) {
                $lieu = $l;
            }
        }
        if ($lieu === null) {
            $erreurs['lieu_id'] = t('stock.erreur_lieu');
        }
        $article = $format = null;
        foreach ($this->depot->articles() as $a) {
            if (($f = GestionStockRepository::format($a, $valeurs['conditionnement_id'])) !== null) {
                [$article, $format] = [$a, $f];
            }
        }
        if ($format === null) {
            $erreurs['conditionnement_id'] = t('stock.erreur_article');
        }
        [$quantite, $erreur] = StockAcces::quantite($valeurs['quantite']);
        if ($erreur !== null) {
            $erreurs['quantite'] = $erreur;
        }
        if ($valeurs['motif'] === '') {
            $erreurs['motif'] = t('stock.erreur_motif_perte');
        }
        if ($valeurs['commentaire'] === '') {
            $erreurs['commentaire'] = t('stock.erreur_commentaire_perte');
        }
        [$date, $erreurDate] = TresorerieSaisie::dateOperation($valeurs['date']);
        if ($erreurDate !== null) {
            $erreurs['date'] = $erreurDate;
        }
        if ($erreurs !== []) {
            $this->afficher($utilisateur, $valeurs, $erreurs, 422);
            return;
        }
        $this->depot->creerPerte([
            'lieu_id' => (int) $lieu['id'], 'produit_id' => (int) $article['id'], 'conditionnement_id' => (int) $format['id'],
            'quantite' => $quantite, 'quantite_base' => $quantite * (float) $format['quantite_base'],
            'motif' => $valeurs['motif'], 'commentaire' => $valeurs['commentaire'], 'date' => $date,
        ], $uid);
        Session::flash(t('stock.perte_enregistree', [
            'quantite' => StockAffichage::nombre($quantite) . ' ' . StockAffichage::format($format, $quantite),
            'article' => champ($article, 'nom'), 'lieu' => $lieu['nom'],
        ]));
        rediriger('/gestion/stock/pertes');
    }

    private function afficher(array $utilisateur, array $valeurs, array $erreurs = [], int $code = 200): void
    {
        http_response_code($code);
        $uid = (int) $utilisateur['id'];
        $visibles = array_column($this->stock->lieux(StockAcces::etablissements($uid, 'stock.voir'), false), null, 'id');
        $periode = TresorerieSaisie::periode('mois');
        $filtreLieu = StockAcces::id($_GET['lieu'] ?? null);
        $filtres = [
            'debut' => $periode['debut'], 'fin' => $periode['fin'],
            'lieu' => $filtreLieu !== null && isset($visibles[$filtreLieu]) ? $filtreLieu : null,
            'motif' => in_array($_GET['motif'] ?? null, GestionStockRepository::MOTIFS_PERTE, true) ? $_GET['motif'] : null,
        ];
        View::afficher('gestion/stock/pertes', [
            'titre' => t('stock.pertes_titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'periode' => $periode,
            'filtres' => $filtres,
            'lieuxVisibles' => $visibles,
            'lieux' => StockAcces::filtrer($this->stock->lieux(StockAcces::etablissements($uid, 'stock.ajuster')), $uid, 'stock.ajuster'),
            'articles' => $this->depot->articles(),
            'tousArticles' => $this->depot->articles(true),
            'pertes' => $this->depot->pertes($filtres, array_keys($visibles)),
            'voirValeur' => Acces::a($uid, 'rapports.marges'),
            'reference' => (new DeviseRepository())->reference(),
            'valeurs' => $valeurs,
            'erreurs' => $erreurs,
        ], 'gestion');
    }
}
