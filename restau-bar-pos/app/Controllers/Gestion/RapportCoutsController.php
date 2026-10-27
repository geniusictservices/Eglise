<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Csv;
use App\Core\Database;
use App\Core\Session;
use App\Core\View;
use App\Repositories\DeviseRepository;
use App\Repositories\RapportCoutsRepository;
use App\Repositories\StockRepository;

/**
 * Rapports > Coûts et rentabilité (cahier des charges 8.4) : coût matière par plat et part du prix de vente,
 * rentabilité par plat, écarts entre consommation attendue (ventes × fiches) et réelle (pertes, inventaires)
 * par produit, sur une période et un établissement (ou un lieu). Export CSV et impression. Droit :
 * rapports.marges (les coûts d'achat), pour les établissements de sa portée.
 */
final class RapportCoutsController
{
    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        if (!Acces::a($uid, 'rapports.marges')) {
            StockAcces::interdit($utilisateur);
        }
        $portee = Acces::etablissements($uid, 'rapports.marges');
        $etablissements = Database::tous('SELECT id, nom FROM etablissements ORDER BY position, id');
        if ($portee !== null) {
            $etablissements = array_values(array_filter($etablissements, static fn (array $e): bool => in_array((int) $e['id'], $portee, true)));
        }
        $etab = StockAcces::id($_GET['etablissement'] ?? null);
        if ($etab !== null && !in_array($etab, array_map('intval', array_column($etablissements, 'id')), true)) {
            $etab = null;
        }
        $vue = ($_GET['vue'] ?? '') === 'ecarts' ? 'ecarts' : 'plats';
        $periode = TresorerieSaisie::periode('mois');
        $stock = new StockRepository();
        // Lieux : ceux de l'établissement choisi, sinon tous ceux de la portée (dépôts centraux avec une portée totale)
        $lieux = array_values(array_filter($stock->lieux($etab !== null ? [$etab] : $portee, false),
            static fn (array $l): bool => $etab === null ? ($l['etablissement_id'] !== null || $portee === null) : (int) $l['etablissement_id'] === $etab));
        $lieuId = StockAcces::id($_GET['lieu'] ?? null);
        $lieu = null;
        foreach ($lieux as $l) {
            if ((int) $l['id'] === $lieuId) {
                $lieu = $l;
            }
        }
        $lieuIds = $lieu !== null ? [(int) $lieu['id']] : array_map(static fn (array $l): int => (int) $l['id'], $lieux);
        $repo = new RapportCoutsRepository();
        $reference = (new DeviseRepository())->reference();
        $plats = $repo->rentabilite($periode['debut'], $periode['fin'], $etab !== null ? [$etab] : $portee);
        $ecarts = $vue === 'ecarts' || Csv::demande() ? $repo->ecarts($periode['debut'], $periode['fin'], $lieuIds) : [];

        if (Csv::demande()) {
            $this->csv($vue, $vue === 'plats' ? $plats : $ecarts, $reference, $periode);
        }
        $totaux = ['ca' => 0.0, 'cout' => 0.0];
        foreach ($plats as $p) {
            $totaux['ca'] += $p['ca'] ?? 0;
            $totaux['cout'] += $p['cout'];
        }
        View::afficher('gestion/rapports/couts', [
            'titre' => t('couts.titre'),
            'utilisateur' => $utilisateur,
            'periode' => $periode,
            'etablissements' => $etablissements,
            'etablissement' => $etab,
            'lieux' => $lieux,
            'lieu' => $lieu,
            'vue' => $vue,
            'plats' => $plats,
            'totaux' => $totaux,
            'ecarts' => $ecarts,
            'inventaires' => $vue === 'ecarts' ? $repo->inventairesValides($periode['debut'], $periode['fin'], $lieuIds) : 0,
            'reference' => $reference,
            'peutFiches' => Acces::a($uid, 'stock.voir'),
        ], 'gestion');
    }

    private function csv(string $vue, array $lignes, ?array $reference, array $periode): never
    {
        $code = $reference['code'] ?? '';
        if ($vue === 'plats') {
            Csv::envoyer(Csv::nomFichier(t('couts.fichier_plats'), $periode), [
                t('couts.col_produit'), t('couts.col_fiche'), t('couts.col_quantite'), t('couts.col_unite'), t('couts.col_ca') . ' (' . $code . ')',
                t('couts.col_cout') . ' (' . $code . ')', t('couts.col_part_cout') . ' (%)', t('couts.col_marge') . ' (' . $code . ')', t('couts.col_taux_marge') . ' (%)',
                t('couts.col_gros'),
            ], array_map(static fn (array $p): array => [
                champ($p, 'nom'), $p['recette'] ? t('couts.oui') : t('couts.non'), Csv::nombre($p['quantite'], 3), StockAffichage::unite((string) $p['unite_base'], 2),
                Csv::montant($p['ca'], $reference), Csv::montant($p['cout'], $reference),
                $p['part_cout'] !== null ? Csv::nombre($p['part_cout'] * 100, 1) : '', Csv::montant($p['marge'], $reference),
                $p['taux_marge'] !== null ? Csv::nombre($p['taux_marge'] * 100, 1) : '', Csv::nombre($p['gros'], 3),
            ], $lignes));
        }
        Csv::envoyer(Csv::nomFichier(t('couts.fichier_ecarts'), $periode), [
            t('couts.col_produit'), t('couts.col_unite'), t('couts.col_ventes'), t('couts.col_production'), t('couts.col_attendue'),
            t('couts.col_pertes'), t('couts.col_inventaire'), t('couts.col_ajustements'), t('couts.col_reelle'), t('couts.col_ecart'),
            t('couts.col_ecart') . ' (%)', t('couts.col_valeur_ecart') . ' (' . $code . ')',
        ], array_map(static fn (array $e): array => [
            champ($e, 'nom'), StockAffichage::unite((string) $e['unite_base'], 2), Csv::nombre($e['ventes'], 3), Csv::nombre($e['production'], 3),
            Csv::nombre($e['attendue'], 3), Csv::nombre($e['pertes'], 3), Csv::nombre($e['inventaire'], 3), Csv::nombre($e['ajustements'], 3),
            Csv::nombre($e['reelle'], 3), Csv::nombre($e['ecart'], 3), $e['ecart_pct'] !== null ? Csv::nombre($e['ecart_pct'] * 100, 1) : '',
            Csv::montant($e['valeur_ecart'], $reference),
        ], $lignes));
    }
}
