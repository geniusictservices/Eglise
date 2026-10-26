<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Journal;

/**
 * Vente en gros au dépôt (cahier des charges 2.1, phase 5) : dépôts qui vendent en gros, grille « Prix de gros »
 * (par casier, par carton), ventes en gros (enregistrées comme toute vente par VenteRepository::enregistrer,
 * avec leur dépôt et leur client revendeur) et emballages de chaque client (prêtés ou consigne facturée).
 */
final class VenteGrosRepository
{
    /** Grille « gros » (créée par la migration 021). */
    public function grille(): ?int
    {
        $g = StockRepository::suite() ? Database::un('SELECT id FROM grilles_prix WHERE code = \'gros\'') : null;
        return $g !== null ? (int) $g['id'] : null;
    }

    /** Dépôts d'établissement qui vendent en gros. @param ?list<int> $etablissements null = tous */
    public function depots(?array $etablissements): array
    {
        if (!StockRepository::suite() || $etablissements === []) {
            return [];
        }
        return Database::tous(
            'SELECT l.*, e.nom AS etablissement_nom FROM lieux_stock l JOIN etablissements e ON e.id = l.etablissement_id
              WHERE l.vente_gros = 1 AND l.actif = 1 AND l.type = \'depot\''
            . ($etablissements !== null ? ' AND l.etablissement_id IN (' . implode(',', array_map('intval', $etablissements)) . ')' : '') . '
           ORDER BY e.position, e.id, l.position, l.nom'
        );
    }

    /**
     * Prix de gros par format et devise.
     * @return array<int, array<int, float>> conditionnement => devise => montant
     */
    public function prix(): array
    {
        $grille = $this->grille();
        $resultat = [];
        if ($grille === null) {
            return [];
        }
        foreach (Database::tous('SELECT conditionnement_id, devise_id, montant FROM prix WHERE grille_prix_id = ?', [$grille]) as $p) {
            $resultat[(int) $p['conditionnement_id']][(int) $p['devise_id']] = (float) $p['montant'];
        }
        return $resultat;
    }

    /** @param array<int, array<int, ?float>> $prix conditionnement => devise => montant (null : retiré) */
    public function definirPrix(array $prix, int $utilisateurId): int
    {
        $grille = $this->grille();
        if ($grille === null) {
            return 0;
        }
        $n = 0;
        (new GestionTresorerieRepository())->transaction(function () use ($prix, $grille, &$n): void {
            foreach ($prix as $conditionnementId => $parDevise) {
                foreach ($parDevise as $deviseId => $montant) {
                    if ($montant === null) {
                        Database::executer('DELETE FROM prix WHERE conditionnement_id = ? AND grille_prix_id = ? AND devise_id = ?', [$conditionnementId, $grille, $deviseId]);
                        continue;
                    }
                    Database::executer(
                        'INSERT INTO prix (conditionnement_id, grille_prix_id, devise_id, montant) VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE montant = VALUES(montant)',
                        [$conditionnementId, $grille, $deviseId, number_format($montant, 2, '.', '')]
                    );
                    $n++;
                }
            }
        });
        Journal::ecrire('ventes_prix_gros', (string) $n, [], $utilisateurId);
        return $n;
    }

    /** Ventes en gros de la période (dépôts de ces établissements). @param ?list<int> $etablissements */
    public function ventes(string $debut, string $fin, ?array $etablissements, ?int $clientId = null): array
    {
        if (!StockRepository::suite() || $etablissements === []) {
            return [];
        }
        $conditions = ['v.cree_le BETWEEN ? AND ?'];
        $params = [$debut, $fin];
        if ($etablissements !== null) {
            $conditions[] = 'v.etablissement_id IN (' . implode(',', array_map('intval', $etablissements)) . ')';
        }
        if ($clientId !== null) {
            $conditions[] = 'g.client_id = ?';
            $params[] = $clientId;
        }
        return Database::tous(
            'SELECT v.id, v.numero, v.total, v.devise_id, v.statut, v.cree_le, g.lieu_id, g.client_id, g.consigne_facturee, g.notes,
                    l.nom AS lieu_nom, e.nom AS etablissement_nom, c.nom AS client_nom, u.nom AS vendeur,
                    (SELECT COALESCE(SUM(p.recu), 0) FROM vente_paiements p WHERE p.vente_id = v.id) AS paye,
                    (SELECT COALESCE(SUM(d.montant), 0) FROM dettes d WHERE d.vente_id = v.id) AS credit,
                    (SELECT COALESCE(SUM(l2.quantite), 0) FROM vente_lignes l2 WHERE l2.vente_id = v.id AND l2.produit_id IS NOT NULL) AS articles
               FROM ventes_gros g
               JOIN ventes v ON v.id = g.vente_id
               JOIN lieux_stock l ON l.id = g.lieu_id
               JOIN etablissements e ON e.id = v.etablissement_id
          LEFT JOIN clients c ON c.id = g.client_id
          LEFT JOIN utilisateurs u ON u.id = v.utilisateur_id
              WHERE ' . implode(' AND ', $conditions) . '
           ORDER BY v.cree_le DESC, v.id DESC LIMIT 300',
            $params
        );
    }

