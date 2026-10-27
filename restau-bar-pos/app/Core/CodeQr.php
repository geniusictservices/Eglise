<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Codes QR des billets d'entrée, des cartes cashless et des tickets de vestiaire : une lettre (B, C, V), le numéro
 * (identifiant du billet ou du ticket, numéro de la carte), un tiret, puis un jeton aléatoire de 10 caractères
 * (alphabet sans 0, O, 1, I : lisible à la main). Le jeton n'est jamais déduit du numéro : un code ne se devine pas.
 * Exemple : « C0007-K3M9XQ2P7A ». La saisie à la main accepte les minuscules et les espaces.
 */
final class CodeQr
{
    public const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    public const LONGUEUR = 10;

    public static function jeton(): string
    {
        $jeton = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::LONGUEUR; $i++) {
            $jeton .= self::ALPHABET[random_int(0, $max)];
        }
        return $jeton;
    }

    /** Code complet : « B000123-K3M9XQ2P7A » (numéro sur 4 chiffres au moins pour les cartes, 6 pour les billets). */
    public static function code(string $type, int $numero, string $jeton): string
    {
        $chiffres = match ($type) { 'B' => 6, 'V' => 5, default => 4 };
        return $type . str_pad((string) $numero, $chiffres, '0', STR_PAD_LEFT) . '-' . $jeton;
    }

    /**
     * Lit un code scanné ou tapé.
     * @return array{type: string, numero: int, jeton: string}|null
     */
    public static function lire(mixed $saisie, ?string $typeAttendu = null): ?array
    {
        if (!is_string($saisie)) {
            return null;
        }
        $texte = strtoupper(preg_replace('/[\s_]+/', '', $saisie) ?? '');
        if (!preg_match('/^([BCV])(\d{1,9})-?([' . self::ALPHABET . ']{' . self::LONGUEUR . '})$/', $texte, $m)) {
            return null;
        }
        if ($typeAttendu !== null && $m[1] !== $typeAttendu) {
            return null;
        }
        return ['type' => $m[1], 'numero' => (int) $m[2], 'jeton' => $m[3]];
    }
}
