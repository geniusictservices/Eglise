<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Acces;
use App\Core\Database;
use App\Core\I18n;
use App\Core\Journal;

/**
 * Événements (soirées des lounges et nightclubs, cahier des charges 13.5) : date et heures, thème, DJ ou artistes,
 * budget ; coûts = dépenses rattachées (depenses.evenement_id, saisies dans Finance > Dépenses avec leur compte
 * payeur et leur devise) ; recettes = ventes de l'établissement de l'ouverture à la fermeture de la soirée.
 * Rentabilité = recettes − coût des marchandises (si le stock le connaît) − coûts de la soirée, en devise de référence.
 *
 * Portée : evenements.voir pour lire, evenements.gerer pour créer, modifier, rattacher des dépenses, dans les
 * établissements de la portée du rôle.
 */
final class EvenementRepository
{
    public const TYPES = ['lounge', 'nightclub'];

    private RapportRepository $rapports;

    public function __construct(?RapportRepository $rapports = null)
    {
        $this->rapports = $rapports ?? new RapportRepository();
    }

    public function rapports(): RapportRepository
    {
        return $this->rapports;
    }

    public static function disponible(): bool
    {
        return RapportRepository::tableExiste('evenements');
    }

    /** Lounges et nightclubs où la personne a la permission. */
    public function etablissements(int $utilisateurId, string $permission): array
    {
        return array_values(array_filter(RapportRepository::etablissements($utilisateurId, $permission),
            static fn (array $e) => in_array($e['type'], self::TYPES, true)));
    }

    /**
     * Soirées dans la portée (evenements.voir), d'un établissement ou de tous ; « a_venir » (pas encore finies)
     * dans l'ordre des dates, « passes » de la plus récente à la plus ancienne.
     */
    public function liste(int $utilisateurId, ?int $etablissementId, string $quand, ?string $maintenant = null): array
    {
        $maintenant ??= date('Y-m-d H:i:s');
        [$portee, $params] = RapportRepository::portee($utilisateurId, 'evenements.voir', 'ev.etablissement_id');
        $where = $portee;
        if ($etablissementId !== null) {
            $where .= ' AND ev.etablissement_id = ?';
            $params[] = $etablissementId;
        }
        if ($quand === 'a_venir') {
            $where .= ' AND ev.fin >= ?';
            $params[] = $maintenant;
        } elseif ($quand === 'passes') {
            $where .= ' AND ev.fin < ?';
            $params[] = $maintenant;
        }
        return Database::tous(
            'SELECT ev.*, e.nom AS etablissement_nom, e.type AS etablissement_type,
                    (SELECT COUNT(*) FROM depenses d WHERE d.evenement_id = ev.id) AS nb_depenses
               FROM evenements ev JOIN etablissements e ON e.id = ev.etablissement_id
              WHERE ' . $where . '
           ORDER BY ev.debut ' . ($quand === 'passes' ? 'DESC' : 'ASC') . ', ev.id',
            $params
        );
    }

    /** Une soirée visible par la personne (evenements.voir pour son établissement), sinon null. */
    public function evenement(int $id, int $utilisateurId): ?array
    {
        $ev = Database::un(
            'SELECT ev.*, e.nom AS etablissement_nom, e.type AS etablissement_type, u.nom AS cree_par_nom
               FROM evenements ev JOIN etablissements e ON e.id = ev.etablissement_id
          LEFT JOIN utilisateurs u ON u.id = ev.cree_par
              WHERE ev.id = ?',
            [$id]
        );
        if ($ev === null || !Acces::a($utilisateurId, 'evenements.voir', (int) $ev['etablissement_id'])) {
            return null;
        }
        return $ev;
    }

    /**
     * @param array{etablissement_id: int, titre: string, theme: ?string, artistes: ?string, debut: string, fin: string,
     *              budget: ?float, budget_devise_id: ?int, description: ?string} $v
     */
    public function enregistrer(?int $id, array $v, int $utilisateurId): int
    {
        $valeurs = [$v['etablissement_id'], $v['titre'], $v['theme'], $v['artistes'], $v['debut'], $v['fin'],
            $v['budget'] !== null ? number_format($v['budget'], 3, '.', '') : null, $v['budget'] !== null ? $v['budget_devise_id'] : null, $v['description']];
        if ($id === null) {
            Database::executer(
                'INSERT INTO evenements (etablissement_id, titre, theme, artistes, debut, fin, budget, budget_devise_id, description, cree_par)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                array_merge($valeurs, [$utilisateurId])
            );
            $id = Database::dernierId();
            Journal::ecrire('evenement_cree', $v['titre'], ['debut' => $v['debut']], $utilisateurId);
            return $id;
        }
        Database::executer(
            'UPDATE evenements SET etablissement_id = ?, titre = ?, theme = ?, artistes = ?, debut = ?, fin = ?, budget = ?, budget_devise_id = ?, description = ?
              WHERE id = ?',
            array_merge($valeurs, [$id])
        );
        Journal::ecrire('evenement_modifie', $v['titre'], ['debut' => $v['debut']], $utilisateurId);
        return $id;
    }

