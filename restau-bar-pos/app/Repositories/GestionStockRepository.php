<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Journal;

/**
 * Écrans du stock : articles stockés, lieux (dépôts et comptoirs) et routage, état du stock, seuils,
 * ajustements, pertes et journal des mouvements. Les écritures de stock passent par StockRepository::mouvement().
 */
final class GestionStockRepository
{
    public const MOTIFS_PERTE = ['casse', 'offert', 'personnel', 'perime', 'renvoye', 'autre'];
    public const PAR_PAGE = 100;

    private StockRepository $stock;

    public function __construct(?StockRepository $stock = null)
    {
        $this->stock = $stock ?? new StockRepository();
    }

    public function stock(): StockRepository
    {
        return $this->stock;
    }

    // ---------- Articles ----------

    /**
     * Produits qui ont un stock : actifs, avec au moins un format, sans fiche technique active (un cocktail
     * ou un plat n'a pas de stock : ses ingrédients en ont). Rangés comme le catalogue. Chaque article a ses
     * formats (conditionnements), sa catégorie (libellé « Bières › Locales ») et « emballage » s'il est consigné.
     * @return array<int, array>
     */
    public function articles(bool $avecInactifs = false): array
    {
        $produits = Database::tous(
            'SELECT p.id, p.nom_fr, p.nom_en, p.unite_base, p.categorie_id, p.parent_id, p.actif,
                    c.nom_fr AS cat_nom_fr, c.nom_en AS cat_nom_en, cp.nom_fr AS parent_nom_fr, cp.nom_en AS parent_nom_en,
                    COALESCE(cp.id, c.id) AS groupe_id, e.produit_id IS NOT NULL AS emballage, e.valeur_consigne, e.devise_id AS consigne_devise_id
               FROM produits p
               JOIN categories c ON c.id = p.categorie_id
          LEFT JOIN categories cp ON cp.id = c.parent_id
          LEFT JOIN produits pp ON pp.id = p.parent_id
          LEFT JOIN emballages e ON e.produit_id = p.id
              WHERE ' . ($avecInactifs ? '1 = 1' : 'p.actif = 1') . '
                AND EXISTS (SELECT 1 FROM conditionnements x WHERE x.produit_id = p.id)
                AND NOT EXISTS (SELECT 1 FROM recettes r JOIN recette_ingredients ri ON ri.recette_id = r.id
                                 WHERE r.produit_id = p.id AND r.actif = 1)
           ORDER BY COALESCE(cp.position, c.position), COALESCE(cp.id, c.id), c.parent_id IS NOT NULL, c.position, c.id,
                    COALESCE(pp.position, p.position), COALESCE(p.parent_id, p.id), p.parent_id IS NOT NULL, p.position, p.id'
        );
        if ($produits === []) {
            return [];
        }
        $formats = [];
        foreach (Database::tous('SELECT * FROM conditionnements ORDER BY produit_id, position, id') as $c) {
            $formats[(int) $c['produit_id']][] = $c;
        }
        $articles = [];
        foreach ($produits as $p) {
            $p['conditionnements'] = $formats[(int) $p['id']] ?? [];
            $p['categorie'] = $p['parent_nom_fr'] !== null
                ? champ(['nom_fr' => $p['parent_nom_fr'], 'nom_en' => $p['parent_nom_en']], 'nom') . ' › ' . champ(['nom_fr' => $p['cat_nom_fr'], 'nom_en' => $p['cat_nom_en']], 'nom')
                : champ(['nom_fr' => $p['cat_nom_fr'], 'nom_en' => $p['cat_nom_en']], 'nom');
            $p['emballage'] = (bool) $p['emballage'];
            $articles[(int) $p['id']] = $p;
        }
        return $articles;
    }

    /** Un format d'un article, ou null. */
    public static function format(array $article, ?int $conditionnementId): ?array
    {
        foreach ($article['conditionnements'] as $c) {
            if ((int) $c['id'] === $conditionnementId) {
                return $c;
            }
        }
        return null;
    }

    /** Format d'achat proposé : le plus grand format achetable, sinon le plus grand format. */
    public static function formatAchat(array $article): ?array
    {
        $formats = $article['conditionnements'];
        $achat = array_values(array_filter($formats, static fn (array $c): bool => (bool) $c['achetable']));
        $liste = $achat ?: $formats;
        usort($liste, static fn (array $a, array $b): int => (float) $b['quantite_base'] <=> (float) $a['quantite_base']);
        return $liste[0] ?? null;
    }

