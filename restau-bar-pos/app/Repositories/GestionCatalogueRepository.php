<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Catalogue côté espace de gestion : catégories, produits, formats (conditionnements), prix.
 * La lecture du menu pour la vitrine et la caisse reste dans CatalogueRepository.
 */
final class GestionCatalogueRepository
{
    public const UNITES = ['bouteille', 'canette', 'verre', 'cl', 'l', 'g', 'kg', 'piece'];
    public const BADGES = ['nouveau', 'populaire', 'epice'];

    // Catégories

    /**
     * Catégories en service, en arbre aplati : chaque catégorie principale est suivie de ses
     * sous-catégories (deux niveaux au plus). Chaque ligne porte « niveau » (0 ou 1),
     * « nb_produits » (produits principaux actifs, tailles non comptées) et « nb_sous ».
     */
    public function categories(): array
    {
        $lignes = Database::tous(
            'SELECT c.*,
                    (SELECT COUNT(*) FROM produits p WHERE p.categorie_id = c.id AND p.actif = 1 AND p.parent_id IS NULL) AS nb_produits,
                    (SELECT COUNT(*) FROM categories s WHERE s.parent_id = c.id AND s.actif = 1) AS nb_sous
               FROM categories c
              WHERE c.actif = 1
              ORDER BY c.position, c.id'
        );
        return self::arbre($lignes);
    }

    /** Range des catégories (déjà triées par position) en arbre aplati : parente, puis ses sous-catégories. */
    public static function arbre(array $lignes): array
    {
        $ids = array_flip(array_map('intval', array_column($lignes, 'id')));
        $enfants = [];
        $racines = [];
        foreach ($lignes as $ligne) {
            $parent = $ligne['parent_id'] === null ? 0 : (int) $ligne['parent_id'];
            // Une sous-catégorie dont la parente n'est pas dans la liste est affichée comme une catégorie principale
            if ($parent !== 0 && isset($ids[$parent])) {
                $enfants[$parent][] = $ligne;
            } else {
                $racines[] = $ligne;
            }
        }
        $arbre = [];
        foreach ($racines as $racine) {
            $racine['niveau'] = 0;
            $arbre[] = $racine;
            foreach ($enfants[(int) $racine['id']] ?? [] as $enfant) {
                $enfant['niveau'] = 1;
                $enfant['parente'] = $racine;
                $arbre[] = $enfant;
            }
        }
        return $arbre;
    }

    public function categorie(int $id): ?array
    {
        return Database::un('SELECT * FROM categories WHERE id = ? AND actif = 1', [$id]);
    }

    /** @param ?int $parentId null = catégorie principale ; sinon sous-catégorie de cette catégorie */
    public function creerCategorie(string $nomFr, string $nomEn, bool $visible, ?int $parentId = null): int
    {
        Database::executer(
            'INSERT INTO categories (parent_id, nom_fr, nom_en, visible_public, position) VALUES (?, ?, ?, ?, ?)',
            [$parentId, $nomFr, $nomEn, (int) $visible, $this->positionSuivante($parentId)]
        );
        return Database::dernierId();
    }

    public function modifierCategorie(int $id, string $nomFr, string $nomEn, bool $visible, ?int $parentId = null): void
    {
        $actuelle = Database::un('SELECT parent_id, position FROM categories WHERE id = ?', [$id]);
        $position = (int) $actuelle['position'];
        $avant = $actuelle['parent_id'] === null ? null : (int) $actuelle['parent_id'];
        if ($avant !== $parentId) {
            // Changement de niveau ou de parente : la catégorie passe en fin de son nouveau niveau
            $position = $this->positionSuivante($parentId);
        }
        Database::executer(
            'UPDATE categories SET parent_id = ?, nom_fr = ?, nom_en = ?, visible_public = ?, position = ? WHERE id = ?',
            [$parentId, $nomFr, $nomEn, (int) $visible, $position, $id]
        );
    }

    /** Sous-catégories en service d'une catégorie. */
    public function sousCategories(int $id): array
    {
        return Database::tous('SELECT * FROM categories WHERE parent_id = ? AND actif = 1 ORDER BY position, id', [$id]);
    }

