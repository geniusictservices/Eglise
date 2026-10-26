<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Csv;
use App\Core\Database;
use App\Core\Session;
use App\Core\View;
use App\Repositories\RapportRepository;

/**
 * Rapports > Export comptable (finance.voir) : pour le comptable, sur une période, trois journaux en CSV —
 * mouvements de trésorerie (chaque entrée et sortie de chaque compte, dans sa devise, avec le taux et la
 * contre-valeur en devise de référence), ventes (une ligne par ticket, annulées comprises) et dépenses (une ligne
 * par paiement). L'écran montre le nombre de lignes et les totaux de chaque journal avant de télécharger.
 */
final class ExportComptableController
{
    public const JOURNAUX = ['tresorerie', 'ventes', 'depenses'];

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $periode = TresorerieSaisie::periode('mois_precedent');
        $debut = $periode['debut'];
        $fin = $periode['fin'];
        $devises = VenteController::devises();
        $repo = new RapportRepository();
        $reference = $repo->reference();

        if (Csv::demande()) {
            $journal = in_array($_GET['journal'] ?? null, self::JOURNAUX, true) ? $_GET['journal'] : 'tresorerie';
            match ($journal) {
                'ventes' => $this->ventes($debut, $fin, $uid, $devises, $repo, $periode),
                'depenses' => $this->depenses($debut, $fin, $devises, $reference, $periode),
                default => $this->tresorerie($debut, $fin, $devises, $reference, $periode),
            };
        }

        // Aperçu : nombre de lignes et totaux par devise
        $mouvements = [];
        foreach (Database::tous(
            'SELECT devise_id, sens, COUNT(*) AS n, SUM(montant) AS montant FROM mouvements_tresorerie
              WHERE date_operation BETWEEN ? AND ? GROUP BY devise_id, sens',
            [$debut, $fin]
        ) as $l) {
            $mouvements[(int) $l['devise_id']][$l['sens']] = (float) $l['montant'];
            $mouvements[(int) $l['devise_id']]['n'] = ($mouvements[(int) $l['devise_id']]['n'] ?? 0) + (int) $l['n'];
        }
        [$portee, $params] = RapportRepository::portee($uid, 'finance.voir', 'v.etablissement_id');
        $ventes = Database::un(
            "SELECT COUNT(*) AS n, SUM(v.statut = 'annulee') AS annulees, COALESCE(SUM(IF(v.statut = 'payee', v.total_reference, 0)), 0) AS reference
               FROM ventes v WHERE v.cree_le BETWEEN ? AND ? AND $portee",
            array_merge([$debut, $fin], $params)
        );
        $depenses = [];
        foreach (Database::tous(
            'SELECT p.devise_id, COUNT(*) AS n, SUM(p.montant) AS montant FROM depenses d JOIN depense_paiements p ON p.depense_id = d.id
              WHERE d.date_depense BETWEEN ? AND ? GROUP BY p.devise_id',
            [$debut, $fin]
        ) as $l) {
            $depenses[(int) $l['devise_id']] = ['n' => (int) $l['n'], 'montant' => (float) $l['montant']];
        }