    /** Format d'une unité de base (quantite_base = 1), sinon le plus petit. */
    public static function formatUnite(array $article): ?array
    {
        $liste = $article['conditionnements'];
        usort($liste, static fn (array $a, array $b): int => (float) $a['quantite_base'] <=> (float) $b['quantite_base']);
        foreach ($liste as $c) {
            if (abs((float) $c['quantite_base'] - 1) < 0.0005) {
                return $c;
            }
        }
        return $liste[0] ?? null;
    }

    /** Catégories principales (pour les filtres et le routage), avec leurs sous-catégories. */
    public function categories(): array
    {
        return GestionCatalogueRepository::arbre(Database::tous('SELECT * FROM categories ORDER BY position, id'));
    }

    // ---------- Lieux et routage ----------

    public function creerLieu(?int $etablissementId, string $type, string $nom, ?string $description): int
    {
        $position = (int) (Database::un(
            'SELECT COALESCE(MAX(position), 0) + 1 AS p FROM lieux_stock WHERE etablissement_id <=> ?',
            [$etablissementId]
        )['p']);
        Database::executer(
            'INSERT INTO lieux_stock (etablissement_id, type, nom, description, position) VALUES (?, ?, ?, ?, ?)',
            [$etablissementId, $type, $nom, $description, min(999, $position)]
        );
        $id = Database::dernierId();
        $this->stock->oublierCache();
        return $id;
    }

    public function modifierLieu(int $id, string $nom, ?string $description, bool $actif): void
    {
        Database::executer('UPDATE lieux_stock SET nom = ?, description = ?, actif = ? WHERE id = ?', [$nom, $description, (int) $actif, $id]);
        $this->stock->oublierCache();
    }

    /** Ce comptoir devient le comptoir par défaut de son établissement. */
    public function definirParDefaut(int $id): void
    {
        $lieu = $this->stock->lieu($id);
        if ($lieu === null || $lieu['type'] !== 'comptoir' || $lieu['etablissement_id'] === null) {
            return;
        }
        Database::executer('UPDATE lieux_stock SET par_defaut = (id = ?) WHERE etablissement_id = ? AND type = \'comptoir\'', [$id, (int) $lieu['etablissement_id']]);
        Database::executer('UPDATE lieux_stock SET actif = 1 WHERE id = ?', [$id]);
        $this->stock->oublierCache();
    }

    public function nomLieuPris(?int $etablissementId, string $nom, int $saufId = 0): bool
    {
        return Database::un('SELECT id FROM lieux_stock WHERE etablissement_id <=> ? AND nom = ? AND id <> ?', [$etablissementId, $nom, $saufId]) !== null;
    }

    /** Un lieu sans aucun mouvement ni document peut être supprimé. */
    public function lieuUtilise(int $id): bool
    {
        foreach ([
            'SELECT 1 FROM stock_mouvements WHERE lieu_id = ? LIMIT 1',
            'SELECT 1 FROM receptions WHERE lieu_id = ? LIMIT 1',
            'SELECT 1 FROM commandes_achat WHERE lieu_id = ? LIMIT 1',
            'SELECT 1 FROM transferts_stock WHERE lieu_source_id = ? OR lieu_destination_id = ? LIMIT 1',
            'SELECT 1 FROM inventaires WHERE lieu_id = ? LIMIT 1',
        ] as $i => $sql) {
            if (Database::un($sql, $i === 3 ? [$id, $id] : [$id]) !== null) {
                return true;
            }
        }
        return false;
    }

    public function supprimerLieu(int $id): void
    {
        Database::executer('DELETE FROM lieux_stock WHERE id = ? AND par_defaut = 0', [$id]);
        $this->stock->oublierCache();
    }

