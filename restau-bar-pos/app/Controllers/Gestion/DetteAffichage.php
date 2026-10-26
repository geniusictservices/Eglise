<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\View;

/** Affichage commun des écrans clients, dettes, remboursements et créances : montants par devise, statuts, interdiction. */
final class DetteAffichage
{
    /**
     * Montants par devise dans l'ordre d'affichage des devises (locale d'abord, dollar ensuite).
     * @param array<int, float> $parDevise
     * @param array<int, array> $devises indexées par id, dans l'ordre (VenteController::devises())
     * @return array<int, float>
     */
    public static function ordonner(array $parDevise, array $devises): array
    {
        $ordre = array_flip(array_keys($devises));
        uksort($parDevise, static fn ($a, $b) => ($ordre[$a] ?? 999) <=> ($ordre[$b] ?? 999));
        return $parDevise;
    }

    /** « 45 000 FC · $12 » (montants non nuls, dans l'ordre des devises). */
    public static function montants(array $parDevise, array $devises, string $separateur = ' · '): string
    {
        $textes = [];
        foreach (self::ordonner($parDevise, $devises) as $deviseId => $montant) {
            if (abs((float) $montant) >= 0.0005 && isset($devises[$deviseId])) {
                $textes[] = TresorerieAffichage::montant($montant, $devises[$deviseId]);
            }
        }
        return implode($separateur, $textes);
    }

    public static function montant(float|string|null $montant, int|string|null $deviseId, array $devises): string
    {
        return isset($devises[(int) $deviseId]) ? TresorerieAffichage::montant($montant, $devises[(int) $deviseId]) : (string) $montant;
    }

    /** Classe du badge d'un statut de dette. */
    public static function badgeDette(string $statut): string
    {
        return match ($statut) {
            'ouverte' => 'badge--braise',
            'soldee' => 'badge--succes',
            'transferee' => 'badge--glace',
            default => 'badge--neutre',
        };
    }

    /** Classe du badge d'un statut de message. */
    public static function badgeMessage(string $statut): string
    {
        return match ($statut) {
            'envoye' => 'badge--succes',
            'echec' => 'badge--erreur',
            default => 'badge--neutre',
        };
    }

    /** Identifiant positif lu dans l'adresse ou un formulaire, sinon null (même règle que TresorerieSaisie::id). */
    public static function id(mixed $valeur): ?int
    {
        return TresorerieSaisie::id($valeur);
    }

    /** UUID v4 (clé d'un formulaire : un double envoi ne compte qu'une fois). */
    public static function uuid(): string
    {
        $octets = random_bytes(16);
        $octets[6] = chr((ord($octets[6]) & 0x0f) | 0x40);
        $octets[8] = chr((ord($octets[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($octets), 4));
    }

    public static function estUuid(string $texte): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $texte) === 1;
    }

    public static function interdit(array $utilisateur): never
    {
        http_response_code(403);
        View::afficher('gestion/interdit', ['titre' => t('acces.interdit_titre'), 'utilisateur' => $utilisateur], 'gestion');
        exit;
    }

    /** Page d'une liste (?page=), ramenée dans les bornes. */
    public static function page(): int
    {
        return TresorerieSaisie::id($_GET['page'] ?? null) ?? 1;
    }
}
