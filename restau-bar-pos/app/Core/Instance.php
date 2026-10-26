<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Une instance = un client, avec sa propre base de données, servie sur son sous-domaine.
 * Sa configuration est dans config/instances/<sous-domaine>.php.
 */
final class Instance
{
    private static ?self $courante = null;

    private function __construct(
        public readonly string $sousDomaine,
        public readonly array $config,
    ) {
    }

    public static function courante(): self
    {
        if (self::$courante === null) {
            throw new RuntimeException('Aucune instance chargée.');
        }
        return self::$courante;
    }

    /**
     * Déduit le sous-domaine de l'hôte demandé.
     * null = sous-domaine réservé (admin, www…) ; '' = hôte inconnu sans instance par défaut.
     */
    public static function sousDomaineDepuisHote(string $hote): ?string
    {
        $hote = strtolower(preg_replace('/:\d+$/', '', $hote) ?? '');
        $base = strtolower((string) Config::get('domaine_base'));

        if ($base !== '' && str_ends_with($hote, '.' . $base)) {
            $sousDomaine = substr($hote, 0, -strlen('.' . $base));
        } elseif (str_ends_with($hote, '.localhost')) {
            // Développement : monbar.localhost:8000
            $sousDomaine = substr($hote, 0, -strlen('.localhost'));
        } else {
            $defaut = Config::get('instance_par_defaut');
            return $defaut ? (string) $defaut : '';
        }

        if (in_array($sousDomaine, Config::get('sous_domaines_reserves', []), true)) {
            return null;
        }
        return $sousDomaine;
    }

    public static function charger(string $sousDomaine): ?self
    {
        // Le sous-domaine sert de nom de fichier : on n'accepte que des caractères sûrs.
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $sousDomaine)) {
            return null;
        }
        $fichier = RACINE . '/config/instances/' . $sousDomaine . '.php';
        if (!is_file($fichier)) {
            return null;
        }
        $config = require $fichier;
        if (!is_array($config) || empty($config['actif'])) {
            return null;
        }
        return self::$courante = new self($sousDomaine, $config);
    }
}
