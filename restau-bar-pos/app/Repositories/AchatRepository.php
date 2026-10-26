<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Journal;

/**
 * Achats : fournisseurs, bons de commande, réceptions (livraisons et achats au marché), paiements aux
 * fournisseurs par la trésorerie (compte + devise, plusieurs lignes ; mouvement de nature « achat »),
 * dettes envers les fournisseurs avec leur échéance, historique des prix d'achat.
 *
 * Une réception fait entrer la marchandise dans le lieu (unités de base), met à jour le CUMP de chaque
 * produit (coût converti dans la devise de référence au taux du jour de la réception), enregistre les
 * emballages reçus, rendus et la consigne payée, et met à jour la commande d'origine.
 */
final class AchatRepository
{
    public const TYPES_FOURNISSEUR = ['brasserie', 'distributeur', 'grossiste', 'marche', 'autre'];
    public const STATUTS_COMMANDE = ['brouillon', 'envoyee', 'partielle', 'recue', 'annulee'];

    private StockRepository $stock;
    private GestionTresorerieRepository $tresorerie;

    public function __construct(?StockRepository $stock = null, ?GestionTresorerieRepository $tresorerie = null)
    {
        $this->stock = $stock ?? new StockRepository();
        $this->tresorerie = $tresorerie ?? new GestionTresorerieRepository();
    }

    public function tresorerie(): GestionTresorerieRepository
    {
        return $this->tresorerie;
    }

    // ---------- Fournisseurs ----------

    public function fournisseurs(bool $actifsSeulement = false): array
    {
        return Database::tous(
            'SELECT f.*, d.code AS devise_code,
                    (SELECT COUNT(*) FROM receptions r WHERE r.fournisseur_id = f.id) AS nb_receptions,
                    (SELECT MAX(r.date_reception) FROM receptions r WHERE r.fournisseur_id = f.id) AS derniere_reception
               FROM fournisseurs f LEFT JOIN devises d ON d.id = f.devise_id'
            . ($actifsSeulement ? ' WHERE f.actif = 1' : '') . '
              ORDER BY f.actif DESC, f.nom'
        );
    }

    public function fournisseur(int $id): ?array
    {
        return Database::un('SELECT f.*, d.code AS devise_code FROM fournisseurs f LEFT JOIN devises d ON d.id = f.devise_id WHERE f.id = ?', [$id]);
    }

    public function nomFournisseurPris(string $nom, int $saufId = 0): bool
    {
        return Database::un('SELECT id FROM fournisseurs WHERE nom = ? AND id <> ?', [$nom, $saufId]) !== null;
    }

    /** @param array{nom: string, type: string, contact: ?string, telephone: ?string, email: ?string, adresse: ?string, delai_paiement: int, devise_id: ?int, notes: ?string, actif: bool} $v */
    public function enregistrerFournisseur(?int $id, array $v): int
    {
        $valeurs = [$v['nom'], $v['type'], $v['contact'], $v['telephone'], $v['email'], $v['adresse'], $v['delai_paiement'], $v['devise_id'], $v['notes'], (int) $v['actif']];
        if ($id === null) {
            Database::executer(
                'INSERT INTO fournisseurs (nom, type, contact, telephone, email, adresse, delai_paiement, devise_id, notes, actif) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                $valeurs
            );
            return Database::dernierId();
        }
        Database::executer(
            'UPDATE fournisseurs SET nom = ?, type = ?, contact = ?, telephone = ?, email = ?, adresse = ?, delai_paiement = ?, devise_id = ?, notes = ?, actif = ? WHERE id = ?',
            [...$valeurs, $id]
        );
        return $id;
    }

    // ---------- Portée ----------

    /**
     * Condition SQL sur la colonne etablissement_id d'une pièce (réception, commande) : établissements de la
     * personne et pièces des dépôts centraux.
     * @param ?list<int> $etablissements null = tous
     * @return array{0: string, 1: list<int>}
     */
    public static function portee(?array $etablissements, string $colonne): array
    {
        if ($etablissements === null) {
            return ['1 = 1', []];
        }
        if ($etablissements === []) {
            return [$colonne . ' IS NULL', []];
        }
        return ['(' . $colonne . ' IS NULL OR ' . $colonne . ' IN (' . implode(',', array_fill(0, count($etablissements), '?')) . '))', array_values($etablissements)];
    }

