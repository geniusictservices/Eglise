<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Cœur du stock : lieux (dépôts et comptoirs), routage des ventes vers un comptoir, journal des mouvements,
 * stock de chaque produit dans chaque lieu (unité de base), coût moyen pondéré (CUMP), fiches techniques.
 *
 * Le stock d'un lieu est toujours la somme de ses mouvements ; mouvement() est le seul endroit qui l'écrit.
 * Les ventes y passent par sortieVente() (appelée dans la transaction de VenteRepository::enregistrer())
 * et annulationVente() (dans celle de GestionVenteRepository::annuler()). Elles ne bloquent jamais une
 * vente : en cas d'erreur, le stock est laissé tel quel (point de sauvegarde) et l'erreur est notée.
 */
final class StockRepository
{
    public const TYPES = [
        'ouverture', 'reception', 'vente', 'recette', 'annulation_vente', 'transfert_sortie', 'transfert_entree',
        'transfert_retour', 'inventaire', 'perte', 'ajustement', 'vide_recupere', 'vide_rendu',
    ];
    /** Types liés à une vente (source_id = ventes.id). */
    public const TYPES_VENTE = ['vente', 'recette', 'vide_recupere', 'annulation_vente'];

    private const EPSILON = 0.0005;

    /** @var array<int, array> routage par établissement */
    private array $routage = [];
    /** @var array<int, ?array> recettes actives par produit (null = pas de recette) */
    private array $recettes = [];
    /** @var array<int, array> réglages par établissement */
    private array $parametres = [];

    // ---------- Réglages ----------

    /** @return array{mode_negatif: string, validation_separee: bool} */
    public function parametres(int $etablissementId): array
    {
        if (!isset($this->parametres[$etablissementId])) {
            $ligne = Database::un('SELECT * FROM stock_parametres WHERE etablissement_id = ?', [$etablissementId]);
            $this->parametres[$etablissementId] = [
                'mode_negatif' => $ligne['mode_negatif'] ?? 'vendre',
                'validation_separee' => $ligne === null || (bool) $ligne['validation_separee'],
            ];
        }
        return $this->parametres[$etablissementId];
    }

    public function definirParametres(int $etablissementId, string $modeNegatif, bool $validationSeparee, int $utilisateurId): void
    {
        Database::executer(
            'INSERT INTO stock_parametres (etablissement_id, mode_negatif, validation_separee, maj_par, maj_le) VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE mode_negatif = VALUES(mode_negatif), validation_separee = VALUES(validation_separee),
                                     maj_par = VALUES(maj_par), maj_le = VALUES(maj_le)',
            [$etablissementId, $modeNegatif, (int) $validationSeparee, $utilisateurId]
        );
        unset($this->parametres[$etablissementId]);
    }

    // ---------- Lieux ----------

    public function lieu(int $id): ?array
    {
        return Database::un(
            'SELECT l.*, e.nom AS etablissement_nom FROM lieux_stock l LEFT JOIN etablissements e ON e.id = l.etablissement_id WHERE l.id = ?',
            [$id]
        );
    }

