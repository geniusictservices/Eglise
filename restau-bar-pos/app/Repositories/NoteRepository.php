<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Journal;

/**
 * Notes ouvertes (cahier des charges, section 4) : un ticket envoyé à une table, ou nommé (« Bar — Patrick »),
 * gardé par le serveur sans être payé. Plusieurs serveurs y ajoutent des lignes ; chaque ligne garde qui l'a
 * commandée, ses précisions (cuisson, allergie), son temps d'envoi (tout de suite ou « à lancer ») et son statut
 * en cuisine. Payer la note (en entier, par articles ou en parts égales) crée une vente par paiement :
 * la caisse enregistre la vente (VenteRepository) et, dans la même transaction, appliquerPaiement() marque
 * ce que la vente a payé et ferme la note quand il ne reste rien.
 */
final class NoteRepository
{
    /** Écart toléré sur les quantités (fractions de parts égales) */
    public const EPSILON = 0.0005;
    public const PARTS_MAX = 20;
    /** Statuts en cuisine : la ligne a été lancée, son annulation compte comme une perte */
    public const LANCEES = ['recu', 'preparation', 'pret', 'servi'];

    public function note(int $id): ?array
    {
        $note = Database::un(
            'SELECT n.*, t.nom AS table_nom, t.places AS table_places, z.code AS zone_code, z.nom_fr AS zone_nom_fr, z.nom_en AS zone_nom_en,
                    u.nom AS serveur_nom, a.nom AS addition_par_nom
               FROM notes n
          LEFT JOIN salle_tables t ON t.id = n.table_id
          LEFT JOIN zones z ON z.id = n.zone_id
          LEFT JOIN utilisateurs u ON u.id = n.serveur_id
          LEFT JOIN utilisateurs a ON a.id = n.addition_par
              WHERE n.id = ?',
            [$id]
        );
        if ($note === null) {
            return null;
        }
        $note['lignes'] = Database::tous(
            'SELECT l.*, u.nom AS ajoute_par_nom, p.nom AS poste_nom, an.nom AS annulee_par_nom, va.nom AS validee_par_nom
               FROM note_lignes l
          LEFT JOIN utilisateurs u ON u.id = l.ajoute_par
          LEFT JOIN postes_cuisine p ON p.id = l.poste_id
          LEFT JOIN utilisateurs an ON an.id = l.annulee_par
          LEFT JOIN utilisateurs va ON va.id = l.annulation_validee_par
              WHERE l.note_id = ? ORDER BY l.id',
            [$id]
        );
        return $note;
    }

    /** Ligne et sa note. */
    public function ligne(int $id): ?array
    {
        return Database::un(
            'SELECT l.*, n.etablissement_id, n.statut AS note_statut, n.zone_id, n.table_id, n.nom AS note_nom, n.type, t.nom AS table_nom
               FROM note_lignes l JOIN notes n ON n.id = l.note_id LEFT JOIN salle_tables t ON t.id = n.table_id WHERE l.id = ?',
            [$id]
        );
    }

    /** Quantité lisible : « 2 », « 1/3 » (part égale), sinon deux décimales (« 0,67 »). */
    public static function quantiteLisible(float|string $quantite): string
    {
        $q = (float) $quantite;
        if (abs($q - round($q)) < self::EPSILON) {
            return (string) (int) round($q);
        }
        for ($k = 2; $k <= self::PARTS_MAX; $k++) {
            if (abs($q * $k - round($q * $k)) < 0.01 * $k) {
                return (int) round($q * $k) . '/' . $k;
            }
        }
        return number_format($q, 2, ',', ' ');
    }

    /** Reste à payer d'une ligne (0 si annulée). */
    public static function reste(array $ligne): float
    {
        if ($ligne['annulee_le'] !== null) {
            return 0.0;
        }
        return max(0.0, round((float) $ligne['quantite'] - (float) $ligne['quantite_payee'], 3));
    }

