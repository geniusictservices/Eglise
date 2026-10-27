<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Journal;

/**
 * Bouteilles avec softs et formules (cahier des charges, section 4) : un produit du catalogue (« Johnnie Walker
 * 70 cl + 4 softs », « Formule anniversaire ») a des groupes de composants : la bouteille (composant fixe, une seule
 * option) et « 4 softs au choix » parmi Coca-Cola, Fanta, Sprite… Son prix est celui du produit, dans chaque grille.
 * À la caisse, une fenêtre fait choisir les composants ; la vente garde la formule (sans sortie de stock) et ses
 * composants à prix 0 (ils sortent du stock comme une vente).
 */
final class FormuleRepository
{
    private static ?bool $disponible = null;
    /** @var array<int, ?array> */
    private array $cache = [];

    public static function disponible(): bool
    {
        return self::$disponible ??= RapportRepository::tableExiste('formules');
    }

    /** Identifiants des produits qui sont des formules actives. @return list<int> */
    public function produits(): array
    {
        if (!self::disponible()) {
            return [];
        }
        return array_map('intval', array_column(Database::tous('SELECT produit_id FROM formules WHERE actif = 1'), 'produit_id'));
    }

    /**
     * Formule d'un produit (active ou non avec $toutes), avec ses groupes et leurs options (formats, libellés).
     * @return array{produit_id: int, actif: bool, groupes: list<array{id: int, nom_fr: string, nom_en: string, nom: string, choix: int,
     *              options: list<array{id: int, produit_id: int, categorie_id: int, libelle: string, format: string, disponible: bool}>}>}|null
     */
    public function formule(int $produitId, bool $toutes = false): ?array
    {
        if (!self::disponible()) {
            return null;
        }
        $cle = $produitId * 2 + (int) $toutes;
        if (array_key_exists($cle, $this->cache)) {
            return $this->cache[$cle];
        }
        $f = Database::un('SELECT * FROM formules WHERE produit_id = ?' . ($toutes ? '' : ' AND actif = 1'), [$produitId]);
        if ($f === null) {
            return $this->cache[$cle] = null;
        }
        $groupes = Database::tous('SELECT * FROM formule_groupes WHERE produit_id = ? ORDER BY position, id', [$produitId]);
        $options = $groupes === [] ? [] : Database::tous(
            'SELECT o.groupe_id, o.conditionnement_id, c.nom_fr AS format_fr, c.nom_en AS format_en, c.quantite_base, p.id AS produit_id,
                    p.categorie_id, p.nom_fr, p.nom_en, p.taille_fr, p.taille_en, p.contenance, p.disponible, p.actif,
                    pp.nom_fr AS parent_fr, pp.nom_en AS parent_en, pp.disponible AS parent_disponible
               FROM formule_options o
               JOIN conditionnements c ON c.id = o.conditionnement_id
               JOIN produits p ON p.id = c.produit_id
          LEFT JOIN produits pp ON pp.id = p.parent_id
              WHERE o.groupe_id IN (' . implode(',', array_map(static fn (array $g) => (int) $g['id'], $groupes)) . ')
           ORDER BY o.position, o.id'
        );
        $parGroupe = [];
        foreach ($options as $o) {
            $parGroupe[(int) $o['groupe_id']][] = [
                'id' => (int) $o['conditionnement_id'],
                'produit_id' => (int) $o['produit_id'],
                'categorie_id' => (int) $o['categorie_id'],
                'libelle' => self::libelle($o),
                'format' => champ(['nom_fr' => $o['format_fr'], 'nom_en' => $o['format_en']], 'nom'),
                'disponible' => (bool) $o['actif'] && (bool) $o['disponible'] && ($o['parent_disponible'] === null || (bool) $o['parent_disponible']),
            ];
        }
        return $this->cache[$cle] = [
            'produit_id' => $produitId,
            'actif' => (bool) $f['actif'],
            'groupes' => array_map(static fn (array $g) => [
                'id' => (int) $g['id'], 'nom_fr' => $g['nom_fr'], 'nom_en' => $g['nom_en'], 'nom' => champ($g, 'nom'),
                'choix' => max(1, (int) $g['choix']), 'options' => $parGroupe[(int) $g['id']] ?? [],
            ], $groupes),
        ];
    }

