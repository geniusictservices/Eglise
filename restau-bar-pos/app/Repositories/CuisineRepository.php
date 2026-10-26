<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Cuisine (cahier des charges, section 8.2) : postes (grill, froid, pizza, pâtisserie, bar…), routage des lignes
 * vers un poste, écran de chaque poste (reçu → en préparation → prêt → servi), ruptures, écrans autorisés.
 *
 * Routage d'une ligne : la catégorie du produit (ou sa catégorie parente) routée vers un poste dans
 * Cuisine > Postes ; sinon, si le module stock route la catégorie vers un comptoir (Plats → Cuisine),
 * le premier poste rattaché à ce comptoir ; sinon la ligne ne part pas en cuisine (bière servie au bar).
 */
final class CuisineRepository
{
    public const STATUTS = ['recu', 'preparation', 'pret', 'servi'];
    /** Durée pendant laquelle un plat servi reste visible (pour revenir en arrière par erreur) */
    public const SERVI_VISIBLE_S = 60;

    /** @var array<int, array> */
    private array $routage = [];

    // ---------- Postes ----------

    /** @return list<array> postes actifs (ou tous) de l'établissement */
    public function postes(int $etabId, bool $actifsSeulement = true): array
    {
        return Database::tous(
            'SELECT p.*, l.nom AS lieu_nom FROM postes_cuisine p LEFT JOIN lieux_stock l ON l.id = p.lieu_id
              WHERE p.etablissement_id = ?' . ($actifsSeulement ? ' AND p.actif = 1' : '') . ' ORDER BY p.position, p.id',
            [$etabId]
        );
    }

    public function poste(int $id): ?array
    {
        return Database::un('SELECT * FROM postes_cuisine WHERE id = ?', [$id]);
    }

    public function creerPoste(int $etabId, string $nom, ?int $lieuId, int $alerte): int
    {
        $position = (int) Database::un('SELECT COALESCE(MAX(position), 0) + 1 AS p FROM postes_cuisine WHERE etablissement_id = ?', [$etabId])['p'];
        Database::executer(
            'INSERT INTO postes_cuisine (etablissement_id, nom, lieu_id, alerte_minutes, position) VALUES (?, ?, ?, ?, ?)',
            [$etabId, $nom, $lieuId, $alerte, $position]
        );
        $this->routage = [];
        return Database::dernierId();
    }

    public function modifierPoste(int $id, string $nom, ?int $lieuId, int $alerte, bool $actif): void
    {
        Database::executer('UPDATE postes_cuisine SET nom = ?, lieu_id = ?, alerte_minutes = ?, actif = ? WHERE id = ?', [$nom, $lieuId, $alerte, $actif ? 1 : 0, $id]);
        $this->routage = [];
    }

    /** @return array<int, int> catégorie → poste (routage propre à la cuisine) */
    public function routageCategories(int $etabId): array
    {
        return array_map('intval', array_column(Database::tous(
            'SELECT r.categorie_id, r.poste_id FROM cuisine_routage r JOIN postes_cuisine p ON p.id = r.poste_id AND p.actif = 1 WHERE r.etablissement_id = ?',
            [$etabId]
        ), 'poste_id', 'categorie_id'));
    }

    /** Remplace le routage : catégorie → poste (0 ou absent : pas de routage propre). */
    public function enregistrerRoutage(int $etabId, array $routage): void
    {
        $postes = array_map('intval', array_column($this->postes($etabId, false), 'id'));
        Database::executer('DELETE FROM cuisine_routage WHERE etablissement_id = ?', [$etabId]);
        foreach ($routage as $categorie => $poste) {
            if ((int) $poste > 0 && in_array((int) $poste, $postes, true)) {
                Database::executer('INSERT INTO cuisine_routage (etablissement_id, categorie_id, poste_id) VALUES (?, ?, ?)', [$etabId, (int) $categorie, (int) $poste]);
            }
        }
        $this->routage = [];
    }

