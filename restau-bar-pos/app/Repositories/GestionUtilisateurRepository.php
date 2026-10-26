<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Utilisateurs, rôles, appareils de caisse et journal (Paramètres > Utilisateurs). */
final class GestionUtilisateurRepository
{
    /** @return list<array> utilisateurs avec leurs rôles (« roles » : liste de {role_id, code, nom_fr, nom_en, tout, etablissement_id, etablissement}) */
    public function tous(): array
    {
        $utilisateurs = Database::tous(
            'SELECT id, nom, email, telephone, poste, avatar, langue, actif, derniere_connexion, cree_le,
                    pin IS NOT NULL AS a_pin, pin_bloque_le, mot_de_passe IS NOT NULL AS a_mot_de_passe
               FROM utilisateurs ORDER BY actif DESC, nom'
        );
        $roles = $this->rolesDesUtilisateurs();
        foreach ($utilisateurs as &$u) {
            $u['roles'] = $roles[(int) $u['id']] ?? [];
        }
        unset($u);
        return $utilisateurs;
    }

    public function parId(int $id): ?array
    {
        $u = Database::un(
            'SELECT id, nom, email, telephone, poste, avatar, langue, actif, derniere_connexion, cree_le,
                    pin IS NOT NULL AS a_pin, pin_bloque_le, mot_de_passe IS NOT NULL AS a_mot_de_passe
               FROM utilisateurs WHERE id = ?',
            [$id]
        );
        if ($u !== null) {
            $u['roles'] = $this->rolesDesUtilisateurs($id)[$id] ?? [];
        }
        return $u;
    }

    /** @return array<int, list<array>> */
    private function rolesDesUtilisateurs(?int $id = null): array
    {
        $lignes = Database::tous(
            'SELECT ur.utilisateur_id, ur.role_id, r.code, r.nom_fr, r.nom_en, r.tout, ur.etablissement_id, e.nom AS etablissement
               FROM utilisateur_roles ur
               JOIN roles r ON r.id = ur.role_id
          LEFT JOIN etablissements e ON e.id = ur.etablissement_id'
                . ($id !== null ? ' WHERE ur.utilisateur_id = ?' : '') . '
           ORDER BY r.position, e.nom',
            $id !== null ? [$id] : []
        );
        $parUtilisateur = [];
        foreach ($lignes as $ligne) {
            $parUtilisateur[(int) $ligne['utilisateur_id']][] = $ligne;
        }
        return $parUtilisateur;
    }

    public function existe(string $colonne, string $valeur, ?int $saufId = null): bool
    {
        if (!in_array($colonne, ['email', 'telephone'], true)) {
            throw new \InvalidArgumentException($colonne);
        }
        return Database::un(
            "SELECT id FROM utilisateurs WHERE $colonne = ?" . ($saufId !== null ? ' AND id <> ?' : ''),
            $saufId !== null ? [$valeur, $saufId] : [$valeur]
        ) !== null;
    }