    /** Clients qui achètent en gros : les revendeurs d'abord, puis les autres (sauf le personnel). */
    public function clients(): array
    {
        return Database::tous(
            'SELECT id, nom, telephone, type, bloque FROM clients WHERE actif = 1 AND type <> \'personnel\' ORDER BY type <> \'revendeur\', nom'
        );
    }

    /**
     * Soldes des clients revendeurs : emballages prêtés et consignés (par emballage), dettes en cours (par devise).
     * @return array<int, array{nom: string, pretes: array<int, float>, consignes: array<int, float>, dettes: array<int, float>}>
     */
    public function soldes(): array
    {
        if (!StockRepository::suite()) {
            return [];
        }
        $resultat = [];
        foreach (Database::tous(
            'SELECT c.id, c.nom, m.produit_id, m.type = \'consigne_facturee\' AS consigne, SUM(m.quantite) AS q
               FROM emballage_mouvements m JOIN clients c ON c.id = m.client_id
              WHERE c.type = \'revendeur\' OR EXISTS (SELECT 1 FROM ventes_gros g WHERE g.client_id = c.id)
           GROUP BY c.id, c.nom, m.produit_id, m.type = \'consigne_facturee\' HAVING ABS(SUM(m.quantite)) > 0.0005 ORDER BY c.nom'
        ) as $l) {
            $id = (int) $l['id'];
            $resultat[$id] ??= ['nom' => $l['nom'], 'pretes' => [], 'consignes' => [], 'dettes' => []];
            $resultat[$id][$l['consigne'] ? 'consignes' : 'pretes'][(int) $l['produit_id']] = (float) $l['q'];
        }
        foreach (Database::tous(
            'SELECT c.id, c.nom, d.devise_id, SUM(d.reste) AS reste FROM dettes d JOIN clients c ON c.id = d.client_id
              WHERE d.reste > 0 AND d.vente_id IN (SELECT vente_id FROM ventes_gros) GROUP BY c.id, c.nom, d.devise_id'
        ) as $d) {
            $id = (int) $d['id'];
            $resultat[$id] ??= ['nom' => $d['nom'], 'pretes' => [], 'consignes' => [], 'dettes' => []];
            $resultat[$id]['dettes'][(int) $d['devise_id']] = (float) $d['reste'];
        }
        return $resultat;
    }

    /**
     * Après l'enregistrement de la vente (dans sa transaction) : la vente en gros, et les emballages du client.
     * @param list<array{produit_id: int, type: string, quantite: float, valeur: ?float, devise_id: ?int}> $emballages
     *        quantite signée : + prêtés / consignés au client, − rapportés
     */
    public function rattacher(int $venteId, int $lieuId, int $clientId, bool $consigneFacturee, ?string $notes, array $emballages, string $date, int $utilisateurId): void
    {
        Database::executer(
            'INSERT INTO ventes_gros (vente_id, lieu_id, client_id, consigne_facturee, notes) VALUES (?, ?, ?, ?, ?)',
            [$venteId, $lieuId, $clientId, (int) $consigneFacturee, $notes]
        );
        foreach ($emballages as $e) {
            if (abs($e['quantite']) < 0.0005) {
                continue;
            }
            Database::executer(
                'INSERT INTO emballage_mouvements (client_id, produit_id, type, quantite, vente_id, valeur, devise_id, date_mouvement, notes, utilisateur_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$clientId, $e['produit_id'], $e['type'], StockRepository::q($e['quantite']), $venteId,
                 $e['valeur'] !== null ? number_format($e['valeur'], 3, '.', '') : null, $e['devise_id'], $date, null, $utilisateurId]
            );
        }
        Journal::ecrire('vente_gros', (string) $venteId, ['lieu' => $lieuId, 'client' => $clientId], $utilisateurId);
    }
}