    /** @return array{categories: array<int, int>, zones: array<int, int>, appareils: array<int, int>} */
    public function routage(int $etablissementId): array
    {
        return [
            'categories' => array_map('intval', array_column(Database::tous('SELECT categorie_id, lieu_id FROM stock_routage_categories WHERE etablissement_id = ?', [$etablissementId]), 'lieu_id', 'categorie_id')),
            'zones' => array_map('intval', array_column(Database::tous('SELECT r.zone_id, r.lieu_id FROM stock_routage_zones r JOIN zones z ON z.id = r.zone_id WHERE z.etablissement_id = ?', [$etablissementId]), 'lieu_id', 'zone_id')),
            'appareils' => array_map('intval', array_column(Database::tous('SELECT r.appareil_id, r.lieu_id FROM stock_routage_appareils r JOIN appareils_caisse a ON a.id = r.appareil_id WHERE a.etablissement_id = ?', [$etablissementId]), 'lieu_id', 'appareil_id')),
        ];
    }

    public function zones(int $etablissementId): array
    {
        return Database::tous('SELECT * FROM zones WHERE etablissement_id = ? ORDER BY id', [$etablissementId]);
    }

    public function appareils(int $etablissementId): array
    {
        return Database::tous('SELECT id, nom, actif FROM appareils_caisse WHERE etablissement_id = ? ORDER BY actif DESC, nom', [$etablissementId]);
    }

    /**
     * Remplace le routage d'un établissement (null ou absent = comptoir par défaut).
     * @param array<int, ?int> $categories categorie_id => lieu_id
     * @param array<int, ?int> $zones
     * @param array<int, ?int> $appareils
     */
    public function definirRoutage(int $etablissementId, array $categories, array $zones, array $appareils): void
    {
        Database::executer('DELETE FROM stock_routage_categories WHERE etablissement_id = ?', [$etablissementId]);
        foreach ($categories as $categorieId => $lieuId) {
            if ($lieuId !== null) {
                Database::executer('INSERT INTO stock_routage_categories (etablissement_id, categorie_id, lieu_id) VALUES (?, ?, ?)', [$etablissementId, $categorieId, $lieuId]);
            }
        }
        Database::executer('DELETE r FROM stock_routage_zones r JOIN zones z ON z.id = r.zone_id WHERE z.etablissement_id = ?', [$etablissementId]);
        foreach ($zones as $zoneId => $lieuId) {
            if ($lieuId !== null) {
                Database::executer('INSERT INTO stock_routage_zones (zone_id, lieu_id) VALUES (?, ?)', [$zoneId, $lieuId]);
            }
        }
        Database::executer('DELETE r FROM stock_routage_appareils r JOIN appareils_caisse a ON a.id = r.appareil_id WHERE a.etablissement_id = ?', [$etablissementId]);
        foreach ($appareils as $appareilId => $lieuId) {
            if ($lieuId !== null) {
                Database::executer('INSERT INTO stock_routage_appareils (appareil_id, lieu_id) VALUES (?, ?)', [$appareilId, $lieuId]);
            }
        }
        $this->stock->oublierCache();
    }

    // ---------- État du stock ----------

    /**
     * Stock des lieux demandés.
     * @param list<int> $lieuIds
     * @return array<int, array<int, array{quantite: float, seuil_alerte: ?float, stock_cible: ?float}>> lieu => produit => niveau
     */
    public function niveaux(array $lieuIds): array
    {
        if ($lieuIds === []) {
            return [];
        }
        $resultat = [];
        foreach (Database::tous(
            'SELECT * FROM stock_niveaux WHERE lieu_id IN (' . implode(',', array_fill(0, count($lieuIds), '?')) . ')',
            array_values($lieuIds)
        ) as $n) {
            $resultat[(int) $n['lieu_id']][(int) $n['produit_id']] = [
                'quantite' => (float) $n['quantite'],
                'seuil_alerte' => $n['seuil_alerte'] !== null ? (float) $n['seuil_alerte'] : null,
                'stock_cible' => $n['stock_cible'] !== null ? (float) $n['stock_cible'] : null,
            ];
        }
        return $resultat;
    }

    /** État d'un niveau : « negatif », « bas » (au seuil ou dessous), « ok ». */
    public static function etat(array $niveau): string
    {
        if ($niveau['quantite'] < -0.0005) {
            return 'negatif';
        }
        if ($niveau['seuil_alerte'] !== null && $niveau['quantite'] <= $niveau['seuil_alerte'] + 0.0005) {
            return 'bas';
        }
        return 'ok';
    }

