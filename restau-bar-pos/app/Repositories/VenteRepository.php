<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Enregistrement d'une vente encaissée à la caisse, en une transaction :
 * la vente, ses totaux par devise, ses lignes, ses paiements, la monnaie rendue,
 * et les mouvements de trésorerie (entrée dans le compte de chaque paiement, sortie de la monnaie).
 */
final class VenteRepository
{
    private GestionTresorerieRepository $tresorerie;

    public function __construct()
    {
        $this->tresorerie = new GestionTresorerieRepository();
    }

    /** Vente déjà enregistrée avec cette clé (double envoi de la caisse). */
    public function parCle(string $cle): ?array
    {
        return Database::un('SELECT * FROM ventes WHERE cle = ?', [$cle]);
    }

    public function parId(int $id): ?array
    {
        return Database::un(
            'SELECT v.*, e.nom AS etablissement, e.slug, u.nom AS vendeur, z.nom_fr AS zone_nom_fr, z.nom_en AS zone_nom_en,
                    d.code AS devise_code, rd.code AS rendu_code
               FROM ventes v
               JOIN etablissements e ON e.id = v.etablissement_id
               JOIN devises d ON d.id = v.devise_id
          LEFT JOIN devises rd ON rd.id = v.rendu_devise_id
          LEFT JOIN utilisateurs u ON u.id = v.utilisateur_id
          LEFT JOIN zones z ON z.id = v.zone_id
              WHERE v.id = ?',
            [$id]
        );
    }

