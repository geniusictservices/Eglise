<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Suivi des coûts (cahier des charges 8.4), en devise de référence :
 *  - coût matière de chaque produit vendu (ses ingrédients, ou lui-même, au coût moyen du moment de la vente),
 *    part du prix de vente, marge : rentabilité par plat ;
 *  - écart entre la consommation attendue (ventes × fiches techniques, productions) et la consommation réelle
 *    (avec les pertes déclarées et les écarts constatés aux inventaires), par produit et par période.
 */
final class RapportCoutsRepository
{
    /**
     * Rentabilité des produits vendus sur la période : ventes payées (pas les annulées).
     * @param ?list<int> $etablissements null = tous
     * @return list<array{produit_id: int, nom_fr: string, nom_en: string, unite_base: string, recette: bool, quantite: float, ca: ?float,
     *                    cout: float, sans_cout: int, marge: ?float, part_cout: ?float, taux_marge: ?float, gros: float}>
     */
    public function rentabilite(string $debut, string $fin, ?array $etablissements): array
    {
        [$portee, $params] = self::portee($etablissements, 'v.etablissement_id');
        $ventes = Database::tous(
            'SELECT COALESCE(c.produit_id, l.produit_id) AS produit_id,
                    SUM(l.quantite * COALESCE(c.quantite_base, 1)) AS quantite,
                    SUM(IF(v.total_reference IS NULL OR v.total = 0, NULL, l.montant * v.total_reference / v.total)) AS ca,
                    SUM(v.total_reference IS NULL AND v.total <> 0) AS sans_reference
               FROM vente_lignes l
               JOIN ventes v ON v.id = l.vente_id
          LEFT JOIN conditionnements c ON c.id = l.conditionnement_id
              WHERE v.statut = \'payee\' AND v.cree_le BETWEEN ? AND ? AND ' . $portee . '
                AND COALESCE(c.produit_id, l.produit_id) IS NOT NULL
           GROUP BY COALESCE(c.produit_id, l.produit_id)',
            array_merge([$debut, $fin], $params)
        );
        if ($ventes === []) {
            return [];
        }
        $suite = StockRepository::suite();
        $gros = [];
        if ($suite) {
            foreach (Database::tous(
                'SELECT COALESCE(c.produit_id, l.produit_id) AS produit_id, SUM(l.quantite * COALESCE(c.quantite_base, 1)) AS q
                   FROM vente_lignes l JOIN ventes v ON v.id = l.vente_id JOIN ventes_gros g ON g.vente_id = v.id
              LEFT JOIN conditionnements c ON c.id = l.conditionnement_id
                  WHERE v.statut = \'payee\' AND v.cree_le BETWEEN ? AND ? AND ' . $portee . ' GROUP BY 1',
                array_merge([$debut, $fin], $params)
            ) as $g) {
                $gros[(int) $g['produit_id']] = (float) $g['q'];
            }
        }
        $couts = [];
        foreach (Database::tous(
            'SELECT ' . ($suite ? 'COALESCE(m.origine_produit_id, m.produit_id)' : 'm.produit_id') . ' AS produit_id,
                    SUM(IF(m.cout_unitaire IS NULL, 0, -m.quantite * m.cout_unitaire)) AS cout, SUM(m.cout_unitaire IS NULL) AS sans_cout
               FROM stock_mouvements m
               JOIN ventes v ON v.id = m.source_id
              WHERE m.type IN (\'vente\', \'recette\') AND v.statut = \'payee\' AND v.cree_le BETWEEN ? AND ? AND ' . $portee . '
           GROUP BY 1',
            array_merge([$debut, $fin], $params)
        ) as $c) {
            $couts[(int) $c['produit_id']] = ['cout' => (float) $c['cout'], 'sans_cout' => (int) $c['sans_cout']];
        }
        $produits = [];
        foreach (Database::tous(
            'SELECT p.id, p.nom_fr, p.nom_en, p.unite_base, (SELECT COUNT(*) FROM recettes r WHERE r.produit_id = p.id AND r.actif = 1' . ($suite ? ' AND r.preparation = 0' : '') . ') AS recette
               FROM produits p WHERE p.id IN (' . implode(',', array_map(static fn (array $v): int => (int) $v['produit_id'], $ventes)) . ')'
        ) as $p) {
            $produits[(int) $p['id']] = $p;
        }
        $resultat = [];
        foreach ($ventes as $v) {
            $id = (int) $v['produit_id'];
            if (!isset($produits[$id])) {
                continue;
            }
            $ca = (int) $v['sans_reference'] > 0 && $v['ca'] === null ? null : (float) $v['ca'];
            $cout = $couts[$id]['cout'] ?? 0.0;
            $marge = $ca !== null ? $ca - $cout : null;
            $resultat[] = [
                'produit_id' => $id, 'nom_fr' => $produits[$id]['nom_fr'], 'nom_en' => $produits[$id]['nom_en'], 'unite_base' => $produits[$id]['unite_base'],
                'recette' => (int) $produits[$id]['recette'] > 0, 'quantite' => (float) $v['quantite'], 'ca' => $ca,
                'cout' => $cout, 'sans_cout' => $couts[$id]['sans_cout'] ?? (isset($couts[$id]) ? 0 : 1),
                'marge' => $marge, 'part_cout' => $ca !== null && $ca > 0 ? $cout / $ca : null, 'taux_marge' => $ca !== null && $ca > 0 ? $marge / $ca : null,
                'gros' => $gros[$id] ?? 0.0,
            ];
        }
        usort($resultat, static fn (array $a, array $b): int => ($b['marge'] ?? -INF) <=> ($a['marge'] ?? -INF));
        return $resultat;
    }

    /**
     * Consommation attendue et réelle de chaque article stocké, dans ces lieux, sur la période (unités de base) :
     * attendue = ventes (produits et ingrédients des fiches, annulations déduites) + ingrédients des productions ;
     * réelle = attendue + pertes + écarts d'inventaire (manque positif) + ajustements à la baisse.
     * @param list<int> $lieuIds
     * @return list<array>
     */
    public function ecarts(string $debut, string $fin, array $lieuIds): array
    {
        if ($lieuIds === []) {
            return [];
        }
        $production = StockRepository::suite() ? "m.type = 'production' AND m.quantite < 0" : '0';
        $lignes = Database::tous(
            "SELECT m.produit_id, p.nom_fr, p.nom_en, p.unite_base,
                    SUM(IF(m.type IN ('vente', 'recette', 'annulation_vente'), -m.quantite, 0)) AS ventes,
                    SUM(IF($production, -m.quantite, 0)) AS production,
                    SUM(IF(m.type = 'perte', -m.quantite, 0)) AS pertes,
                    SUM(IF(m.type = 'inventaire', -m.quantite, 0)) AS inventaire,
                    SUM(IF(m.type = 'ajustement', -m.quantite, 0)) AS ajustements,
                    SUM(IF(m.type IN ('perte', 'inventaire', 'ajustement') AND m.cout_unitaire IS NOT NULL, -m.quantite * m.cout_unitaire, 0)) AS valeur_ecart,
                    SUM(IF(m.type IN ('vente', 'recette', 'annulation_vente') OR ($production), IF(m.cout_unitaire IS NULL, 0, -m.quantite * m.cout_unitaire), 0)) AS valeur_attendue
               FROM stock_mouvements m
               JOIN produits p ON p.id = m.produit_id
              WHERE m.lieu_id IN (" . implode(',', array_fill(0, count($lieuIds), '?')) . ") AND m.date_mouvement BETWEEN ? AND ?
                AND m.type IN ('vente', 'recette', 'annulation_vente', 'production', 'perte', 'inventaire', 'ajustement')
                AND NOT EXISTS (SELECT 1 FROM emballages e WHERE e.produit_id = m.produit_id)
           GROUP BY m.produit_id, p.nom_fr, p.nom_en, p.unite_base",
            array_merge(array_values($lieuIds), [$debut, $fin])
        );
        $resultat = [];
        foreach ($lignes as $l) {
            $attendue = (float) $l['ventes'] + (float) $l['production'];
            $ecart = (float) $l['pertes'] + (float) $l['inventaire'] + (float) $l['ajustements'];
            if (abs($attendue) < 0.0005 && abs($ecart) < 0.0005) {
                continue;
            }
            $resultat[] = $l + [
                'attendue' => $attendue, 'reelle' => $attendue + $ecart, 'ecart' => $ecart,
                'ecart_pct' => $attendue > 0.0005 ? $ecart / $attendue : null,
            ];
        }
        usort($resultat, static fn (array $a, array $b): int => (float) $b['valeur_ecart'] <=> (float) $a['valeur_ecart'] ?: strcmp($a['nom_fr'], $b['nom_fr']));
        return $resultat;
    }

    /** Inventaires validés sur la période dans ces lieux (sans eux, la consommation réelle n'est pas mesurée). */
    public function inventairesValides(string $debut, string $fin, array $lieuIds): int
    {
        if ($lieuIds === []) {
            return 0;
        }
        return (int) Database::un(
            "SELECT COUNT(*) AS n FROM inventaires WHERE statut = 'valide' AND lieu_id IN (" . implode(',', array_fill(0, count($lieuIds), '?')) . ') AND valide_le BETWEEN ? AND ?',
            array_merge(array_values($lieuIds), [$debut, $fin])
        )['n'];
    }

    /** @return array{0: string, 1: list<int>} */
    private static function portee(?array $etablissements, string $colonne): array
    {
        if ($etablissements === null) {
            return ['1 = 1', []];
        }
        if ($etablissements === []) {
            return ['1 = 0', []];
        }
        return [$colonne . ' IN (' . implode(',', array_fill(0, count($etablissements), '?')) . ')', array_values(array_map('intval', $etablissements))];
    }
}