    /** CUMP de tous les produits (référence) : produit_id => coût par unité de base. */
    public function cumps(): array
    {
        return array_map('floatval', array_column(Database::tous('SELECT produit_id, cump FROM stock_couts'), 'cump', 'produit_id'));
    }

    /** Seuil d'alerte et stock visé d'un produit dans un lieu (null = aucun). */
    public function definirSeuils(int $lieuId, int $produitId, ?float $seuil, ?float $cible): void
    {
        Database::executer(
            'INSERT INTO stock_niveaux (lieu_id, produit_id, quantite, seuil_alerte, stock_cible) VALUES (?, ?, 0, ?, ?)
             ON DUPLICATE KEY UPDATE seuil_alerte = VALUES(seuil_alerte), stock_cible = VALUES(stock_cible)',
            [$lieuId, $produitId, $seuil !== null ? StockRepository::q($seuil) : null, $cible !== null ? StockRepository::q($cible) : null]
        );
    }

    /**
     * Ajustement manuel (correction ou stock d'ouverture) : le stock du lieu change de $delta unités de base.
     */
    public function ajuster(int $lieuId, int $produitId, float $delta, string $type, string $motif, int $utilisateurId, string $date,
                            ?int $conditionnementId = null, ?float $quantiteSaisie = null): int
    {
        return $this->transaction(function () use ($lieuId, $produitId, $delta, $type, $motif, $utilisateurId, $date, $conditionnementId, $quantiteSaisie): int {
            $id = $this->stock->mouvement($lieuId, $produitId, $delta, $type, null, $date, $motif, $utilisateurId, $conditionnementId, $quantiteSaisie);
            Journal::ecrire('stock_ajuste', (string) $id, ['lieu' => $lieuId, 'produit' => $produitId, 'quantite' => round($delta, 3), 'motif' => $motif], $utilisateurId);
            return $id;
        });
    }

    /**
     * Dernier achat de chaque produit (fournisseur, format, prix, date) : pour les suggestions de commande.
     * @return array<int, array>
     */
    public function derniersAchats(): array
    {
        $resultat = [];
        foreach (Database::tous(
            'SELECT rl.produit_id, rl.conditionnement_id, rl.prix_unitaire, r.devise_id, r.fournisseur_id, f.nom AS fournisseur, r.date_reception
               FROM reception_lignes rl
               JOIN receptions r ON r.id = rl.reception_id
               JOIN fournisseurs f ON f.id = r.fournisseur_id
              ORDER BY r.date_reception DESC, rl.id DESC'
        ) as $ligne) {
            $resultat[(int) $ligne['produit_id']] ??= $ligne;
        }
        return $resultat;
    }

    // ---------- Journal ----------

    /**
     * Mouvements d'une période dans des lieux, les plus récents d'abord.
     * @param array{debut: string, fin: string, lieu: ?int, type: ?string, produit: ?int} $filtres
     * @param list<int> $lieuIds lieux visibles
     * @return array{mouvements: list<array>, total: int}
     */
    public function mouvements(array $filtres, array $lieuIds, int $page): array
    {
        if ($lieuIds === []) {
            return ['mouvements' => [], 'total' => 0];
        }
        $conditions = ['m.date_mouvement BETWEEN ? AND ?', 'm.lieu_id IN (' . implode(',', array_fill(0, count($lieuIds), '?')) . ')'];
        $params = array_merge([$filtres['debut'], $filtres['fin']], array_values($lieuIds));
        if ($filtres['lieu']) {
            $conditions[] = 'm.lieu_id = ?';
            $params[] = $filtres['lieu'];
        }
        if ($filtres['type']) {
            $conditions[] = 'm.type = ?';
            $params[] = $filtres['type'];
        }
        if ($filtres['produit']) {
            $conditions[] = 'm.produit_id = ?';
            $params[] = $filtres['produit'];
        }
        $where = implode(' AND ', $conditions);
        $total = (int) Database::un('SELECT COUNT(*) AS n FROM stock_mouvements m WHERE ' . $where, $params)['n'];
        $mouvements = Database::tous(
            'SELECT m.*, p.nom_fr, p.nom_en, p.unite_base, l.nom AS lieu_nom, l.type AS lieu_type, e.nom AS etablissement_nom, u.nom AS auteur,
                    c.nom_fr AS format_nom_fr, c.nom_en AS format_nom_en
               FROM stock_mouvements m
               JOIN produits p ON p.id = m.produit_id
               JOIN lieux_stock l ON l.id = m.lieu_id
          LEFT JOIN etablissements e ON e.id = l.etablissement_id
          LEFT JOIN utilisateurs u ON u.id = m.utilisateur_id
          LEFT JOIN conditionnements c ON c.id = m.conditionnement_id
              WHERE ' . $where . '
              ORDER BY m.date_mouvement DESC, m.id DESC
              LIMIT ' . self::PAR_PAGE . ' OFFSET ' . (max(0, $page - 1) * self::PAR_PAGE),
            $params
        );
        return ['mouvements' => $mouvements, 'total' => $total];
    }