    /** @return array{vente: array, lignes: list<array>, paiements: list<array>, totaux: list<array>}|null tout pour le reçu */
    public function recu(int $id): ?array
    {
        $vente = $this->parId($id);
        if ($vente === null) {
            return null;
        }
        return [
            'vente' => $vente,
            'lignes' => Database::tous('SELECT * FROM vente_lignes WHERE vente_id = ? ORDER BY id', [$id]),
            'paiements' => Database::tous(
                'SELECT p.*, d.code AS devise_code, c.nom AS compte FROM vente_paiements p
                   JOIN devises d ON d.id = p.devise_id JOIN comptes_tresorerie c ON c.id = p.compte_id
                  WHERE p.vente_id = ? ORDER BY p.id',
                [$id]
            ),
            'totaux' => Database::tous(
                'SELECT t.*, d.code AS devise_code FROM vente_totaux t JOIN devises d ON d.id = t.devise_id
                  WHERE t.vente_id = ? ORDER BY d.est_locale DESC, d.code = "USD" DESC, d.position',
                [$id]
            ),
            'credits' => (new DetteRepository($this->tresorerie))->dettesDeVente($id),
            // Nuit : cartes cashless, remises, billets d'entrée, tickets de vestiaire, points de fidélité
            'cartes' => RemiseRepository::disponible() ? (new CashlessRepository($this->tresorerie))->deVente($id) : [],
            'remises' => RemiseRepository::disponible() ? Database::tous(
                'SELECT r.*, a.nom AS accorde_nom, va.nom AS valide_nom FROM vente_remises r LEFT JOIN utilisateurs a ON a.id = r.accorde_par
              LEFT JOIN utilisateurs va ON va.id = r.valide_par WHERE r.vente_id = ? ORDER BY r.id', [$id]) : [],
            'billets' => RemiseRepository::disponible() ? (new BilletterieRepository())->deVente($id) : [],
            'depots' => RemiseRepository::disponible() ? (new VestiaireRepository())->deVente($id) : [],
            'points' => RemiseRepository::disponible() ? Database::tous(
                'SELECT m.type, m.points, c.nom AS client_nom, (SELECT COALESCE(SUM(x.points), 0) FROM fidelite_mouvements x WHERE x.client_id = m.client_id) AS solde
                   FROM fidelite_mouvements m JOIN clients c ON c.id = m.client_id WHERE m.vente_id = ? ORDER BY m.id', [$id]) : [],
            // Toutes les devises (même désactivées depuis) pour afficher les montants
            'devises' => array_column((new GestionDeviseRepository())->toutes(), null, 'id'),
            'etablissement' => Database::un('SELECT * FROM etablissements WHERE id = ?', [(int) $vente['etablissement_id']]),
        ];
    }

    /**
     * @param array{etablissement: array, cle: string, zone_id: ?int, grille_id: int, utilisateur_id: int, appareil_id: ?int,
     *              devise: array, total: float, reference: ?array, total_reference: ?float,
     *              totaux: list<array{devise: array, total: float, taux: ?float}>,
     *              lignes: list<array{produit_id: int, conditionnement_id: int, libelle: string, format: ?string, quantite: int, prix: float, prix_reference: ?float}>,
     *              paiements: list<array{moyen: string, compte: array, devise: array, recu: float, taux: ?float}>,
     *              rendu: ?array{montant: float, devise: array, compte: array, taux: ?float}, date?: string,
     *              credits?: list<array{client_id: int, devise: array, montant: float, echeance: string, servi_par: ?int, garant_id: ?int, note: ?string}>,
     *              session_id?: ?int, apres?: callable(int): void,
     *              hors_ligne?: array{heure_appareil: ?string, numero: ?string, ecart: ?float, notes: list<string>}} $v
     *        quantite : décimale pour une part d'une note partagée en parts égales (1/3 de Tilapia)
     *        credits : part du ticket mise à l'ardoise d'un client (une dette par ligne, accordée par utilisateur_id)
     *        date (facultative, « Y-m-d H:i:s ») : heure de la vente et de ses mouvements de trésorerie,
     *        maintenant si absente (bin/demo-ventes.php l'utilise ; une vente faite sans réseau garde l'heure de l'appareil)
     *        hors_ligne (facultatif) : vente faite sans réseau, envoyée au retour du réseau
     * @return int identifiant de la vente
     */
    public function enregistrer(array $v): int
    {
        // Migration 022 (nuit) : remises, natures de lignes, cartes cashless, client de la fidélité
        $nuit = RemiseRepository::disponible();
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            // Le même ticket envoyé deux fois (réseau lent, double toucher) : une seule vente
            $existante = Database::un('SELECT id FROM ventes WHERE cle = ? FOR UPDATE', [$v['cle']]);
            if ($existante !== null) {
                $pdo->commit();
                return (int) $existante['id'];
            }
            $etab = $v['etablissement'];
            $sequence = (int) Database::un(
                'SELECT COALESCE(MAX(sequence), 0) + 1 AS s FROM ventes WHERE etablissement_id = ? FOR UPDATE',
                [(int) $etab['id']]
            )['s'];
            $numero = self::prefixe($etab['slug']) . '-' . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
            $maintenant = $v['date'] ?? date('Y-m-d H:i:s');

            // Contre-valeur encaissée : paiements reçus moins la monnaie, plus la part payée avec une carte cashless
            // (l'argent est entré dans la trésorerie à la recharge)
            $encaisse = 0.0;
            foreach ($v['paiements'] as $p) {
                $encaisse += $p['taux'] ? $p['recu'] / $p['taux'] : 0.0;
            }
            foreach ($v['cashless'] ?? [] as $c) {
                $encaisse += $c['taux'] ? $c['montant'] / $c['taux'] : 0.0;
            }
            if ($v['rendu'] !== null && $v['rendu']['taux']) {
                $encaisse -= $v['rendu']['montant'] / $v['rendu']['taux'];
            }
            Database::executer(
                'INSERT INTO ventes (etablissement_id, sequence, numero, cle, zone_id, grille_prix_id, utilisateur_id, appareil_id,
                                     devise_id, total, reference_id, total_reference, encaisse_reference, rendu, rendu_devise_id, rendu_compte_id, cree_le)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) $etab['id'], $sequence, $numero, $v['cle'], $v['zone_id'], $v['grille_id'], $v['utilisateur_id'], $v['appareil_id'],
                    (int) $v['devise']['id'], self::decimal($v['total']),
                    $v['reference'] ? (int) $v['reference']['id'] : null,
                    $v['total_reference'] !== null ? self::decimal($v['total_reference']) : null,
                    $v['reference'] ? self::decimal(round($encaisse, 3)) : null,
                    self::decimal($v['rendu']['montant'] ?? 0),
                    $v['rendu'] ? (int) $v['rendu']['devise']['id'] : null,
                    $v['rendu'] ? (int) $v['rendu']['compte']['id'] : null,
                    $maintenant,
                ]
            );
            $id = Database::dernierId();
            // Session de caisse (établissements qui les demandent)
            if (!empty($v['session_id'])) {
                Database::executer('UPDATE ventes SET session_id = ? WHERE id = ?', [(int) $v['session_id'], $id]);
            }
            // Vente faite sans réseau (migration 023) : heure de l'appareil, reçu provisoire, écart et ce qui aurait été refusé
            if (!empty($v['hors_ligne']) && self::horsLigneDisponible()) {
                $h = $v['hors_ligne'];
                Database::executer(
                    'UPDATE ventes SET hors_ligne = 1, heure_appareil = ?, synchronisee_le = ?, numero_provisoire = ?, ecart_hors_ligne = ?, notes_hors_ligne = ? WHERE id = ?',
                    [$h['heure_appareil'] ?? null, date('Y-m-d H:i:s'), isset($h['numero']) ? mb_substr((string) $h['numero'], 0, 40) : null,
                        isset($h['ecart']) ? self::decimal((float) $h['ecart']) : null,
                        !empty($h['notes']) ? mb_substr(implode(',', $h['notes']), 0, 255) : null, $id]
                );
            }
            if ($nuit) {
                Database::executer('UPDATE ventes SET brut = ?, remise = ?, client_id = ? WHERE id = ?', [
                    self::decimal((float) ($v['brut'] ?? $v['total'])), self::decimal((float) ($v['remise'] ?? 0)), $v['client_id'] ?? null, $id,
                ]);
            }

