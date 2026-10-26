<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Session;
use App\Core\View;
use App\Repositories\EtablissementRepository;
use App\Repositories\PersonnelRepository;

/**
 * Droits et outils communs des écrans Personnel et paie. Chaque permission vaut pour les établissements
 * de la portée du rôle : un employé se voit (ou se modifie) si l'un de ses établissements y est.
 */
final class PersonnelAcces
{
    /** Établissements où la personne a la permission (null = tous). */
    public static function portee(int $utilisateurId, string $permission): ?array
    {
        return Acces::etablissements($utilisateurId, $permission);
    }

    /** @return list<array> établissements actifs de la portée */
    public static function etablissements(int $utilisateurId, string $permission): array
    {
        $portee = self::portee($utilisateurId, $permission);
        return array_values(array_filter((new EtablissementRepository())->actifs(),
            static fn (array $e) => $portee === null || in_array((int) $e['id'], $portee, true)));
    }

    /** Établissement choisi dans l'adresse (?etablissement=) s'il est permis, sinon le premier permis (ou null si $tous). */
    public static function etablissementChoisi(array $etablissements, mixed $valeur, bool $tous = false): ?array
    {
        $id = TresorerieSaisie::id($valeur);
        foreach ($etablissements as $e) {
            if ((int) $e['id'] === $id) {
                return $e;
            }
        }
        return $tous ? null : ($etablissements[0] ?? null);
    }

    /** Peut-elle agir sur cet employé (permission dans au moins un de ses établissements) ? */
    public static function peutEmploye(int $utilisateurId, string $permission, array $employe): bool
    {
        return PersonnelRepository::dansPortee($employe, self::portee($utilisateurId, $permission)) && Acces::a($utilisateurId, $permission);
    }

    /** Employé visible (personnel.voir, ou la permission demandée), sinon 404. */
    public static function employeOu404(string $id, int $utilisateurId, string $permission = 'personnel.voir'): array
    {
        $employe = ctype_digit($id) && strlen($id) < 10 ? (new PersonnelRepository())->employe((int) $id) : null;
        if ($employe === null || !self::peutEmploye($utilisateurId, $permission, $employe)) {
            pageIntrouvable();
        }
        return $employe;
    }

    public static function interdit(array $utilisateur): never
    {
        http_response_code(403);
        View::afficher('gestion/interdit', ['titre' => t('acces.interdit_titre'), 'utilisateur' => $utilisateur], 'gestion');
        exit;
    }

    /** Connexion, puis jeton du formulaire (requêtes POST). */
    public static function connecte(bool $post = false): array
    {
        $utilisateur = Session::exigerConnexion();
        if ($post) {
            Session::verifierCsrf();
        }
        return $utilisateur;
    }
}