        View::afficher('gestion/rapports/export-comptable', [
            'titre' => t('rcompta.titre'),
            'utilisateur' => $utilisateur,
            'periode' => $periode,
            'mouvements' => $mouvements,
            'ventes' => $ventes,
            'depenses' => $depenses,
            'devises' => $devises,
            'reference' => $reference,
        ], 'gestion');
    }

    /** Pièce justificative d'un mouvement : numéro du ticket, de la dépense, du transfert… */
    private static function piece(array $m): string
    {
        $id = (int) $m['source_id'];
        return match ($m['nature']) {
            'vente' => (string) ($m['vente_numero'] ?? 'V-' . $id),
            'depense' => 'DEP-' . $id,
            'transfert' => 'TRF-' . $id,
            'remboursement' => 'RMB-' . $id,
            'achat' => 'ACH-' . $id,
            'salaire' => 'SAL-' . $id,
            'avance' => 'AVA-' . $id,
            'ajustement' => 'AJU-' . $id,
            default => $id > 0 ? strtoupper(substr((string) $m['nature'], 0, 3)) . '-' . $id : '',
        };
    }

    private function tresorerie(string $debut, string $fin, array $devises, ?array $reference, array $periode): never
    {
        $lignes = Database::tous(
            "SELECT m.*, c.nom AS compte_nom, c.type AS compte_type, e.nom AS etablissement_nom, u.nom AS utilisateur_nom,
                    IF(m.nature = 'vente', (SELECT v.numero FROM ventes v WHERE v.id = m.source_id), NULL) AS vente_numero
               FROM mouvements_tresorerie m
               JOIN comptes_tresorerie c ON c.id = m.compte_id
          LEFT JOIN etablissements e ON e.id = c.etablissement_id
          LEFT JOIN utilisateurs u ON u.id = m.utilisateur_id
              WHERE m.date_operation BETWEEN ? AND ?
           ORDER BY m.date_operation, m.id",
            [$debut, $fin]
        );
        $codeRef = $reference['code'] ?? '';
        $entetes = [t('rcompta.col_date'), t('rcompta.col_journal'), t('rcompta.col_piece'), t('rcompta.col_libelle'), t('rcompta.col_compte'),
            t('rcompta.col_type_compte'), t('gestion.etablissement'), t('rapports.csv_devise'), t('rcompta.col_entree'), t('rcompta.col_sortie'),
            t('rcompta.col_taux'), t('rcompta.col_contre_valeur', ['code' => $codeRef]), t('rcompta.col_utilisateur')];
        $sortie = [];
        foreach ($lignes as $m) {
            $devise = $devises[(int) $m['devise_id']] ?? null;
            $entree = $m['sens'] === 'entree';
            $sortie[] = [Csv::date($m['date_operation']), t('tresorerie.nature_' . $m['nature']) === 'tresorerie.nature_' . $m['nature'] ? $m['nature'] : t('tresorerie.nature_' . $m['nature']),
                self::piece($m), (string) $m['libelle'], $m['compte_nom'], t('tresorerie.type_' . $m['compte_type']), (string) ($m['etablissement_nom'] ?? t('tresorerie.compte_commun')),
                $devise['code'] ?? '', $entree ? Csv::montant($m['montant'], $devise) : '', $entree ? '' : Csv::montant($m['montant'], $devise),
                $m['taux'] !== null ? Csv::nombre($m['taux'], 6) : '', $m['contre_valeur'] !== null ? Csv::montant(($entree ? 1 : -1) * (float) $m['contre_valeur'], $reference) : '',
                (string) ($m['utilisateur_nom'] ?? '')];
        }
        Csv::envoyer(Csv::nomFichier('journal-tresorerie', $periode['du'] !== null ? $periode : null), $entetes, $sortie);
    }

    private function ventes(string $debut, string $fin, int $uid, array $devises, RapportRepository $repo, array $periode): never
    {
        [$portee, $params] = RapportRepository::portee($uid, 'finance.voir', 'v.etablissement_id');
        $locale = $repo->locale();
        $reference = $repo->reference();
        $lignes = Database::tous(
            'SELECT v.id, v.numero, v.cree_le, v.statut, v.devise_id, v.total, v.total_reference, v.encaisse_reference, v.annulee_le, v.motif_annulation,
                    e.nom AS etablissement_nom, u.nom AS vendeur, vt.total AS total_locale, ' . RapportRepository::journeeSql('v.cree_le') . ' AS journee,
                    (SELECT COALESCE(SUM(d.montant), 0) FROM dettes d WHERE d.vente_id = v.id AND d.devise_id = ?) AS credit_locale,
                    (SELECT GROUP_CONCAT(DISTINCT p.moyen) FROM vente_paiements p WHERE p.vente_id = v.id) AS moyens
               FROM ventes v
               JOIN etablissements e ON e.id = v.etablissement_id
          LEFT JOIN utilisateurs u ON u.id = v.utilisateur_id
          LEFT JOIN vente_totaux vt ON vt.vente_id = v.id AND vt.devise_id = ?
              WHERE v.cree_le BETWEEN ? AND ? AND ' . $portee . '
           ORDER BY v.cree_le, v.id',
            array_merge([(int) ($locale['id'] ?? 0), (int) ($locale['id'] ?? 0), $debut, $fin], $params)
        );
        $l = $locale['code'] ?? '';
        $r = $reference['code'] ?? '';
        $entetes = [t('rcompta.col_date'), t('rapports.col_jour'), t('rpersonnel.csv_ticket'), t('gestion.etablissement'), t('gventes.vendeur'), t('rcompta.col_statut'),
            t('rcompta.col_devise_ticket'), t('rcompta.col_total_ticket'), t('rapports.csv_ca', ['code' => $l]), t('rapports.csv_ca', ['code' => $r]),
            t('rcompta.col_encaisse_ref', ['code' => $r]), t('rpersonnel.csv_credit_ventes', ['code' => $l]), t('rcompta.col_moyens'), t('rcompta.col_annulee_le'), t('gventes.motif')];
        $sortie = [];
        foreach ($lignes as $v) {
            $devise = $devises[(int) $v['devise_id']] ?? null;
            $sortie[] = [Csv::date($v['cree_le']), $v['journee'], $v['numero'], $v['etablissement_nom'], (string) $v['vendeur'],
                t($v['statut'] === 'payee' ? 'gventes.badge_payee' : 'gventes.badge_annulee'), $devise['code'] ?? '', Csv::montant($v['total'], $devise),
                Csv::montant($v['total_locale'], $locale), Csv::montant($v['total_reference'], $reference), Csv::montant($v['encaisse_reference'], $reference),
                Csv::montant($v['credit_locale'], $locale),
                implode(', ', array_map(static fn (string $m) => t('tresorerie.type_' . ($m === 'especes' ? 'caisse' : $m)), array_filter(explode(',', (string) $v['moyens'])))),
                Csv::date($v['annulee_le']), (string) $v['motif_annulation']];
        }
        Csv::envoyer(Csv::nomFichier('journal-ventes', $periode['du'] !== null ? $periode : null), $entetes, $sortie);
    }

    private function depenses(string $debut, string $fin, array $devises, ?array $reference, array $periode): never
    {
        $avecSoiree = RapportRepository::colonneExiste('depenses', 'evenement_id');
        $lignes = Database::tous(
            "SELECT d.id, d.date_depense, d.libelle, d.beneficiaire, cat.nom_fr AS categorie_nom_fr, cat.nom_en AS categorie_nom_en,
                    e.nom AS etablissement_nom, c.nom AS compte_nom, c.type AS compte_type, p.devise_id, p.montant, m.contre_valeur, m.taux,
                    " . ($avecSoiree ? '(SELECT ev.titre FROM evenements ev WHERE ev.id = d.evenement_id)' : 'NULL') . " AS soiree
               FROM depenses d
               JOIN categories_depenses cat ON cat.id = d.categorie_id
               JOIN depense_paiements p ON p.depense_id = d.id
               JOIN comptes_tresorerie c ON c.id = p.compte_id
          LEFT JOIN etablissements e ON e.id = d.etablissement_id
          LEFT JOIN mouvements_tresorerie m ON m.nature = 'depense' AND m.source_id = d.id AND m.compte_id = p.compte_id AND m.devise_id = p.devise_id
              WHERE d.date_depense BETWEEN ? AND ?
           ORDER BY d.date_depense, d.id, p.position",
            [$debut, $fin]
        );
        $entetes = [t('rcompta.col_date'), t('rcompta.col_piece'), t('rcompta.col_categorie'), t('rcompta.col_libelle'), t('rcompta.col_beneficiaire'),
            t('gestion.etablissement'), t('evenements.champ_depense'), t('rcompta.col_compte'), t('rcompta.col_type_compte'), t('rapports.csv_devise'),
            t('rapports.csv_montant'), t('rcompta.col_taux'), t('rcompta.col_contre_valeur', ['code' => $reference['code'] ?? ''])];
        $sortie = [];
        foreach ($lignes as $d) {
            $devise = $devises[(int) $d['devise_id']] ?? null;
            $sortie[] = [Csv::date($d['date_depense']), 'DEP-' . (int) $d['id'], champ($d, 'categorie_nom'), $d['libelle'], (string) $d['beneficiaire'],
                (string) ($d['etablissement_nom'] ?? t('depenses.commune_tous')), (string) $d['soiree'], $d['compte_nom'], t('tresorerie.type_' . $d['compte_type']),
                $devise['code'] ?? '', Csv::montant($d['montant'], $devise), $d['taux'] !== null ? Csv::nombre($d['taux'], 6) : '',
                $d['contre_valeur'] !== null ? Csv::montant($d['contre_valeur'], $reference) : ''];
        }
        Csv::envoyer(Csv::nomFichier('journal-depenses', $periode['du'] !== null ? $periode : null), $entetes, $sortie);
    }
}