    /** « Coca-Cola », « Primus Grande 72 cl » : nom du produit (et de sa taille). */
    private static function libelle(array $o): string
    {
        if ($o['parent_fr'] !== null) {
            $taille = GestionCatalogueRepository::libelleTaille($o);
            $parent = champ(['nom_fr' => $o['parent_fr'], 'nom_en' => $o['parent_en']], 'nom');
            return $taille !== '' ? $parent . ' ' . $taille : $parent;
        }
        return champ($o, 'nom');
    }

    /**
     * Vérifie les composants choisis pour UNE formule : dans chaque groupe, exactement « choix » articles parmi ses
     * options (un groupe d'une seule option se remplit seul) ; un format épuisé (stock en mode « bloquer ») ou en
     * rupture est refusé si $verifierStock.
     * @param list<int> $epuises formats épuisés au comptoir
     * @return list<array{conditionnement_id: int, produit_id: int, categorie_id: int, libelle: string, format: string, quantite: int}>
     * @throws \InvalidArgumentException code « composants » ou « epuise:<libellé> »
     */
    public function valider(array $formule, mixed $choix, array $epuises = [], bool $verifierStock = true): array
    {
        $demandes = [];
        foreach (is_array($choix) ? $choix : [] as $c) {
            $id = (int) ($c['id'] ?? 0);
            $q = (int) ($c['qte'] ?? 0);
            if ($id <= 0 || $q < 1 || $q > 99) {
                throw new \InvalidArgumentException('composants');
            }
            $demandes[$id] = ($demandes[$id] ?? 0) + $q;
        }
        $horsStock = array_flip($epuises);
        $composants = [];
        foreach ($formule['groupes'] as $g) {
            $options = array_column($g['options'], null, 'id');
            if ($options === []) {
                throw new \InvalidArgumentException('composants');
            }
            if (count($options) === 1) {
                $seule = array_key_first($options);
                $demandes[$seule] = max($demandes[$seule] ?? 0, $g['choix']);
            }
            $total = 0;
            foreach ($options as $id => $o) {
                $q = $demandes[$id] ?? 0;
                if ($q <= 0) {
                    continue;
                }
                $pris = min($q, $g['choix'] - $total);
                if ($pris <= 0) {
                    continue;
                }
                if ($verifierStock && (!$o['disponible'] || isset($horsStock[$id]))) {
                    throw new \InvalidArgumentException('epuise:' . $o['libelle']);
                }
                $demandes[$id] -= $pris;
                $total += $pris;
                $composants[] = $o + ['conditionnement_id' => $id, 'quantite' => $pris];
            }
            if ($total !== $g['choix']) {
                throw new \InvalidArgumentException('composants');
            }
        }
        // Un article choisi qui n'appartient à aucun groupe (ou en trop)
        if (array_filter($demandes, static fn (int $q) => $q > 0) !== []) {
            throw new \InvalidArgumentException('composants');
        }
        return $composants;
    }

    /**
     * Formats d'une formule épuisés : un groupe dont toutes les options sont épuisées ou en rupture rend la formule
     * impossible à vendre.
     * @param list<int> $epuises formats épuisés au comptoir
     */
    public function epuisee(array $formule, array $epuises): bool
    {
        $hors = array_flip($epuises);
        foreach ($formule['groupes'] as $g) {
            $ok = array_filter($g['options'], static fn (array $o) => $o['disponible'] && !isset($hors[$o['id']]));
            if ($ok === []) {
                return true;
            }
        }
        return false;
    }

    // ---------- Gestion ----------

    /** Formules (actives ou non) avec le nom du produit et ses groupes. */
    public function liste(): array
    {
        if (!self::disponible()) {
            return [];
        }
        $lignes = Database::tous(
            'SELECT f.produit_id, f.actif, p.nom_fr, p.nom_en, p.actif AS produit_actif, c.nom_fr AS categorie_fr, c.nom_en AS categorie_en
               FROM formules f JOIN produits p ON p.id = f.produit_id JOIN categories c ON c.id = p.categorie_id
           ORDER BY p.nom_fr'
        );
        foreach ($lignes as &$l) {
            $l['formule'] = $this->formule((int) $l['produit_id'], true);
        }
        unset($l);
        return $lignes;
    }

