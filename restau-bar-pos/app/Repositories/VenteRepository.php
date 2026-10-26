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
     *              session_id?: ?int} $v
     *        credits : part du ticket mise à l'ardoise d'un client (une dette par ligne, accordée par utilisateur_id)
     *        date (facultative, « Y-m-d H:i:s ») : heure de la vente et de ses mouvements de trésorerie,
     *        maintenant si absente (la caisse ne l'envoie jamais ; bin/demo-ventes.php l'utilise)
     * @return int identifiant de la vente
     */
    public function enregistrer(array $v): int
    {
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

            $encaisse = 0.0;
            foreach ($v['paiements'] as $p) {
                $encaisse += $p['taux'] ? $p['recu'] / $p['taux'] : 0.0;
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

            foreach ($v['totaux'] as $t) {
                Database::executer(
                    'INSERT INTO vente_totaux (vente_id, devise_id, total, taux) VALUES (?, ?, ?, ?)',
                    [$id, (int) $t['devise']['id'], self::decimal($t['total']), $t['taux'] !== null ? GestionDeviseRepository::decimal($t['taux']) : null]
                );
            }
            foreach ($v['lignes'] as $l) {
                Database::executer(
                    'INSERT INTO vente_lignes (vente_id, produit_id, conditionnement_id, libelle, format, quantite, prix_unitaire, montant, prix_reference)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $id, $l['produit_id'], $l['conditionnement_id'], mb_substr($l['libelle'], 0, 160), $l['format'] !== null ? mb_substr($l['format'], 0, 80) : null,
                        $l['quantite'], self::decimal($l['prix']), self::decimal($l['prix'] * $l['quantite']),
                        $l['prix_reference'] !== null ? self::decimal($l['prix_reference']) : null,
                    ]
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
            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
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
