<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Csv;
use App\Core\Database;
use App\Repositories\GestionDetteRepository;
use App\Repositories\GestionTresorerieRepository;
use App\Repositories\GestionVenteRepository;

/**
 * Exports CSV des écrans existants (?export=csv, mêmes filtres que l'écran) : tickets, ventes par vendeur,
 * créances, vue d'ensemble de la trésorerie, mouvements d'un compte, dépenses. Chaque contrôleur appelle la
 * méthode de son écran juste avant d'afficher la page ; le format est celui de App\Core\Csv.
 */
final class ExportCsv
{
    /** Ventes > Tickets : tous les tickets des filtres (pas seulement la page affichée). */
    public static function ventes(GestionVenteRepository $depot, array $filtres, int $uid, array $periode, array $devises): never
    {
        $entetes = [t('gventes.col_ticket'), t('gventes.col_date'), t('gestion.etablissement'), t('gventes.vendeur'), t('gventes.col_articles'),
            t('rapports.csv_montant'), t('rapports.csv_devise'), t('gventes.col_paiement'), t('gventes.statut'), t('rcompta.col_annulee_le')];
        $lignes = [];
        for ($page = 1; $page <= 400; $page++) {
            $liste = $depot->liste($filtres, $uid, $page);
            foreach ($liste['ventes'] as $v) {
                $devise = $devises[(int) $v['devise_id']] ?? null;
                $lignes[] = [$v['numero'], Csv::date($v['cree_le']), $v['etablissement_nom'], (string) ($v['vendeur'] ?? ''), Csv::nombre($v['articles'], 0),
                    Csv::montant($v['total'], $devise), $devise['code'] ?? '',
                    implode(', ', array_map(static fn (string $m) => t('tresorerie.type_' . ($m === 'especes' ? 'caisse' : $m)), $v['moyens'])),
                    t($v['statut'] === 'payee' ? 'gventes.badge_payee' : 'gventes.badge_annulee'), Csv::date($v['annulee_le'])];
            }
            if ($page * GestionVenteRepository::PAR_PAGE >= $liste['total']) {
                break;
            }
        }
        Csv::envoyer(Csv::nomFichier('tickets', $periode['du'] !== null ? $periode : null), $entetes, $lignes);
    }

    /** Rapports > Ventes par vendeur : le classement de l'écran. */
    public static function vendeurs(array $vendeurs, array $total, ?array $locale, ?array $reference, array $periode): never
    {
        $l = $locale['code'] ?? '';
        $r = $reference['code'] ?? '';
        $entetes = [t('rpersonnel.col_rang'), t('gventes.vendeur'), t('rventes.col_tickets'), t('gventes.col_articles'), t('rapports.csv_ca', ['code' => $l]),
            t('gventes.equivalent_encaisse', ['code' => $r]), t('rapports.csv_panier', ['code' => $l]), t('rventes.col_part') . ' (%)'];
        $lignes = [];
        foreach ($vendeurs as $i => $v) {
            $lignes[] = [$i + 1, $v['nom'] ?? t('gventes.vendeur_inconnu'), $v['tickets'], $v['articles'], Csv::montant($v['total'], $locale),
                Csv::montant($v['reference'], $reference), Csv::montant($v['tickets'] > 0 ? $v['total'] / $v['tickets'] : 0, $locale),
                Csv::nombre($total['total'] > 0 ? $v['total'] / $total['total'] * 100 : 0, 1)];
        }
        $lignes[] = ['', t('rapports.total'), $total['tickets'], $total['articles'], Csv::montant($total['total'], $locale), Csv::montant($total['reference'], $reference),
            Csv::montant($total['tickets'] > 0 ? $total['total'] / $total['tickets'] : 0, $locale), Csv::nombre(100, 1)];
        Csv::envoyer(Csv::nomFichier('ventes-par-vendeur', $periode['du'] !== null ? $periode : null), $entetes, $lignes);
    }

