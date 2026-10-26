<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Trésorerie : comptes de trois TYPES (caisse, mobile money, banque), journal des mouvements et transferts.
 * Un compte tient une ou plusieurs devises (compte_devises) : « Caisse du Comptoir » a un solde en FC et un
 * solde en $, comptés séparément. Le solde de chaque devise est toujours la somme du journal pour cette
 * devise (entrées moins sorties), solde initial compris : ventes, dépenses, transferts, et plus tard achats
 * et remboursements n'ont qu'à écrire leurs lignes avec ajouterMouvement().
 */
final class GestionTresorerieRepository
{
    public const TYPES = ['caisse', 'mobile_money', 'banque'];

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
     * (locale, dollar, autres), puis les devises désactivées qu'un compte tient encore.
     * @return array<int, array>
     */
    public function devises(): array
    {
        if ($this->toutesDevises === null) {
            $this->toutesDevises = $this->devises->actives();
            $utilisees = array_map('intval', array_column(Database::tous(
                'SELECT devise_id FROM compte_devises UNION SELECT devise_id FROM mouvements_tresorerie'
            ), 'devise_id'));
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

    /**
     * Comptes, actifs d'abord, rangés par type (caisse, mobile money, banque) puis position.
     * Chaque compte a :
     *  - devises : devise_id => [devise_id, code, solde_initial, solde, nb_mouvements, tenue], dans l'ordre
     *    des devises (locale, dollar, autres) ; solde = journal de cette devise ; nb_mouvements = lignes hors
     *    solde initial ; tenue = false seulement pour une devise du journal que le compte ne tient plus
     *    (ne devrait pas arriver : on ne retire une devise que sans mouvement) ;
     *  - nb_mouvements : toutes devises confondues, hors solde initial.
     */
    public function comptes(): array
    {
        $comptes = Database::tous(
            'SELECT c.*, e.nom AS etablissement_nom
               FROM comptes_tresorerie c
               LEFT JOIN etablissements e ON e.id = c.etablissement_id
              ORDER BY c.active DESC, FIELD(c.type, \'caisse\', \'mobile_money\', \'banque\'), c.position, c.nom'
        );
        if ($comptes === []) {
            return [];
        }
        $devises = $this->devises();
        $tenues = [];
        foreach (Database::tous('SELECT compte_id, devise_id, solde_initial FROM compte_devises') as $ligne) {
            $tenues[(int) $ligne['compte_id']][(int) $ligne['devise_id']] = (float) $ligne['solde_initial'];
        }
        $journal = [];
        foreach (Database::tous(
            'SELECT compte_id, devise_id, SUM(IF(sens = \'entree\', montant, -montant)) AS solde,
                    SUM(nature <> \'solde_initial\') AS nb
               FROM mouvements_tresorerie GROUP BY compte_id, devise_id'
        ) as $ligne) {
            $journal[(int) $ligne['compte_id']][(int) $ligne['devise_id']] = [(float) $ligne['solde'], (int) $ligne['nb']];
        }
        $ordre = array_flip(array_keys($devises));
        foreach ($comptes as &$compte) {
            $id = (int) $compte['id'];
            $liste = [];
            foreach (($tenues[$id] ?? []) + array_fill_keys(array_keys($journal[$id] ?? []), null) as $deviseId => $soldeInitial) {
                [$solde, $nb] = $journal[$id][$deviseId] ?? [0.0, 0];
                $liste[$deviseId] = [
                    'devise_id' => $deviseId,
                    'code' => $devises[$deviseId]['code'] ?? '?',
                    'solde_initial' => (float) $soldeInitial,
                    'solde' => $solde,
                    'nb_mouvements' => $nb,
                    'tenue' => $soldeInitial !== null,
                ];
            }
            uksort($liste, static fn ($a, $b) => ($ordre[$a] ?? 999) <=> ($ordre[$b] ?? 999));
            $compte['devises'] = $liste;
            $compte['nb_mouvements'] = array_sum(array_column($liste, 'nb_mouvements'));
        }
        unset($compte);
        return $comptes;
    }

    /** Comptes actifs, indexés par id (même forme que comptes()). */
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

    /** Le compte (tel que rendu par comptes()) tient-il cette devise ? */
    public static function tient(array $compte, int $deviseId): bool
    {
        return !empty($compte['devises'][$deviseId]['tenue']);
    }

    /** Solde d'un compte (tel que rendu par comptes()) dans une devise. */
    public static function solde(array $compte, int $deviseId): float
    {
        return (float) ($compte['devises'][$deviseId]['solde'] ?? 0);
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

    /**
     * @param array{nom: string, type: string, operateur: ?string, numero: ?string, etablissement_id: ?int,
     *              solde_initial_le: string, position: int, devises: array<int, float>} $v devises : devise_id => solde initial
     */
    public function creerCompte(array $v, int $utilisateurId): int
    {
        return $this->transaction(function () use ($v, $utilisateurId): int {
            Database::executer(
                'INSERT INTO comptes_tresorerie (nom, type, operateur, numero, etablissement_id, solde_initial_le, position)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$v['nom'], $v['type'], $v['operateur'], $v['numero'], $v['etablissement_id'], $v['solde_initial_le'], $v['position']]
            );
            $id = Database::dernierId();
            $this->ecrireDevises($id, $v['devises']);
            $this->ecrireSoldesInitiaux($id, $utilisateurId);
            return $id;
        });
    }

    /**
     * Les devises ajoutées reçoivent leur ligne « solde initial » ; une devise retirée doit n'avoir aucun
     * mouvement hors solde initial (le contrôleur le vérifie, la base le garantit aussi : LogicException).
     */
    public function modifierCompte(int $id, array $v, int $utilisateurId): void
    {
        $this->transaction(function () use ($id, $v, $utilisateurId): void {
            Database::executer(
                'UPDATE comptes_tresorerie
                    SET nom = ?, type = ?, operateur = ?, numero = ?, etablissement_id = ?, solde_initial_le = ?, position = ?
                  WHERE id = ?',
                [$v['nom'], $v['type'], $v['operateur'], $v['numero'], $v['etablissement_id'], $v['solde_initial_le'], $v['position'], $id]
            );
            $this->ecrireDevises($id, $v['devises']);
            $this->ecrireSoldesInitiaux($id, $utilisateurId);
        });
    }

    /** Remplace les devises tenues par un compte (devise_id => solde initial). */
    private function ecrireDevises(int $id, array $devises): void
    {
        $retirees = array_diff(
            array_map('intval', array_column(Database::tous('SELECT devise_id FROM compte_devises WHERE compte_id = ?', [$id]), 'devise_id')),
            array_keys($devises)
        );
        foreach ($retirees as $deviseId) {
            $mouvements = Database::un(
                'SELECT COUNT(*) AS n FROM mouvements_tresorerie WHERE compte_id = ? AND devise_id = ? AND nature <> \'solde_initial\'',
                [$id, $deviseId]
            );
            if ((int) $mouvements['n'] > 0) {
                throw new \LogicException('La devise ' . $deviseId . ' du compte ' . $id . ' a des mouvements');
            }
            Database::executer('DELETE FROM mouvements_tresorerie WHERE compte_id = ? AND devise_id = ? AND nature = \'solde_initial\'', [$id, $deviseId]);
            Database::executer('DELETE FROM compte_devises WHERE compte_id = ? AND devise_id = ?', [$id, $deviseId]);
        }
        foreach ($devises as $deviseId => $soldeInitial) {
            Database::executer(
                'INSERT INTO compte_devises (compte_id, devise_id, solde_initial) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE solde_initial = VALUES(solde_initial)',
                [$id, (int) $deviseId, self::decimal((float) $soldeInitial)]
            );
        }
    }

    /** Remplace les lignes « solde initial » du journal : une par devise tenue avec un solde initial, à la date du compte. */
    private function ecrireSoldesInitiaux(int $id, int $utilisateurId): void
    {
        Database::executer('DELETE FROM mouvements_tresorerie WHERE compte_id = ? AND nature = \'solde_initial\'', [$id]);
        $compte = Database::un('SELECT * FROM comptes_tresorerie WHERE id = ?', [$id]);
        if ($compte === null) {
            return;
        }
        foreach (Database::tous('SELECT devise_id, solde_initial FROM compte_devises WHERE compte_id = ?', [$id]) as $ligne) {
            if ((float) $ligne['solde_initial'] > 0) {
                $this->ajouterMouvement($compte, 'entree', (float) $ligne['solde_initial'], 'solde_initial', $id,
                    $compte['solde_initial_le'], null, $utilisateurId, (int) $ligne['devise_id']);
            }
        }
    }

    public function definirActif(int $id, bool $active): void
    {
        Database::executer('UPDATE comptes_tresorerie SET active = ? WHERE id = ?', [(int) $active, $id]);
    }

    /** Suppression d'un compte sans aucun mouvement (hors soldes initiaux) ; ses devises partent avec lui. */
    public function supprimerCompte(int $id): void
    {
        $this->transaction(function () use ($id): void {
            Database::executer('DELETE FROM mouvements_tresorerie WHERE compte_id = ? AND nature = \'solde_initial\'', [$id]);
            Database::executer('DELETE FROM comptes_tresorerie WHERE id = ?', [$id]);
        });
    }

    // ---------- Journal ----------

    /**
     * Ajoute une ligne au journal d'un compte, dans une devise que le compte tient, avec le taux du moment
     * et la contre-valeur dans la devise de référence. $deviseId est obligatoire pour un compte à plusieurs
     * devises ; pour un compte à une seule devise, il vaut celle-ci par défaut.
     * À appeler dans une transaction quand l'opération a plusieurs lignes.
     */
    public function ajouterMouvement(array $compte, string $sens, float $montant, string $nature, ?int $sourceId,
                                     string $date, ?string $libelle, ?int $utilisateurId, ?int $deviseId = null): void
    {
        $tenues = array_map('intval', array_column(Database::tous('SELECT devise_id FROM compte_devises WHERE compte_id = ?', [(int) $compte['id']]), 'devise_id'));
        if ($deviseId === null) {
            if (count($tenues) !== 1) {
                throw new \LogicException('Devise du mouvement à préciser pour le compte ' . $compte['id']);
            }
            $deviseId = $tenues[0];
        } elseif (!in_array($deviseId, $tenues, true)) {
            throw new \LogicException('Le compte ' . $compte['id'] . ' ne tient pas la devise ' . $deviseId);
        }
        $compte['devise_id'] = $deviseId;
        $devise = $this->devises()[$deviseId] ?? ['id' => $deviseId, 'taux' => null];
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

    /**
     * Soldes d'un compte avant une date (exclue), par devise.
     * @return array<int, float> devise_id => solde
     */
    public function soldesAvant(int $compteId, string $date): array
    {
        $soldes = [];
        foreach (Database::tous(
            'SELECT devise_id, SUM(IF(sens = \'entree\', montant, -montant)) AS solde
               FROM mouvements_tresorerie WHERE compte_id = ? AND date_operation < ? GROUP BY devise_id',
            [$compteId, $date]
        ) as $ligne) {
            $soldes[(int) $ligne['devise_id']] = (float) $ligne['solde'];
        }
        return $soldes;
    }

    /**
     * Mouvements d'un compte sur une période (d'une seule devise si $deviseId), avec l'opération d'origine
     * (transfert et ses devises, dépense).
     */
    public function mouvements(int $compteId, string $debut, string $fin, ?int $deviseId = null): array
    {
        $params = [$compteId, $debut, $fin];
        if ($deviseId !== null) {
            $params[] = $deviseId;
        }
        return Database::tous(
            'SELECT m.*, u.nom AS auteur,
                    t.compte_source_id, t.compte_destination_id, t.devise_source_id, t.devise_destination_id, t.motif,
                    cs.nom AS source_nom, cd.nom AS destination_nom,
                    d.libelle AS depense_libelle, d.beneficiaire, cat.nom_fr AS categorie_nom_fr, cat.nom_en AS categorie_nom_en
               FROM mouvements_tresorerie m
               LEFT JOIN utilisateurs u ON u.id = m.utilisateur_id
               LEFT JOIN transferts_tresorerie t ON m.nature = \'transfert\' AND t.id = m.source_id
               LEFT JOIN comptes_tresorerie cs ON cs.id = t.compte_source_id
               LEFT JOIN comptes_tresorerie cd ON cd.id = t.compte_destination_id
               LEFT JOIN depenses d ON m.nature = \'depense\' AND d.id = m.source_id
               LEFT JOIN categories_depenses cat ON cat.id = d.categorie_id
              WHERE m.compte_id = ? AND m.date_operation BETWEEN ? AND ?' . ($deviseId !== null ? ' AND m.devise_id = ?' : '') . '
              ORDER BY m.date_operation, m.id',
            $params
        );
    }

    // ---------- Transferts ----------

    /** Transferts d'une période ; devise_source_id et devise_destination_id disent dans quelle devise chaque côté a bougé. */
    public function transferts(string $debut, string $fin): array
    {
        return Database::tous(
            'SELECT t.*, cs.nom AS source_nom, cs.type AS source_type,
                    cd.nom AS destination_nom, cd.type AS destination_type,
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

    /**
     * Transfert : une sortie de la source (dans sa devise) et une entrée sur la destination (dans la sienne),
     * dans une seule transaction. Même compte et deux devises = change dans la caisse.
     */
    public function creerTransfert(array $source, int $deviseSourceId, array $destination, int $deviseDestinationId,
                                   float $montantSource, float $montantDestination, string $date, ?string $motif, int $utilisateurId): int
    {
        return $this->transaction(function () use ($source, $deviseSourceId, $destination, $deviseDestinationId, $montantSource, $montantDestination, $date, $motif, $utilisateurId): int {
            Database::executer(
                'INSERT INTO transferts_tresorerie
                    (compte_source_id, compte_destination_id, devise_source_id, devise_destination_id,
                     montant_source, montant_destination, taux, date_transfert, motif, utilisateur_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) $source['id'], (int) $destination['id'], $deviseSourceId, $deviseDestinationId,
                    self::decimal($montantSource), self::decimal($montantDestination),
                    GestionDeviseRepository::decimal($montantDestination / $montantSource), $date, $motif, $utilisateurId,
                ]
            );
            $id = Database::dernierId();
            $this->ajouterMouvement($source, 'sortie', $montantSource, 'transfert', $id, $date, $motif, $utilisateurId, $deviseSourceId);
            $this->ajouterMouvement($destination, 'entree', $montantDestination, 'transfert', $id, $date, $motif, $utilisateurId, $deviseDestinationId);
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