    // ---------- Commandes ----------

    public function commandes(?array $etablissements, ?string $statut, ?int $fournisseurId): array
    {
        [$portee, $params] = self::portee($etablissements, 'c.etablissement_id');
        $conditions = [$portee];
        if ($statut !== null) {
            $conditions[] = 'c.statut = ?';
            $params[] = $statut;
        }
        if ($fournisseurId !== null) {
            $conditions[] = 'c.fournisseur_id = ?';
            $params[] = $fournisseurId;
        }
        return Database::tous(
            'SELECT c.*, f.nom AS fournisseur, l.nom AS lieu_nom, e.nom AS etablissement_nom, u.nom AS auteur,
                    (SELECT COUNT(*) FROM commande_lignes x WHERE x.commande_id = c.id) AS nb_lignes,
                    (SELECT SUM(x.quantite * COALESCE(x.prix_unitaire, 0)) FROM commande_lignes x WHERE x.commande_id = c.id) AS montant
               FROM commandes_achat c
               JOIN fournisseurs f ON f.id = c.fournisseur_id
               JOIN lieux_stock l ON l.id = c.lieu_id
          LEFT JOIN etablissements e ON e.id = c.etablissement_id
          LEFT JOIN utilisateurs u ON u.id = c.utilisateur_id
              WHERE ' . implode(' AND ', $conditions) . '
              ORDER BY FIELD(c.statut, \'brouillon\', \'envoyee\', \'partielle\', \'recue\', \'annulee\'), c.date_commande DESC, c.id DESC
              LIMIT 300',
            $params
        );
    }

    public function commande(int $id): ?array
    {
        $commande = Database::un(
            'SELECT c.*, f.nom AS fournisseur, f.telephone AS fournisseur_telephone, l.nom AS lieu_nom, e.nom AS etablissement_nom, u.nom AS auteur
               FROM commandes_achat c
               JOIN fournisseurs f ON f.id = c.fournisseur_id
               JOIN lieux_stock l ON l.id = c.lieu_id
          LEFT JOIN etablissements e ON e.id = c.etablissement_id
          LEFT JOIN utilisateurs u ON u.id = c.utilisateur_id
              WHERE c.id = ?',
            [$id]
        );
        if ($commande === null) {
            return null;
        }
        $commande['lignes'] = Database::tous(
            'SELECT x.*, p.nom_fr, p.nom_en, p.unite_base, k.nom_fr AS format_nom_fr, k.nom_en AS format_nom_en,
                    (SELECT COALESCE(SUM(rl.quantite), 0) FROM reception_lignes rl WHERE rl.commande_ligne_id = x.id) AS quantite_recue
               FROM commande_lignes x
               JOIN produits p ON p.id = x.produit_id
          LEFT JOIN conditionnements k ON k.id = x.conditionnement_id
              WHERE x.commande_id = ? ORDER BY x.position, x.id',
            [$id]
        );
        $commande['receptions'] = Database::tous('SELECT id, numero, date_reception, total FROM receptions WHERE commande_id = ? ORDER BY date_reception', [$id]);
        return $commande;
    }

    /**
     * @param array{fournisseur_id: int, lieu_id: int, etablissement_id: ?int, devise_id: int, date_commande: string, date_prevue: ?string, notes: ?string} $v
     * @param list<array{produit_id: int, conditionnement_id: ?int, quantite: float, quantite_base: float, prix_unitaire: ?float}> $lignes
     */
    public function enregistrerCommande(?int $id, array $v, array $lignes, int $utilisateurId): int
    {
        return $this->tresorerie->transaction(function () use ($id, $v, $lignes, $utilisateurId): int {
            $valeurs = [$v['fournisseur_id'], $v['lieu_id'], $v['etablissement_id'], $v['devise_id'], $v['date_commande'], $v['date_prevue'], $v['notes']];
            if ($id === null) {
                Database::executer(
                    'INSERT INTO commandes_achat (fournisseur_id, lieu_id, etablissement_id, devise_id, date_commande, date_prevue, notes, utilisateur_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [...$valeurs, $utilisateurId]
                );
                $id = Database::dernierId();
                Database::executer('UPDATE commandes_achat SET numero = ? WHERE id = ?', [sprintf('BC-%06d', $id), $id]);
            } else {
                Database::executer(
                    'UPDATE commandes_achat SET fournisseur_id = ?, lieu_id = ?, etablissement_id = ?, devise_id = ?, date_commande = ?, date_prevue = ?, notes = ?
                      WHERE id = ? AND statut = \'brouillon\'',
                    [...$valeurs, $id]
                );
                Database::executer('DELETE FROM commande_lignes WHERE commande_id = ?', [$id]);
            }
            foreach (array_values($lignes) as $position => $l) {
                Database::executer(
                    'INSERT INTO commande_lignes (commande_id, produit_id, conditionnement_id, quantite, quantite_base, prix_unitaire, position) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$id, $l['produit_id'], $l['conditionnement_id'], StockRepository::q($l['quantite']), StockRepository::q($l['quantite_base']),
                     $l['prix_unitaire'] !== null ? GestionTresorerieRepository::decimal($l['prix_unitaire']) : null, $position]
                );
            }
            return $id;
        });
    }

