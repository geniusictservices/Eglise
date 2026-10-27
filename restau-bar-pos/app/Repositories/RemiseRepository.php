<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Acces;
use App\Core\Database;
use App\Core\Journal;

/**
 * Remises et offerts (cahier des charges, section 6.2) : limite de chaque rôle (remise maximale en %, au-delà le PIN
 * d'un supérieur), remises enregistrées avec la vente, offerts comptés comme une perte dans le stock, rapport.
 */
final class RemiseRepository
{
    private static ?bool $disponible = null;

    /** La migration 022 est-elle appliquée ? */
    public static function disponible(): bool
    {
        return self::$disponible ??= RapportRepository::tableExiste('vente_remises');
    }

    /**
     * Part maximale (0 à 1) qu'une personne accorde seule dans un établissement ; null = sans limite.
     * Sans « caisse.remise » : 0 (toute remise demande un supérieur). Plusieurs rôles : la plus haute limite.
     */
    public static function limite(int $utilisateurId, int $etabId): ?float
    {
        if (!Acces::a($utilisateurId, 'caisse.remise', $etabId)) {
            return 0.0;
        }
        if (Acces::droits($utilisateurId)['tout']) {
            return null;
        }
        if (!self::disponible()) {
            return null;
        }
        $roles = Database::tous(
            "SELECT r.tout, r.remise_max FROM utilisateur_roles ur
               JOIN roles r ON r.id = ur.role_id
          LEFT JOIN role_permissions rp ON rp.role_id = r.id AND rp.permission = 'caisse.remise'
              WHERE ur.utilisateur_id = ? AND (ur.etablissement_id IS NULL OR ur.etablissement_id = ?) AND (r.tout = 1 OR rp.permission IS NOT NULL)",
            [$utilisateurId, $etabId]
        );
        $max = 0.0;
        foreach ($roles as $r) {
            if ((int) $r['tout'] === 1 || $r['remise_max'] === null) {
                return null;
            }
            $max = max($max, (float) $r['remise_max'] / 100);
        }
        return $max;
    }

    /** La personne peut-elle accorder cette part seule ? */
    public static function permise(int $utilisateurId, int $etabId, float $part): bool
    {
        $limite = self::limite($utilisateurId, $etabId);
        return $limite === null || $part <= $limite + 1e-6;
    }

    /** Rôles qui ont « caisse.remise », avec leur limite (écran des remises). */
    public function roles(): array
    {
        return Database::tous(
            "SELECT r.id, r.code, r.nom_fr, r.nom_en, r.tout, r.remise_max
               FROM roles r LEFT JOIN role_permissions rp ON rp.role_id = r.id AND rp.permission = 'caisse.remise'
              WHERE r.tout = 1 OR rp.permission IS NOT NULL ORDER BY r.position, r.id"
        );
    }

    public function definirLimite(int $roleId, ?float $pourcent, int $par): void
    {
        $role = Database::un('SELECT code, tout FROM roles WHERE id = ?', [$roleId]);
        if ($role === null || (int) $role['tout'] === 1) {
            return;
        }
        Database::executer('UPDATE roles SET remise_max = ? WHERE id = ?', [$pourcent !== null ? number_format($pourcent, 2, '.', '') : null, $roleId]);
        Journal::ecrire('remise_limite', $role['code'], ['pourcent' => $pourcent], $par);
    }

    /**
     * Remises d'une période (rapport), avec la vente, la ligne, qui a accordé et qui a validé.
     * @param array{debut: string, fin: string, etablissement: ?int, motif: ?string} $f
     */
    public function liste(array $f, int $utilisateurId): array
    {
        [$portee, $params] = RapportRepository::portee($utilisateurId, 'ventes.voir', 'v.etablissement_id');
        $where = $portee . ' AND r.cree_le >= ? AND r.cree_le < ?';
        $params = array_merge($params, [$f['debut'], $f['fin']]);
        if (!empty($f['etablissement'])) {
            $where .= ' AND v.etablissement_id = ?';
            $params[] = (int) $f['etablissement'];
        }
        if (!empty($f['motif'])) {
            $where .= ' AND r.motif = ?';
            $params[] = $f['motif'];
        }
        return Database::tous(
            'SELECT r.*, v.numero, v.statut AS vente_statut, v.devise_id AS ticket_devise_id, v.etablissement_id, e.nom AS etablissement_nom,
                    l.libelle, l.quantite, a.nom AS accorde_nom, va.nom AS valide_nom
               FROM vente_remises r
               JOIN ventes v ON v.id = r.vente_id
               JOIN etablissements e ON e.id = v.etablissement_id
          LEFT JOIN vente_lignes l ON l.id = r.vente_ligne_id
          LEFT JOIN utilisateurs a ON a.id = r.accorde_par
          LEFT JOIN utilisateurs va ON va.id = r.valide_par
              WHERE ' . $where . ' ORDER BY r.cree_le DESC, r.id DESC LIMIT 500',
            $params
        );
    }

