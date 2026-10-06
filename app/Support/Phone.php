<?php

namespace App\Support;

/**
 * Normalise les numéros de téléphone au format international (+243812345678).
 *
 * Accepte les saisies courantes : « 0812 345 678 », « 243812345678 »,
 * « +243 81 234 5678 », « 00243… ». Un numéro sans indicatif reçoit celui
 * du pays par défaut (la RDC).
 */
class Phone
{
    public static function normalize(?string $input, ?string $countryCode = null): ?string
    {
        if ($input === null) {
            return null;
        }

        $countryCode ??= config('waumini.default_country_code', '243');
        $hasPlus = str_starts_with(trim($input), '+');
        $digits = preg_replace('/\D+/', '', $input);

        if ($digits === '') {
            return null;
        }

        if ($hasPlus) {
            $number = $digits;
        } elseif (str_starts_with($digits, '00')) {
            $number = substr($digits, 2);
        } elseif (str_starts_with($digits, $countryCode) && strlen($digits) > 10) {
            $number = $digits;
        } elseif (str_starts_with($digits, '0')) {
            $number = $countryCode.substr($digits, 1);
        } else {
            $number = $countryCode.$digits;
        }

        // E.164 : 8 à 15 chiffres au total.
        if (strlen($number) < 8 || strlen($number) > 15) {
            return null;
        }

        return '+'.$number;
    }

    /** Affichage lisible : +243 812 345 678 */
    public static function format(?string $e164): string
    {
        if (! $e164) {
            return '';
        }

        if (preg_match('/^\+243(\d{3})(\d{3})(\d{3})$/', $e164, $m)) {
            return "+243 {$m[1]} {$m[2]} {$m[3]}";
        }

        return $e164;
    }
}
