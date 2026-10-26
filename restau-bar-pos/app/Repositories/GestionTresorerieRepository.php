<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Trésorerie : comptes (espèces, mobile money, banque), journal des mouvements et transferts.
 * Chaque compte tient une seule devise. Son solde est toujours la somme du journal
 * (entrées moins sorties), solde initial compris : les ventes, achats et remboursements des phases
 * suivantes n'auront qu'à écrire leurs lignes avec ajouterMouvement().
 */
final class GestionTresorerieRepository
{
    public const TYPES = ['especes', 'mobile_money', 'banque'];

    private DeviseRepository $devises;
    /** @var array<int, array>|null */
    private ?array $toutesDevises = null;

    public function __construct(?DeviseRepository $devises = null)
    {
        $this->devises = $devises ?? new DeviseRepository();
    }

    public function deviseRepository(): DeviseRepository
    {
        return $this->devises;
    }

    // ---------- Devises ----------

    /**
     * Devises utiles à la trésorerie, indexées par id : les actives dans l'ordre d'affichage
     * (locale, dollar, autres), puis les devises désactivées qui tiennent encore un compte.
     * @return array<int, array>
     */
    public function devises(): array
    {
        if ($this->toutesDevises === null) {
            $this->toutesDevises = $this->devises->actives();
            $utilisees = array_map('intval', array_column(Database::tous('SELECT DISTINCT devise_id FROM comptes_tresorerie'), 'devise_id'));
            foreach ((new GestionDeviseRepository())->toutes() as $devise) {
                $id = (int) $devise['id'];
                if (!isset($this->toutesDevises[$id]) && in_array($id, $utilisees, true)) {
                    $this->toutesDevises[$id] = $devise;
                }
            }
        }
        return $this->toutesDevises;
    }

    public function reference(): ?array
    {
        return $this->devises->reference();
    }

    /** Taux d'une devise à une date (unités pour 1 unité de la référence actuelle), sinon le taux en vigueur. */
    public function tauxA(array $devise, string $date): ?float
    {
        $reference = $this->reference();
        if ($reference === null) {
            return null;
        }
        if ((int) $devise['id'] === (int) $reference['id']) {
            return 1.0;
        }
        $ligne = Database::un(
            'SELECT taux FROM taux_change
              WHERE devise_id = ? AND valable_depuis <= ? AND COALESCE(reference_id, ?) = ?
              ORDER BY valable_depuis DESC, id DESC LIMIT 1',
            [(int) $devise['id'], $date, (int) $reference['id'], (int) $reference['id']]
        );
        $taux = $ligne['taux'] ?? ($this->devises()[(int) $devise['id']]['taux'] ?? null);
        return $taux !== null && (float) $taux > 0 ? (float) $taux : null;
    }

    // ---------- Comptes ----------

