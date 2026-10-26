<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Modules de l'espace de gestion, montrés sur l'écran d'accueil après la connexion.
 * Chaque module a ses écrans : barre latérale sur ordinateur, barre du bas sur téléphone.
 * Un écran sans « lien » est annoncé « Bientôt » (pas encore construit).
 * Avec les rôles (phase 2), chaque utilisateur ne verra que les modules auxquels il a droit.
 */
final class Modules
{
    /** @return array<string, array{icone: string, lien: ?string, ecrans: list<array{cle: string, icone: string, lien: ?string, court?: bool}>}> */
    public static function tous(): array
    {
        return [
            'caisse' => ['icone' => 'caisse', 'lien' => '/caisse', 'ecrans' => []],
            'ventes' => ['icone' => 'ventes', 'lien' => null, 'ecrans' => [
                ['cle' => 'tickets', 'icone' => 'ventes', 'lien' => null],
                ['cle' => 'clients', 'icone' => 'compte', 'lien' => null],
                ['cle' => 'dettes', 'icone' => 'dettes', 'lien' => null],
            ]],
            'achats' => ['icone' => 'achats', 'lien' => null, 'ecrans' => [
                ['cle' => 'fournisseurs', 'icone' => 'etablissements', 'lien' => null],
                ['cle' => 'commandes', 'icone' => 'achats', 'lien' => null],
                ['cle' => 'receptions', 'icone' => 'stock', 'lien' => null],
            ]],
            'stock' => ['icone' => 'stock', 'lien' => null, 'ecrans' => [
                ['cle' => 'depots', 'icone' => 'stock', 'lien' => null],
                ['cle' => 'inventaires', 'icone' => 'inventaire', 'lien' => null],
                ['cle' => 'mouvements_stock', 'icone' => 'transferts', 'lien' => null],
            ]],
            'personnel' => ['icone' => 'personnel', 'lien' => null, 'ecrans' => [
                ['cle' => 'employes', 'icone' => 'compte', 'lien' => null],
                ['cle' => 'presence', 'icone' => 'qr', 'lien' => null],
                ['cle' => 'plannings', 'icone' => 'planning', 'lien' => null],
                ['cle' => 'paie', 'icone' => 'paie', 'lien' => null],
                ['cle' => 'avances', 'icone' => 'depenses', 'lien' => null],
            ]],
            'finance' => ['icone' => 'finance', 'lien' => '/gestion/tresorerie', 'ecrans' => [
                ['cle' => 'comptes', 'icone' => 'finance', 'lien' => '/gestion/tresorerie'],
                ['cle' => 'transferts', 'icone' => 'transferts', 'lien' => '/gestion/tresorerie/transferts'],
                ['cle' => 'depenses', 'icone' => 'depenses', 'lien' => '/gestion/depenses'],
                ['cle' => 'devises', 'icone' => 'devises', 'lien' => '/gestion/devises'],
            ]],
            'rapports' => ['icone' => 'rapports', 'lien' => '/gestion/tableau-de-bord', 'ecrans' => [
                ['cle' => 'tableau', 'icone' => 'rapports', 'lien' => '/gestion/tableau-de-bord'],
                ['cle' => 'rapport_ventes', 'icone' => 'ventes', 'lien' => null],
                ['cle' => 'rapport_personnel', 'icone' => 'compte', 'lien' => null],
            ]],
            'carte' => ['icone' => 'carte', 'lien' => '/gestion/catalogue', 'ecrans' => [
                ['cle' => 'produits', 'icone' => 'carte', 'lien' => '/gestion/catalogue'],
                ['cle' => 'categories', 'icone' => 'categories', 'lien' => '/gestion/catalogue/categories'],
                ['cle' => 'prix', 'icone' => 'prix', 'lien' => '/gestion/grilles'],
                ['cle' => 'medias', 'icone' => 'medias', 'lien' => '/gestion/mediatheque'],
                ['cle' => 'qr', 'icone' => 'qr', 'lien' => '/gestion/qr'],
            ]],
            'parametres' => ['icone' => 'parametres', 'lien' => '/gestion/etablissements', 'ecrans' => [
                ['cle' => 'etablissements', 'icone' => 'etablissements', 'lien' => '/gestion/etablissements'],
                ['cle' => 'utilisateurs', 'icone' => 'compte', 'lien' => null],
                ['cle' => 'compte', 'icone' => 'compte', 'lien' => '/gestion/compte'],
                ['cle' => 'aide', 'icone' => 'aide', 'lien' => '/gestion/aide'],
                ['cle' => 'design', 'icone' => 'design', 'lien' => '/gestion/design'],
            ]],
        ];
    }

    /**
     * Module et écran de la page affichée : l'écran dont le lien est le plus long préfixe du chemin
     * (« /gestion/tresorerie/transferts » l'emporte sur « /gestion/tresorerie »).
     *
     * @return array{module: string, ecran: string}|null
     */
    public static function courant(string $chemin): ?array
    {
        $trouve = null;
        $longueur = 0;
        foreach (self::tous() as $code => $module) {
            foreach ($module['ecrans'] as $ecran) {
                $lien = $ecran['lien'];
                if ($lien !== null && ($chemin === $lien || str_starts_with($chemin, $lien . '/')) && strlen($lien) > $longueur) {
                    $trouve = ['module' => $code, 'ecran' => $ecran['cle']];
                    $longueur = strlen($lien);
                }
            }
        }
        return $trouve;
    }
}