    /**
     * Crée ou modifie une personne et remplace ses rôles, en une transaction.
     *
     * @param array{nom: string, poste: ?string, telephone: ?string, email: ?string, langue: string} $valeurs
     * @param list<array{role_id: int, etablissement_id: ?int}> $roles
     */
    public function enregistrer(?int $id, array $valeurs, array $roles, ?string $motDePasse, ?string $pin): int
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            if ($id === null) {
                Database::executer(
                    'INSERT INTO utilisateurs (nom, poste, telephone, email, langue) VALUES (?, ?, ?, ?, ?)',
                    [$valeurs['nom'], $valeurs['poste'], $valeurs['telephone'], $valeurs['email'], $valeurs['langue']]
                );
                $id = Database::dernierId();
            } else {
                Database::executer(
                    'UPDATE utilisateurs SET nom = ?, poste = ?, telephone = ?, email = ?, langue = ? WHERE id = ?',
                    [$valeurs['nom'], $valeurs['poste'], $valeurs['telephone'], $valeurs['email'], $valeurs['langue'], $id]
                );
            }
            if ($motDePasse !== null) {
                Database::executer(
                    'UPDATE utilisateurs SET mot_de_passe = ?, connexion_essais = 0, connexion_bloquee_jusqua = NULL WHERE id = ?',
                    [password_hash($motDePasse, PASSWORD_DEFAULT), $id]
                );
            }
            if ($pin !== null) {
                $this->definirPin($id, $pin);
            }
            Database::executer('DELETE FROM utilisateur_roles WHERE utilisateur_id = ?', [$id]);
            foreach ($roles as $role) {
                Database::executer(
                    'INSERT IGNORE INTO utilisateur_roles (utilisateur_id, role_id, etablissement_id) VALUES (?, ?, ?)',
                    [$id, $role['role_id'], $role['etablissement_id']]
                );
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    public function definirPin(int $id, string $pin): void
    {
        Database::executer(
            'UPDATE utilisateurs SET pin = ?, pin_essais = 0, pin_bloque_le = NULL WHERE id = ?',
            [password_hash($pin, PASSWORD_DEFAULT), $id]
        );
    }

    /** Ce PIN est-il déjà celui d'une autre personne ? (deux personnes, un même PIN : confusion à la caisse) */
    public function pinDejaPris(string $pin, ?int $saufId): bool
    {
        foreach (Database::tous('SELECT id, pin FROM utilisateurs WHERE pin IS NOT NULL AND actif = 1') as $ligne) {
            if ((int) $ligne['id'] !== $saufId && password_verify($pin, $ligne['pin'])) {
                return true;
            }
        }
        return false;
    }

    public function supprimerPin(int $id): void
    {
        Database::executer('UPDATE utilisateurs SET pin = NULL, pin_essais = 0, pin_bloque_le = NULL WHERE id = ?', [$id]);
    }

    public function supprimerMotDePasse(int $id): void
    {
        Database::executer('UPDATE utilisateurs SET mot_de_passe = NULL WHERE id = ?', [$id]);
    }

    public function debloquer(int $id): void
    {
        Database::executer(
            'UPDATE utilisateurs SET pin_essais = 0, pin_bloque_le = NULL, connexion_essais = 0, connexion_bloquee_jusqua = NULL WHERE id = ?',
            [$id]
        );
    }

    public function definirActif(int $id, bool $actif): void
    {
        Database::executer('UPDATE utilisateurs SET actif = ? WHERE id = ?', [(int) $actif, $id]);
    }

    /** Propriétaires actifs sans limite d'établissement (il en faut toujours au moins un). */
    public function proprietaires(): array
    {
        return array_map('intval', array_column(Database::tous(
            'SELECT DISTINCT u.id FROM utilisateurs u
               JOIN utilisateur_roles ur ON ur.utilisateur_id = u.id AND ur.etablissement_id IS NULL
               JOIN roles r ON r.id = ur.role_id AND r.tout = 1
              WHERE u.actif = 1'
        ), 'id'));
    }

    // ---------- Rôles ----------

    /** @return list<array> rôles avec leurs permissions et le nombre de personnes */
    public function roles(): array
    {
        $roles = Database::tous(
            'SELECT r.*, (SELECT COUNT(DISTINCT ur.utilisateur_id) FROM utilisateur_roles ur WHERE ur.role_id = r.id) AS nb_personnes
               FROM roles r ORDER BY r.position, r.nom_fr'
        );
        $permissions = [];
        foreach (Database::tous('SELECT role_id, permission FROM role_permissions') as $ligne) {
            $permissions[(int) $ligne['role_id']][] = $ligne['permission'];
        }
        foreach ($roles as &$role) {
            $role['permissions'] = $permissions[(int) $role['id']] ?? [];
        }
        unset($role);
        return $roles;
    }

    public function role(int $id): ?array
    {
        foreach ($this->roles() as $role) {
            if ((int) $role['id'] === $id) {
                return $role;
            }
        }
        return null;
    }

    public function codeRoleExiste(string $code, ?int $saufId = null): bool
    {
        return Database::un(
            'SELECT id FROM roles WHERE code = ?' . ($saufId !== null ? ' AND id <> ?' : ''),
            $saufId !== null ? [$code, $saufId] : [$code]
        ) !== null;
    }

    /** @param list<string> $permissions */
    public function enregistrerRole(?int $id, array $valeurs, array $permissions): int
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            if ($id === null) {
                $position = (int) (Database::un('SELECT COALESCE(MAX(position), 0) + 1 AS p FROM roles')['p']);
                Database::executer(
                    'INSERT INTO roles (code, nom_fr, nom_en, description_fr, description_en, position) VALUES (?, ?, ?, ?, ?, ?)',
                    [$valeurs['code'], $valeurs['nom_fr'], $valeurs['nom_en'], $valeurs['description_fr'], $valeurs['description_en'], $position]
                );
                $id = Database::dernierId();
            } else {
                Database::executer(
                    'UPDATE roles SET nom_fr = ?, nom_en = ?, description_fr = ?, description_en = ? WHERE id = ? AND tout = 0',
                    [$valeurs['nom_fr'], $valeurs['nom_en'], $valeurs['description_fr'], $valeurs['description_en'], $id]
                );
            }
            Database::executer('DELETE FROM role_permissions WHERE role_id = ?', [$id]);
            foreach ($permissions as $permission) {
                Database::executer('INSERT INTO role_permissions (role_id, permission) VALUES (?, ?)', [$id, $permission]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    public function supprimerRole(int $id): void
    {
        Database::executer('DELETE FROM roles WHERE id = ? AND tout = 0', [$id]);
    }

    // ---------- Appareils et journal ----------

    public function appareils(): array
    {
        return Database::tous(
            'SELECT a.id, a.nom, a.actif, a.cree_le, a.dernier_usage, a.pin_echecs, e.nom AS etablissement, u.nom AS autorise_par
               FROM appareils_caisse a
               JOIN etablissements e ON e.id = a.etablissement_id
          LEFT JOIN utilisateurs u ON u.id = a.autorise_par
           ORDER BY a.actif DESC, a.dernier_usage DESC, a.id DESC'
        );
    }

    public function appareil(int $id): ?array
    {
        return Database::un('SELECT * FROM appareils_caisse WHERE id = ?', [$id]);
    }

    public function retirerAppareil(int $id): void
    {
        Database::executer('UPDATE appareils_caisse SET actif = 0 WHERE id = ?', [$id]);
    }

    /** @return list<array> dernières entrées du journal, filtrées par personne si demandé */
    public function journal(?int $utilisateurId, int $limite = 200): array
    {
        return Database::tous(
            'SELECT j.*, u.nom AS utilisateur, a.nom AS appareil
               FROM journal j
          LEFT JOIN utilisateurs u ON u.id = j.utilisateur_id
          LEFT JOIN appareils_caisse a ON a.id = j.appareil_id'
                . ($utilisateurId !== null ? ' WHERE j.utilisateur_id = ?' : '') . '
           ORDER BY j.id DESC LIMIT ' . max(1, min(1000, $limite)),
            $utilisateurId !== null ? [$utilisateurId] : []
        );
    }
}