    public function definirStatut(int $id, string $statut, int $utilisateurId): void
    {
        Database::executer('UPDATE evenements SET statut = ? WHERE id = ?', [$statut === 'annule' ? 'annule' : 'prevu', $id]);
        Journal::ecrire($statut === 'annule' ? 'evenement_annule' : 'evenement_retabli', (string) $id, [], $utilisateurId);
    }

    /** Suppression d'une soirée sans dépense rattachée (sinon, on l'annule). */
    public function supprimer(int $id, int $utilisateurId): bool
    {
        if (Database::un('SELECT id FROM depenses WHERE evenement_id = ? LIMIT 1', [$id]) !== null) {
            return false;
        }
        $ev = Database::un('SELECT titre FROM evenements WHERE id = ?', [$id]);
        Database::executer('DELETE FROM evenements WHERE id = ?', [$id]);
        Journal::ecrire('evenement_supprime', (string) ($ev['titre'] ?? $id), [], $utilisateurId);
        return true;
    }

    // ---------- Coûts : dépenses rattachées ----------

    /**
     * Dépenses (avec paiements et équivalent en référence) rattachées à la soirée, ou candidates : dépenses de
     * l'établissement ou communes, datées du mois qui précède la soirée jusqu'à une semaine après, sans soirée.
     */
    public function depenses(array $ev, bool $candidates = false): array
    {
        if ($candidates) {
            $where = 'd.evenement_id IS NULL AND (d.etablissement_id = ? OR d.etablissement_id IS NULL) AND d.date_depense BETWEEN ? AND ?';
            $params = [(int) $ev['etablissement_id'],
                (new \DateTimeImmutable($ev['debut']))->modify('-30 days')->format('Y-m-d 00:00:00'),
                (new \DateTimeImmutable($ev['fin']))->modify('+7 days')->format('Y-m-d 23:59:59')];
        } else {
            $where = 'd.evenement_id = ?';
            $params = [(int) $ev['id']];
        }
        $depenses = Database::tous(
            'SELECT d.id, d.date_depense, d.libelle, d.beneficiaire, d.categorie_id, d.etablissement_id, cat.nom_fr AS categorie_nom_fr, cat.nom_en AS categorie_nom_en
               FROM depenses d JOIN categories_depenses cat ON cat.id = d.categorie_id
              WHERE ' . $where . ' ORDER BY d.date_depense, d.id LIMIT 200',
            $params
        );
        if ($depenses === []) {
            return [];
        }
        $paiements = Database::tous(
            "SELECT p.depense_id, p.devise_id, p.montant, c.nom AS compte_nom, c.type AS compte_type, m.contre_valeur, m.reference_id
               FROM depense_paiements p
               JOIN comptes_tresorerie c ON c.id = p.compte_id
          LEFT JOIN mouvements_tresorerie m ON m.nature = 'depense' AND m.source_id = p.depense_id AND m.compte_id = p.compte_id AND m.devise_id = p.devise_id
              WHERE p.depense_id IN (" . implode(',', array_map(static fn (array $d) => (int) $d['id'], $depenses)) . ')
           ORDER BY p.depense_id, p.position, p.id'
        );
        $parDepense = [];
        foreach ($paiements as $p) {
            $parDepense[(int) $p['depense_id']][] = $p;
        }
        foreach ($depenses as &$d) {
            $d['paiements'] = $parDepense[(int) $d['id']] ?? [];
            $d['montants'] = [];
            $d['reference'] = 0.0;
            foreach ($d['paiements'] as $p) {
                $d['montants'][(int) $p['devise_id']] = ($d['montants'][(int) $p['devise_id']] ?? 0.0) + (float) $p['montant'];
                $d['reference'] += $this->rapports->enReference((float) $p['montant'], (int) $p['devise_id'], $p['contre_valeur'], $p['reference_id']);
            }
        }
        unset($d);
        return $depenses;
    }

    /**
     * Rattache (ou détache) des dépenses à la soirée. On ne rattache qu'une dépense de l'établissement ou commune,
     * sans autre soirée ; on ne détache que les siennes.
     * @param list<int> $lier
     * @param list<int> $detacher
     * @return int nombre de dépenses changées
     */
    public function lier(array $ev, array $lier, array $detacher, int $utilisateurId): int
    {
        $n = 0;
        foreach (array_unique($lier) as $depenseId) {
            $n += Database::executer(
                'UPDATE depenses SET evenement_id = ? WHERE id = ? AND evenement_id IS NULL AND (etablissement_id = ? OR etablissement_id IS NULL)',
                [(int) $ev['id'], (int) $depenseId, (int) $ev['etablissement_id']]
            );
        }
        foreach (array_unique($detacher) as $depenseId) {
            $n += Database::executer('UPDATE depenses SET evenement_id = NULL WHERE id = ? AND evenement_id = ?', [(int) $depenseId, (int) $ev['id']]);
        }
        if ($n > 0) {
            Journal::ecrire('evenement_couts', $ev['titre'], ['lier' => array_values($lier), 'detacher' => array_values($detacher)], $utilisateurId);
        }
        return $n;
    }

    /** Rattache une dépense saisie dans Finance > Dépenses (champ « Soirée »), ou la détache (null). */
    public static function rattacherDepense(int $depenseId, ?int $evenementId): void
    {
        if (!self::disponible()) {
            return;
        }
        Database::executer('UPDATE depenses SET evenement_id = ? WHERE id = ?', [$evenementId, $depenseId]);
    }

    /** Soirée d'une dépense (null si aucune ou si le module n'est pas installé). */
    public static function deLaDepense(int $depenseId): ?int
    {
        if (!self::disponible()) {
            return null;
        }
        $ligne = Database::un('SELECT evenement_id FROM depenses WHERE id = ?', [$depenseId]);
        return $ligne !== null && $ligne['evenement_id'] !== null ? (int) $ligne['evenement_id'] : null;
    }

    /**
     * Soirées proposées dans le formulaire d'une dépense : celles des 60 derniers jours et à venir, dans la portée
     * (evenements.voir), plus celle déjà choisie.
     */
    public static function choixPourDepense(int $utilisateurId, ?int $dejaChoisie = null): array
    {
        if (!self::disponible() || !Acces::a($utilisateurId, 'evenements.voir')) {
            return [];
        }
        [$portee, $params] = RapportRepository::portee($utilisateurId, 'evenements.voir', 'ev.etablissement_id');
        $params[] = date('Y-m-d 00:00:00', strtotime('-60 days'));
        $params[] = (int) $dejaChoisie;
        return Database::tous(
            'SELECT ev.id, ev.titre, ev.debut, ev.etablissement_id, e.nom AS etablissement_nom
               FROM evenements ev JOIN etablissements e ON e.id = ev.etablissement_id
              WHERE (' . $portee . ' AND ev.debut >= ?) OR ev.id = ?
           ORDER BY ev.debut DESC LIMIT 60',
            $params
        );
    }

    // ---------- Bilan d'une soirée ----------

    /**
     * Recettes et coûts d'une soirée : ventes de l'établissement de debut à fin (totaux, par catégorie, par heure,
     * produits), dépenses rattachées (par catégorie), coût des marchandises, résultat et budget, en référence.
     */
    public function bilan(array $ev, bool $avecMarge, bool $detail = true): array
    {
        $ventes = $this->rapports->ventes(['entre' => [$ev['debut'], $ev['fin']], 'etablissement' => (int) $ev['etablissement_id']], 0, false);
        $totaux = RapportRepository::totaux($ventes);
        $depenses = $this->depenses($ev);
        $couts = array_sum(array_column($depenses, 'reference'));
        $parCategorie = [];
        foreach ($depenses as $d) {
            $cle = (int) $d['categorie_id'];
            $parCategorie[$cle] ??= ['nom' => champ($d, 'categorie_nom'), 'reference' => 0.0, 'nombre' => 0];
            $parCategorie[$cle]['reference'] += $d['reference'];
            $parCategorie[$cle]['nombre']++;
        }
        uasort($parCategorie, static fn (array $a, array $b) => $b['reference'] <=> $a['reference']);
        $marchandises = $avecMarge ? $totaux['cout'] : 0.0;
        $budget = $ev['budget'] !== null && $ev['budget_devise_id'] !== null
            ? $this->rapports->enReference((float) $ev['budget'], (int) $ev['budget_devise_id']) : null;
        $resultat = [
            'ventes' => $ventes,
            'totaux' => $totaux,
            'depenses' => $depenses,
            'couts' => $couts,
            'categories_couts' => $parCategorie,
            'marchandises' => $marchandises,
            'resultat' => $totaux['ca_reference'] - $marchandises - $couts,
            'budget_reference' => $budget,
            'categories' => [],
            'heures' => [],
            'produits' => [],
        ];
        $resultat['marge_pct'] = $totaux['ca_reference'] > 0 ? $resultat['resultat'] / $totaux['ca_reference'] : null;
        if (!$detail || $ventes === []) {
            return $resultat;
        }
        // Recettes par catégorie du produit (bouteilles VIP, cocktails, bières…) et par heure de la nuit
        $ids = array_map(static fn (array $v) => $v['id'], array_filter($ventes, static fn (array $v) => $v['statut'] === 'payee'));
        if ($ids !== []) {
            $champ = I18n::langue() === 'en' ? 'en' : 'fr';
            $locale = $this->rapports->locale();
            // Lignes sans produit : entrées, vestiaire, minimum non atteint (migration 022), sinon « sans catégorie »
            $pdo = Database::pdo();
            $sansCategorie = RapportRepository::sansComposants('l') !== ''
                ? "CASE l.nature WHEN 'entree' THEN " . $pdo->quote(t('evenements.cat_entrees')) . " WHEN 'vestiaire' THEN " . $pdo->quote(t('evenements.cat_vestiaire'))
                    . " WHEN 'minimum' THEN " . $pdo->quote(t('evenements.cat_minimum')) . ' ELSE ' . $pdo->quote(t('evenements.sans_categorie')) . ' END'
                : $pdo->quote(t('evenements.sans_categorie'));
            $resultat['categories'] = Database::tous(
                'SELECT COALESCE(cp.id, c.id) AS id, COALESCE(cp.nom_' . $champ . ', c.nom_' . $champ . ', ' . $sansCategorie . ') AS nom,
                        SUM(l.quantite) AS quantite,
                        SUM(CASE WHEN v.devise_id = ? THEN l.montant ELSE COALESCE(l.prix_reference, 0) * l.quantite * ? END) AS locale,
                        SUM(COALESCE(l.prix_reference, 0) * l.quantite) AS reference
                   FROM vente_lignes l
                   JOIN ventes v ON v.id = l.vente_id
              LEFT JOIN conditionnements co ON co.id = l.conditionnement_id
              LEFT JOIN produits p ON p.id = COALESCE(co.produit_id, l.produit_id)
              LEFT JOIN categories c ON c.id = p.categorie_id
              LEFT JOIN categories cp ON cp.id = c.parent_id
                  WHERE l.vente_id IN (' . implode(',', array_map('intval', $ids)) . ')' . RapportRepository::sansComposants('l') . '
               GROUP BY COALESCE(cp.id, c.id), COALESCE(cp.nom_' . $champ . ', c.nom_' . $champ . ', ' . $sansCategorie . ')
               ORDER BY locale DESC',
                [(int) ($locale['id'] ?? 0), $this->rapports->tauxLocal()]
            );
            $produits = $this->rapports->produits($ventes);
            usort($produits, static fn (array $a, array $b) => $b['locale'] <=> $a['locale']);
            $resultat['produits'] = array_slice($produits, 0, 8);
        }
        // Heures de la soirée, de l'ouverture à la fermeture (au plus 24)
        $heure = (new \DateTimeImmutable($ev['debut']))->setTime((int) date('H', strtotime($ev['debut'])), 0);
        $fin = new \DateTimeImmutable($ev['fin']);
        while ($heure < $fin && count($resultat['heures']) < 24) {
            $resultat['heures'][$heure->format('Y-m-d H')] = RapportRepository::vide();
            $heure = $heure->modify('+1 hour');
        }
        foreach ($ventes as $v) {
            $cle = substr($v['cree_le'], 0, 13);
            if (isset($resultat['heures'][$cle])) {
                RapportRepository::ajouter($resultat['heures'][$cle], $v);
            }
        }
        $resultat['heures'] = array_map([RapportRepository::class, 'completer'], $resultat['heures']);
        return $resultat;
    }
}