    /** Produits qui peuvent devenir une formule (actifs, pas encore formule, sans tailles). */
    public function candidats(): array
    {
        return Database::tous(
            'SELECT p.id, p.nom_fr, p.nom_en, c.nom_fr AS categorie_fr, c.nom_en AS categorie_en FROM produits p
               JOIN categories c ON c.id = p.categorie_id
              WHERE p.actif = 1 AND p.parent_id IS NULL AND NOT EXISTS (SELECT 1 FROM formules f WHERE f.produit_id = p.id)
                AND NOT EXISTS (SELECT 1 FROM produits t WHERE t.parent_id = p.id)
           ORDER BY c.position, p.position, p.nom_fr'
        );
    }

    /** Formats vendables, rangés par catégorie, pour choisir les composants. */
    public function formatsVendables(): array
    {
        $lignes = Database::tous(
            'SELECT c.id, c.nom_fr AS format_fr, c.nom_en AS format_en, p.id AS produit_id, p.categorie_id, p.nom_fr, p.nom_en, p.taille_fr, p.taille_en,
                    p.contenance, p.disponible, p.actif, pp.nom_fr AS parent_fr, pp.nom_en AS parent_en, pp.disponible AS parent_disponible,
                    cat.nom_fr AS categorie_fr, cat.nom_en AS categorie_en
               FROM conditionnements c
               JOIN produits p ON p.id = c.produit_id AND p.actif = 1
          LEFT JOIN produits pp ON pp.id = p.parent_id
               JOIN categories cat ON cat.id = p.categorie_id
              WHERE c.vendable = 1 AND NOT EXISTS (SELECT 1 FROM formules f WHERE f.produit_id = p.id)
           ORDER BY cat.position, cat.id, COALESCE(pp.nom_fr, p.nom_fr), p.position, c.position'
        );
        return array_map(static fn (array $l) => [
            'id' => (int) $l['id'], 'libelle' => self::libelle($l), 'format' => champ(['nom_fr' => $l['format_fr'], 'nom_en' => $l['format_en']], 'nom'),
            'categorie' => champ(['nom_fr' => $l['categorie_fr'], 'nom_en' => $l['categorie_en']], 'nom'),
        ], $lignes);
    }

    /**
     * Enregistre une formule : ses groupes (nom, nombre à choisir, formats), remplacés en entier.
     * @param list<array{nom_fr: string, nom_en: string, choix: int, options: list<int>}> $groupes
     */
    public function enregistrer(int $produitId, bool $actif, array $groupes, int $par): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            Database::executer('INSERT INTO formules (produit_id, actif) VALUES (?, ?) ON DUPLICATE KEY UPDATE actif = VALUES(actif)', [$produitId, $actif ? 1 : 0]);
            Database::executer('DELETE FROM formule_groupes WHERE produit_id = ?', [$produitId]);
            foreach (array_values($groupes) as $i => $g) {
                Database::executer('INSERT INTO formule_groupes (produit_id, nom_fr, nom_en, choix, position) VALUES (?, ?, ?, ?, ?)',
                    [$produitId, $g['nom_fr'], $g['nom_en'] !== '' ? $g['nom_en'] : $g['nom_fr'], $g['choix'], $i]);
                $groupeId = Database::dernierId();
                foreach (array_values(array_unique($g['options'])) as $k => $condId) {
                    Database::executer('INSERT INTO formule_options (groupe_id, conditionnement_id, position) VALUES (?, ?, ?)', [$groupeId, $condId, $k]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $this->cache = [];
        Journal::ecrire('formule_enregistree', (string) $produitId, ['groupes' => count($groupes)], $par);
    }

    public function retirer(int $produitId, int $par): void
    {
        Database::executer('DELETE FROM formules WHERE produit_id = ?', [$produitId]);
        $this->cache = [];
        Journal::ecrire('formule_retiree', (string) $produitId, [], $par);
    }
}
