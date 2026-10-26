<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Session;
use App\Core\View;
use App\Repositories\DeviseRepository;
use App\Repositories\GestionStockRepository;
use App\Repositories\ProductionRepository;
use App\Repositories\StockRepository;

/**
 * Stock > Productions : la cuisine prépare à l'avance une sauce, une marinade ou un bouillon d'après sa fiche
 * « préparation » : les ingrédients sortent du lieu, N unités de la préparation y entrent (avec leur date de
 * péremption) ; les plats qui l'utilisent la consomment ensuite. Produire : stock.ajuster pour le lieu.
 */
final class ProductionController
{
    private StockRepository $stock;
    private ProductionRepository $productions;
    private GestionStockRepository $depot;

    public function __construct()
    {
        $this->stock = new StockRepository();
        $this->productions = new ProductionRepository($this->stock);
        $this->depot = new GestionStockRepository($this->stock);
    }

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $this->afficher($utilisateur, [
            'lieu_id' => StockAcces::id($_GET['lieu'] ?? null), 'produit_id' => StockAcces::id($_GET['preparation'] ?? null),
            'quantite' => '', 'date' => '', 'peremption' => '', 'notes' => '',
        ]);
    }

    public function creer(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $uid = (int) $utilisateur['id'];
        $lieux = $this->lieuxProduction($uid);
        if ($lieux === []) {
            StockAcces::interdit($utilisateur);
        }
        $valeurs = [
            'lieu_id' => StockAcces::id($_POST['lieu_id'] ?? null),
            'produit_id' => StockAcces::id($_POST['produit_id'] ?? null),
            'quantite' => TresorerieSaisie::post('quantite'),
            'date' => TresorerieSaisie::post('date'),
            'peremption' => TresorerieSaisie::post('peremption'),
            'notes' => mb_substr(TresorerieSaisie::post('notes'), 0, 255),
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
        $preparation = null;
        foreach ($this->productions->preparations() as $p) {
            if ((int) $p['id'] === $valeurs['produit_id']) {
                $preparation = $p;
            }
        }
        if ($preparation === null) {
            $erreurs['produit_id'] = t('stock.erreur_preparation');
        }
        [$quantite, $erreur] = StockAcces::quantite($valeurs['quantite']);
        if ($erreur !== null) {
            $erreurs['quantite'] = $erreur;
        }
        [$date, $erreurDate] = TresorerieSaisie::dateOperation($valeurs['date']);
        if ($erreurDate !== null) {
            $erreurs['date'] = $erreurDate;
        }
        [$peremption, $erreurPeremption] = StockAcces::date($valeurs['peremption']);
        if ($erreurPeremption !== null) {
            $erreurs['peremption'] = $erreurPeremption;
        } elseif ($peremption !== null && $date !== null && $peremption < substr($date, 0, 10)) {
            $erreurs['peremption'] = t('stock.erreur_peremption_passee');
        }
        if ($erreurs === [] && $lieu !== null && $preparation !== null) {
            // Mode « bloquer » : pas de production avec des ingrédients qui manquent dans le lieu
            $etab = $lieu['etablissement_id'] !== null ? (int) $lieu['etablissement_id'] : null;
            if ($etab !== null && $this->stock->parametres($etab)['mode_negatif'] === 'bloquer') {
                foreach ($this->productions->besoins((int) $preparation['id'], $quantite) as $ingredientId => $besoin) {
                    if ($this->stock->quantite((int) $lieu['id'], $ingredientId) < $besoin - 0.0005) {
                        $erreurs['quantite'] = t('stock.erreur_production_manque');
                        break;
                    }
                }
            }
        }
        if ($erreurs !== []) {
            $this->afficher($utilisateur, $valeurs, $erreurs, 422);
            return;
        }
        $id = $this->productions->produire((int) $lieu['id'], (int) $preparation['id'], $quantite, $date, $peremption,
            $valeurs['notes'] !== '' ? $valeurs['notes'] : null, $uid);
        Session::flash(t('stock.production_enregistree', [
            'numero' => sprintf('PR-%06d', $id), 'quantite' => StockAffichage::nombre($quantite) . ' ' . StockAffichage::unite((string) $preparation['unite_base'], $quantite),
            'preparation' => champ($preparation, 'nom'), 'lieu' => $lieu['nom'],
        ]));
        rediriger('/gestion/stock/productions');
    }

    /** Lieux où la personne peut produire (stock.ajuster). */
    private function lieuxProduction(int $uid): array
    {
        return StockAcces::filtrer($this->stock->lieux(StockAcces::etablissements($uid, 'stock.ajuster')), $uid, 'stock.ajuster');
    }

    private function afficher(array $utilisateur, array $valeurs, array $erreurs = [], int $code = 200): void
    {
        http_response_code($code);
        $uid = (int) $utilisateur['id'];
        $visibles = array_column($this->stock->lieux(StockAcces::etablissements($uid, 'stock.voir'), false), null, 'id');
        $lieux = $this->lieuxProduction($uid);
        $periode = TresorerieSaisie::periode('mois');
        $preparations = $this->productions->preparations();
        // Besoins par unité produite et stock de chaque ingrédient dans les lieux du formulaire (aperçu en direct)
        $articles = $this->depot->articles(true);
        $besoins = [];
        foreach ($preparations as $p) {
            $liste = [];
            foreach ($this->productions->besoins((int) $p['id'], 1.0) as $ingredientId => $q) {
                $a = $articles[$ingredientId] ?? null;
                $stock = [];
                foreach ($lieux as $l) {
                    $stock[(int) $l['id']] = round($this->stock->quantite((int) $l['id'], $ingredientId), 3);
                }
                $liste[] = ['nom' => $a ? champ($a, 'nom') : '#' . $ingredientId, 'q' => round($q, 6),
                            'unite1' => StockAffichage::unite((string) ($a['unite_base'] ?? ''), 1), 'uniteN' => StockAffichage::unite((string) ($a['unite_base'] ?? ''), 2), 'stock' => $stock];
            }
            $besoins[(int) $p['id']] = $liste;
        }
        View::afficher('gestion/stock/productions', [
            'titre' => t('stock.productions_titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'periode' => $periode,
            'lieux' => $lieux,
            'preparations' => $preparations,
            'besoins' => $besoins,
            'productions' => $this->productions->liste(array_keys($visibles), $periode['debut'], $periode['fin']),
            'voirValeur' => Acces::a($uid, 'rapports.marges'),
            'reference' => (new DeviseRepository())->reference(),
            'peutFiches' => Acces::a($uid, 'stock.recettes'),
            'valeurs' => $valeurs,
            'erreurs' => $erreurs,
        ], 'gestion');
    }
}