    /** Rapports > Créances : une ligne par dette ouverte de la portée (rapports.voir), avec son ancienneté. */
    public static function creances(int $uid, ?int $etablissementId, array $devises, ?array $reference): never
    {
        $depot = new GestionDetteRepository();
        [$portee, $params] = $depot->portee($uid, 'rapports.voir', 'd.etablissement_id');
        $aujourdhui = date('Y-m-d');
        $where = "d.statut = 'ouverte' AND d.reste > 0 AND $portee";
        if ($etablissementId !== null) {
            $where .= ' AND d.etablissement_id = ?';
            $params[] = $etablissementId;
        }
        $dettes = Database::tous(
            "SELECT d.*, c.nom AS client_nom, c.telephone, e.nom AS etablissement_nom, v.numero, r.nom AS responsable_nom, s.nom AS servi_nom,
                    a.nom AS accorde_nom, g.nom AS garant_nom, DATEDIFF(?, DATE(d.cree_le)) AS age
               FROM dettes d
               JOIN clients c ON c.id = d.client_id
               JOIN etablissements e ON e.id = d.etablissement_id
          LEFT JOIN ventes v ON v.id = d.vente_id
          LEFT JOIN utilisateurs r ON r.id = d.responsable_id
          LEFT JOIN utilisateurs s ON s.id = d.servi_par
          LEFT JOIN utilisateurs a ON a.id = d.accorde_par
          LEFT JOIN utilisateurs g ON g.id = d.garant_id
              WHERE $where ORDER BY d.cree_le, d.id",
            array_merge([$aujourdhui], $params)
        );
        $entetes = [t('gdettes.col_client'), t('rcompta.col_telephone'), t('gestion.etablissement'), t('rpersonnel.csv_ticket'), t('rpersonnel.csv_date'),
            t('rpersonnel.csv_echeance'), t('rpersonnel.csv_montant'), t('rpersonnel.csv_reste'), t('rapports.csv_devise'),
            t('rpersonnel.csv_reste_ref', ['code' => $reference['code'] ?? '']), t('rpersonnel.csv_anciennete'), t('rtableau.en_retard'),
            t('rpersonnel.col_responsable'), t('rcompta.col_servi_par'), t('rcompta.col_accorde_par'), t('rcompta.col_garant')];
        $lignes = [];
        $total = 0.0;
        foreach ($dettes as $d) {
            $devise = $devises[(int) $d['devise_id']] ?? null;
            $ref = $depot->enReference((float) $d['reste'], (int) $d['devise_id']);
            $total += $ref ?? 0.0;
            $age = (int) $d['age'];
            $lignes[] = [$d['client_nom'], (string) $d['telephone'], $d['etablissement_nom'], (string) $d['numero'], Csv::date($d['cree_le'], false), Csv::date($d['echeance'], false),
                Csv::montant($d['montant'], $devise), Csv::montant($d['reste'], $devise), $devise['code'] ?? '', $ref !== null ? Csv::montant($ref, $reference) : '',
                t('gdettes.tranche_' . ($age <= 30 ? '0_30' : ($age <= 60 ? '31_60' : 'plus_60'))), $d['echeance'] < $aujourdhui ? t('rpersonnel.oui') : t('rpersonnel.non'),
                (string) $d['responsable_nom'], (string) $d['servi_nom'], (string) $d['accorde_nom'], (string) $d['garant_nom']];
        }
        $lignes[] = [t('rapports.total'), '', '', '', '', '', '', '', '', Csv::montant($total, $reference), '', '', '', '', '', ''];
        Csv::envoyer(Csv::nomFichier('creances'), $entetes, $lignes);
    }

