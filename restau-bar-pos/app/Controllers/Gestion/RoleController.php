<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Journal;
use App\Core\Permissions;
use App\Core\Session;
use App\Core\View;
use App\Repositories\GestionUtilisateurRepository;

/**
 * Paramètres > Utilisateurs > Rôles : modèles du cahier des charges, modifiables, et rôles créés.
 * Le rôle Propriétaire (« tout ») ne se modifie pas. On ne met dans un rôle que des droits qu'on a soi-même.
 */
final class RoleController
{
    private GestionUtilisateurRepository $depot;

    public function __construct()
    {
        $this->depot = new GestionUtilisateurRepository();
    }

    public function index(): void
    {
        $moi = Session::exigerConnexion();
        View::afficher('gestion/utilisateurs/roles', [
            'titre' => t('roles.titre'),
            'utilisateur' => $moi,
            'flash' => Session::lireFlash(),
            'roles' => $this->depot->roles(),
        ], 'gestion');
    }

    public function nouveau(): void
    {
        $moi = Session::exigerConnexion();
        $this->afficherFiche($moi, null, []);
    }

    public function creer(): void
    {
        $moi = Session::exigerConnexion();
        Session::verifierCsrf();
        $this->enregistrer($moi, null);
    }

    public function fiche(string $id): void
    {
        $moi = Session::exigerConnexion();
        $this->afficherFiche($moi, $this->roleOu404($id), []);
    }

    public function modifier(string $id): void
    {
        $moi = Session::exigerConnexion();
        Session::verifierCsrf();
        $role = $this->roleOu404($id);
        if ($role['tout']) {
            $this->afficherFiche($moi, $role, ['erreurs' => ['general' => t('roles.erreur_proprietaire')]], 422);
            return;
        }
        $this->enregistrer($moi, $role);
    }

    public function supprimer(string $id): void
    {
        $moi = Session::exigerConnexion();
        Session::verifierCsrf();
        $role = $this->roleOu404($id);
        if ($role['tout'] || (int) $role['nb_personnes'] > 0) {
            $this->afficherFiche($moi, $role, ['erreurs' => ['general' => t($role['tout'] ? 'roles.erreur_proprietaire' : 'roles.erreur_utilise', ['n' => (int) $role['nb_personnes']])]], 422);
            return;
        }
        $this->depot->supprimerRole((int) $role['id']);
        Journal::ecrire('role_supprime', $role['nom_fr']);
        Session::flash(t('roles.supprime', ['nom' => champ($role, 'nom')]));
        rediriger('/gestion/utilisateurs/roles');
    }

    private function enregistrer(array $moi, ?array $role): void
    {
        $texte = static fn (string $cle, int $max): string => mb_substr(is_string($_POST[$cle] ?? null) ? trim((string) preg_replace('/\p{Cc}/u', '', $_POST[$cle])) : '', 0, $max);
        $valeurs = [
            'nom_fr' => $texte('nom_fr', 80),
            'nom_en' => $texte('nom_en', 80),
            'description_fr' => $texte('description_fr', 255) ?: null,
            'description_en' => $texte('description_en', 255) ?: null,
        ];
        $valeurs['nom_en'] = $valeurs['nom_en'] ?: $valeurs['nom_fr'];
        $saisies = is_array($_POST['permissions'] ?? null) ? array_values(array_filter($_POST['permissions'], 'is_string')) : [];
        $permissions = array_values(array_unique(array_filter($saisies, [Permissions::class, 'existe'])));

        $erreurs = [];
        if ($valeurs['nom_fr'] === '') {
            $erreurs['nom_fr'] = t('roles.erreur_nom');
        }
        foreach ($permissions as $permission) {
            // On ne met dans un rôle que ce qu'on a partout soi-même
            if (!Acces::a((int) $moi['id'], $permission) || Acces::etablissements((int) $moi['id'], $permission) !== null) {
                $erreurs['permissions'] = t('roles.erreur_droit', ['permission' => t('perm.' . $permission)]);
                break;
            }
        }
        if ($erreurs !== []) {
            $this->afficherFiche($moi, $role, ['erreurs' => $erreurs, 'valeurs' => $valeurs, 'permissions' => $permissions], 422);
            return;
        }
        if ($role === null) {
            $base = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $valeurs['nom_fr']) ?: 'role')), '_') ?: 'role';
            $code = $base;
            for ($i = 2; $this->depot->codeRoleExiste($code); $i++) {
                $code = $base . '_' . $i;
            }
            $valeurs['code'] = substr($code, 0, 40);
        }
        $id = $this->depot->enregistrerRole($role ? (int) $role['id'] : null, $valeurs, $permissions);
        Acces::oublier();
        Journal::ecrire($role ? 'role_modifie' : 'role_cree', $valeurs['nom_fr'], ['permissions' => $permissions]);
        Session::flash(t($role ? 'roles.modifie' : 'roles.cree', ['nom' => $valeurs['nom_fr']]));
        rediriger('/gestion/utilisateurs/roles/' . $id);
    }

    private function roleOu404(string $id): array
    {
        $role = ctype_digit($id) ? $this->depot->role((int) $id) : null;
        if ($role === null) {
            pageIntrouvable();
        }
        return $role;
    }

    private function afficherFiche(array $moi, ?array $role, array $etat, int $code = 200): void
    {
        http_response_code($code);
        View::afficher('gestion/utilisateurs/role', [
            'titre' => $role ? champ($role, 'nom') : t('roles.nouveau'),
            'utilisateur' => $moi,
            'flash' => Session::lireFlash(),
            'role' => $role,
            'valeurs' => $etat['valeurs'] ?? ($role ?? []),
            'permissionsChoisies' => $etat['permissions'] ?? ($role['permissions'] ?? []),
            'erreurs' => $etat['erreurs'] ?? [],
            'groupes' => Permissions::parModule(),
            'mesDroits' => static fn (string $p) => Acces::a((int) $moi['id'], $p) && Acces::etablissements((int) $moi['id'], $p) === null,
        ], 'gestion');
    }
}