    public function changerStatutCommande(int $id, string $de, string $vers): bool
    {
        $colonne = $vers === 'envoyee' ? ', envoyee_le = NOW()' : ($vers === 'annulee' ? ', annulee_le = NOW()' : '');
        return Database::executer('UPDATE commandes_achat SET statut = ?' . $colonne . ' WHERE id = ? AND statut = ?', [$vers, $id, $de]) > 0;
    }

    /** Statut d'une commande d'après ses réceptions : partielle tant qu'une ligne n'est pas reçue en entier. */
    private function majStatutCommande(int $commandeId): void
    {
        $lignes = Database::tous(
            'SELECT x.quantite, (SELECT COALESCE(SUM(rl.quantite), 0) FROM reception_lignes rl WHERE rl.commande_ligne_id = x.id) AS recue
               FROM commande_lignes x WHERE x.commande_id = ?',
            [$commandeId]
        );
        $complete = $lignes !== [] && array_filter($lignes, static fn (array $l): bool => (float) $l['recue'] < (float) $l['quantite'] - 0.0005) === [];
        Database::executer(
            'UPDATE commandes_achat SET statut = ? WHERE id = ? AND statut IN (\'brouillon\', \'envoyee\', \'partielle\')',
            [$complete ? 'recue' : 'partielle', $commandeId]
        );
    }

    // ---------- Réceptions ----------

    /**
     * @param array{debut: string, fin: string, fournisseur: ?int, lieu: ?int, paiement: ?string} $filtres paiement : non_payee, partielle, payee
     */
    public function receptions(array $filtres, ?array $etablissements): array
    {
        [$portee, $params] = self::portee($etablissements, 'r.etablissement_id');
        $conditions = [$portee, 'r.date_reception BETWEEN ? AND ?'];
        array_push($params, $filtres['debut'], $filtres['fin']);
        if ($filtres['fournisseur']) {
            $conditions[] = 'r.fournisseur_id = ?';
            $params[] = $filtres['fournisseur'];
        }
        if ($filtres['lieu']) {
            $conditions[] = 'r.lieu_id = ?';
            $params[] = $filtres['lieu'];
        }
        $lignes = Database::tous(
            'SELECT r.*, f.nom AS fournisseur, l.nom AS lieu_nom, e.nom AS etablissement_nom, u.nom AS auteur, c.numero AS commande_numero,
                    COALESCE((SELECT SUM(pl.contre_valeur) FROM paiements_fournisseurs pf JOIN paiement_fournisseur_lignes pl ON pl.paiement_id = pf.id
                               WHERE pf.reception_id = r.id), 0) AS paye
               FROM receptions r
               JOIN fournisseurs f ON f.id = r.fournisseur_id
               JOIN lieux_stock l ON l.id = r.lieu_id
          LEFT JOIN etablissements e ON e.id = r.etablissement_id
          LEFT JOIN utilisateurs u ON u.id = r.utilisateur_id
          LEFT JOIN commandes_achat c ON c.id = r.commande_id
              WHERE ' . implode(' AND ', $conditions) . '
              ORDER BY r.date_reception DESC, r.id DESC LIMIT 500',
            $params
        );
        foreach ($lignes as &$r) {
            $r['reste'] = max(0.0, round((float) $r['total'] - (float) $r['paye'], 3));
            $r['etat_paiement'] = self::etatPaiement((float) $r['total'], (float) $r['paye']);
        }
        unset($r);
        if ($filtres['paiement']) {
            $lignes = array_values(array_filter($lignes, static fn (array $r): bool => $r['etat_paiement'] === $filtres['paiement']));
        }
        return $lignes;
    }