    /**
     * Notes ouvertes de l'établissement, avec leur reste à payer (prix à l'envoi, devise du ticket), leur durée,
     * le nombre de plats prêts et de plats « à lancer ».
     * @return list<array>
     */
    public function ouvertes(int $etabId): array
    {
        return Database::tous(
            "SELECT n.id, n.table_id, n.nom, n.zone_id, n.serveur_id, n.ouverte_le, n.addition_le, n.version, n.couverts, n.parts, n.parts_payees,
                    t.nom AS table_nom, u.nom AS serveur_nom,
                    COALESCE(SUM(CASE WHEN l.annulee_le IS NULL THEN (l.quantite - l.quantite_payee) * l.prix END), 0) AS reste,
                    COALESCE(SUM(l.annulee_le IS NULL AND l.statut = 'pret'), 0) AS prets,
                    COALESCE(SUM(l.annulee_le IS NULL AND l.statut = 'attente'), 0) AS attente,
                    COALESCE(SUM(l.annulee_le IS NULL AND l.quantite > l.quantite_payee), 0) AS lignes,
                    MAX(l.ajoute_le) AS derniere
               FROM notes n
          LEFT JOIN salle_tables t ON t.id = n.table_id
          LEFT JOIN utilisateurs u ON u.id = n.serveur_id
          LEFT JOIN note_lignes l ON l.note_id = n.id
              WHERE n.etablissement_id = ? AND n.statut = 'ouverte' AND n.type = 'note'
           GROUP BY n.id ORDER BY n.ouverte_le, n.id",
            [$etabId]
        );
    }

    /** Note ouverte sur cette table, s'il y en a une. */
    public function ouverteSurTable(int $tableId): ?array
    {
        return Database::un("SELECT * FROM notes WHERE table_id = ? AND statut = 'ouverte' AND type = 'note' ORDER BY id LIMIT 1", [$tableId]);
    }