    /** Comptes avec leur solde, actifs d'abord, rangés par type (espèces, mobile money, banque) puis position. */
    public function comptes(): array
    {
        return Database::tous(
            'SELECT c.*, e.nom AS etablissement_nom,
                    COALESCE((SELECT SUM(IF(m.sens = \'entree\', m.montant, -m.montant))
                                FROM mouvements_tresorerie m WHERE m.compte_id = c.id), 0) AS solde,
                    (SELECT COUNT(*) FROM mouvements_tresorerie m
                      WHERE m.compte_id = c.id AND m.nature <> \'solde_initial\') AS nb_mouvements
               FROM comptes_tresorerie c
               LEFT JOIN etablissements e ON e.id = c.etablissement_id
              ORDER BY c.active DESC, FIELD(c.type, \'especes\', \'mobile_money\', \'banque\'), c.position, c.nom'
        );
    }

    /** Comptes actifs, indexés par id. */
    public function comptesActifs(): array
    {
        $resultat = [];
        foreach ($this->comptes() as $compte) {
            if ($compte['active']) {
                $resultat[(int) $compte['id']] = $compte;
            }
        }
        return $resultat;
    }

    public function compte(int $id): ?array
    {
        foreach ($this->comptes() as $compte) {
            if ((int) $compte['id'] === $id) {
                return $compte;
            }
        }
        return null;
    }

    public function nomPris(string $nom, int $saufId = 0): bool
    {
        return Database::un('SELECT id FROM comptes_tresorerie WHERE nom = ? AND id <> ?', [$nom, $saufId]) !== null;
    }

    public function positionSuivante(string $type): int
    {
        $ligne = Database::un('SELECT MAX(position) AS p FROM comptes_tresorerie WHERE type = ?', [$type]);
        return min(999, (int) ($ligne['p'] ?? 0) + 1);
    }

    /** @param array{nom: string, type: string, devise_id: int, operateur: ?string, numero: ?string, etablissement_id: ?int, solde_initial: float, solde_initial_le: string, position: int} $v */
    public function creerCompte(array $v, int $utilisateurId): int
    {
        return $this->transaction(function () use ($v, $utilisateurId): int {
            Database::executer(
                'INSERT INTO comptes_tresorerie (nom, type, devise_id, operateur, numero, etablissement_id, solde_initial, solde_initial_le, position)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $v['nom'], $v['type'], $v['devise_id'], $v['operateur'], $v['numero'], $v['etablissement_id'],
                    self::decimal($v['solde_initial']), $v['solde_initial_le'], $v['position'],
                ]
            );
            $id = Database::dernierId();
            $this->ecrireSoldeInitial($id, $utilisateurId);
            return $id;
        });
    }

    /** La devise ne change que si le compte n'a encore aucun mouvement (hors solde initial). */
    public function modifierCompte(int $id, array $v, int $utilisateurId): void
    {
        $this->transaction(function () use ($id, $v, $utilisateurId): void {
            Database::executer(
                'UPDATE comptes_tresorerie
                    SET nom = ?, type = ?, devise_id = ?, operateur = ?, numero = ?, etablissement_id = ?,
                        solde_initial = ?, solde_initial_le = ?, position = ?
                  WHERE id = ?',
                [
                    $v['nom'], $v['type'], $v['devise_id'], $v['operateur'], $v['numero'], $v['etablissement_id'],
                    self::decimal($v['solde_initial']), $v['solde_initial_le'], $v['position'], $id,
                ]
            );
            $this->ecrireSoldeInitial($id, $utilisateurId);
        });
    }

    /** Remplace la ligne « solde initial » du journal par celle qui correspond au compte. */
    private function ecrireSoldeInitial(int $id, int $utilisateurId): void
    {
        Database::executer('DELETE FROM mouvements_tresorerie WHERE compte_id = ? AND nature = \'solde_initial\'', [$id]);
        $compte = Database::un('SELECT * FROM comptes_tresorerie WHERE id = ?', [$id]);
        if ($compte !== null && (float) $compte['solde_initial'] > 0) {
            $this->ajouterMouvement($compte, 'entree', (float) $compte['solde_initial'], 'solde_initial', $id,
                $compte['solde_initial_le'], null, $utilisateurId);
        }
    }

    public function definirActif(int $id, bool $active): void
    {
        Database::executer('UPDATE comptes_tresorerie SET active = ? WHERE id = ?', [(int) $active, $id]);
    }

    /** Suppression d'un compte sans aucun mouvement (hors solde initial). */
    public function supprimerCompte(int $id): void
    {
        $this->transaction(function () use ($id): void {
            Database::executer('DELETE FROM mouvements_tresorerie WHERE compte_id = ? AND nature = \'solde_initial\'', [$id]);
            Database::executer('DELETE FROM comptes_tresorerie WHERE id = ?', [$id]);
        });
    }

    // ---------- Journal ----------

    /**
     * Écrit une ligne du journal pour un compte, avec le taux du moment et la contre-valeur
     * dans la devise de référence. À appeler dans une transaction quand l'opération a plusieurs lignes.
     */
    public function ajouterMouvement(array $compte, string $sens, float $montant, string $nature, ?int $sourceId,
                                     string $date, ?string $libelle, ?int $utilisateurId): void
    {
        $devise = $this->devises()[(int) $compte['devise_id']] ?? ['id' => (int) $compte['devise_id'], 'taux' => null];
        $reference = $this->reference();
        $taux = $this->tauxA($devise, $date);
        $contreValeur = $taux !== null && $reference !== null
            ? round($montant / $taux, max(2, (int) $reference['decimales']))
            : null;
        Database::executer(
            'INSERT INTO mouvements_tresorerie
                (compte_id, sens, montant, devise_id, taux, reference_id, contre_valeur, nature, source_id, date_operation, libelle, utilisateur_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $compte['id'], $sens, self::decimal($montant), (int) $compte['devise_id'],
                $taux !== null ? GestionDeviseRepository::decimal($taux) : null,
                $taux !== null && $reference !== null ? (int) $reference['id'] : null,
                $contreValeur !== null ? self::decimal($contreValeur) : null,
                $nature, $sourceId, $date, $libelle !== null ? mb_substr($libelle, 0, 255) : null, $utilisateurId ?: null,
            ]
        );
    }

    public function supprimerMouvements(string $nature, int $sourceId): void
    {
        Database::executer('DELETE FROM mouvements_tresorerie WHERE nature = ? AND source_id = ?', [$nature, $sourceId]);
    }

    /** Solde d'un compte avant une date (exclue). */
    public function soldeAvant(int $compteId, string $date): float
    {
        $ligne = Database::un(
            'SELECT COALESCE(SUM(IF(sens = \'entree\', montant, -montant)), 0) AS solde
               FROM mouvements_tresorerie WHERE compte_id = ? AND date_operation < ?',
            [$compteId, $date]
        );
        return (float) ($ligne['solde'] ?? 0);
    }

    /** Mouvements d'un compte sur une période, avec l'opération d'origine (transfert, dépense). */
    public function mouvements(int $compteId, string $debut, string $fin): array
    {
        return Database::tous(
            'SELECT m.*, u.nom AS auteur,
                    t.compte_source_id, t.compte_destination_id, t.motif,
                    cs.nom AS source_nom, cd.nom AS destination_nom,
                    d.libelle AS depense_libelle, d.beneficiaire, cat.nom_fr AS categorie_nom_fr, cat.nom_en AS categorie_nom_en
               FROM mouvements_tresorerie m
               LEFT JOIN utilisateurs u ON u.id = m.utilisateur_id
               LEFT JOIN transferts_tresorerie t ON m.nature = \'transfert\' AND t.id = m.source_id
               LEFT JOIN comptes_tresorerie cs ON cs.id = t.compte_source_id
               LEFT JOIN comptes_tresorerie cd ON cd.id = t.compte_destination_id
               LEFT JOIN depenses d ON m.nature = \'depense\' AND d.id = m.source_id
               LEFT JOIN categories_depenses cat ON cat.id = d.categorie_id
              WHERE m.compte_id = ? AND m.date_operation BETWEEN ? AND ?
              ORDER BY m.date_operation, m.id',
            [$compteId, $debut, $fin]
        );
    }

    // ---------- Transferts ----------

    public function transferts(string $debut, string $fin): array
    {
        return Database::tous(
            'SELECT t.*, cs.nom AS source_nom, cs.devise_id AS source_devise_id, cs.type AS source_type,
                    cd.nom AS destination_nom, cd.devise_id AS destination_devise_id, cd.type AS destination_type,
                    u.nom AS auteur
               FROM transferts_tresorerie t
               JOIN comptes_tresorerie cs ON cs.id = t.compte_source_id
               JOIN comptes_tresorerie cd ON cd.id = t.compte_destination_id
               LEFT JOIN utilisateurs u ON u.id = t.utilisateur_id
              WHERE t.date_transfert BETWEEN ? AND ?
              ORDER BY t.date_transfert DESC, t.id DESC
              LIMIT 500',
            [$debut, $fin]
        );
    }

    public function transfert(int $id): ?array
    {
        return Database::un('SELECT * FROM transferts_tresorerie WHERE id = ?', [$id]);
    }

    /** Transfert : une sortie de la source et une entrée sur la destination, dans une seule transaction. */
    public function creerTransfert(array $source, array $destination, float $montantSource, float $montantDestination,
                                   string $date, ?string $motif, int $utilisateurId): int
    {
        return $this->transaction(function () use ($source, $destination, $montantSource, $montantDestination, $date, $motif, $utilisateurId): int {
            Database::executer(
                'INSERT INTO transferts_tresorerie
                    (compte_source_id, compte_destination_id, montant_source, montant_destination, taux, date_transfert, motif, utilisateur_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) $source['id'], (int) $destination['id'], self::decimal($montantSource), self::decimal($montantDestination),
                    GestionDeviseRepository::decimal($montantDestination / $montantSource), $date, $motif, $utilisateurId,
                ]
            );
            $id = Database::dernierId();
            $this->ajouterMouvement($source, 'sortie', $montantSource, 'transfert', $id, $date, $motif, $utilisateurId);
            $this->ajouterMouvement($destination, 'entree', $montantDestination, 'transfert', $id, $date, $motif, $utilisateurId);
            return $id;
        });
    }

    public function supprimerTransfert(int $id): void
    {
        $this->transaction(function () use ($id): void {
            $this->supprimerMouvements('transfert', $id);
            Database::executer('DELETE FROM transferts_tresorerie WHERE id = ?', [$id]);
        });
    }

    // ---------- Outils ----------

    /**
     * Exécute une fonction dans une transaction (ou dans celle déjà ouverte).
     * @template T
     * @param callable(): T $fonction
     * @return T
     */
    public function transaction(callable $fonction): mixed
    {
        $pdo = Database::pdo();
        if ($pdo->inTransaction()) {
            return $fonction();
        }
        $pdo->beginTransaction();
        try {
            $resultat = $fonction();
            $pdo->commit();
            return $resultat;
        } catch (\Throwable $erreur) {
            $pdo->rollBack();
            throw $erreur;
        }
    }

    /** Montant au format des colonnes DECIMAL(18,3). */
    public static function decimal(float $montant): string
    {
        return number_format($montant, 3, '.', '');
    }
}