    public static function etatPaiement(float $total, float $paye): string
    {
        if ($total <= 0.0005 || $paye >= $total - 0.005) {
            return 'payee';
        }
        return $paye > 0.0005 ? 'partielle' : 'non_payee';
    }

    public function reception(int $id): ?array
    {
        $r = Database::un(
            'SELECT r.*, f.nom AS fournisseur, f.type AS fournisseur_type, l.nom AS lieu_nom, l.type AS lieu_type, e.nom AS etablissement_nom,
                    u.nom AS auteur, c.numero AS commande_numero
               FROM receptions r
               JOIN fournisseurs f ON f.id = r.fournisseur_id
               JOIN lieux_stock l ON l.id = r.lieu_id
          LEFT JOIN etablissements e ON e.id = r.etablissement_id
          LEFT JOIN utilisateurs u ON u.id = r.utilisateur_id
          LEFT JOIN commandes_achat c ON c.id = r.commande_id
              WHERE r.id = ?',
            [$id]
        );
        if ($r === null) {
            return null;
        }
        $r['lignes'] = Database::tous(
            'SELECT x.*, p.nom_fr, p.nom_en, p.unite_base, k.nom_fr AS format_nom_fr, k.nom_en AS format_nom_en
               FROM reception_lignes x
               JOIN produits p ON p.id = x.produit_id
          LEFT JOIN conditionnements k ON k.id = x.conditionnement_id
              WHERE x.reception_id = ? ORDER BY x.position, x.id',
            [$id]
        );
        $r['emballages'] = Database::tous(
            'SELECT m.*, p.nom_fr, p.nom_en FROM emballage_mouvements m JOIN produits p ON p.id = m.produit_id WHERE m.reception_id = ? ORDER BY m.id',
            [$id]
        );
        $r['paiements'] = $this->paiements($id);
        $r['paye'] = array_sum(array_map(static fn (array $p): float => (float) $p['contre_valeur'], $r['paiements']));
        $r['reste'] = max(0.0, round((float) $r['total'] - $r['paye'], 3));
        $r['etat_paiement'] = self::etatPaiement((float) $r['total'], $r['paye']);
        return $r;
    }