    /** Finance > Comptes : le solde de chaque devise de chaque compte, puis les totaux par devise. */
    public static function tresorerie(array $groupes, array $inactifs, array $totaux, array $devises, ?array $reference, ?float $totalReference): never
    {
        $entetes = [t('rcompta.col_type_compte'), t('rcompta.col_compte'), t('gestion.etablissement'), t('rcompta.col_actif'), t('rapports.csv_devise'), t('rcompta.col_solde')];
        $lignes = [];
        foreach (array_merge(...array_values($groupes), ...[$inactifs]) as $compte) {
            foreach ($compte['devises'] as $deviseId => $ligne) {
                if (empty($ligne['tenue']) && abs((float) $ligne['solde']) < 0.0005) {
                    continue;
                }
                $devise = $devises[$deviseId] ?? null;
                $lignes[] = [t('tresorerie.type_' . $compte['type']), $compte['nom'], (string) ($compte['etablissement_nom'] ?? t('tresorerie.compte_commun')),
                    $compte['active'] ? t('rpersonnel.oui') : t('rpersonnel.non'), $devise['code'] ?? '', Csv::montant($ligne['solde'], $devise)];
            }
        }
        foreach ($totaux as $deviseId => $montant) {
            $devise = $devises[$deviseId] ?? null;
            $lignes[] = [t('rapports.total'), '', '', '', $devise['code'] ?? '', Csv::montant($montant, $devise)];
        }
        if ($reference !== null && $totalReference !== null) {
            $lignes[] = [t('rcompta.total_converti'), '', '', '', $reference['code'], Csv::montant($totalReference, $reference)];
        }
        Csv::envoyer(Csv::nomFichier('tresorerie-soldes'), $entetes, $lignes);
    }

    /** Fiche d'un compte : les mouvements de la période, avec le solde de la devise après chaque ligne. */
    public static function mouvements(array $compte, array $mouvements, array $devises, array $periode): never
    {
        $entetes = [t('rcompta.col_date'), t('rcompta.col_journal'), t('rcompta.col_libelle'), t('rapports.csv_devise'), t('rcompta.col_entree'),
            t('rcompta.col_sortie'), t('rcompta.col_solde_apres'), t('rcompta.col_utilisateur')];
        $lignes = [];
        foreach ($mouvements as $m) {
            $devise = $devises[(int) $m['devise_id']] ?? null;
            $entree = $m['sens'] === 'entree';
            $nature = t('tresorerie.nature_' . $m['nature']);
            $lignes[] = [Csv::date($m['date_operation']), str_starts_with($nature, 'tresorerie.') ? $m['nature'] : $nature,
                (string) ($m['depense_libelle'] ?? $m['libelle'] ?? ''), $devise['code'] ?? '',
                $entree ? Csv::montant($m['montant'], $devise) : '', $entree ? '' : Csv::montant($m['montant'], $devise),
                Csv::montant($m['solde_apres'] ?? null, $devise), (string) ($m['auteur'] ?? '')];
        }
        Csv::envoyer(Csv::nomFichier('compte-' . $compte['nom'], $periode['du'] !== null ? $periode : null), $entetes, $lignes);
    }

    /** Finance > Dépenses : une ligne par paiement des dépenses filtrées (même liste que l'écran). */
    public static function depenses(array $depenses, array $devises, ?array $reference, array $periode): never
    {
        $entetes = [t('rcompta.col_date'), t('rcompta.col_piece'), t('rcompta.col_categorie'), t('rcompta.col_libelle'), t('rcompta.col_beneficiaire'),
            t('gestion.etablissement'), t('rcompta.col_compte'), t('rcompta.col_type_compte'), t('rapports.csv_devise'), t('rapports.csv_montant'),
            t('rcompta.col_contre_valeur', ['code' => $reference['code'] ?? ''])];
        $lignes = [];
        foreach ($depenses as $d) {
            foreach ($d['paiements'] as $p) {
                $devise = $devises[(int) $p['devise_id']] ?? null;
                $equivalent = $p['contre_valeur'] !== null && $reference !== null && (int) $p['reference_id'] === (int) $reference['id'] ? (float) $p['contre_valeur'] : null;
                $lignes[] = [Csv::date($d['date_depense']), 'DEP-' . (int) $d['id'], champ($d, 'categorie_nom'), $d['libelle'], (string) $d['beneficiaire'],
                    (string) ($d['etablissement_nom'] ?? t('depenses.commune_tous')), $p['compte_nom'], t('tresorerie.type_' . $p['compte_type']),
                    $devise['code'] ?? '', Csv::montant($p['montant'], $devise), $equivalent !== null ? Csv::montant($equivalent, $reference) : ''];
            }
        }
        Csv::envoyer(Csv::nomFichier('depenses', $periode['du'] !== null ? $periode : null), $entetes, $lignes);
    }
}