    /**
     * Ventes payées de la période : total brut, remises, offerts et net, par devise du ticket.
     * @return array<int, array{brut: float, remises: float, offerts: float, net: float, tickets: int, remises_n: int}>
     */
    public function totaux(array $f, int $utilisateurId): array
    {
        [$portee, $params] = RapportRepository::portee($utilisateurId, 'ventes.voir', 'v.etablissement_id');
        $where = $portee . " AND v.statut = 'payee' AND v.cree_le >= ? AND v.cree_le < ?";
        $params = array_merge($params, [$f['debut'], $f['fin']]);
        if (!empty($f['etablissement'])) {
            $where .= ' AND v.etablissement_id = ?';
            $params[] = (int) $f['etablissement'];
        }
        $lignes = Database::tous(
            'SELECT v.devise_id, COUNT(*) AS tickets, SUM(COALESCE(v.brut, v.total)) AS brut, SUM(v.remise) AS remises, SUM(v.total) AS net,
                    SUM(v.remise > 0) AS avec_remise,
                    COALESCE(SUM((SELECT SUM(l.remise) FROM vente_lignes l WHERE l.vente_id = v.id AND l.offert = 1)), 0) AS offerts
               FROM ventes v WHERE ' . $where . ' GROUP BY v.devise_id',
            $params
        );
        $totaux = [];
        foreach ($lignes as $l) {
            $totaux[(int) $l['devise_id']] = [
                'brut' => (float) $l['brut'], 'remises' => (float) $l['remises'], 'offerts' => (float) $l['offerts'],
                'net' => (float) $l['net'], 'tickets' => (int) $l['tickets'], 'remises_n' => (int) $l['avec_remise'],
            ];
        }
        return $totaux;
    }

    /**
     * Lignes offertes d'une vente : perte dans le stock (motif « offert ») au comptoir qui les sert, au lieu d'une
     * sortie de vente ; une fiche technique perd ses ingrédients. Après la vente, sans jamais la bloquer.
     */
    public static function pertesOffertes(int $venteId, int $par): void
    {
        if (!self::disponible() || !method_exists(GestionStockRepository::class, 'creerPerte')) {
            return;
        }
        try {
            $vente = Database::un('SELECT id, numero, etablissement_id, zone_id, appareil_id, cree_le FROM ventes WHERE id = ?', [$venteId]);
            if ($vente === null) {
                return;
            }
            $lignes = Database::tous(
                "SELECT l.*, c.produit_id AS cond_produit, c.quantite_base, p.categorie_id
                   FROM vente_lignes l
              LEFT JOIN conditionnements c ON c.id = l.conditionnement_id
              LEFT JOIN produits p ON p.id = COALESCE(c.produit_id, l.produit_id)
                  WHERE l.vente_id = ? AND l.offert = 1 AND l.nature IN ('article', 'composant') ORDER BY l.id",
                [$venteId]
            );
            if ($lignes === []) {
                return;
            }
            $stock = new StockRepository();
            $gestion = new GestionStockRepository($stock);
            $valideur = Database::un('SELECT u.nom FROM vente_remises r JOIN utilisateurs u ON u.id = COALESCE(r.valide_par, r.accorde_par) WHERE r.vente_id = ? ORDER BY r.id LIMIT 1', [$venteId]);
            foreach ($lignes as $l) {
                $produitId = (int) ($l['cond_produit'] ?? $l['produit_id']);
                if ($produitId === 0) {
                    continue;
                }
                $lieu = $stock->comptoirPour((int) $vente['etablissement_id'], $l['categorie_id'] !== null ? (int) $l['categorie_id'] : null,
                    $vente['zone_id'] !== null ? (int) $vente['zone_id'] : null, $vente['appareil_id'] !== null ? (int) $vente['appareil_id'] : null);
                $base = (float) $l['quantite'] * ($l['quantite_base'] !== null ? (float) $l['quantite_base'] : 1.0);
                $commentaire = mb_substr(t('nuit.perte_offert', ['article' => $l['libelle'], 'vente' => $vente['numero'], 'par' => $valideur['nom'] ?? '']), 0, 255);
                $recette = $stock->recetteActive($produitId);
                if ($recette !== null) {
                    foreach ($stock->consommation($recette, $base) as $ingredient => $quantite) {
                        $gestion->creerPerte(['lieu_id' => $lieu, 'produit_id' => $ingredient, 'conditionnement_id' => null, 'quantite' => $quantite,
                            'quantite_base' => $quantite, 'motif' => 'offert', 'commentaire' => $commentaire, 'date' => $vente['cree_le']], $par);
                    }
                } else {
                    $gestion->creerPerte(['lieu_id' => $lieu, 'produit_id' => $produitId,
                        'conditionnement_id' => $l['cond_produit'] !== null ? (int) $l['conditionnement_id'] : null, 'quantite' => (float) $l['quantite'],
                        'quantite_base' => $base, 'motif' => 'offert', 'commentaire' => $commentaire, 'date' => $vente['cree_le']], $par);
                }
            }
        } catch (\Throwable $e) {
            error_log('Perte non enregistrée pour un offert : ' . $e->getMessage());
        }
    }
}
