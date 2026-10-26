<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\View;

/**
 * Droits des écrans Stock et Achats, lieu par lieu. Un lieu d'un établissement suit la portée du rôle
 * (Acces::a avec l'établissement) ; un dépôt central (sans établissement) se consulte avec la permission
 * dans n'importe quel établissement, mais ne se modifie qu'avec la permission pour tous les établissements.
 */
final class StockAcces
{
    /** Établissements où la personne a la permission (null = tous). */
    public static function etablissements(int $utilisateurId, string $permission): ?array
    {
        return Acces::etablissements($utilisateurId, $permission);
    }

    /** Peut-elle agir (permission $permission) sur ce lieu ou cette pièce rattachée à un établissement (null = central) ? */
    public static function peut(int $utilisateurId, string $permission, ?int $etablissementId): bool
    {
        if ($etablissementId === null) {
            return Acces::a($utilisateurId, $permission) && Acces::etablissements($utilisateurId, $permission) === null;
        }
        return Acces::a($utilisateurId, $permission, $etablissementId);
    }

    /** Peut-elle voir ce lieu (lecture) ? */
    public static function voit(int $utilisateurId, string $permission, ?int $etablissementId): bool
    {
        return $etablissementId === null ? Acces::a($utilisateurId, $permission) : Acces::a($utilisateurId, $permission, $etablissementId);
    }

    /** Lieux (tels que rendus par StockRepository::lieux()) sur lesquels la personne peut agir. */
    public static function filtrer(array $lieux, int $utilisateurId, string $permission): array
    {
        return array_values(array_filter($lieux, static fn (array $l): bool =>
            self::peut($utilisateurId, $permission, $l['etablissement_id'] !== null ? (int) $l['etablissement_id'] : null)));
    }

    public static function interdit(array $utilisateur): never
    {
        http_response_code(403);
        View::afficher('gestion/interdit', ['titre' => t('acces.interdit_titre'), 'utilisateur' => $utilisateur], 'gestion');
        exit;
    }

    /** Identifiant positif lu dans une chaîne d'adresse ou de formulaire, sinon null. */
    public static function id(mixed $valeur): ?int
    {
        return is_string($valeur) && ctype_digit($valeur) && strlen($valeur) < 10 && (int) $valeur > 0 ? (int) $valeur : null;
    }

    /**
     * Quantité saisie (« 12 », « 0,5 », « 2.25 ») : nombre positif ou nul, trois décimales au plus.
     * @return array{0: ?float, 1: ?string} quantité, ou message d'erreur
     */
    public static function quantite(mixed $saisie, bool $zeroPermis = false): array
    {
        $texte = TresorerieSaisie::texte($saisie);
        if ($texte === '') {
            return [null, t('stock.erreur_quantite_vide')];
        }
        $nombre = DeviseAffichage::lireNombre($texte);
        if ($nombre === null) {
            return [null, t('stock.erreur_quantite_nombre', ['saisie' => $texte])];
        }
        $valeur = (float) $nombre;
        if ($valeur >= 1e9) {
            return [null, t('stock.erreur_quantite_grande')];
        }
        if ($valeur <= 0 && !$zeroPermis) {
            return [null, t('stock.erreur_quantite_zero')];
        }
        return [round($valeur, 3), null];
    }
}