            foreach ($v['totaux'] as $t) {
                Database::executer(
                    'INSERT INTO vente_totaux (vente_id, devise_id, total, taux) VALUES (?, ?, ?, ?)',
                    [$id, (int) $t['devise']['id'], self::decimal($t['total']), $t['taux'] !== null ? GestionDeviseRepository::decimal($t['taux']) : null]
                );
            }
            // Lignes : montant = prix × quantité − remise ; un composant de formule garde sa formule (parent)
            $ligneIds = [];
            $offerts = false;
            foreach ($v['lignes'] as $index => $l) {
                $remise = (float) ($l['remise'] ?? 0);
                Database::executer(
                    'INSERT INTO vente_lignes (vente_id, produit_id, conditionnement_id, libelle, format, quantite, prix_unitaire, montant, prix_reference)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $id, $l['produit_id'], $l['conditionnement_id'], mb_substr($l['libelle'], 0, 160), $l['format'] !== null ? mb_substr($l['format'], 0, 80) : null,
                        is_int($l['quantite']) ? $l['quantite'] : number_format((float) $l['quantite'], 3, '.', ''), self::decimal($l['prix']),
                        self::decimal($l['prix'] * $l['quantite'] - $remise),
                        $l['prix_reference'] !== null ? self::decimal($l['prix_reference']) : null,
                    ]
                );
                $ligneIds[$index] = Database::dernierId();
                if ($nuit && (($l['nature'] ?? 'article') !== 'article' || $remise > 0 || !empty($l['offert']))) {
                    Database::executer('UPDATE vente_lignes SET nature = ?, parent_id = ?, remise = ?, offert = ? WHERE id = ?', [
                        $l['nature'] ?? 'article', isset($l['parent']) ? ($ligneIds[$l['parent']] ?? null) : null, self::decimal($remise),
                        !empty($l['offert']) ? 1 : 0, $ligneIds[$index],
                    ]);
                    $offerts = $offerts || !empty($l['offert']);
                }
            }
            foreach ($v['remises'] ?? [] as $r) {
                Database::executer(
                    'INSERT INTO vente_remises (vente_id, vente_ligne_id, type, valeur, devise_id, taux, montant, motif, precision_motif, accorde_par, valide_par, cree_le)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$id, $r['ligne'] !== null ? ($ligneIds[$r['ligne']] ?? null) : null, $r['type'], self::decimal($r['valeur']), $r['devise_id'],
                        number_format($r['part'], 6, '.', ''), self::decimal($r['montant']), $r['motif'], $r['precision'], $r['accorde_par'], $r['valide_par'], $maintenant]
                );
            }
            $libelle = 'Vente ' . $numero;
            foreach ($v['paiements'] as $p) {
                Database::executer(
                    'INSERT INTO vente_paiements (vente_id, moyen, compte_id, devise_id, recu, taux, contre_valeur) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [
                        $id, $p['moyen'], (int) $p['compte']['id'], (int) $p['devise']['id'], self::decimal($p['recu']),
                        $p['taux'] !== null ? GestionDeviseRepository::decimal($p['taux']) : null,
                        $p['taux'] ? self::decimal(round($p['recu'] / $p['taux'], 3)) : null,
                    ]
                );
                $this->tresorerie->ajouterMouvement($p['compte'], 'entree', $p['recu'], 'vente', $id, $maintenant, $libelle, $v['utilisateur_id'], (int) $p['devise']['id']);
            }
            // Cartes cashless : le solde baisse (verrouillé, jamais négatif), sans mouvement de trésorerie
            foreach ($v['cashless'] ?? [] as $c) {
                (new CashlessRepository($this->tresorerie))->debiter($c['carte_id'], (int) $c['devise']['id'], $c['montant'], $id, (int) $etab['id'],
                    (int) $v['utilisateur_id'], $v['appareil_id'], $maintenant);
                Database::executer('INSERT INTO vente_cashless (vente_id, carte_id, devise_id, montant, taux, contre_valeur) VALUES (?, ?, ?, ?, ?, ?)', [
                    $id, $c['carte_id'], (int) $c['devise']['id'], self::decimal($c['montant']), $c['taux'] !== null ? GestionDeviseRepository::decimal($c['taux']) : null,
                    $c['taux'] ? self::decimal(round($c['montant'] / $c['taux'], 3)) : null,
                ]);
            }
            foreach ($v['credits'] ?? [] as $c) {
                (new DetteRepository($this->tresorerie))->creer([
                    'client_id' => $c['client_id'], 'etablissement_id' => (int) $etab['id'], 'vente_id' => $id,
                    'devise_id' => (int) $c['devise']['id'], 'montant' => $c['montant'], 'echeance' => $c['echeance'],
                    'servi_par' => $c['servi_par'], 'accorde_par' => $v['utilisateur_id'], 'garant_id' => $c['garant_id'],
                    'note' => $c['note'] ?? null, 'date' => $maintenant,
                ]);
            }
            if ($v['rendu'] !== null && $v['rendu']['montant'] > 0) {
                $this->tresorerie->ajouterMouvement($v['rendu']['compte'], 'sortie', $v['rendu']['montant'], 'vente', $id, $maintenant,
                    'Monnaie rendue ' . $numero, $v['utilisateur_id'], (int) $v['rendu']['devise']['id']);
            }
            (new StockRepository())->sortieVente($id);
            // Suites dans la même transaction (payer une note, billets, points…) ; une exception annule la vente
            $apres = $v['apres'] ?? [];
            foreach (is_callable($apres) ? [$apres] : (array) $apres as $suite) {
                if (is_callable($suite)) {
                    $suite($id, $ligneIds);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        // Articles offerts : perte dans le stock (après la vente, sans jamais la bloquer)
        if ($offerts) {
            RemiseRepository::pertesOffertes($id, (int) $v['utilisateur_id']);
        }
        return $id;
    }

    private static ?bool $horsLigne = null;

    /** Migration 023 (mode hors ligne de la caisse) appliquée ? */
    public static function horsLigneDisponible(): bool
    {
        return self::$horsLigne ??= Database::un("SHOW COLUMNS FROM ventes LIKE 'hors_ligne'") !== null;
    }

    /** Préfixe du numéro de ticket : initiales de l'établissement (« comptoir-du-lac » → « CDL »). */
    public static function prefixe(string $slug): string
    {
        $mots = array_filter(explode('-', $slug), static fn (string $m) => $m !== '');
        $initiales = strtoupper(implode('', array_map(static fn (string $m) => $m[0], $mots)));
        return substr($initiales !== '' ? $initiales : 'V', 0, 6);
    }

    private static function decimal(float $montant): string
    {
        return number_format($montant, 3, '.', '');
    }
}
