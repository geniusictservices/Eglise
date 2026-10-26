<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Gestion des devises et des taux : toutes les devises (actives ou non), saisie des taux,
 * historique, changement de devise de référence.
 * Un taux = nombre d'unités de la devise pour 1 unité de la devise de référence.
 */
final class GestionDeviseRepository
{
    /** Toutes les devises, avec leur taux en vigueur et le nombre d'établissements qui les acceptent. */
    public function toutes(): array
    {
        $maintenant = date('Y-m-d H:i:s');
        return Database::tous(
            'SELECT d.*,
                    (SELECT t.taux FROM taux_change t
                      WHERE t.devise_id = d.id AND t.valable_depuis <= ?
                      ORDER BY t.valable_depuis DESC, t.id DESC LIMIT 1) AS taux,
                    (SELECT t.valable_depuis FROM taux_change t
                      WHERE t.devise_id = d.id AND t.valable_depuis <= ?
                      ORDER BY t.valable_depuis DESC, t.id DESC LIMIT 1) AS taux_depuis,
                    (SELECT COUNT(*) FROM etablissement_devises ed WHERE ed.devise_id = d.id) AS nb_etablissements
               FROM devises d
              ORDER BY d.est_locale DESC, d.code = "USD" DESC, d.position, d.code',
            [$maintenant, $maintenant]
        );
    }

    public function parId(int $id): ?array
    {
        foreach ($this->toutes() as $devise) {
            if ((int) $devise['id'] === $id) {
                return $devise;
            }
        }
        return null;
    }

    /** Devise de référence, active ou non (il n'y en a qu'une). */
    public function reference(): ?array
    {
        foreach ($this->toutes() as $devise) {
            if ($devise['est_reference']) {
                return $devise;
            }
        }
        return null;
    }

    public function codeExiste(string $code, ?int $saufId = null): bool
    {
        return Database::un('SELECT id FROM devises WHERE code = ? AND id <> ?', [$code, $saufId ?? 0]) !== null;
    }

