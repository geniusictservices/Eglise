<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Session;
use App\Core\View;
use App\Repositories\AchatRepository;
use App\Repositories\GestionStockRepository;
use App\Repositories\StockRepository;

/**
 * Achats > Dettes fournisseurs (factures à payer par fournisseur, reste dû par devise, échéances et retards)
 * et Achats > Prix d'achat (historique par produit et fournisseur, coût par unité de base, CUMP).
 */
final class AchatController
{
    private StockRepository $stock;
    private AchatRepository $achats;

    public function __construct()
    {
        $this->stock = new StockRepository();
        $this->achats = new AchatRepository($this->stock);
    }

    public function dettes(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $dettes = $this->achats->dettes(StockAcces::etablissements($uid, 'achats.voir'));
        $totaux = [];
        foreach ($dettes as $d) {
            foreach ($d['par_devise'] as $deviseId => $m) {
                $totaux[$deviseId] = ($totaux[$deviseId] ?? 0) + $m;
            }
        }
        $ordre = array_flip(array_keys(VenteController::devises()));
        uksort($totaux, static fn ($a, $b) => ($ordre[$a] ?? 999) <=> ($ordre[$b] ?? 999));
        View::afficher('gestion/achats/dettes', [
            'titre' => t('achats.dettes_titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'dettes' => $dettes,
            'totaux' => $totaux,
            'devises' => VenteController::devises(),
        ], 'gestion');
    }

    public function prix(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $periode = TresorerieSaisie::periode('tout');
        $depot = new GestionStockRepository($this->stock);
        $articles = $depot->articles(true);
        $filtres = [
            'produit' => StockAcces::id($_GET['produit'] ?? null),
            'fournisseur' => StockAcces::id($_GET['fournisseur'] ?? null),
            'debut' => $periode['debut'], 'fin' => $periode['fin'],
        ];
        View::afficher('gestion/achats/prix', [
            'titre' => t('achats.prix_titre'),
            'utilisateur' => $utilisateur,
            'periode' => $periode,
            'filtres' => $filtres,
            'lignes' => $this->achats->prix($filtres, StockAcces::etablissements($uid, 'achats.voir')),
            'articles' => $articles,
            'fournisseurs' => $this->achats->fournisseurs(),
            'cumps' => $depot->cumps(),
            'devises' => VenteController::devises(),
            'reference' => (new \App\Repositories\DeviseRepository())->reference(),
            'voirCump' => \App\Core\Acces::a($uid, 'rapports.marges'),
        ], 'gestion');
    }
}
