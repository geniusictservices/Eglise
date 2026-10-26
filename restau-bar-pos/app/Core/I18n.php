<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Traductions de l'interface (lang/<code>.php) et choix de la langue du visiteur :
 * paramètre ?lang=, sinon cookie, sinon en-tête du navigateur, sinon langue par défaut.
 */
final class I18n
{
    private static string $langue = 'fr';
    private static array $textes = [];

    public static function initialiser(?string $langueDefaut = null): void
    {
        $langues = Config::get('langues', ['fr']);
        $choix = $_GET['lang'] ?? $_COOKIE['lang'] ?? null;

        if (is_string($choix) && in_array($choix, $langues, true)) {
            if (isset($_GET['lang'])) {
                setcookie('lang', $choix, [
                    'expires' => time() + 365 * 86400,
                    'path' => '/',
                    'samesite' => 'Lax',
                ]);
            }
            self::$langue = $choix;
        } else {
            self::$langue = self::depuisNavigateur($langues) ?? $langueDefaut ?? Config::get('langue_defaut', 'fr');
        }

        self::$textes = require RACINE . '/lang/' . self::$langue . '.php';
    }

    public static function langue(): string
    {
        return self::$langue;
    }

    public static function texte(string $cle, array $params = []): string
    {
        $texte = self::$textes[$cle] ?? $cle;
        foreach ($params as $nom => $valeur) {
            $texte = str_replace('{' . $nom . '}', (string) $valeur, $texte);
        }
        return $texte;
    }

    private static function depuisNavigateur(array $langues): ?string
    {
        $entete = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        foreach (explode(',', $entete) as $partie) {
            $code = strtolower(substr(trim($partie), 0, 2));
            if (in_array($code, $langues, true)) {
                return $code;
            }
        }
        return null;
    }
}
