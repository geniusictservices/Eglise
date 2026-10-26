<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\View;
use App\Repositories\EtablissementRepository;

/**
 * Outils des écrans Salle et Cuisine : établissements où la personne a une permission, établissement choisi
 * (?etablissement=), refus, lecture des saisies.
 */
final class SalleAcces
{
    /** @return list<array> établissements actifs où la personne a la permission */
    public static function etablissements(int $uid, string $permission): array
    {
        $permis = Acces::etablissements($uid, $permission);
        return array_values(array_filter(
            (new EtablissementRepository())->actifs(),
            static fn (array $e) => $permis === null || in_array((int) $e['id'], $permis, true)
        ));
    }

    /** Établissement choisi parmi la liste (paramètre « etablissement » de l'adresse ou du formulaire), le premier sinon. */
    public static function choisi(array $liste, mixed $valeur = null): ?array
    {
        $valeur ??= $_POST['etablissement'] ?? $_GET['etablissement'] ?? null;
        $id = is_scalar($valeur) && ctype_digit((string) $valeur) ? (int) $valeur : 0;
        foreach ($liste as $e) {
            if ((int) $e['id'] === $id) {
                return $e;
            }
        }
        return $liste[0] ?? null;
    }

    /** L'établissement de cet identifiant, si la personne y a la permission ; sinon page 403. */
    public static function exiger(array $utilisateur, string $permission, int $etabId): array
    {
        $etab = (new EtablissementRepository())->parId($etabId);
        if ($etab === null || !Acces::a((int) $utilisateur['id'], $permission, $etabId)) {
            self::interdit($utilisateur);
        }
        return $etab;
    }

    public static function interdit(array $utilisateur): never
    {
        http_response_code(403);
        View::afficher('gestion/interdit', ['titre' => t('acces.interdit_titre'), 'utilisateur' => $utilisateur], 'gestion');
        exit;
    }

    /** Entier lu dans une saisie, borné. */
    public static function entier(mixed $valeur, int $min, int $max, int $defaut): int
    {
        if (!is_scalar($valeur) || !preg_match('/^-?\d+$/', trim((string) $valeur))) {
            return $defaut;
        }
        return max($min, min($max, (int) $valeur));
    }

    /** Texte d'une saisie, sans espaces superflus, coupé à $max caractères. */
    public static function texte(mixed $valeur, int $max): string
    {
        return is_string($valeur) ? mb_substr(trim(preg_replace('/\s+/u', ' ', $valeur) ?? ''), 0, $max) : '';
    }

    /** Heure « HH:MM » valide, sinon null. */
    public static function heure(mixed $valeur): ?string
    {
        return is_string($valeur) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $valeur) ? $valeur : null;
    }

    /** Date « Y-m-d » valide, sinon null. */
    public static function date(mixed $valeur): ?string
    {
        if (!is_string($valeur)) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);
        return $d !== false && $d->format('Y-m-d') === $valeur ? $valeur : null;
    }
}