    /**
     * Vérifie la catégorie parente choisie (deux niveaux au plus, pas de boucle).
     * @param ?array $categorie la catégorie modifiée, null à la création
     * @return ?string message d'erreur, null si le choix est permis
     */
    public function erreurParente(?array $categorie, ?int $parentId): ?string
    {
        if ($parentId === null) {
            return null;
        }
        $parente = $this->categorie($parentId);
        if ($parente === null) {
            return t('categories.erreur_parente_inconnue');
        }
        if ($categorie !== null) {
            $nom = champ($categorie, 'nom');
            if ((int) $categorie['id'] === $parentId) {
                return t('categories.erreur_parente_elle_meme', ['nom' => $nom]);
            }
            if ((int) ($parente['parent_id'] ?? 0) === (int) $categorie['id']) {
                return t('categories.erreur_parente_boucle', ['nom' => $nom, 'parente' => champ($parente, 'nom')]);
            }
            $sous = $this->sousCategories((int) $categorie['id']);
            if ($sous !== []) {
                return t('categories.erreur_parente_a_sous', ['nom' => $nom, 'liste' => implode(', ', array_map(static fn (array $c) => champ($c, 'nom'), $sous))]);
            }
        }
        if ($parente['parent_id'] !== null) {
            return t('categories.erreur_parente_niveau', ['parente' => champ($parente, 'nom')]);
        }
        return null;
    }

    private function positionSuivante(?int $parentId): int
    {
        return (int) Database::un(
            'SELECT COALESCE(MAX(position), 0) + 1 AS n FROM categories WHERE actif = 1 AND parent_id <=> ?',
            [$parentId]
        )['n'];
    }

    /**
     * Supprime une catégorie sans sous-catégorie ni produit actif. Si elle garde des produits
     * désactivés ou des sous-catégories archivées, elle est seulement archivée (ils y restent rattachés).
     *
     * @return ?string null si la catégorie est supprimée, sinon la raison du refus :
     *                 « sous_categories » ou « produits »
     */
    public function supprimerCategorie(int $id): ?string
    {
        if ($this->sousCategories($id) !== []) {
            return 'sous_categories';
        }
        $compte = Database::un(
            'SELECT SUM(actif = 1) AS actifs, COUNT(*) AS tous FROM produits WHERE categorie_id = ?',
            [$id]
        );
        if ((int) $compte['actifs'] > 0) {
            return 'produits';
        }
        $archivees = (int) Database::un('SELECT COUNT(*) AS n FROM categories WHERE parent_id = ?', [$id])['n'];
        if ((int) $compte['tous'] > 0 || $archivees > 0) {
            Database::executer('UPDATE categories SET actif = 0 WHERE id = ?', [$id]);
        } else {
            Database::executer('DELETE FROM categories WHERE id = ?', [$id]);
        }
        return null;
    }

    /** Monte (-1) ou descend (+1) une catégorie d'un cran, parmi celles de son niveau (même parente). */
    public function deplacerCategorie(int $id, int $sens): void
    {
        $categorie = $this->categorie($id);
        if ($categorie === null) {
            return;
        }
        $ids = array_map('intval', array_column(
            Database::tous(
                'SELECT id FROM categories WHERE actif = 1 AND parent_id <=> ? ORDER BY position, id',
                [$categorie['parent_id'] === null ? null : (int) $categorie['parent_id']]
            ),
            'id'
        ));
        $this->echanger('categories', $ids, $id, $sens);
    }

    // Produits