    /** @param array{etablissement_id: int, table_id: ?int, nom: ?string, zone_id: ?int, serveur_id: int, couverts?: ?int, reservation_id?: ?int, type?: string, vente_id?: ?int, statut?: string} $v */
    public function creer(array $v): int
    {
        $maintenant = date('Y-m-d H:i:s');
        Database::executer(
            'INSERT INTO notes (etablissement_id, type, table_id, nom, zone_id, couverts, statut, serveur_id, reservation_id, ouverte_le, vente_id, fermee_le)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $v['etablissement_id'], $v['type'] ?? 'note', $v['table_id'], $v['nom'] !== null ? mb_substr($v['nom'], 0, 80) : null, $v['zone_id'],
                $v['couverts'] ?? null, $v['statut'] ?? 'ouverte', $v['serveur_id'], $v['reservation_id'] ?? null, $maintenant,
                $v['vente_id'] ?? null, ($v['statut'] ?? 'ouverte') === 'ouverte' ? null : $maintenant,
            ]
        );
        $id = Database::dernierId();
        if (!empty($v['reservation_id'])) {
            Database::executer("UPDATE reservations SET statut = 'arrivee' WHERE id = ? AND statut = 'prevue'", [(int) $v['reservation_id']]);
        }
        return $id;
    }

    /**
     * Ajoute des lignes déjà vérifiées et chiffrées par la caisse (prix calculés par le serveur).
     * Une ligne avec un poste part tout de suite en cuisine (statut « reçu », un bon par poste),
     * ou attend que le serveur la lance (temps 2, statut « à lancer »).
     * @param list<array{produit_id: int, conditionnement_id: int, categorie_id: ?int, libelle: string, format: ?string, quantite: int,
     *                   grille: int, prix: float, prix_devises: array<string, float>, remarque: ?string, allergie: ?string,
     *                   temps: int, poste_id: ?int}> $lignes
     * @return list<int> bons de cuisine créés (pour l'impression)
     */
    public function ajouter(int $noteId, array $lignes, int $par): array
    {
        $maintenant = date('Y-m-d H:i:s');
        $envois = [];
        foreach ($lignes as $l) {
            $statut = 'sans';
            $envoi = null;
            if ($l['poste_id'] !== null) {
                if ($l['temps'] >= 2) {
                    $statut = 'attente';
                } else {
                    $statut = 'recu';
                    $envoi = $envois[$l['poste_id']] ??= $this->nouvelEnvoi($noteId, $l['poste_id'], $par, $maintenant);
                }
            }
            Database::executer(
                'INSERT INTO note_lignes (note_id, produit_id, conditionnement_id, categorie_id, libelle, format, quantite, grille_prix_id, prix, prix_devises,
                                          remarque, allergie, temps, poste_id, envoi_id, statut, ajoute_par, ajoute_le, envoye_le)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $noteId, $l['produit_id'], $l['conditionnement_id'], $l['categorie_id'], mb_substr($l['libelle'], 0, 160),
                    $l['format'] !== null ? mb_substr($l['format'], 0, 80) : null, $l['quantite'], $l['grille'],
                    number_format($l['prix'], 3, '.', ''), json_encode($l['prix_devises'], JSON_THROW_ON_ERROR),
                    $l['remarque'], $l['allergie'], $statut === 'sans' ? 1 : $l['temps'], $l['poste_id'], $envoi, $statut, $par, $maintenant,
                    $statut === 'recu' ? $maintenant : null,
                ]
            );
            // Formule : les composants choisis (pour une unité), payés et sortis du stock avec elle
            // (l'id d'abord : une lecture entre les deux remettrait lastInsertId à zéro)
            $ligneId = Database::dernierId();
            if (!empty($l['composants']) && RemiseRepository::disponible()) {
                Database::executer('UPDATE note_lignes SET composants = ? WHERE id = ?', [json_encode($l['composants'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $ligneId]);
            }
        }
        $this->toucher($noteId);
        return array_values($envois);
    }

    private function nouvelEnvoi(int $noteId, int $posteId, int $par, string $date): int
    {
        Database::executer('INSERT INTO note_envois (note_id, poste_id, par, cree_le) VALUES (?, ?, ?, ?)', [$noteId, $posteId, $par, $date]);
        return Database::dernierId();
    }

    /** Lance la suite : les plats « à lancer » partent en cuisine (un bon par poste). @return list<int> bons */
    public function lancer(int $noteId, int $par, ?array $ids = null): array
    {
        $attente = Database::tous("SELECT id, poste_id FROM note_lignes WHERE note_id = ? AND statut = 'attente' AND annulee_le IS NULL ORDER BY id", [$noteId]);
        $maintenant = date('Y-m-d H:i:s');
        $envois = [];
        foreach ($attente as $l) {
            if ($ids !== null && !in_array((int) $l['id'], $ids, true)) {
                continue;
            }
            $poste = (int) $l['poste_id'];
            $envoi = $envois[$poste] ??= $this->nouvelEnvoi($noteId, $poste, $par, $maintenant);
            Database::executer("UPDATE note_lignes SET statut = 'recu', envoi_id = ?, envoye_le = ? WHERE id = ?", [$envoi, $maintenant, (int) $l['id']]);
        }
        $this->toucher($noteId);
        return array_values($envois);
    }

    /** Plats prêts apportés à la table : « servi ». */
    public function servir(int $noteId, ?array $ids = null): int
    {
        $sql = "UPDATE note_lignes SET statut = 'servi', servi_le = NOW() WHERE note_id = ? AND statut = 'pret' AND annulee_le IS NULL";
        $params = [$noteId];
        if ($ids !== null) {
            if ($ids === []) {
                return 0;
            }
            $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = array_merge($params, array_map('intval', $ids));
        }
        $n = Database::executer($sql, $params);
        $this->toucher($noteId);
        return $n;
    }

    public function demanderAddition(int $noteId, int $par): void
    {
        Database::executer('UPDATE notes SET addition_le = NOW(), addition_par = ?, version = version + 1 WHERE id = ?', [$par, $noteId]);
    }

    /** Change la note de table (ou la nomme) ; les nouvelles lignes prendront la grille de la nouvelle zone. */
    public function transferer(int $noteId, ?int $tableId, ?int $zoneId, ?string $nom): void
    {
        Database::executer(
            'UPDATE notes SET table_id = ?, zone_id = ?, nom = ?, version = version + 1 WHERE id = ?',
            [$tableId, $zoneId, $nom !== null && $nom !== '' ? mb_substr($nom, 0, 80) : null, $noteId]
        );
    }

    /** Fusionne la note source dans la cible : ses lignes (et ce qui en est déjà payé) passent dans la cible. */
    public function fusionner(int $sourceId, int $cibleId): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            Database::executer('UPDATE note_lignes SET note_id = ? WHERE note_id = ?', [$cibleId, $sourceId]);
            Database::executer('UPDATE note_envois SET note_id = ? WHERE note_id = ?', [$cibleId, $sourceId]);
            Database::executer('UPDATE note_paiements SET note_id = ? WHERE note_id = ?', [$cibleId, $sourceId]);
            Database::executer("UPDATE notes SET statut = 'fusionnee', fusionnee_dans = ?, fermee_le = NOW(), version = version + 1 WHERE id = ?", [$cibleId, $sourceId]);
            // Un partage en parts égales en cours ne vaut plus pour la note agrandie
            Database::executer('UPDATE notes SET parts = NULL, parts_payees = 0, version = version + 1 WHERE id = ?', [$cibleId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Annule tout ou partie d'une ligne. Une ligne lancée en cuisine (ou déjà sur la note, hors « à lancer »)
     * demande la validation d'un supérieur ($validePar) ; lancée en cuisine, elle compte comme une perte.
     * Une ligne déjà payée (même en partie) ne s'annule pas.
     * @return array la ligne annulée (avec « perte »)
     */
    public function annulerLigne(array $ligne, int $quantite, int $par, ?int $validePar, string $motif): array
    {
        $perte = in_array($ligne['statut'], self::LANCEES, true);
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $actuelle = Database::un('SELECT * FROM note_lignes WHERE id = ? FOR UPDATE', [(int) $ligne['id']]);
            if ($actuelle === null || $actuelle['annulee_le'] !== null || (float) $actuelle['quantite_payee'] > self::EPSILON) {
                throw new \DomainException('ligne_non_annulable');
            }
            $quantite = max(1, min($quantite, (int) $actuelle['quantite']));
            $id = (int) $actuelle['id'];
            if ($quantite < (int) $actuelle['quantite']) {
                // Annulation partielle : la ligne garde le reste ; une copie annulée garde la quantité retirée
                Database::executer('UPDATE note_lignes SET quantite = quantite - ? WHERE id = ?', [$quantite, $id]);
                $colonnes = array_diff(array_keys($actuelle), ['id']);
                Database::executer(
                    'INSERT INTO note_lignes (' . implode(', ', $colonnes) . ') SELECT ' . implode(', ', array_map(static fn ($c) => $c === 'quantite' ? '?' : $c, $colonnes)) . ' FROM note_lignes WHERE id = ?',
                    [$quantite, $id]
                );
                $id = Database::dernierId();
            }
            Database::executer(
                'UPDATE note_lignes SET annulee_le = NOW(), annulee_par = ?, annulation_validee_par = ?, annulation_motif = ?, perte = ? WHERE id = ?',
                [$par, $validePar, mb_substr($motif, 0, 160), $perte ? 1 : 0, $id]
            );
            $this->toucher((int) $actuelle['note_id']);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $annulee = $this->ligne($id);
        if ($perte) {
            self::perteStock($annulee, $par, $validePar);
        }
        return $annulee;
    }

    /**
     * Plat annulé après son lancement : perte dans le stock (module stock, s'il est installé), au comptoir
     * qui le sert ; un plat avec une fiche technique perd ses ingrédients. Ne bloque jamais l'annulation.
     */
    public static function perteStock(array $ligne, int $par, ?int $validePar): void
    {
        if (!class_exists(StockRepository::class) || !class_exists(GestionStockRepository::class)
            || !method_exists(GestionStockRepository::class, 'creerPerte') || $ligne['produit_id'] === null) {
            return;
        }
        try {
            $stock = new StockRepository();
            $gestion = new GestionStockRepository($stock);
            $lieu = $stock->comptoirPour((int) $ligne['etablissement_id'], $ligne['categorie_id'] !== null ? (int) $ligne['categorie_id'] : null, $ligne['zone_id'] !== null ? (int) $ligne['zone_id'] : null);
            $cond = $ligne['conditionnement_id'] !== null ? Database::un('SELECT produit_id, quantite_base FROM conditionnements WHERE id = ?', [(int) $ligne['conditionnement_id']]) : null;
            $base = (float) $ligne['quantite'] * ($cond !== null ? (float) $cond['quantite_base'] : 1.0);
            $valideur = $validePar !== null ? (Database::un('SELECT nom FROM utilisateurs WHERE id = ?', [$validePar])['nom'] ?? '') : '';
            $commentaire = mb_substr(t('salle.perte_commentaire', [
                'note' => CuisineRepository::titreNote($ligne), 'plat' => $ligne['libelle'], 'valideur' => $valideur, 'motif' => $ligne['annulation_motif'] ?? '',
            ]), 0, 255);
            $produitId = (int) $ligne['produit_id'];
            $recette = $stock->recetteActive($produitId);
            $date = date('Y-m-d H:i:s');
            if ($recette !== null) {
                foreach ($stock->consommation($recette, $base) as $ingredient => $quantite) {
                    $gestion->creerPerte(['lieu_id' => $lieu, 'produit_id' => $ingredient, 'conditionnement_id' => null, 'quantite' => $quantite,
                        'quantite_base' => $quantite, 'motif' => 'renvoye', 'commentaire' => $commentaire, 'date' => $date], $par);
                }
            } else {
                $gestion->creerPerte(['lieu_id' => $lieu, 'produit_id' => $cond !== null ? (int) $cond['produit_id'] : $produitId,
                    'conditionnement_id' => $cond !== null ? (int) $ligne['conditionnement_id'] : null, 'quantite' => (float) $ligne['quantite'],
                    'quantite_base' => $base, 'motif' => 'renvoye', 'commentaire' => $commentaire, 'date' => $date], $par);
            }
        } catch (\Throwable $e) {
            error_log('Perte non enregistrée pour une ligne annulée : ' . $e->getMessage());
        }
    }

    /**
     * Ce qu'un paiement de la note règle, ligne par ligne (quantités, éventuellement fractionnaires) :
     * « tout » le reste, des « lignes » choisies (quantité de chacune), ou une part « egal » sur N du reste.
     * @param array{mode?: string, lignes?: list<array{id: int, qte: float}>, parts?: int} $demande
     * @return list<array{ligne: array, quantite: float}>
     * @throws \InvalidArgumentException demande illisible ou qui dépasse le reste
     */
    public static function part(array $note, array $demande): array
    {
        $restes = [];
        foreach ($note['lignes'] as $l) {
            $reste = self::reste($l);
            if ($reste > self::EPSILON) {
                $restes[(int) $l['id']] = ['ligne' => $l, 'reste' => $reste];
            }
        }
        $mode = is_string($demande['mode'] ?? null) ? $demande['mode'] : 'tout';
        $part = [];
        if ($mode === 'tout') {
            foreach ($restes as $r) {
                $part[] = ['ligne' => $r['ligne'], 'quantite' => $r['reste']];
            }
        } elseif ($mode === 'lignes') {
            foreach (is_array($demande['lignes'] ?? null) ? $demande['lignes'] : [] as $choix) {
                $id = (int) ($choix['id'] ?? 0);
                $qte = is_numeric($choix['qte'] ?? null) ? round((float) $choix['qte'], 3) : 0.0;
                if (!isset($restes[$id]) || $qte <= 0 || $qte > $restes[$id]['reste'] + self::EPSILON) {
                    throw new \InvalidArgumentException('ligne');
                }
                $part[] = ['ligne' => $restes[$id]['ligne'], 'quantite' => min($qte, $restes[$id]['reste'])];
            }
        } elseif ($mode === 'egal') {
            $parts = (int) ($demande['parts'] ?? 0);
            if ($parts < 2 || $parts > self::PARTS_MAX) {
                throw new \InvalidArgumentException('parts');
            }
            // Parts restantes : N moins celles déjà payées dans ce partage ; la dernière prend le reste exact
            $k = (int) $note['parts'] === $parts ? max(1, $parts - (int) $note['parts_payees']) : $parts;
            foreach ($restes as $r) {
                $q = $k === 1 ? $r['reste'] : round($r['reste'] / $k, 3);
                if ($q > self::EPSILON) {
                    $part[] = ['ligne' => $r['ligne'], 'quantite' => $q];
                }
            }
        } else {
            throw new \InvalidArgumentException('mode');
        }
        if ($part === []) {
            throw new \InvalidArgumentException('vide');
        }
        return $part;
    }

    /**
     * Dans la transaction de la vente : marque ce que la vente a payé, vérifie que personne n'a payé ou changé
     * ces lignes entre-temps (sinon \DomainException : la vente est annulée), ferme la note s'il ne reste rien.
     * @param list<array{ligne: array, quantite: float}> $part
     */
    public function appliquerPaiement(int $noteId, array $part, int $venteId, ?int $parts): void
    {
        $note = Database::un('SELECT * FROM notes WHERE id = ? FOR UPDATE', [$noteId]);
        if ($note === null || $note['statut'] !== 'ouverte') {
            throw new \DomainException('note_fermee');
        }
        foreach ($part as $p) {
            $id = (int) $p['ligne']['id'];
            $ligne = Database::un('SELECT * FROM note_lignes WHERE id = ? AND note_id = ? FOR UPDATE', [$id, $noteId]);
            if ($ligne === null || $p['quantite'] > self::reste($ligne) + self::EPSILON) {
                throw new \DomainException('note_changee');
            }
            Database::executer('UPDATE note_lignes SET quantite_payee = LEAST(quantite, quantite_payee + ?) WHERE id = ?', [number_format($p['quantite'], 3, '.', ''), $id]);
            Database::executer('INSERT INTO note_paiements (vente_id, note_ligne_id, note_id, quantite) VALUES (?, ?, ?, ?)', [$venteId, $id, $noteId, number_format($p['quantite'], 3, '.', '')]);
        }
        if ($parts !== null) {
            if ((int) $note['parts'] === $parts) {
                Database::executer('UPDATE notes SET parts_payees = parts_payees + 1 WHERE id = ?', [$noteId]);
            } else {
                Database::executer('UPDATE notes SET parts = ?, parts_payees = 1 WHERE id = ?', [$parts, $noteId]);
            }
        }
        $reste = Database::un(
            'SELECT COUNT(*) AS n FROM note_lignes WHERE note_id = ? AND annulee_le IS NULL AND quantite - quantite_payee > ?',
            [$noteId, self::EPSILON]
        );
        if ((int) $reste['n'] === 0) {
            Database::executer("UPDATE notes SET statut = 'payee', fermee_le = NOW() WHERE id = ?", [$noteId]);
        }
        $this->toucher($noteId);
    }

    /** Ventes créées depuis une note. @return list<array> */
    public function ventes(int $noteId): array
    {
        return Database::tous(
            'SELECT DISTINCT v.id, v.numero, v.total, v.statut, v.cree_le, d.code AS devise_code FROM note_paiements p
               JOIN ventes v ON v.id = p.vente_id JOIN devises d ON d.id = v.devise_id WHERE p.note_id = ? ORDER BY v.id',
            [$noteId]
        );
    }

    /** Annule une note vide (sans ligne active ni paiement), par exemple ouverte sur la mauvaise table. */
    public function fermerVide(int $noteId): bool
    {
        $actives = Database::un('SELECT COUNT(*) AS n FROM note_lignes WHERE note_id = ? AND annulee_le IS NULL', [$noteId]);
        if ((int) $actives['n'] > 0) {
            return false;
        }
        return Database::executer("UPDATE notes SET statut = 'annulee', fermee_le = NOW(), version = version + 1 WHERE id = ? AND statut = 'ouverte'", [$noteId]) > 0;
    }

    private function toucher(int $noteId): void
    {
        Database::executer('UPDATE notes SET version = version + 1 WHERE id = ?', [$noteId]);
    }

    /**
     * Historique des notes d'une période (écran de gestion) : ouvertes, payées, fusionnées, annulées.
     * @return list<array>
     */
    public function historique(int $etabId, string $debut, string $fin): array
    {
        return Database::tous(
            "SELECT n.*, t.nom AS table_nom, u.nom AS serveur_nom,
                    COALESCE(SUM(CASE WHEN l.annulee_le IS NULL THEN l.quantite * l.prix END), 0) AS total,
                    COALESCE(SUM(CASE WHEN l.annulee_le IS NULL THEN (l.quantite - l.quantite_payee) * l.prix END), 0) AS reste,
                    COALESCE(SUM(l.annulee_le IS NOT NULL), 0) AS annulees,
                    (SELECT COUNT(DISTINCT p.vente_id) FROM note_paiements p WHERE p.note_id = n.id) AS ventes
               FROM notes n
          LEFT JOIN salle_tables t ON t.id = n.table_id
          LEFT JOIN utilisateurs u ON u.id = n.serveur_id
          LEFT JOIN note_lignes l ON l.note_id = n.id
              WHERE n.etablissement_id = ? AND n.type = 'note' AND (n.statut = 'ouverte' OR (n.ouverte_le >= ? AND n.ouverte_le < ?))
           GROUP BY n.id ORDER BY n.statut <> 'ouverte', n.ouverte_le DESC, n.id DESC",
            [$etabId, $debut, $fin]
        );
    }

    /** Journal : une action sur une note, avec la personne et l'appareil. */
    public static function journal(string $action, array $note, array $details, int $par, ?int $appareilId): void
    {
        Journal::ecrire($action, CuisineRepository::titreNote($note) . ' #' . (int) ($note['note_id'] ?? $note['id']), $details, $par, $appareilId);
    }
}