    /**
     * Enregistre une réception et ses effets (stock, CUMP, emballages, commande, paiements comptants).
     * @param array{fournisseur_id: int, commande_id: ?int, lieu_id: int, etablissement_id: ?int, devise: array, date: string,
     *              facture_numero: ?string, sans_facture: bool, justificatif: ?string, echeance: ?string, notes: ?string} $v
     * @param list<array{produit_id: int, conditionnement_id: ?int, commande_ligne_id: ?int, quantite_commandee: ?float, quantite: float,
     *                   quantite_base: float, prix_unitaire: float}> $lignes
     * @param list<array{produit_id: int, rendus: float, payes: float}> $emballages vides rendus et consigne payée
     * @param list<array{compte: array, devise: array, montant: float, contre_valeur: float}> $paiements payé tout de suite
     */
    public function creerReception(array $v, array $lignes, array $emballages, array $paiements, int $utilisateurId): int
    {
        return $this->tresorerie->transaction(function () use ($v, $lignes, $emballages, $paiements, $utilisateurId): int {
            $devise = $v['devise'];
            $reference = $this->tresorerie->reference();
            $taux = $this->tresorerie->tauxA($devise, $v['date']);
            $montantLignes = 0.0;
            foreach ($lignes as $l) {
                $montantLignes += $l['quantite'] * $l['prix_unitaire'];
            }
            $catalogue = (new EmballageRepository($this->stock))->emballages();
            $consigne = 0.0;
            foreach ($emballages as $e) {
                if ($e['payes'] > 0 && isset($catalogue[$e['produit_id']])) {
                    $consigne += $e['payes'] * $this->convertir((float) $catalogue[$e['produit_id']]['valeur_consigne'], (int) $catalogue[$e['produit_id']]['devise_id'], $devise, $v['date']);
                }
            }
            $montantLignes = round($montantLignes, 3);
            $consigne = round($consigne, (int) $devise['decimales']);
            Database::executer(
                'INSERT INTO receptions (fournisseur_id, commande_id, lieu_id, etablissement_id, devise_id, taux, date_reception, facture_numero, sans_facture,
                                         justificatif, montant_lignes, montant_consigne, total, echeance, notes, utilisateur_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $v['fournisseur_id'], $v['commande_id'], $v['lieu_id'], $v['etablissement_id'], (int) $devise['id'],
                    $taux !== null ? GestionDeviseRepository::decimal($taux) : null, $v['date'], $v['facture_numero'], (int) $v['sans_facture'],
                    $v['justificatif'], GestionTresorerieRepository::decimal($montantLignes), GestionTresorerieRepository::decimal($consigne),
                    GestionTresorerieRepository::decimal($montantLignes + $consigne), $v['echeance'], $v['notes'], $utilisateurId,
                ]
            );
            $id = Database::dernierId();
            $numero = sprintf('REC-%06d', $id);
            Database::executer('UPDATE receptions SET numero = ? WHERE id = ?', [$numero, $id]);
            $fournisseur = $this->fournisseur($v['fournisseur_id']);
            $motif = $numero . ' · ' . ($fournisseur['nom'] ?? '');
            $liens = (new EmballageRepository($this->stock))->liens();
            $recus = [];
            foreach (array_values($lignes) as $position => $l) {
                // Coût par unité de base, en devise de référence au taux du jour de la réception
                $coutBase = $l['quantite_base'] > 0 && $taux !== null && $taux > 0
                    ? $l['quantite'] * $l['prix_unitaire'] / $l['quantite_base'] / $taux : null;
                Database::executer(
                    'INSERT INTO reception_lignes (reception_id, commande_ligne_id, produit_id, conditionnement_id, quantite_commandee, quantite, quantite_base,
                                                   prix_unitaire, montant, cout_base_reference, position)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $id, $l['commande_ligne_id'], $l['produit_id'], $l['conditionnement_id'],
                        $l['quantite_commandee'] !== null ? StockRepository::q($l['quantite_commandee']) : null,
                        StockRepository::q($l['quantite']), StockRepository::q($l['quantite_base']),
                        GestionTresorerieRepository::decimal($l['prix_unitaire']), GestionTresorerieRepository::decimal($l['quantite'] * $l['prix_unitaire']),
                        $coutBase !== null ? number_format($coutBase, 6, '.', '') : null, $position,
                    ]
                );
                if ($l['quantite_base'] <= 0) {
                    continue;
                }
                if ($coutBase !== null && $reference !== null && $l['prix_unitaire'] > 0) {
                    $this->stock->majCump($l['produit_id'], $l['quantite_base'], $coutBase, (int) $reference['id']);
                }
                $this->stock->mouvement($v['lieu_id'], $l['produit_id'], $l['quantite_base'], 'reception', $id, $v['date'], $motif, $utilisateurId,
                    $l['conditionnement_id'], $l['quantite']);
                foreach ($liens[$l['conditionnement_id'] ?? 0] ?? [] as $emballageId => $parFormat) {
                    $recus[$emballageId] = ($recus[$emballageId] ?? 0) + $parFormat * $l['quantite'];
                }
            }
            // Emballages : reçus avec la marchandise (dus au fournisseur), rendus (sortent du lieu), consigne payée
            $echanges = new EmballageRepository($this->stock);
            foreach ($recus as $emballageId => $quantite) {
                $echanges->echanger($v['fournisseur_id'], null, $emballageId, 'recu', $quantite, null, $id, null, null, $v['date'], null, $utilisateurId);
            }
            foreach ($emballages as $e) {
                if ($e['rendus'] > 0) {
                    $echanges->echanger($v['fournisseur_id'], null, $e['produit_id'], 'rendu', $e['rendus'], $v['lieu_id'], $id, null, null, $v['date'], $numero, $utilisateurId);
                }
                if ($e['payes'] > 0 && isset($catalogue[$e['produit_id']])) {
                    $valeur = $e['payes'] * $this->convertir((float) $catalogue[$e['produit_id']]['valeur_consigne'], (int) $catalogue[$e['produit_id']]['devise_id'], $devise, $v['date']);
                    $echanges->echanger($v['fournisseur_id'], null, $e['produit_id'], 'consigne_payee', $e['payes'], null, $id, $valeur, (int) $devise['id'], $v['date'], null, $utilisateurId);
                }
            }
            if ($v['commande_id'] !== null) {
                $this->majStatutCommande($v['commande_id']);
            }
            if ($paiements !== []) {
                $this->payer($id, $paiements, $v['date'], null, $utilisateurId);
            }
            Journal::ecrire('achat_reception', $numero, ['fournisseur' => $v['fournisseur_id'], 'total' => round($montantLignes + $consigne, 3)], $utilisateurId);
            return $id;
        });
    }

    /** Montant d'une devise convertie dans une autre au taux du jour donné (via la devise de référence). */
    public function convertir(float $montant, int $deDeviseId, array $vers, string $date): float
    {
        if ($deDeviseId === (int) $vers['id']) {
            return $montant;
        }
        $devises = $this->tresorerie->devises();
        $de = $devises[$deDeviseId] ?? null;
        $tauxDe = $de ? $this->tresorerie->tauxA($de, $date) : null;
        $tauxVers = $this->tresorerie->tauxA($vers, $date);
        return $tauxDe && $tauxVers ? $montant / $tauxDe * $tauxVers : 0.0;
    }

    // ---------- Paiements ----------

    public function paiements(int $receptionId): array
    {
        return Database::tous(
            'SELECT pl.*, pf.date_paiement, pf.notes, pf.utilisateur_id, pf.reception_id, u.nom AS auteur, c.nom AS compte_nom, c.type AS compte_type, d.code AS devise_code
               FROM paiements_fournisseurs pf
               JOIN paiement_fournisseur_lignes pl ON pl.paiement_id = pf.id
               JOIN comptes_tresorerie c ON c.id = pl.compte_id
               JOIN devises d ON d.id = pl.devise_id
          LEFT JOIN utilisateurs u ON u.id = pf.utilisateur_id
              WHERE pf.reception_id = ?
           ORDER BY pf.date_paiement, pf.id, pl.position',
            [$receptionId]
        );
    }

    /**
     * Paiement d'une réception : chaque ligne sort de son compte dans sa devise (journal de trésorerie,
     * nature « achat »). contre_valeur = la part de la facture couverte (dans sa devise).
     * @param list<array{compte: array, devise: array, montant: float, contre_valeur: float}> $lignes
     */
    public function payer(int $receptionId, array $lignes, string $date, ?string $notes, int $utilisateurId): int
    {
        return $this->tresorerie->transaction(function () use ($receptionId, $lignes, $date, $notes, $utilisateurId): int {
            $reception = Database::un('SELECT r.*, f.nom AS fournisseur FROM receptions r JOIN fournisseurs f ON f.id = r.fournisseur_id WHERE r.id = ? FOR UPDATE', [$receptionId]);
            Database::executer(
                'INSERT INTO paiements_fournisseurs (reception_id, fournisseur_id, date_paiement, notes, utilisateur_id) VALUES (?, ?, ?, ?, ?)',
                [$receptionId, (int) $reception['fournisseur_id'], $date, $notes, $utilisateurId]
            );
            $id = Database::dernierId();
            $libelle = 'Paiement ' . $reception['fournisseur'] . ' · ' . $reception['numero'];
            foreach (array_values($lignes) as $position => $l) {
                $taux = $l['contre_valeur'] > 0 ? $l['montant'] / $l['contre_valeur'] : null;
                Database::executer(
                    'INSERT INTO paiement_fournisseur_lignes (paiement_id, compte_id, devise_id, montant, taux, contre_valeur, position) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$id, (int) $l['compte']['id'], (int) $l['devise']['id'], GestionTresorerieRepository::decimal($l['montant']),
                     $taux !== null ? GestionDeviseRepository::decimal($taux) : null, GestionTresorerieRepository::decimal($l['contre_valeur']), $position]
                );
                $this->tresorerie->ajouterMouvement($l['compte'], 'sortie', $l['montant'], 'achat', $id, $date, $libelle, $utilisateurId, (int) $l['devise']['id']);
            }
            Journal::ecrire('achat_paiement', $reception['numero'], ['paiement' => $id], $utilisateurId);
            return $id;
        });
    }

    /** Annule un paiement : ses lignes du journal de trésorerie disparaissent avec lui. */
    public function supprimerPaiement(int $paiementId, int $utilisateurId): ?int
    {
        return $this->tresorerie->transaction(function () use ($paiementId, $utilisateurId): ?int {
            $paiement = Database::un('SELECT * FROM paiements_fournisseurs WHERE id = ? FOR UPDATE', [$paiementId]);
            if ($paiement === null) {
                return null;
            }
            $this->tresorerie->supprimerMouvements('achat', $paiementId);
            Database::executer('DELETE FROM paiements_fournisseurs WHERE id = ?', [$paiementId]);
            Journal::ecrire('achat_paiement_annule', (string) $paiementId, ['reception' => (int) $paiement['reception_id']], $utilisateurId);
            return (int) $paiement['reception_id'];
        });
    }

    public function paiement(int $id): ?array
    {
        return Database::un('SELECT * FROM paiements_fournisseurs WHERE id = ?', [$id]);
    }

    // ---------- Dettes et prix ----------

    /**
     * Réceptions pas entièrement payées, par fournisseur, avec le reste dû par devise et les échéances.
     * @return list<array{fournisseur_id: int, fournisseur: string, par_devise: array<int, float>, en_retard: int, prochaine: ?string, receptions: list<array>}>
     */
    public function dettes(?array $etablissements): array
    {
        $receptions = array_filter(
            $this->receptions(['debut' => '1000-01-01 00:00:00', 'fin' => '9999-12-31 23:59:59', 'fournisseur' => null, 'lieu' => null, 'paiement' => null], $etablissements),
            static fn (array $r): bool => $r['etat_paiement'] !== 'payee'
        );
        $aujourdhui = date('Y-m-d');
        $resultat = [];
        foreach ($receptions as $r) {
            $id = (int) $r['fournisseur_id'];
            $resultat[$id] ??= ['fournisseur_id' => $id, 'fournisseur' => $r['fournisseur'], 'par_devise' => [], 'en_retard' => 0, 'prochaine' => null, 'receptions' => []];
            $resultat[$id]['par_devise'][(int) $r['devise_id']] = ($resultat[$id]['par_devise'][(int) $r['devise_id']] ?? 0) + $r['reste'];
            $r['en_retard'] = $r['echeance'] !== null && $r['echeance'] < $aujourdhui;
            if ($r['en_retard']) {
                $resultat[$id]['en_retard']++;
            } elseif ($r['echeance'] !== null && ($resultat[$id]['prochaine'] === null || $r['echeance'] < $resultat[$id]['prochaine'])) {
                $resultat[$id]['prochaine'] = $r['echeance'];
            }
            $resultat[$id]['receptions'][] = $r;
        }
        foreach ($resultat as &$f) {
            usort($f['receptions'], static fn (array $a, array $b): int => strcmp((string) ($a['echeance'] ?? '9999'), (string) ($b['echeance'] ?? '9999')));
        }
        unset($f);
        usort($resultat, static fn (array $a, array $b): int => [$b['en_retard'], $a['fournisseur']] <=> [$a['en_retard'], $b['fournisseur']]);
        return $resultat;
    }

    /**
     * Historique des prix d'achat (lignes de réception), le plus récent d'abord.
     * @param array{produit: ?int, fournisseur: ?int, debut: string, fin: string} $filtres
     */
    public function prix(array $filtres, ?array $etablissements): array
    {
        [$portee, $params] = self::portee($etablissements, 'r.etablissement_id');
        $conditions = [$portee, 'r.date_reception BETWEEN ? AND ?'];
        array_push($params, $filtres['debut'], $filtres['fin']);
        if ($filtres['produit']) {
            $conditions[] = 'x.produit_id = ?';
            $params[] = $filtres['produit'];
        }
        if ($filtres['fournisseur']) {
            $conditions[] = 'r.fournisseur_id = ?';
            $params[] = $filtres['fournisseur'];
        }
        return Database::tous(
            'SELECT x.*, r.numero, r.date_reception, r.devise_id, r.fournisseur_id, r.id AS reception_id, f.nom AS fournisseur, p.nom_fr, p.nom_en, p.unite_base,
                    k.nom_fr AS format_nom_fr, k.nom_en AS format_nom_en, k.quantite_base AS format_base
               FROM reception_lignes x
               JOIN receptions r ON r.id = x.reception_id
               JOIN fournisseurs f ON f.id = r.fournisseur_id
               JOIN produits p ON p.id = x.produit_id
          LEFT JOIN conditionnements k ON k.id = x.conditionnement_id
              WHERE ' . implode(' AND ', $conditions) . '
           ORDER BY r.date_reception DESC, x.id DESC LIMIT 500',
            $params
        );
    }
}