    /**
     * Produits principaux pour la liste de gestion, dans l'ordre du menu, chacun avec ses tailles
     * (« tailles ») sous lui. Filtres : categorie (id, sous-catégories comprises), etablissement (id),
     * recherche (texte, noms des tailles compris), desactives (bool).
     * Avec « desactives », une taille désactivée d'un produit encore actif est listée seule.
     */
    public function produits(array $filtres, int $grilleId, ?int $deviseReferenceId): array
    {
        $actif = empty($filtres['desactives']) ? 1 : 0;
        $conditions = ['p.actif = ?'];
        $params = [$actif];
        $conditions[] = $actif
            ? 'p.parent_id IS NULL'
            : '(p.parent_id IS NULL OR EXISTS (SELECT 1 FROM produits m WHERE m.id = p.parent_id AND m.actif = 1))';

        if (!empty($filtres['categorie'])) {
            $conditions[] = '(p.categorie_id = ? OR p.categorie_id IN (SELECT s.id FROM categories s WHERE s.parent_id = ?))';
            array_push($params, (int) $filtres['categorie'], (int) $filtres['categorie']);
        }
        if (!empty($filtres['etablissement'])) {
            $conditions[] = '(EXISTS (SELECT 1 FROM produit_etablissements pe WHERE pe.produit_id = p.id AND pe.etablissement_id = ?)
                OR EXISTS (SELECT 1 FROM produits v JOIN produit_etablissements pv ON pv.produit_id = v.id
                            WHERE v.parent_id = p.id AND v.actif = p.actif AND pv.etablissement_id = ?))';
            array_push($params, (int) $filtres['etablissement'], (int) $filtres['etablissement']);
        }
        if (($filtres['recherche'] ?? '') !== '') {
            $motif = '%' . addcslashes((string) $filtres['recherche'], '%_\\') . '%';
            $conditions[] = '(p.nom_fr LIKE ? OR p.nom_en LIKE ? OR EXISTS (SELECT 1 FROM produits v WHERE v.parent_id = p.id
                AND (v.taille_fr LIKE ? OR v.taille_en LIKE ? OR v.contenance LIKE ?)))';
            array_push($params, $motif, $motif, $motif, $motif, $motif);
        }

        $produits = Database::tous(
            'SELECT p.*, c.nom_fr AS categorie_fr, c.nom_en AS categorie_en, c.visible_public AS categorie_visible, c.actif AS categorie_active,
                    c.parent_id AS categorie_parente, m.nom_fr AS parent_fr, m.nom_en AS parent_en
               FROM produits p
               JOIN categories c ON c.id = p.categorie_id
               LEFT JOIN produits m ON m.id = p.parent_id
              WHERE ' . implode(' AND ', $conditions) . '
              ORDER BY c.actif DESC, c.position, c.id, p.position, p.id',
            $params
        );
        if ($produits === []) {
            return [];
        }

        // Tailles des produits principaux trouvés (actives, ou désactivées dans la liste des désactivés)
        $principaux = array_map('intval', array_column(array_filter($produits, static fn (array $p) => $p['parent_id'] === null), 'id'));
        $tailles = $principaux === [] ? [] : Database::tous(
            'SELECT * FROM produits WHERE actif = ? AND parent_id IN (' . implode(',', array_fill(0, count($principaux), '?')) . ')
              ORDER BY position, id',
            array_merge([$actif], $principaux)
        );

        $ids = array_map('intval', array_merge(array_column($produits, 'id'), array_column($tailles, 'id')));
        $marques = implode(',', array_fill(0, count($ids), '?'));

        $etablissements = [];
        foreach (Database::tous(
            "SELECT pe.produit_id, e.nom FROM produit_etablissements pe
               JOIN etablissements e ON e.id = pe.etablissement_id
              WHERE e.actif = 1 AND pe.produit_id IN ($marques)
              ORDER BY e.position, e.nom",
            $ids
        ) as $ligne) {
            $etablissements[$ligne['produit_id']][] = $ligne['nom'];
        }

        // Prix principal : premier format vendable, grille par défaut, devise de référence
        $formats = [];
        foreach (Database::tous(
            "SELECT c.produit_id, c.nom_fr, c.nom_en, c.quantite_base, pr.montant
               FROM conditionnements c
               LEFT JOIN prix pr ON pr.conditionnement_id = c.id AND pr.grille_prix_id = ? AND pr.devise_id = ?
              WHERE c.vendable = 1 AND c.produit_id IN ($marques)
              ORDER BY c.position, c.id",
            array_merge([$grilleId, (int) $deviseReferenceId], $ids)
        ) as $ligne) {
            $formats[$ligne['produit_id']][] = $ligne;
        }

        $parParent = [];
        foreach ($tailles as $taille) {
            $taille['etablissements'] = $etablissements[$taille['id']] ?? [];
            $taille['formats'] = $formats[$taille['id']] ?? [];
            $parParent[$taille['parent_id']][] = $taille;
        }
        foreach ($produits as &$produit) {
            $produit['etablissements'] = $etablissements[$produit['id']] ?? [];
            $produit['formats'] = $formats[$produit['id']] ?? [];
            $produit['tailles'] = $parParent[$produit['id']] ?? [];
            // Établissements des tailles, quand le produit principal n'en a pas à lui
            foreach ($produit['tailles'] as $taille) {
                $produit['etablissements'] = array_values(array_unique(array_merge($produit['etablissements'], $taille['etablissements'])));
            }
        }
        return $produits;
    }

    public function produit(int $id): ?array
    {
        return Database::un('SELECT * FROM produits WHERE id = ?', [$id]);
    }

    // Tailles (produits variantes d'un produit principal)

    /** « Petite 33 cl » : nom de la taille suivi de la contenance (si elle n'y est pas déjà). */
    public static function libelleTaille(array $produit): string
    {
        $taille = champ($produit, 'taille');
        $contenance = trim((string) ($produit['contenance'] ?? ''));
        if ($contenance === '' || mb_stripos($taille, $contenance) !== false) {
            return $taille;
        }
        return $taille === '' ? $contenance : $taille . ' ' . $contenance;
    }

    /**
     * Tailles d'un produit principal, dans leur ordre, avec le prix principal de chacune
     * (premier format vendu, grille par défaut, devise de référence) dans « prix_principal ».
     */
    public function tailles(int $parentId, int $grilleId, ?int $deviseReferenceId, bool $actives = true): array
    {
        $tailles = Database::tous(
            'SELECT * FROM produits WHERE parent_id = ?' . ($actives ? ' AND actif = 1' : '') . ' ORDER BY position, id',
            [$parentId]
        );
        foreach ($tailles as &$taille) {
            $taille['formats'] = Database::tous(
                'SELECT c.*, pr.montant FROM conditionnements c
                   LEFT JOIN prix pr ON pr.conditionnement_id = c.id AND pr.grille_prix_id = ? AND pr.devise_id = ?
                  WHERE c.produit_id = ? AND c.vendable = 1
                  ORDER BY c.position, c.id',
                [$grilleId, (int) $deviseReferenceId, $taille['id']]
            );
        }
        return $tailles;
    }

    /**
     * Ajoute une taille à un produit principal. Elle reprend la catégorie, les établissements, l'unité de base,
     * les badges, les allergènes, l'affichage et la photo du produit principal ; ses formats et prix restent à saisir.
     */
    public function creerTaille(array $parent, string $tailleFr, string $tailleEn, ?string $contenance): int
    {
        $parentId = (int) $parent['id'];
        $position = (int) Database::un('SELECT COALESCE(MAX(position), 0) + 1 AS n FROM produits WHERE parent_id = ?', [$parentId])['n'];
        Database::executer(
            'INSERT INTO produits (categorie_id, parent_id, nom_fr, nom_en, taille_fr, taille_en, contenance, photo, photo_credit,
                                   unite_base, badges, allergenes_fr, allergenes_en, visible_public, disponible, position)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)',
            [
                $parent['categorie_id'], $parentId, '', '', $tailleFr, $tailleEn === '' ? null : $tailleEn, $contenance,
                $parent['photo'], $parent['photo_credit'], $parent['unite_base'], $parent['badges'],
                $parent['allergenes_fr'], $parent['allergenes_en'], (int) $parent['visible_public'], $position,
            ]
        );
        $id = Database::dernierId();
        $this->definirEtablissements($id, $this->etablissementsDuProduit($parentId));
        $this->synchroniserTailles($parentId);
        return $id;
    }

    public function definirTaille(int $id, string $tailleFr, string $tailleEn, ?string $contenance): void
    {
        Database::executer(
            'UPDATE produits SET taille_fr = ?, taille_en = ?, contenance = ? WHERE id = ?',
            [$tailleFr, $tailleEn === '' ? null : $tailleEn, $contenance, $id]
        );
        $parent = Database::un('SELECT parent_id FROM produits WHERE id = ?', [$id]);
        if ($parent && $parent['parent_id'] !== null) {
            $this->synchroniserTailles((int) $parent['parent_id']);
        }
    }

    /**
     * Les tailles suivent leur produit principal : même catégorie, et nom « Primus Petite »
     * (nom du produit principal suivi de la taille), utile pour la recherche et les futurs rapports.
     */
    public function synchroniserTailles(int $parentId): void
    {
        Database::executer(
            "UPDATE produits v JOIN produits p ON p.id = v.parent_id
                SET v.categorie_id = p.categorie_id,
                    v.nom_fr = LEFT(CONCAT(p.nom_fr, ' ', v.taille_fr), 128),
                    v.nom_en = LEFT(CONCAT(IF(p.nom_en = '', p.nom_fr, p.nom_en), ' ', COALESCE(NULLIF(v.taille_en, ''), v.taille_fr)), 128)
              WHERE v.parent_id = ?",
            [$parentId]
        );
    }

    /** @param array $donnees colonnes de la table produits (informations et vente) */
    public function creerProduit(array $donnees): int
    {
        $position = (int) Database::un(
            'SELECT COALESCE(MAX(position), 0) + 1 AS n FROM produits WHERE categorie_id = ? AND parent_id IS NULL',
            [$donnees['categorie_id']]
        )['n'];
        Database::executer(
            'INSERT INTO produits (categorie_id, nom_fr, nom_en, description_fr, description_en, unite_base, badges,
                                   allergenes_fr, allergenes_en, visible_public, disponible, position)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $donnees['categorie_id'], $donnees['nom_fr'], $donnees['nom_en'],
                $donnees['description_fr'], $donnees['description_en'], $donnees['unite_base'], $donnees['badges'],
                $donnees['allergenes_fr'], $donnees['allergenes_en'],
                (int) $donnees['visible_public'], (int) $donnees['disponible'], $position,
            ]
        );
        return Database::dernierId();
    }

    public function modifierInformations(int $id, array $donnees): void
    {
        $actuel = $this->produit($id);
        $position = $actuel['position'];
        if ((int) $actuel['categorie_id'] !== (int) $donnees['categorie_id'] && $actuel['parent_id'] === null) {
            // Changement de catégorie : le produit passe en fin de la nouvelle catégorie
            $position = (int) Database::un(
                'SELECT COALESCE(MAX(position), 0) + 1 AS n FROM produits WHERE categorie_id = ? AND parent_id IS NULL',
                [$donnees['categorie_id']]
            )['n'];
        }
        Database::executer(
            'UPDATE produits SET categorie_id = ?, nom_fr = ?, nom_en = ?, description_fr = ?, description_en = ?,
                    unite_base = ?, badges = ?, allergenes_fr = ?, allergenes_en = ?, position = ?
              WHERE id = ?',
            [
                $donnees['categorie_id'], $donnees['nom_fr'], $donnees['nom_en'],
                $donnees['description_fr'], $donnees['description_en'], $donnees['unite_base'], $donnees['badges'],
                $donnees['allergenes_fr'], $donnees['allergenes_en'], $actuel['parent_id'] === null ? $position : $actuel['position'], $id,
            ]
        );
        if ($actuel['parent_id'] === null) {
            $this->synchroniserTailles($id);
        }
    }

    public function modifierVente(int $id, bool $visible, bool $disponible, array $etablissementIds): void
    {
        Database::executer('UPDATE produits SET visible_public = ?, disponible = ? WHERE id = ?', [(int) $visible, (int) $disponible, $id]);
        $this->definirEtablissements($id, $etablissementIds);
    }

    /** @param int[] $etablissementIds */
    public function definirEtablissements(int $produitId, array $etablissementIds): void
    {
        Database::executer('DELETE FROM produit_etablissements WHERE produit_id = ?', [$produitId]);
        foreach (array_unique($etablissementIds) as $etablissementId) {
            Database::executer(
                'INSERT INTO produit_etablissements (produit_id, etablissement_id) VALUES (?, ?)',
                [$produitId, $etablissementId]
            );
        }
    }

    /** @return int[] */
    public function etablissementsDuProduit(int $produitId): array
    {
        return array_map('intval', array_column(
            Database::tous('SELECT etablissement_id FROM produit_etablissements WHERE produit_id = ?', [$produitId]),
            'etablissement_id'
        ));
    }

    public function definirPhoto(int $id, ?string $photo, ?string $credit): void
    {
        Database::executer('UPDATE produits SET photo = ?, photo_credit = ? WHERE id = ?', [$photo, $credit, $id]);
    }

    public function definirCreditPhoto(int $id, ?string $credit): void
    {
        Database::executer('UPDATE produits SET photo_credit = ? WHERE id = ?', [$credit, $id]);
    }

    /** Bascule disponible / épuisé et rend le nouvel état. */
    public function basculerDisponible(int $id): bool
    {
        Database::executer('UPDATE produits SET disponible = 1 - disponible WHERE id = ?', [$id]);
        return (bool) Database::un('SELECT disponible FROM produits WHERE id = ?', [$id])['disponible'];
    }

    /** Désactive le produit ; un produit principal entraîne ses tailles. */
    public function desactiver(int $id): void
    {
        Database::executer('UPDATE produits SET actif = 0 WHERE id = ? OR parent_id = ?', [$id, $id]);
    }

    /**
     * Réactive le produit, et sa catégorie (avec sa catégorie parente) si elle avait été archivée.
     * Un produit principal retrouve ses tailles ; une taille fait revenir son produit principal.
     */
    public function reactiver(int $id): void
    {
        $produit = $this->produit($id);
        if ($produit === null) {
            return;
        }
        if ($produit['parent_id'] === null) {
            Database::executer('UPDATE produits SET actif = 1 WHERE id = ? OR parent_id = ?', [$id, $id]);
        } else {
            Database::executer('UPDATE produits SET actif = 1 WHERE id IN (?, ?)', [$id, (int) $produit['parent_id']]);
        }
        Database::executer(
            'UPDATE categories c JOIN produits p ON p.categorie_id = c.id
                SET c.actif = 1 WHERE p.id = ? AND c.actif = 0',
            [$id]
        );
        Database::executer(
            'UPDATE categories c JOIN categories s ON s.parent_id = c.id JOIN produits p ON p.categorie_id = s.id
                SET c.actif = 1 WHERE p.id = ? AND c.actif = 0',
            [$id]
        );
    }

    /** Monte (-1) ou descend (+1) un produit d'un cran dans sa catégorie, ou une taille parmi les tailles de son produit. */
    public function deplacerProduit(int $id, int $sens): void
    {
        $produit = $this->produit($id);
        if ($produit === null) {
            return;
        }
        $freres = $produit['parent_id'] === null
            ? Database::tous('SELECT id FROM produits WHERE categorie_id = ? AND parent_id IS NULL AND actif = 1 ORDER BY position, id', [$produit['categorie_id']])
            : Database::tous('SELECT id FROM produits WHERE parent_id = ? AND actif = 1 ORDER BY position, id', [(int) $produit['parent_id']]);
        $this->echanger('produits', array_map('intval', array_column($freres, 'id')), $id, $sens);
    }

    // Formats (conditionnements)

    public function formats(int $produitId): array
    {
        return Database::tous('SELECT * FROM conditionnements WHERE produit_id = ? ORDER BY position, id', [$produitId]);
    }

    public function format(int $produitId, int $id): ?array
    {
        return Database::un('SELECT * FROM conditionnements WHERE id = ? AND produit_id = ?', [$id, $produitId]);
    }

    public function ajouterFormat(int $produitId, array $donnees): int
    {
        Database::executer(
            'INSERT INTO conditionnements (produit_id, nom_fr, nom_en, quantite_base, vendable, achetable, code_barres, position)
             SELECT ?, ?, ?, ?, ?, ?, ?, COALESCE(MAX(position), 0) + 1 FROM conditionnements WHERE produit_id = ?',
            [
                $produitId, $donnees['nom_fr'], $donnees['nom_en'], $donnees['quantite_base'],
                (int) $donnees['vendable'], (int) $donnees['achetable'], $donnees['code_barres'], $produitId,
            ]
        );
        return Database::dernierId();
    }

    public function modifierFormat(int $id, array $donnees): void
    {
        Database::executer(
            'UPDATE conditionnements SET nom_fr = ?, nom_en = ?, quantite_base = ?, vendable = ?, achetable = ?, code_barres = ?
              WHERE id = ?',
            [
                $donnees['nom_fr'], $donnees['nom_en'], $donnees['quantite_base'],
                (int) $donnees['vendable'], (int) $donnees['achetable'], $donnees['code_barres'], $id,
            ]
        );
    }

    /** Supprime un format et ses prix (clé étrangère en cascade). */
    public function supprimerFormat(int $id): void
    {
        Database::executer('DELETE FROM conditionnements WHERE id = ?', [$id]);
    }

    /** Produit d'un autre format qui porte déjà ce code-barres, sinon null. */
    public function codeBarresUtilise(string $code, int $saufFormatId = 0): ?array
    {
        return Database::un(
            'SELECT p.id, p.nom_fr, p.nom_en, c.nom_fr AS format_fr, c.nom_en AS format_en
               FROM conditionnements c JOIN produits p ON p.id = c.produit_id
              WHERE c.code_barres = ? AND c.id <> ? AND p.actif = 1
              LIMIT 1',
            [$code, $saufFormatId]
        );
    }

    // Prix

    public function grilles(): array
    {
        return Database::tous('SELECT * FROM grilles_prix ORDER BY id');
    }

    /** Grille appliquée par défaut : celle du premier établissement actif, sinon la première grille. */
    public function grilleParDefaut(): int
    {
        $ligne = Database::un('SELECT grille_prix_id FROM etablissements WHERE actif = 1 ORDER BY position, nom LIMIT 1')
            ?? Database::un('SELECT id AS grille_prix_id FROM grilles_prix ORDER BY id LIMIT 1');
        return (int) ($ligne['grille_prix_id'] ?? 0);
    }

    /** @return array<int, array<int, array<int, float>>> [format][grille][devise] => montant */
    public function prixDuProduit(int $produitId): array
    {
        $prix = [];
        foreach (Database::tous(
            'SELECT pr.conditionnement_id, pr.grille_prix_id, pr.devise_id, pr.montant
               FROM prix pr JOIN conditionnements c ON c.id = pr.conditionnement_id
              WHERE c.produit_id = ?',
            [$produitId]
        ) as $ligne) {
            $prix[(int) $ligne['conditionnement_id']][(int) $ligne['grille_prix_id']][(int) $ligne['devise_id']] = (float) $ligne['montant'];
        }
        return $prix;
    }

    /** Enregistre un prix, ou le supprime si le montant est null. */
    public function definirPrix(int $formatId, int $grilleId, int $deviseId, ?float $montant): void
    {
        if ($montant === null) {
            Database::executer(
                'DELETE FROM prix WHERE conditionnement_id = ? AND grille_prix_id = ? AND devise_id = ?',
                [$formatId, $grilleId, $deviseId]
            );
            return;
        }
        Database::executer(
            'INSERT INTO prix (conditionnement_id, grille_prix_id, devise_id, montant) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE montant = VALUES(montant)',
            [$formatId, $grilleId, $deviseId, number_format($montant, 2, '.', '')]
        );
    }

    /** Renumérote 1..n puis échange l'élément avec son voisin. */
    private function echanger(string $table, array $ids, int $id, int $sens): void
    {
        $index = array_search($id, $ids, true);
        if ($index === false) {
            return;
        }
        $voisin = $index + ($sens < 0 ? -1 : 1);
        if (isset($ids[$voisin])) {
            [$ids[$index], $ids[$voisin]] = [$ids[$voisin], $ids[$index]];
        }
        $requete = Database::pdo()->prepare("UPDATE $table SET position = ? WHERE id = ?");
        foreach ($ids as $position => $idLigne) {
            $requete->execute([$position + 1, $idLigne]);
        }
    }
}
