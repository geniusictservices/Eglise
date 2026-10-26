<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Acces;
use App\Core\Database;
use App\Core\Journal;

/**
 * Ventes dans l'espace de gestion : liste filtrée et paginée avec ses totaux, fiche d'une vente,
 * annulation, et rapport « ventes par vendeur ».
 *
 * La portée est appliquée ici, dans chaque requête, et pas seulement dans l'écran : une personne
 * limitée à un établissement ne lit que les ventes des établissements où elle a la permission
 * (ventes.voir pour la liste et la fiche, rapports.voir pour le rapport) et n'annule que là où
 * elle a ventes.annuler.
 */
final class GestionVenteRepository
{
    public const PAR_PAGE = 50;
    public const MOYENS = ['especes', 'mobile_money', 'banque'];
    public const STATUTS = ['payee', 'annulee'];

    private GestionTresorerieRepository $tresorerie;

    public function __construct(?GestionTresorerieRepository $tresorerie = null)
    {
        $this->tresorerie = $tresorerie ?? new GestionTresorerieRepository();
    }

    // ---------- Portée ----------

    /**
     * Condition SQL limitant v.etablissement_id aux établissements où la personne a la permission.
     * @return array{0: string, 1: list<int>}
     */
    private function portee(int $utilisateurId, string $permission): array
    {
        if (!Acces::a($utilisateurId, $permission)) {
            return ['0 = 1', []];
        }
        $ids = Acces::etablissements($utilisateurId, $permission);
        if ($ids === null) {
            return ['1 = 1', []];
        }
        if ($ids === []) {
            return ['0 = 1', []];
        }
        return ['v.etablissement_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')', array_map('intval', $ids)];
    }

    /** Établissements que la personne peut choisir dans les filtres (permission donnée). */
    public function etablissements(int $utilisateurId, string $permission): array
    {
        $ids = Acces::etablissements($utilisateurId, $permission);
        $tous = Database::tous('SELECT id, nom, slug, type FROM etablissements ORDER BY nom');
        if (!Acces::a($utilisateurId, $permission)) {
            return [];
        }
        return array_values(array_filter($tous, static fn (array $e) => $ids === null || in_array((int) $e['id'], $ids, true)));
    }

    /**
     * Conditions communes : période, établissement, vendeur, moyen, statut, portée.
     * @param array{debut: string, fin: string, etablissement?: ?int, vendeur?: ?int, moyen?: ?string, statut?: ?string} $filtres
     * @return array{0: string, 1: list<mixed>}
     */
    private function conditions(array $filtres, int $utilisateurId, string $permission): array
    {
        [$portee, $params] = $this->portee($utilisateurId, $permission);
        $conditions = [$portee, 'v.cree_le BETWEEN ? AND ?'];
        $params[] = $filtres['debut'];
        $params[] = $filtres['fin'];
        if (!empty($filtres['etablissement'])) {
            $conditions[] = 'v.etablissement_id = ?';
            $params[] = (int) $filtres['etablissement'];
        }
        if (!empty($filtres['vendeur'])) {
            $conditions[] = 'v.utilisateur_id = ?';
            $params[] = (int) $filtres['vendeur'];
        }
        if (!empty($filtres['moyen']) && in_array($filtres['moyen'], self::MOYENS, true)) {
            $conditions[] = 'EXISTS (SELECT 1 FROM vente_paiements fp WHERE fp.vente_id = v.id AND fp.moyen = ?)';
            $params[] = $filtres['moyen'];
        }
        if (!empty($filtres['statut']) && in_array($filtres['statut'], self::STATUTS, true)) {
            $conditions[] = 'v.statut = ?';
            $params[] = $filtres['statut'];
        }
        return [implode(' AND ', $conditions), $params];
    }

    // ---------- Liste ----------