    /**
     * Lieux, rangés par établissement (dépôts centraux à la fin), dépôts puis comptoirs.
     * @param ?list<int> $etablissements null = tous ; sinon ces établissements et les dépôts centraux
     */
    public function lieux(?array $etablissements = null, bool $actifsSeulement = true): array
    {
        $conditions = [];
        $params = [];
        if ($actifsSeulement) {
            $conditions[] = 'l.actif = 1';
        }
        if ($etablissements !== null) {
            $conditions[] = $etablissements === []
                ? 'l.etablissement_id IS NULL'
                : '(l.etablissement_id IS NULL OR l.etablissement_id IN (' . implode(',', array_fill(0, count($etablissements), '?')) . '))';
            array_push($params, ...$etablissements);
        }
        return Database::tous(
            'SELECT l.*, e.nom AS etablissement_nom FROM lieux_stock l LEFT JOIN etablissements e ON e.id = l.etablissement_id'
            . ($conditions ? ' WHERE ' . implode(' AND ', $conditions) : '') . '
             ORDER BY l.etablissement_id IS NULL, e.position, e.id, l.actif DESC, FIELD(l.type, \'depot\', \'comptoir\'), l.par_defaut DESC, l.position, l.nom',
            $params
        );
    }

    /** Comptoir par défaut de l'établissement ; créé (« Bar ») s'il n'y en a aucun. */
    public function comptoirParDefaut(int $etablissementId): int
    {
        $ligne = Database::un(
            'SELECT id FROM lieux_stock WHERE etablissement_id = ? AND type = \'comptoir\' AND actif = 1
              ORDER BY par_defaut DESC, position, id LIMIT 1',
            [$etablissementId]
        );
        if ($ligne !== null) {
            return (int) $ligne['id'];
        }
        Database::executer(
            'INSERT INTO lieux_stock (etablissement_id, type, nom, par_defaut, position) VALUES (?, \'comptoir\', \'Bar\', 1, 1)',
            [$etablissementId]
        );
        unset($this->routage[$etablissementId]);
        return Database::dernierId();
    }

    /** Comptoirs par défaut de chaque établissement (créés s'il le faut). */
    public function assurerComptoirs(): void
    {
        foreach (Database::tous('SELECT id FROM etablissements') as $etab) {
            $this->comptoirParDefaut((int) $etab['id']);
        }
    }

    /** @return array{defaut: int, categories: array<int, int>, zones: array<int, int>, appareils: array<int, int>, parents: array<int, ?int>} */
    private function routage(int $etablissementId): array
    {
        if (!isset($this->routage[$etablissementId])) {
            $defaut = $this->comptoirParDefaut($etablissementId);
            $actifs = 'JOIN lieux_stock l ON l.id = r.lieu_id AND l.actif = 1 AND l.type = \'comptoir\' AND l.etablissement_id = ?';
            $this->routage[$etablissementId] = [
                'defaut' => $defaut,
                'categories' => array_map('intval', array_column(Database::tous(
                    'SELECT r.categorie_id, r.lieu_id FROM stock_routage_categories r ' . $actifs . ' WHERE r.etablissement_id = ?',
                    [$etablissementId, $etablissementId]
                ), 'lieu_id', 'categorie_id')),
                'zones' => array_map('intval', array_column(Database::tous(
                    'SELECT r.zone_id, r.lieu_id FROM stock_routage_zones r ' . $actifs,
                    [$etablissementId]
                ), 'lieu_id', 'zone_id')),
                'appareils' => array_map('intval', array_column(Database::tous(
                    'SELECT r.appareil_id, r.lieu_id FROM stock_routage_appareils r ' . $actifs,
                    [$etablissementId]
                ), 'lieu_id', 'appareil_id')),
                'parents' => array_map(static fn ($p) => $p === null ? null : (int) $p, array_column(
                    Database::tous('SELECT id, parent_id FROM categories'), 'parent_id', 'id'
                )),
            ];
        }
        return $this->routage[$etablissementId];
    }

    /**
     * Comptoir qui sert un produit vendu : celui de sa catégorie (ou de sa catégorie parente) s'il est routé
     * (Plats → Cuisine), sinon celui de l'appareil de caisse, sinon celui de la zone (Carré VIP → Bar VIP),
     * sinon le comptoir par défaut de l'établissement.
     */
    public function comptoirPour(int $etablissementId, ?int $categorieId, ?int $zoneId = null, ?int $appareilId = null): int
    {
        $r = $this->routage($etablissementId);
        if ($categorieId !== null) {
            if (isset($r['categories'][$categorieId])) {
                return $r['categories'][$categorieId];
            }
            $parent = $r['parents'][$categorieId] ?? null;
            if ($parent !== null && isset($r['categories'][$parent])) {
                return $r['categories'][$parent];
            }
        }
        if ($appareilId !== null && isset($r['appareils'][$appareilId])) {
            return $r['appareils'][$appareilId];
        }
        if ($zoneId !== null && isset($r['zones'][$zoneId])) {
            return $r['zones'][$zoneId];
        }
        return $r['defaut'];
    }

    public function oublierCache(): void
    {
        $this->routage = [];
        $this->recettes = [];
        $this->parametres = [];
    }

    // ---------- Mouvements ----------

    /**
     * Écrit un mouvement et met à jour le stock du lieu (créé à 0 s'il n'existe pas : il peut devenir négatif).
     * @param float $quantite unité de base, positive pour une entrée, négative pour une sortie
     * @return int identifiant du mouvement
     */
    public function mouvement(int $lieuId, int $produitId, float $quantite, string $type, ?int $sourceId, string $date,
                              ?string $motif, ?int $utilisateurId, ?int $conditionnementId = null, ?float $quantiteSaisie = null): int
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Type de mouvement inconnu : ' . $type);
        }
        Database::executer(
            'INSERT INTO stock_niveaux (lieu_id, produit_id, quantite, maj_le) VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE quantite = quantite + VALUES(quantite), maj_le = VALUES(maj_le)',
            [$lieuId, $produitId, self::q($quantite)]
        );
        $apres = Database::un('SELECT quantite FROM stock_niveaux WHERE lieu_id = ? AND produit_id = ?', [$lieuId, $produitId]);
        $cout = $this->cump($produitId);
        Database::executer(
            'INSERT INTO stock_mouvements (lieu_id, produit_id, type, quantite, stock_apres, conditionnement_id, quantite_saisie,
                                           cout_unitaire, source_id, motif, utilisateur_id, date_mouvement)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $lieuId, $produitId, $type, self::q($quantite), $apres['quantite'] ?? null, $conditionnementId,
                $quantiteSaisie !== null ? self::q($quantiteSaisie) : null,
                $cout !== null ? number_format($cout, 6, '.', '') : null,
                $sourceId, $motif !== null ? mb_substr($motif, 0, 255) : null, $utilisateurId ?: null, $date,
            ]
        );
        return Database::dernierId();
    }

    /** Stock d'un produit dans un lieu (0 s'il n'a jamais bougé). */
    public function quantite(int $lieuId, int $produitId): float
    {
        $ligne = Database::un('SELECT quantite FROM stock_niveaux WHERE lieu_id = ? AND produit_id = ?', [$lieuId, $produitId]);
        return (float) ($ligne['quantite'] ?? 0);
    }

    /** Stock d'un produit dans un lieu à une date (somme des mouvements jusqu'à cette date incluse). */
    public function quantiteA(int $lieuId, int $produitId, string $date): float
    {
        $ligne = Database::un(
            'SELECT COALESCE(SUM(quantite), 0) AS q FROM stock_mouvements WHERE lieu_id = ? AND produit_id = ? AND date_mouvement <= ?',
            [$lieuId, $produitId, $date]
        );
        return (float) $ligne['q'];
    }

    /**
     * Recalcule le stock de chaque lieu depuis le journal et le « stock après » de chaque mouvement, dans
     * l'ordre des dates (utile après des mouvements datés dans le passé : démonstration, rejeu des ventes).
     */
    public function recalculer(): void
    {
        $pdo = Database::pdo();
        $dejaOuverte = $pdo->inTransaction();
        if (!$dejaOuverte) {
            $pdo->beginTransaction();
        }
        try {
            Database::executer('UPDATE stock_niveaux SET quantite = 0');
            Database::executer(
                'INSERT INTO stock_niveaux (lieu_id, produit_id, quantite, maj_le)
                 SELECT lieu_id, produit_id, SUM(quantite), NOW() FROM stock_mouvements GROUP BY lieu_id, produit_id
                 ON DUPLICATE KEY UPDATE quantite = VALUES(quantite)'
            );
            $courant = [];
            $requete = $pdo->query('SELECT id, lieu_id, produit_id, quantite FROM stock_mouvements ORDER BY date_mouvement, id');
            $maj = $pdo->prepare('UPDATE stock_mouvements SET stock_apres = ? WHERE id = ?');
            foreach ($requete->fetchAll() as $m) {
                $cle = $m['lieu_id'] . '-' . $m['produit_id'];
                $courant[$cle] = round(($courant[$cle] ?? 0) + (float) $m['quantite'], 3);
                $maj->execute([self::q($courant[$cle]), (int) $m['id']]);
            }
            if (!$dejaOuverte) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if (!$dejaOuverte) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Refait les sorties de stock de toutes les ventes, dans l'ordre (ventes enregistrées avant le module,
     * démonstration) : les mouvements de vente existants sont remplacés, puis le stock est recalculé.
     * @return int nombre de ventes rejouées
     */
    public function rejouerVentes(): int
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            Database::executer(
                'DELETE FROM stock_mouvements WHERE type IN (\'vente\', \'recette\', \'annulation_vente\')
                    OR (type = \'vide_recupere\' AND source_id IS NOT NULL AND EXISTS (SELECT 1 FROM ventes v WHERE v.id = stock_mouvements.source_id))'
            );
            $ventes = Database::tous('SELECT id, statut FROM ventes ORDER BY cree_le, id');
            foreach ($ventes as $v) {
                $this->sortieVente((int) $v['id']);
                if ($v['statut'] === 'annulee') {
                    $this->annulationVente((int) $v['id']);
                }
            }
            $this->recalculer();
            $pdo->commit();
            return count($ventes);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ---------- Ventes ----------

    /**
     * Sortie du stock pour une vente : chaque ligne sort du comptoir qui la sert (routage), en unités de base
     * (quantité × quantite_base du format vendu) ; un produit avec une fiche technique fait sortir ses
     * ingrédients ; un format avec des emballages (bouteille consignée) les fait revenir au comptoir (vides).
     * Sans effet si la vente a déjà ses mouvements. Ne lève jamais d'exception : la vente passe toujours.
     */
    public function sortieVente(int $venteId, ?string $date = null): void
    {
        $this->sansBloquer(function () use ($venteId, $date): void {
            $vente = Database::un('SELECT id, etablissement_id, zone_id, appareil_id, utilisateur_id, numero, cree_le FROM ventes WHERE id = ?', [$venteId]);
            if ($vente === null) {
                return;
            }
            if (Database::un('SELECT id FROM stock_mouvements WHERE type IN (\'vente\', \'recette\', \'vide_recupere\') AND source_id = ? LIMIT 1', [$venteId]) !== null) {
                return;
            }
            $date ??= $vente['cree_le'];
            $etab = (int) $vente['etablissement_id'];
            $zone = $vente['zone_id'] !== null ? (int) $vente['zone_id'] : null;
            $appareil = $vente['appareil_id'] !== null ? (int) $vente['appareil_id'] : null;
            $uid = $vente['utilisateur_id'] !== null ? (int) $vente['utilisateur_id'] : null;
            $motif = 'Vente ' . $vente['numero'];
            $lignes = Database::tous(
                'SELECT l.produit_id AS ligne_produit, l.conditionnement_id, l.quantite, c.produit_id AS cond_produit, c.quantite_base,
                        p.id AS produit_id, p.categorie_id
                   FROM vente_lignes l
              LEFT JOIN conditionnements c ON c.id = l.conditionnement_id
              LEFT JOIN produits p ON p.id = COALESCE(c.produit_id, l.produit_id)
                  WHERE l.vente_id = ? ORDER BY l.id',
                [$venteId]
            );
            foreach ($lignes as $l) {
                if ($l['produit_id'] === null) {
                    continue;
                }
                $produitId = (int) $l['produit_id'];
                $nombre = (float) $l['quantite'];
                $base = $nombre * ($l['quantite_base'] !== null ? (float) $l['quantite_base'] : 1.0);
                $condId = $l['cond_produit'] !== null ? (int) $l['conditionnement_id'] : null;
                $lieu = $this->comptoirPour($etab, (int) $l['categorie_id'], $zone, $appareil);
                $recette = $this->recetteActive($produitId);
                if ($recette !== null) {
                    foreach ($this->consommation($recette, $base) as $ingredientId => $quantite) {
                        $this->mouvement($lieu, $ingredientId, -$quantite, 'recette', $venteId, $date, $motif, $uid);
                    }
                } else {
                    $this->mouvement($lieu, $produitId, -$base, 'vente', $venteId, $date, $motif, $uid, $condId, $nombre);
                }
                if ($condId !== null) {
                    foreach ($this->emballagesDu($condId) as $emballageId => $parFormat) {
                        $this->mouvement($lieu, $emballageId, $parFormat * $nombre, 'vide_recupere', $venteId, $date, $motif, $uid);
                    }
                }
            }
        });
    }

    /** Remet en stock ce qu'une vente annulée avait fait sortir (et retire les vides récupérés). Sans effet si déjà fait. */
    public function annulationVente(int $venteId): void
    {
        $this->sansBloquer(function () use ($venteId): void {
            if (Database::un('SELECT id FROM stock_mouvements WHERE type = \'annulation_vente\' AND source_id = ? LIMIT 1', [$venteId]) !== null) {
                return;
            }
            $vente = Database::un('SELECT numero, annulee_le, annulee_par FROM ventes WHERE id = ?', [$venteId]);
            if ($vente === null) {
                return;
            }
            $date = $vente['annulee_le'] ?? date('Y-m-d H:i:s');
            $uid = $vente['annulee_par'] !== null ? (int) $vente['annulee_par'] : null;
            $mouvements = Database::tous(
                'SELECT * FROM stock_mouvements WHERE type IN (\'vente\', \'recette\', \'vide_recupere\') AND source_id = ? ORDER BY id',
                [$venteId]
            );
            foreach ($mouvements as $m) {
                $this->mouvement((int) $m['lieu_id'], (int) $m['produit_id'], -(float) $m['quantite'], 'annulation_vente', $venteId,
                    $date, 'Annulation vente ' . $vente['numero'], $uid,
                    $m['conditionnement_id'] !== null ? (int) $m['conditionnement_id'] : null,
                    $m['quantite_saisie'] !== null ? (float) $m['quantite_saisie'] : null);
            }
        });
    }

    /**
     * Mode « bloquer » : formats vendables de l'établissement que le comptoir qui les sert ne peut plus fournir
     * (stock inférieur au contenu d'un format ; pour un cocktail ou un plat, un ingrédient manque). La caisse
     * les montre « épuisés ». Liste vide en mode « vendre quand même ».
     * @return list<int> identifiants de conditionnements
     */
    public function epuises(int $etablissementId, ?int $appareilId = null, ?int $zoneId = null): array
    {
        if ($this->parametres($etablissementId)['mode_negatif'] !== 'bloquer') {
            return [];
        }
        $formats = Database::tous(
            'SELECT c.id, c.produit_id, c.quantite_base, p.categorie_id
               FROM conditionnements c
               JOIN produits p ON p.id = c.produit_id AND p.actif = 1
               JOIN produit_etablissements pe ON pe.produit_id = p.id AND pe.etablissement_id = ?
              WHERE c.vendable = 1',
            [$etablissementId]
        );
        $stock = [];
        foreach (Database::tous(
            'SELECT n.lieu_id, n.produit_id, n.quantite FROM stock_niveaux n JOIN lieux_stock l ON l.id = n.lieu_id WHERE l.etablissement_id = ?',
            [$etablissementId]
        ) as $n) {
            $stock[(int) $n['lieu_id']][(int) $n['produit_id']] = (float) $n['quantite'];
        }
        $epuises = [];
        foreach ($formats as $f) {
            $lieu = $this->comptoirPour($etablissementId, (int) $f['categorie_id'], $zoneId, $appareilId);
            $besoins = ($recette = $this->recetteActive((int) $f['produit_id'])) !== null
                ? $this->consommation($recette, (float) $f['quantite_base'])
                : [(int) $f['produit_id'] => (float) $f['quantite_base']];
            foreach ($besoins as $produitId => $besoin) {
                if (($stock[$lieu][$produitId] ?? 0.0) < $besoin - self::EPSILON) {
                    $epuises[] = (int) $f['id'];
                    break;
                }
            }
        }
        return $epuises;
    }

    /** Exécute une écriture de stock liée à une vente sans jamais faire échouer la vente. */
    private function sansBloquer(callable $fonction): void
    {
        $pdo = Database::pdo();
        $propre = !$pdo->inTransaction();
        try {
            if ($propre) {
                $pdo->beginTransaction();
            } else {
                $pdo->exec('SAVEPOINT stock_vente');
            }
            $fonction();
            if ($propre) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT stock_vente');
            }
        } catch (\Throwable $e) {
            // Interblocage : la base a déjà annulé toute la transaction de la vente ; la vente doit échouer
            // franchement (la caisse la renvoie avec la même clé) plutôt que d'être perdue en silence
            if (!$propre && $e instanceof \PDOException && (int) ($e->errorInfo[1] ?? 0) === 1213) {
                throw $e;
            }
            try {
                if ($propre) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } else {
                    $pdo->exec('ROLLBACK TO SAVEPOINT stock_vente');
                }
            } catch (\Throwable) {
                // La transaction de la vente décide seule de la suite
            }
            error_log('Stock non mis à jour pour une vente : ' . $e->getMessage());
        }
    }

    // ---------- Fiches techniques ----------

    /** @return ?array{id: int, rendement: float, ingredients: list<array{produit_id: int, quantite: float, rendement_pct: float}>} */
    public function recetteActive(int $produitId): ?array
    {
        if (!array_key_exists($produitId, $this->recettes)) {
            $recette = Database::un('SELECT id, rendement FROM recettes WHERE produit_id = ? AND actif = 1', [$produitId]);
            if ($recette !== null) {
                $ingredients = array_map(static fn (array $i): array => [
                    'produit_id' => (int) $i['produit_id'], 'quantite' => (float) $i['quantite'], 'rendement_pct' => (float) $i['rendement_pct'],
                ], Database::tous('SELECT produit_id, quantite, rendement_pct FROM recette_ingredients WHERE recette_id = ? ORDER BY position, id', [(int) $recette['id']]));
                $recette = $ingredients === [] ? null : ['id' => (int) $recette['id'], 'rendement' => max(0.001, (float) $recette['rendement']), 'ingredients' => $ingredients];
            }
            $this->recettes[$produitId] = $recette;
        }
        return $this->recettes[$produitId];
    }

    /**
     * Ingrédients consommés (unité de base de chacun) pour $base unités du produit de la recette :
     * quantité utilisable × base / rendement, divisée par la part utilisable de l'ingrédient.
     * @return array<int, float>
     */
    public function consommation(array $recette, float $base): array
    {
        $resultat = [];
        foreach ($recette['ingredients'] as $i) {
            $pct = $i['rendement_pct'] > 0 ? $i['rendement_pct'] / 100 : 1.0;
            $resultat[$i['produit_id']] = ($resultat[$i['produit_id']] ?? 0) + $i['quantite'] * $base / $recette['rendement'] / $pct;
        }
        return $resultat;
    }

    /** @return array<int, float> emballage => quantité par format */
    private function emballagesDu(int $conditionnementId): array
    {
        static $cache = [];
        return $cache[$conditionnementId] ??= array_map('floatval', array_column(
            Database::tous('SELECT emballage_id, quantite FROM conditionnement_emballages WHERE conditionnement_id = ?', [$conditionnementId]),
            'quantite', 'emballage_id'
        ));
    }

    // ---------- Coûts ----------

    /** CUMP d'un produit par unité de base (devise de référence), null s'il n'a jamais été acheté. */
    public function cump(int $produitId): ?float
    {
        $ligne = Database::un('SELECT cump FROM stock_couts WHERE produit_id = ?', [$produitId]);
        return $ligne !== null ? (float) $ligne['cump'] : null;
    }

    /**
     * Nouveau CUMP après l'entrée de $quantite unités au coût $cout (référence), à appeler AVANT le mouvement
     * d'entrée : (stock total × ancien coût + quantité × coût) / (stock total + quantité). Un stock négatif
     * compte pour zéro.
     */
    public function majCump(int $produitId, float $quantite, float $cout, int $deviseReferenceId): void
    {
        if ($quantite <= 0) {
            return;
        }
        $total = max(0.0, (float) (Database::un('SELECT COALESCE(SUM(quantite), 0) AS q FROM stock_niveaux WHERE produit_id = ?', [$produitId])['q']));
        $actuel = $this->cump($produitId);
        $nouveau = $actuel === null || $total <= self::EPSILON ? $cout : ($total * $actuel + $quantite * $cout) / ($total + $quantite);
        Database::executer(
            'INSERT INTO stock_couts (produit_id, cump, devise_id, maj_le) VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE cump = VALUES(cump), devise_id = VALUES(devise_id), maj_le = VALUES(maj_le)',
            [$produitId, number_format($nouveau, 6, '.', ''), $deviseReferenceId]
        );
    }

    /**
     * Coût de revient d'une unité de base du produit (devise de référence) : ses ingrédients au CUMP s'il a une
     * fiche technique, sinon son CUMP. complet = false si un ingrédient n'a pas encore de coût.
     * @return array{cout: ?float, complet: bool}
     */
    public function coutRevient(int $produitId): array
    {
        $recette = $this->recetteActive($produitId);
        if ($recette === null) {
            $cump = $this->cump($produitId);
            return ['cout' => $cump, 'complet' => $cump !== null];
        }
        $total = 0.0;
        $complet = true;
        foreach ($this->consommation($recette, 1.0) as $ingredientId => $quantite) {
            $cump = $this->cump($ingredientId);
            if ($cump === null) {
                $complet = false;
                continue;
            }
            $total += $cump * $quantite;
        }
        return ['cout' => $total, 'complet' => $complet];
    }

    /** Quantité au format des colonnes DECIMAL(16,3). */
    public static function q(float $quantite): string
    {
        return number_format(round($quantite, 3), 3, '.', '');
    }
}