    /**
     * Poste qui prépare un produit de cette catégorie, ou null (pas de cuisine).
     * Voir la règle en tête de classe ; le module stock n'est consulté que s'il est installé.
     */
    public function postePour(int $etabId, ?int $categorieId, ?int $zoneId = null, ?int $appareilId = null): ?int
    {
        if (!isset($this->routage[$etabId])) {
            $postes = $this->postes($etabId);
            $parLieu = [];
            foreach ($postes as $p) {
                if ($p['lieu_id'] !== null) {
                    $parLieu[(int) $p['lieu_id']] ??= (int) $p['id'];
                }
            }
            $this->routage[$etabId] = [
                'categories' => $this->routageCategories($etabId),
                'parLieu' => $parLieu,
                'parents' => array_map(static fn ($p) => $p === null ? null : (int) $p, array_column(Database::tous('SELECT id, parent_id FROM categories'), 'parent_id', 'id')),
            ];
        }
        $r = $this->routage[$etabId];
        if ($categorieId !== null) {
            if (isset($r['categories'][$categorieId])) {
                return $r['categories'][$categorieId];
            }
            $parent = $r['parents'][$categorieId] ?? null;
            if ($parent !== null && isset($r['categories'][$parent])) {
                return $r['categories'][$parent];
            }
        }
        if ($r['parLieu'] !== [] && class_exists(StockRepository::class) && method_exists(StockRepository::class, 'comptoirPour')) {
            try {
                $lieu = (new StockRepository())->comptoirPour($etabId, $categorieId, $zoneId, $appareilId);
                return $r['parLieu'][$lieu] ?? null;
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }

    // ---------- Écran de cuisine ----------

    /**
     * Bons en cours d'un établissement (tous les postes, ou un seul) : un bon par envoi d'une note à un poste,
     * avec ses lignes non servies (et les servies depuis moins d'une minute), et les plats « à suivre » de la
     * même note pour ce poste (envoi en plusieurs temps).
     * @return list<array>
     */
    public function bons(int $etabId, ?int $posteId): array
    {
        $params = [$etabId];
        $filtre = '';
        if ($posteId !== null) {
            $filtre = ' AND e.poste_id = ?';
            $params[] = $posteId;
        }
        $params[] = date('Y-m-d H:i:s', time() - self::SERVI_VISIBLE_S);
        $lignes = Database::tous(
            "SELECT l.id, l.envoi_id, l.quantite, l.libelle, l.format, l.remarque, l.allergie, l.statut, l.envoye_le, l.pret_le, l.servi_le,
                    e.poste_id, e.cree_le AS envoi_le, n.id AS note_id, n.type, n.nom AS note_nom, n.etablissement_id,
                    t.nom AS table_nom, z.nom_fr AS zone_nom_fr, z.nom_en AS zone_nom_en, u.nom AS ajoute_par_nom, s.nom AS serveur_nom,
                    p.nom AS poste_nom, p.alerte_minutes, v.numero AS vente_numero
               FROM note_lignes l
               JOIN note_envois e ON e.id = l.envoi_id
               JOIN notes n ON n.id = l.note_id
               JOIN postes_cuisine p ON p.id = e.poste_id
          LEFT JOIN salle_tables t ON t.id = n.table_id
          LEFT JOIN zones z ON z.id = n.zone_id
          LEFT JOIN utilisateurs u ON u.id = l.ajoute_par
          LEFT JOIN utilisateurs s ON s.id = n.serveur_id
          LEFT JOIN ventes v ON v.id = n.vente_id
              WHERE n.etablissement_id = ? $filtre AND l.annulee_le IS NULL AND n.statut IN ('ouverte', 'payee')
                AND (l.statut IN ('recu', 'preparation', 'pret') OR (l.statut = 'servi' AND l.servi_le >= ?))
              ORDER BY e.cree_le, e.id, l.id",
            $params
        );
        $bons = [];
        foreach ($lignes as $l) {
            $id = (int) $l['envoi_id'];
            $bons[$id] ??= [
                'id' => $id, 'note' => (int) $l['note_id'], 'poste' => (int) $l['poste_id'], 'posteNom' => $l['poste_nom'],
                'titre' => self::titreNote($l), 'zone' => champ(['nom_fr' => $l['zone_nom_fr'], 'nom_en' => $l['zone_nom_en']], 'nom') ?: null,
                'serveur' => $l['serveur_nom'], 'depuis' => $l['envoi_le'], 'alerte' => (int) $l['alerte_minutes'],
                'lignes' => [], 'suivants' => [],
            ];
            $bons[$id]['lignes'][] = [
                'id' => (int) $l['id'], 'qte' => (int) $l['quantite'], 'libelle' => $l['libelle'], 'format' => $l['format'],
                'remarque' => $l['remarque'], 'allergie' => $l['allergie'], 'statut' => $l['statut'], 'par' => $l['ajoute_par_nom'],
            ];
        }
        if ($bons === []) {
            return [];
        }
        // Plats « à suivre » (pas encore lancés par le serveur) des mêmes notes et des mêmes postes
        $notes = array_unique(array_column($bons, 'note'));
        $attente = Database::tous(
            'SELECT id, note_id, poste_id, quantite, libelle, remarque, allergie FROM note_lignes
              WHERE statut = \'attente\' AND annulee_le IS NULL AND note_id IN (' . implode(',', array_fill(0, count($notes), '?')) . ') ORDER BY id',
            array_values($notes)
        );
        foreach ($bons as &$bon) {
            foreach ($attente as $a) {
                if ((int) $a['note_id'] === $bon['note'] && (int) $a['poste_id'] === $bon['poste']) {
                    $bon['suivants'][] = ['id' => (int) $a['id'], 'qte' => (int) $a['quantite'], 'libelle' => $a['libelle'], 'remarque' => $a['remarque'], 'allergie' => $a['allergie']];
                }
            }
            $statuts = array_column($bon['lignes'], 'statut');
            $bon['statut'] = match (true) {
                !array_diff($statuts, ['servi']) => 'servi',
                !array_diff($statuts, ['pret', 'servi']) => 'pret',
                in_array('preparation', $statuts, true) || in_array('pret', $statuts, true) => 'preparation',
                default => 'recu',
            };
        }
        unset($bon);
        // Une seule carte de « suivants » par note et par poste : sur son dernier bon
        $vus = [];
        foreach (array_reverse(array_keys($bons)) as $id) {
            $cle = $bons[$id]['note'] . '-' . $bons[$id]['poste'];
            if (isset($vus[$cle])) {
                $bons[$id]['suivants'] = [];
            }
            $vus[$cle] = true;
        }
        return array_values($bons);
    }

    /** « Table T4 », « Bar — Patrick », « Comptoir CDL-000123 » */
    public static function titreNote(array $n): string
    {
        if (!empty($n['table_nom'])) {
            return t('salle.table_nom', ['nom' => $n['table_nom']]);
        }
        if (($n['type'] ?? 'note') === 'comptoir') {
            return t('salle.comptoir_vente', ['numero' => $n['vente_numero'] ?? '']);
        }
        return (string) ($n['note_nom'] ?? $n['nom'] ?? t('salle.note_sans_nom'));
    }

    /**
     * Fait avancer (ou reculer) des lignes en cuisine ; seulement celles de l'établissement, jamais annulées.
     * @param list<int> $ids
     * @return int lignes changées
     */
    public function changerStatut(int $etabId, array $ids, string $statut): int
    {
        if ($ids === [] || !in_array($statut, self::STATUTS, true)) {
            return 0;
        }
        $colonne = ['recu' => null, 'preparation' => 'preparation_le', 'pret' => 'pret_le', 'servi' => 'servi_le'][$statut];
        $maintenant = date('Y-m-d H:i:s');
        $marques = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'UPDATE note_lignes l JOIN notes n ON n.id = l.note_id SET l.statut = ?'
            . ($colonne ? ', l.' . $colonne . ' = ?' : '')
            // Revenir en arrière efface les heures suivantes (temps de préparation juste)
            . ($statut === 'recu' ? ', l.preparation_le = NULL, l.pret_le = NULL, l.servi_le = NULL' : '')
            . ($statut === 'preparation' ? ', l.pret_le = NULL, l.servi_le = NULL' : '')
            . ($statut === 'pret' ? ', l.servi_le = NULL' : '')
            . " WHERE n.etablissement_id = ? AND l.annulee_le IS NULL AND l.statut IN ('recu', 'preparation', 'pret', 'servi') AND l.id IN ($marques)";
        $params = array_merge([$statut], $colonne ? [$maintenant] : [], [$etabId], array_map('intval', $ids));
        $n = Database::executer($sql, $params);
        if ($n > 0) {
            Database::executer('UPDATE notes n JOIN note_lignes l ON l.note_id = n.id SET n.version = n.version + 1 WHERE l.id IN (' . $marques . ')', array_map('intval', $ids));
        }
        return $n;
    }

    /** Lignes d'un bon (envoi) de l'établissement. @return list<int> */
    public function lignesDuBon(int $etabId, int $envoiId): array
    {
        return array_map('intval', array_column(Database::tous(
            'SELECT l.id FROM note_lignes l JOIN notes n ON n.id = l.note_id WHERE l.envoi_id = ? AND n.etablissement_id = ? AND l.annulee_le IS NULL',
            [$envoiId, $etabId]
        ), 'id'));
    }

    /** Tout ce qu'il faut pour imprimer un bon de cuisine (80 mm). */
    public function bon(int $envoiId): ?array
    {
        $envoi = Database::un(
            'SELECT e.*, p.nom AS poste_nom, n.etablissement_id, n.type, n.nom AS note_nom, n.couverts, t.nom AS table_nom,
                    z.nom_fr AS zone_nom_fr, z.nom_en AS zone_nom_en, u.nom AS par_nom, et.nom AS etablissement_nom, v.numero AS vente_numero
               FROM note_envois e JOIN postes_cuisine p ON p.id = e.poste_id JOIN notes n ON n.id = e.note_id
               JOIN etablissements et ON et.id = n.etablissement_id
          LEFT JOIN salle_tables t ON t.id = n.table_id LEFT JOIN zones z ON z.id = n.zone_id
          LEFT JOIN utilisateurs u ON u.id = e.par LEFT JOIN ventes v ON v.id = n.vente_id
              WHERE e.id = ?',
            [$envoiId]
        );
        if ($envoi === null) {
            return null;
        }
        $envoi['titre'] = self::titreNote($envoi);
        return [
            'envoi' => $envoi,
            'lignes' => Database::tous('SELECT * FROM note_lignes WHERE envoi_id = ? ORDER BY id', [$envoiId]),
            'suivants' => Database::tous('SELECT * FROM note_lignes WHERE note_id = ? AND poste_id = ? AND statut = \'attente\' AND annulee_le IS NULL ORDER BY id', [(int) $envoi['note_id'], (int) $envoi['poste_id']]),
        ];
    }

    /**
     * Produits que les postes de l'établissement préparent (catégories routées), avec leur disponibilité,
     * pour bloquer un plat en rupture depuis l'écran de cuisine.
     * @return list<array{id: int, nom: string, categorie: string, disponible: bool}>
     */
    public function produitsDesPostes(int $etabId, ?int $posteId): array
    {
        $produits = Database::tous(
            'SELECT p.id, p.nom_fr, p.nom_en, p.categorie_id, p.disponible, c.nom_fr AS categorie_fr, c.nom_en AS categorie_en
               FROM produits p JOIN produit_etablissements pe ON pe.produit_id = p.id AND pe.etablissement_id = ?
               JOIN categories c ON c.id = p.categorie_id
              WHERE p.actif = 1 AND p.parent_id IS NULL ORDER BY c.position, p.position, p.id',
            [$etabId]
        );
        $resultat = [];
        foreach ($produits as $p) {
            $poste = $this->postePour($etabId, (int) $p['categorie_id']);
            if ($poste === null || ($posteId !== null && $poste !== $posteId)) {
                continue;
            }
            $resultat[] = [
                'id' => (int) $p['id'], 'nom' => champ($p, 'nom'), 'disponible' => (bool) $p['disponible'],
                'categorie' => champ(['nom_fr' => $p['categorie_fr'], 'nom_en' => $p['categorie_en']], 'nom'),
            ];
        }
        return $resultat;
    }

    /**
     * Rupture : le plat (et ses tailles) disparaît des caisses de tous les établissements, comme « épuisé »
     * dans le catalogue. Seulement un produit vendu par cet établissement.
     */
    public function rupture(int $etabId, int $produitId, bool $disponible): ?array
    {
        $produit = Database::un(
            'SELECT p.* FROM produits p JOIN produit_etablissements pe ON pe.produit_id = p.id AND pe.etablissement_id = ? WHERE p.id = ? AND p.actif = 1',
            [$etabId, $produitId]
        );
        if ($produit === null) {
            return null;
        }
        Database::executer('UPDATE produits SET disponible = ? WHERE id = ? OR parent_id = ?', [$disponible ? 1 : 0, $produitId, $produitId]);
        return $produit;
    }

    /** Produits indisponibles (en rupture) vendus par l'établissement, tailles comprises. @return list<int> */
    public function indisponibles(int $etabId): array
    {
        return array_map('intval', array_column(Database::tous(
            'SELECT p.id FROM produits p JOIN produit_etablissements pe ON pe.produit_id = COALESCE(p.parent_id, p.id) AND pe.etablissement_id = ?
              WHERE p.actif = 1 AND p.disponible = 0',
            [$etabId]
        ), 'id'));
    }

    // ---------- Écrans autorisés ----------

    public const COOKIE = 'rbp_cuisine';

    /** Écran de cuisine autorisé de ce navigateur, ou null. */
    public static function ecran(): ?array
    {
        static $ecran = false;
        if ($ecran === false) {
            $ecran = null;
            $jeton = $_COOKIE[self::COOKIE] ?? '';
            if (is_string($jeton) && preg_match('/^[a-f0-9]{64}$/', $jeton)) {
                $ecran = Database::un(
                    'SELECT c.*, e.slug, e.nom AS etablissement_nom FROM ecrans_cuisine c JOIN etablissements e ON e.id = c.etablissement_id
                      WHERE c.jeton = ? AND c.actif = 1 AND e.actif = 1',
                    [hash('sha256', $jeton)]
                );
                if ($ecran !== null && ($ecran['dernier_usage'] === null || strtotime($ecran['dernier_usage']) < time() - 300)) {
                    Database::executer('UPDATE ecrans_cuisine SET dernier_usage = NOW() WHERE id = ?', [(int) $ecran['id']]);
                }
            }
        }
        return $ecran;
    }

    /** Autorise ce navigateur comme écran de cuisine (cookie de 400 jours, empreinte en base). */
    public static function autoriser(string $nom, int $etabId, ?int $posteId, int $utilisateurId): int
    {
        $jeton = bin2hex(random_bytes(32));
        Database::executer(
            'INSERT INTO ecrans_cuisine (nom, etablissement_id, poste_id, jeton, autorise_par) VALUES (?, ?, ?, ?, ?)',
            [$nom, $etabId, $posteId, hash('sha256', $jeton), $utilisateurId]
        );
        $id = Database::dernierId();
        setcookie(self::COOKIE, $jeton, [
            'expires' => time() + 400 * 86400, 'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Strict',
        ]);
        $_COOKIE[self::COOKIE] = $jeton;
        return $id;
    }

    /** @return list<array> */
    public function ecrans(?array $etablissements): array
    {
        $sql = 'SELECT c.id, c.nom, c.etablissement_id, c.poste_id, c.cree_le, c.dernier_usage, c.actif, e.nom AS etablissement_nom,
                       p.nom AS poste_nom, u.nom AS autorise_par_nom
                  FROM ecrans_cuisine c JOIN etablissements e ON e.id = c.etablissement_id
             LEFT JOIN postes_cuisine p ON p.id = c.poste_id LEFT JOIN utilisateurs u ON u.id = c.autorise_par
                 WHERE c.actif = 1';
        $params = [];
        if ($etablissements !== null) {
            if ($etablissements === []) {
                return [];
            }
            $sql .= ' AND c.etablissement_id IN (' . implode(',', array_fill(0, count($etablissements), '?')) . ')';
            $params = $etablissements;
        }
        return Database::tous($sql . ' ORDER BY e.nom, c.nom', $params);
    }

    /**
     * Temps de préparation des plats prêts sur une période (envoi → prêt), par poste.
     * @return list<array{poste: string, plats: int, moyenne: ?float, retards: int}>
     */
    public function tempsPreparation(int $etabId, string $debut, string $fin): array
    {
        return array_map(static fn (array $l) => [
            'poste' => $l['nom'], 'plats' => (int) $l['plats'], 'moyenne' => $l['moyenne'] !== null ? round((float) $l['moyenne'] / 60, 1) : null,
            'retards' => (int) $l['retards'],
        ], Database::tous(
            'SELECT p.nom, COUNT(l.id) AS plats, AVG(TIMESTAMPDIFF(SECOND, l.envoye_le, l.pret_le)) AS moyenne,
                    SUM(TIMESTAMPDIFF(SECOND, l.envoye_le, l.pret_le) > p.alerte_minutes * 60) AS retards
               FROM postes_cuisine p
          LEFT JOIN note_lignes l ON l.poste_id = p.id AND l.pret_le IS NOT NULL AND l.envoye_le >= ? AND l.envoye_le < ? AND l.annulee_le IS NULL
              WHERE p.etablissement_id = ? AND p.actif = 1 GROUP BY p.id ORDER BY p.position, p.id',
            [$debut, $fin, $etabId]
        ));
    }
}