    /**
     * Ventes filtrées, les plus récentes d'abord, une page à la fois.
     * @return array{ventes: list<array>, total: int}
     */
    public function liste(array $filtres, int $utilisateurId, int $page): array
    {
        [$where, $params] = $this->conditions($filtres, $utilisateurId, 'ventes.voir');
        $total = (int) (Database::un('SELECT COUNT(*) AS n FROM ventes v WHERE ' . $where, $params)['n'] ?? 0);
        $decalage = max(0, ($page - 1) * self::PAR_PAGE);
        $ventes = Database::tous(
            'SELECT v.id, v.numero, v.cree_le, v.etablissement_id, v.devise_id, v.total, v.statut, v.annulee_le,
                    e.nom AS etablissement_nom, u.nom AS vendeur, u.avatar AS vendeur_avatar,
                    (SELECT COALESCE(SUM(l.quantite), 0) FROM vente_lignes l WHERE l.vente_id = v.id) AS articles,
                    (SELECT GROUP_CONCAT(DISTINCT p.moyen ORDER BY FIELD(p.moyen, \'especes\', \'mobile_money\', \'banque\'))
                       FROM vente_paiements p WHERE p.vente_id = v.id) AS moyens
               FROM ventes v
               JOIN etablissements e ON e.id = v.etablissement_id
          LEFT JOIN utilisateurs u ON u.id = v.utilisateur_id
              WHERE ' . $where . '
           ORDER BY v.cree_le DESC, v.id DESC
              LIMIT ' . self::PAR_PAGE . ' OFFSET ' . $decalage,
            $params
        );
        foreach ($ventes as &$vente) {
            $vente['moyens'] = $vente['moyens'] !== null && $vente['moyens'] !== '' ? explode(',', $vente['moyens']) : [];
        }
        unset($vente);
        return ['ventes' => $ventes, 'total' => $total];
    }

    /**
     * Totaux des ventes payées qui répondent aux filtres (le filtre « statut » est ignoré : une vente
     * annulée ne compte jamais) : nombre de tickets et d'articles, total des tickets par devise,
     * encaissé par devise et par moyen (paiements reçus moins la monnaie rendue), équivalent en
     * devise de référence (contre-valeur encaissée), et nombre de ventes annulées sur les mêmes filtres.
     *
     * @return array{tickets: int, articles: int, parDevise: array<int, float>, encaisse: array<int, array<string, float>>,
     *               reference: float, annulees: int}
     */
    public function totaux(array $filtres, int $utilisateurId): array
    {
        [$where, $params] = $this->conditions(['statut' => 'payee'] + $filtres, $utilisateurId, 'ventes.voir');
        [$whereAnnulees, $paramsAnnulees] = $this->conditions(['statut' => 'annulee'] + $filtres, $utilisateurId, 'ventes.voir');
        $where .= ' AND v.statut = \'payee\'';

        $ligne = Database::un(
            'SELECT COUNT(*) AS tickets, COALESCE(SUM(v.encaisse_reference), 0) AS reference FROM ventes v WHERE ' . $where,
            $params
        );
        $articles = Database::un(
            'SELECT COALESCE(SUM(l.quantite), 0) AS n FROM vente_lignes l JOIN ventes v ON v.id = l.vente_id WHERE ' . $where,
            $params
        );
        $parDevise = [];
        foreach (Database::tous('SELECT v.devise_id, SUM(v.total) AS total FROM ventes v WHERE ' . $where . ' GROUP BY v.devise_id', $params) as $l) {
            $parDevise[(int) $l['devise_id']] = (float) $l['total'];
        }
        $encaisse = [];
        foreach (Database::tous(
            'SELECT p.devise_id, p.moyen, SUM(p.recu) AS montant FROM vente_paiements p JOIN ventes v ON v.id = p.vente_id
              WHERE ' . $where . ' GROUP BY p.devise_id, p.moyen',
            $params
        ) as $l) {
            $encaisse[(int) $l['devise_id']][$l['moyen']] = (float) $l['montant'];
        }
        // La monnaie rendue sort du compte qui l'a donnée (toujours des espèces à la caisse)
        foreach (Database::tous(
            'SELECT v.rendu_devise_id AS devise_id, c.type AS moyen, SUM(v.rendu) AS montant
               FROM ventes v JOIN comptes_tresorerie c ON c.id = v.rendu_compte_id
              WHERE ' . $where . ' AND v.rendu > 0 GROUP BY v.rendu_devise_id, c.type',
            $params
        ) as $l) {
            $deviseId = (int) $l['devise_id'];
            $encaisse[$deviseId][$l['moyen']] = ($encaisse[$deviseId][$l['moyen']] ?? 0.0) - (float) $l['montant'];
        }
        $annulees = Database::un('SELECT COUNT(*) AS n FROM ventes v WHERE ' . $whereAnnulees, $paramsAnnulees);

        return [
            'tickets' => (int) $ligne['tickets'],
            'articles' => (int) $articles['n'],
            'parDevise' => $parDevise,
            'encaisse' => $encaisse,
            'reference' => (float) $ligne['reference'],
            'annulees' => (int) $annulees['n'],
        ];
    }

    /** Personnes qui ont vendu dans les établissements visibles (filtre « vendeur »). */
    public function vendeurs(int $utilisateurId): array
    {
        [$portee, $params] = $this->portee($utilisateurId, 'ventes.voir');
        return Database::tous(
            'SELECT u.id, u.nom FROM utilisateurs u
              WHERE EXISTS (SELECT 1 FROM ventes v WHERE v.utilisateur_id = u.id AND ' . $portee . ')
              ORDER BY u.nom',
            $params
        );
    }

    // ---------- Fiche ----------

    /**
     * Tout pour la fiche d'une vente visible par la personne : le reçu (VenteRepository::recu()),
     * l'appareil, la photo du vendeur, qui a annulé, le type de chaque compte et les mouvements
     * de trésorerie de la vente. null si la vente n'existe pas ou n'est pas dans sa portée.
     */
    public function fiche(int $id, int $utilisateurId): ?array
    {
        [$portee, $params] = $this->portee($utilisateurId, 'ventes.voir');
        $extra = Database::un(
            'SELECT a.nom AS appareil, u.avatar AS vendeur_avatar, ua.nom AS annulee_par_nom, g.nom_fr AS grille_nom_fr, g.nom_en AS grille_nom_en
               FROM ventes v
          LEFT JOIN appareils_caisse a ON a.id = v.appareil_id
          LEFT JOIN utilisateurs u ON u.id = v.utilisateur_id
          LEFT JOIN utilisateurs ua ON ua.id = v.annulee_par
          LEFT JOIN grilles_prix g ON g.id = v.grille_prix_id
              WHERE v.id = ? AND ' . $portee,
            array_merge([$id], $params)
        );
        if ($extra === null) {
            return null;
        }
        $recu = (new VenteRepository())->recu($id);
        if ($recu === null) {
            return null;
        }
        $types = array_column(Database::tous(
            'SELECT c.id, c.type FROM comptes_tresorerie c JOIN vente_paiements p ON p.compte_id = c.id WHERE p.vente_id = ?',
            [$id]
        ), 'type', 'id');
        foreach ($recu['paiements'] as &$paiement) {
            $paiement['compte_type'] = $types[(int) $paiement['compte_id']] ?? $paiement['moyen'];
        }
        unset($paiement);
        $recu['extra'] = $extra;
        $recu['mouvements'] = Database::tous(
            'SELECT m.*, c.nom AS compte_nom, c.type AS compte_type
               FROM mouvements_tresorerie m JOIN comptes_tresorerie c ON c.id = m.compte_id
              WHERE m.nature = \'vente\' AND m.source_id = ?
           ORDER BY m.date_operation, m.id',
            [$id]
        );
        return $recu;
    }

    /** La personne peut-elle annuler cette vente (payée, et ventes.annuler pour son établissement) ? */
    public function peutAnnuler(array $vente, int $utilisateurId): bool
    {
        return $vente['statut'] === 'payee' && Acces::a($utilisateurId, 'ventes.annuler', (int) $vente['etablissement_id']);
    }

    /**
     * Annule une vente payée, en une transaction : statut, date, auteur et motif, puis une contre-écriture
     * de chaque mouvement de trésorerie de la vente (sortie pour chaque paiement reçu, entrée pour la
     * monnaie rendue), datée de l'annulation. Les mouvements d'origine restent : le journal de chaque
     * compte garde la vente le jour de la vente et le remboursement le jour de l'annulation, et les
     * soldes reviennent exactement à ce qu'ils étaient sans la vente.
     *
     * @return string 'annulee', 'introuvable' (pas dans la portée), 'interdit' (pas ventes.annuler ici), 'deja' (déjà annulée)
     */
    public function annuler(int $id, int $utilisateurId, string $motif): string
    {
        [$portee, $params] = $this->portee($utilisateurId, 'ventes.voir');
        return $this->tresorerie->transaction(function () use ($id, $utilisateurId, $motif, $portee, $params): string {
            // Verrou de la ligne : deux annulations simultanées ne font qu'une contre-écriture
            $vente = Database::un('SELECT v.* FROM ventes v WHERE v.id = ? AND ' . $portee . ' FOR UPDATE', array_merge([$id], $params));
            if ($vente === null) {
                return 'introuvable';
            }
            if (!Acces::a($utilisateurId, 'ventes.annuler', (int) $vente['etablissement_id'])) {
                return 'interdit';
            }
            if ($vente['statut'] !== 'payee') {
                return 'deja';
            }
            $maintenant = date('Y-m-d H:i:s');
            $mouvements = Database::tous(
                'SELECT * FROM mouvements_tresorerie WHERE nature = \'vente\' AND source_id = ? ORDER BY id',
                [$id]
            );
            Database::executer(
                'UPDATE ventes SET statut = \'annulee\', annulee_le = ?, annulee_par = ?, motif_annulation = ? WHERE id = ? AND statut = \'payee\'',
                [$maintenant, $utilisateurId, mb_substr($motif, 0, 255), $id]
            );
            $libelle = 'Annulation vente ' . $vente['numero'];
            foreach ($mouvements as $m) {
                $this->tresorerie->ajouterMouvement(
                    ['id' => (int) $m['compte_id'], 'devise_id' => (int) $m['devise_id']],
                    $m['sens'] === 'entree' ? 'sortie' : 'entree',
                    (float) $m['montant'], 'vente', $id, $maintenant, $libelle, $utilisateurId
                );
            }
            Journal::ecrire('vente_annulee', $vente['numero'], ['motif' => mb_substr($motif, 0, 255)], $utilisateurId);
            return 'annulee';
        });
    }

    // ---------- Rapport : ventes par vendeur ----------

    /**
     * Classement des vendeurs sur une période (ventes payées) : tickets, articles, total dans la devise
     * locale (totaux de chaque ticket dans cette devise), équivalent encaissé en devise de référence.
     * Rangé par total décroissant.
     * @return list<array{utilisateur_id: ?int, nom: ?string, avatar: ?string, tickets: int, articles: int, total: float, reference: float}>
     */
    public function rapportVendeurs(array $filtres, int $utilisateurId, int $localeId): array
    {
        [$where, $params] = $this->conditions(['statut' => 'payee', 'vendeur' => null, 'moyen' => null] + $filtres, $utilisateurId, 'rapports.voir');
        $lignes = Database::tous(
            'SELECT v.utilisateur_id, u.nom, u.avatar, COUNT(*) AS tickets,
                    COALESCE(SUM((SELECT SUM(l.quantite) FROM vente_lignes l WHERE l.vente_id = v.id)), 0) AS articles,
                    COALESCE(SUM(vt.total), 0) AS total,
                    COALESCE(SUM(v.encaisse_reference), 0) AS reference
               FROM ventes v
          LEFT JOIN utilisateurs u ON u.id = v.utilisateur_id
          LEFT JOIN vente_totaux vt ON vt.vente_id = v.id AND vt.devise_id = ?
              WHERE ' . $where . '
           GROUP BY v.utilisateur_id, u.nom, u.avatar
           ORDER BY total DESC, tickets DESC, u.nom',
            array_merge([$localeId], $params)
        );
        return array_map(static fn (array $l): array => [
            'utilisateur_id' => $l['utilisateur_id'] !== null ? (int) $l['utilisateur_id'] : null,
            'nom' => $l['nom'],
            'avatar' => $l['avatar'],
            'tickets' => (int) $l['tickets'],
            'articles' => (int) $l['articles'],
            'total' => (float) $l['total'],
            'reference' => (float) $l['reference'],
        ], $lignes);
    }

    /**
     * Produits les plus vendus de la période (ventes payées) : quantité et montant dans la devise du ticket.
     * Une ligne par article tel que vendu (taille et format compris).
     */
    public function topProduits(array $filtres, int $utilisateurId, int $limite = 10): array
    {
        [$where, $params] = $this->conditions(['statut' => 'payee', 'vendeur' => null, 'moyen' => null] + $filtres, $utilisateurId, 'rapports.voir');
        return Database::tous(
            'SELECT l.produit_id, l.conditionnement_id, l.libelle, l.format, v.devise_id,
                    SUM(l.quantite) AS quantite, SUM(l.montant) AS montant
               FROM vente_lignes l JOIN ventes v ON v.id = l.vente_id
              WHERE ' . $where . '
           GROUP BY l.produit_id, l.conditionnement_id, l.libelle, l.format, v.devise_id
           ORDER BY quantite DESC, montant DESC, l.libelle
              LIMIT ' . max(1, $limite),
            $params
        );
    }
}