    /** @param array{code: string, symbole: string, nom_fr: string, nom_en: string, decimales: int, arrondi: string, symbole_avant: bool, position: int, active: bool} $valeurs */
    public function creer(array $valeurs): int
    {
        // La toute première devise devient la référence
        $premiere = Database::un('SELECT id FROM devises WHERE est_reference = 1') === null;
        Database::executer(
            'INSERT INTO devises (code, symbole, nom_fr, nom_en, decimales, arrondi, symbole_avant, est_reference, active, position)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $valeurs['code'], $valeurs['symbole'], $valeurs['nom_fr'], $valeurs['nom_en'],
                $valeurs['decimales'], $valeurs['arrondi'], (int) $valeurs['symbole_avant'],
                (int) $premiere, (int) ($premiere || $valeurs['active']), $valeurs['position'],
            ]
        );
        return Database::dernierId();
    }

    public function modifier(int $id, array $valeurs): void
    {
        Database::executer(
            'UPDATE devises SET code = ?, symbole = ?, nom_fr = ?, nom_en = ?, decimales = ?, arrondi = ?, symbole_avant = ?, position = ?
              WHERE id = ?',
            [
                $valeurs['code'], $valeurs['symbole'], $valeurs['nom_fr'], $valeurs['nom_en'],
                $valeurs['decimales'], $valeurs['arrondi'], (int) $valeurs['symbole_avant'], $valeurs['position'], $id,
            ]
        );
    }

    public function definirActive(int $id, bool $active): void
    {
        Database::executer('UPDATE devises SET active = ? WHERE id = ? AND est_reference = 0', [(int) $active, $id]);
    }

    /** Établissements qui acceptent une devise (nom, et si c'est leur devise affichée par défaut). */
    public function etablissementsAcceptant(int $deviseId): array
    {
        return Database::tous(
            'SELECT e.id, e.nom, (e.devise_affichage_id = ed.devise_id) AS par_defaut
               FROM etablissement_devises ed
               JOIN etablissements e ON e.id = ed.etablissement_id
              WHERE ed.devise_id = ?
              ORDER BY e.position, e.nom',
            [$deviseId]
        );
    }

    /** Nombre de prix enregistrés dans une devise, et nombre de produits concernés. */
    public function prixEnregistres(int $deviseId): array
    {
        $ligne = Database::un(
            'SELECT COUNT(*) AS prix, COUNT(DISTINCT c.produit_id) AS produits
               FROM prix p
               JOIN conditionnements c ON c.id = p.conditionnement_id
              WHERE p.devise_id = ?',
            [$deviseId]
        );
        return ['prix' => (int) ($ligne['prix'] ?? 0), 'produits' => (int) ($ligne['produits'] ?? 0)];
    }

    /**
     * Enregistre des taux saisis ensemble.
     * @param array<int, string> $taux devise_id => taux (chaîne décimale)
     */
    public function enregistrerTaux(array $taux, string $valableDepuis, int $utilisateurId, int $referenceId): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            foreach ($taux as $deviseId => $valeur) {
                Database::executer(
                    'INSERT INTO taux_change (devise_id, taux, reference_id, valable_depuis, cree_par) VALUES (?, ?, ?, ?, ?)',
                    [$deviseId, $valeur, $referenceId, $valableDepuis, $utilisateurId]
                );
            }
            $pdo->commit();
        } catch (\Throwable $erreur) {
            $pdo->rollBack();
            throw $erreur;
        }
    }

    /** Derniers taux saisis, avec la devise, la référence de l'époque et l'auteur. */
    public function historique(int $limite = 30): array
    {
        return Database::tous(
            'SELECT t.*, d.code, d.symbole, d.nom_fr, d.nom_en,
                    COALESCE(r.code, (SELECT code FROM devises WHERE est_reference = 1 LIMIT 1)) AS reference_code,
                    u.nom AS auteur
               FROM taux_change t
               JOIN devises d ON d.id = t.devise_id
               LEFT JOIN devises r ON r.id = t.reference_id
               LEFT JOIN utilisateurs u ON u.id = t.cree_par
              WHERE t.devise_id <> COALESCE(t.reference_id, (SELECT id FROM devises WHERE est_reference = 1 LIMIT 1))
              ORDER BY t.cree_le DESC, t.id DESC
              LIMIT ' . max(1, min(200, $limite))
        );
    }

    /** Ids des taux en vigueur (le plus récent déjà valable pour chaque devise). */
    public function idsTauxEnVigueur(): array
    {
        $lignes = Database::tous(
            'SELECT (SELECT t.id FROM taux_change t
                      WHERE t.devise_id = d.id AND t.valable_depuis <= ?
                      ORDER BY t.valable_depuis DESC, t.id DESC LIMIT 1) AS id
               FROM devises d',
            [date('Y-m-d H:i:s')]
        );
        return array_map('intval', array_filter(array_column($lignes, 'id')));
    }

    /**
     * Change la devise de référence. Les taux en vigueur sont recalculés par rapport à la nouvelle
     * référence (1 nouvelle = 1 / taux_nouvelle ancienne), et les taux programmés pour plus tard,
     * exprimés dans l'ancienne référence, sont supprimés.
     */
    /** Devise locale (tickets et factures) : une seule à la fois, parmi les devises actives. */
    public function choisirLocale(int $id): void
    {
        Database::executer('UPDATE devises SET est_locale = (id = ?) WHERE active = 1 OR id = ?', [$id, $id]);
        Database::executer('UPDATE devises SET est_locale = 0 WHERE active = 0');
    }

    public function changerReference(int $ancienneId, int $nouvelleId, float $tauxNouvelle, int $utilisateurId): void
    {
        $maintenant = date('Y-m-d H:i:s');
        $devises = $this->toutes();
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            Database::executer('DELETE FROM taux_change WHERE valable_depuis > ?', [$maintenant]);
            foreach ($devises as $devise) {
                $id = (int) $devise['id'];
                if ($id === $nouvelleId) {
                    $taux = 1.0;
                } elseif ($id === $ancienneId) {
                    $taux = 1 / $tauxNouvelle;
                } elseif ($devise['taux'] !== null && (float) $devise['taux'] > 0) {
                    $taux = (float) $devise['taux'] / $tauxNouvelle;
                } else {
                    continue;
                }
                Database::executer(
                    'INSERT INTO taux_change (devise_id, taux, reference_id, valable_depuis, cree_par) VALUES (?, ?, ?, ?, ?)',
                    [$id, self::decimal($taux), $nouvelleId, $maintenant, $utilisateurId]
                );
            }
            Database::executer('UPDATE devises SET est_reference = 0 WHERE id = ?', [$ancienneId]);
            Database::executer('UPDATE devises SET est_reference = 1, active = 1 WHERE id = ?', [$nouvelleId]);
            $pdo->commit();
        } catch (\Throwable $erreur) {
            $pdo->rollBack();
            throw $erreur;
        }
    }

    /** Taux au format de la colonne DECIMAL(18,6), au moins 0.000001. */
    public static function decimal(float $taux): string
    {
        return number_format(max($taux, 0.000001), 6, '.', '');
    }
}