    // ---------- Pertes ----------

    /** @param array{lieu_id: int, produit_id: int, conditionnement_id: ?int, quantite: float, quantite_base: float, motif: string, commentaire: string, date: string} $v */
    public function creerPerte(array $v, int $utilisateurId): int
    {
        return $this->transaction(function () use ($v, $utilisateurId): int {
            $cout = $this->stock->cump($v['produit_id']);
            Database::executer(
                'INSERT INTO pertes_stock (lieu_id, produit_id, conditionnement_id, quantite, quantite_base, motif, commentaire, cout, date_perte, utilisateur_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $v['lieu_id'], $v['produit_id'], $v['conditionnement_id'], StockRepository::q($v['quantite']), StockRepository::q($v['quantite_base']),
                    $v['motif'], $v['commentaire'], $cout !== null ? number_format($cout * $v['quantite_base'], 3, '.', '') : null, $v['date'], $utilisateurId,
                ]
            );
            $id = Database::dernierId();
            $this->stock->mouvement($v['lieu_id'], $v['produit_id'], -$v['quantite_base'], 'perte', $id, $v['date'],
                t('stock.motif_perte_' . $v['motif']) . ' : ' . $v['commentaire'], $utilisateurId, $v['conditionnement_id'], $v['quantite']);
            Journal::ecrire('stock_perte', (string) $id, ['lieu' => $v['lieu_id'], 'produit' => $v['produit_id'], 'quantite' => $v['quantite_base'], 'motif' => $v['motif']], $utilisateurId);
            return $id;
        });
    }

    /**
     * Pertes d'une période dans des lieux.
     * @param array{debut: string, fin: string, lieu: ?int, motif: ?string} $filtres
     */
    public function pertes(array $filtres, array $lieuIds): array
    {
        if ($lieuIds === []) {
            return [];
        }
        $conditions = ['x.date_perte BETWEEN ? AND ?', 'x.lieu_id IN (' . implode(',', array_fill(0, count($lieuIds), '?')) . ')'];
        $params = array_merge([$filtres['debut'], $filtres['fin']], array_values($lieuIds));
        if ($filtres['lieu']) {
            $conditions[] = 'x.lieu_id = ?';
            $params[] = $filtres['lieu'];
        }
        if ($filtres['motif']) {
            $conditions[] = 'x.motif = ?';
            $params[] = $filtres['motif'];
        }
        return Database::tous(
            'SELECT x.*, p.nom_fr, p.nom_en, p.unite_base, l.nom AS lieu_nom, e.nom AS etablissement_nom, u.nom AS auteur,
                    c.nom_fr AS format_nom_fr, c.nom_en AS format_nom_en
               FROM pertes_stock x
               JOIN produits p ON p.id = x.produit_id
               JOIN lieux_stock l ON l.id = x.lieu_id
          LEFT JOIN etablissements e ON e.id = l.etablissement_id
          LEFT JOIN utilisateurs u ON u.id = x.utilisateur_id
          LEFT JOIN conditionnements c ON c.id = x.conditionnement_id
              WHERE ' . implode(' AND ', $conditions) . '
              ORDER BY x.date_perte DESC, x.id DESC LIMIT 500',
            $params
        );
    }

    // ---------- Outils ----------

    /**
     * @template T
     * @param callable(): T $fonction
     * @return T
     */
    public function transaction(callable $fonction): mixed
    {
        return (new GestionTresorerieRepository())->transaction($fonction);
    }
}
