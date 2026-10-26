<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Droits de la personne connectée, lus à chaque requête (un rôle modifié s'applique tout de suite).
 * Une permission vaut pour les établissements de la portée du rôle (NULL = tous).
 */
final class Acces
{
    /** @var array<int, array{tout: bool, permissions: array<string, list<?int>>}> */
    private static array $cache = [];

    /** @return array{tout: bool, permissions: array<string, list<?int>>} */
    public static function droits(int $utilisateurId): array
    {
        if (!isset(self::$cache[$utilisateurId])) {
            $lignes = Database::tous(
                'SELECT r.tout, ur.etablissement_id, rp.permission
                   FROM utilisateur_roles ur
                   JOIN utilisateurs u ON u.id = ur.utilisateur_id AND u.actif = 1
                   JOIN roles r ON r.id = ur.role_id
              LEFT JOIN role_permissions rp ON rp.role_id = r.id
                  WHERE ur.utilisateur_id = ?',
                [$utilisateurId]
            );
            $droits = ['tout' => false, 'permissions' => []];
            foreach ($lignes as $ligne) {
                $portee = $ligne['etablissement_id'] === null ? null : (int) $ligne['etablissement_id'];
                if ($ligne['tout'] && $portee === null) {
                    $droits['tout'] = true;
                }
                // Un propriétaire limité à un établissement a toutes les permissions, mais pour celui-là seulement
                $codes = $ligne['tout'] ? Permissions::toutes() : ($ligne['permission'] !== null ? [$ligne['permission']] : []);
                foreach ($codes as $code) {
                    $droits['permissions'][$code][] = $portee;
                }
            }
            self::$cache[$utilisateurId] = $droits;
        }
        return self::$cache[$utilisateurId];
    }

    public static function oublier(?int $utilisateurId = null): void
    {
        if ($utilisateurId === null) {
            self::$cache = [];
        } else {
            unset(self::$cache[$utilisateurId]);
        }
    }

    /** La personne a-t-elle cette permission (pour cet établissement, ou pour au moins un si $etablissementId est nul) ? */
    public static function a(?int $utilisateurId, ?string $permission, ?int $etablissementId = null): bool
    {
        if ($permission === null) {
            return $utilisateurId !== null;
        }
        if ($utilisateurId === null) {
            return false;
        }
        $droits = self::droits($utilisateurId);
        if ($droits['tout']) {
            return true;
        }
        $portees = $droits['permissions'][$permission] ?? [];
        if ($portees === []) {
            return false;
        }
        return $etablissementId === null || in_array(null, $portees, true) || in_array($etablissementId, $portees, true);
    }

    /** Établissements où la personne a la permission : null = tous. */
    public static function etablissements(int $utilisateurId, string $permission): ?array
    {
        $droits = self::droits($utilisateurId);
        if ($droits['tout']) {
            return null;
        }
        $portees = $droits['permissions'][$permission] ?? [];
        return in_array(null, $portees, true) ? null : array_values(array_unique($portees));
    }

    /** A-t-elle au moins un module de gestion (autre que la caisse) ? Sinon, elle ne voit que la caisse. */
    public static function aLaGestion(int $utilisateurId): bool
    {
        $droits = self::droits($utilisateurId);
        if ($droits['tout']) {
            return true;
        }
        foreach (array_keys($droits['permissions']) as $code) {
            if (!str_starts_with($code, 'caisse.')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Garde de l'espace de gestion, avant l'action : l'écran (Modules) donne la permission pour voir
     * et celle pour modifier (requêtes POST). Écran inconnu (accueil, déconnexion…) : seule la connexion compte.
     */
    public static function garder(string $methode, string $chemin): void
    {
        $utilisateur = Session::utilisateur();
        if ($utilisateur === null || !str_starts_with($chemin, '/gestion')) {
            return;
        }
        // Compte désactivé depuis la connexion : la session s'arrête tout de suite
        if (Database::un('SELECT id FROM utilisateurs WHERE id = ? AND actif = 1', [(int) $utilisateur['id']]) === null) {
            Session::deconnecter();
            rediriger('/gestion/connexion');
        }
        $ecran = Modules::ecranDe($chemin);
        if ($ecran === null) {
            return;
        }
        $id = (int) $utilisateur['id'];
        $permission = $methode === 'POST' ? ($ecran['modifier'] ?? $ecran['voir']) : $ecran['voir'];
        $permis = self::a($id, $permission);
        // Le champ image (logo et couverture d'un établissement) liste et envoie des images de la médiathèque
        if (!$permis && in_array($chemin, ['/gestion/mediatheque/liste', '/gestion/mediatheque/envoyer'], true)) {
            $permis = self::a($id, 'carte.modifier') || self::a($id, 'parametres.etablissements');
        }
        if (!$permis) {
            http_response_code(403);
            View::afficher('gestion/interdit', ['titre' => t('acces.interdit_titre'), 'utilisateur' => $utilisateur], 'gestion');
            exit;
        }
    }
}
