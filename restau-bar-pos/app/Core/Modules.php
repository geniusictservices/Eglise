<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Modules de l'espace de gestion, montrés sur l'écran d'accueil après la connexion.
 * Chaque module a ses écrans : barre latérale sur ordinateur, barre du bas sur téléphone.
 * Un écran sans « lien » est annoncé « Bientôt » (pas encore construit).
 * « voir » : permission pour ouvrir l'écran (null = toute personne connectée) ;
 * « modifier » : permission pour ses formulaires (POST), « voir » si absente. Acces::garder() les applique.
 */
final class Modules
{
    /** @return array<string, array{icone: string, lien: ?string, voir?: string, ecrans: list<array{cle: string, icone: string, lien: ?string, voir: ?string, modifier?: string}>}> */
    public static function tous(): array
    {
        return [
            'caisse' => ['icone' => 'caisse', 'lien' => '/caisse', 'voir' => 'caisse.vendre', 'ecrans' => []],
            'ventes' => ['icone' => 'ventes', 'lien' => null, 'ecrans' => [
                ['cle' => 'tickets', 'icone' => 'ventes', 'lien' => null, 'voir' => 'ventes.voir'],
                ['cle' => 'clients', 'icone' => 'compte', 'lien' => null, 'voir' => 'ventes.voir'],
                ['cle' => 'dettes', 'icone' => 'dettes', 'lien' => null, 'voir' => 'ventes.voir'],
            ]],
            'achats' => ['icone' => 'achats', 'lien' => null, 'ecrans' => [
                ['cle' => 'fournisseurs', 'icone' => 'etablissements', 'lien' => null, 'voir' => 'achats.voir'],
                ['cle' => 'commandes', 'icone' => 'achats', 'lien' => null, 'voir' => 'achats.voir'],
                ['cle' => 'receptions', 'icone' => 'stock', 'lien' => null, 'voir' => 'achats.voir'],
            ]],
            'stock' => ['icone' => 'stock', 'lien' => null, 'ecrans' => [
                ['cle' => 'depots', 'icone' => 'stock', 'lien' => null, 'voir' => 'stock.voir'],
                ['cle' => 'inventaires', 'icone' => 'inventaire', 'lien' => null, 'voir' => 'stock.voir'],
                ['cle' => 'mouvements_stock', 'icone' => 'transferts', 'lien' => null, 'voir' => 'stock.voir'],
            ]],
            'personnel' => ['icone' => 'personnel', 'lien' => null, 'ecrans' => [
                ['cle' => 'employes', 'icone' => 'compte', 'lien' => null, 'voir' => 'personnel.voir'],
                ['cle' => 'presence', 'icone' => 'qr', 'lien' => null, 'voir' => 'personnel.voir'],
                ['cle' => 'plannings', 'icone' => 'planning', 'lien' => null, 'voir' => 'personnel.voir'],
                ['cle' => 'paie', 'icone' => 'paie', 'lien' => null, 'voir' => 'personnel.paie'],
                ['cle' => 'avances', 'icone' => 'depenses', 'lien' => null, 'voir' => 'personnel.paie'],
            ]],
            'finance' => ['icone' => 'finance', 'lien' => '/gestion/tresorerie', 'ecrans' => [
                ['cle' => 'comptes', 'icone' => 'finance', 'lien' => '/gestion/tresorerie', 'voir' => 'finance.voir', 'modifier' => 'finance.comptes'],
                ['cle' => 'transferts', 'icone' => 'transferts', 'lien' => '/gestion/tresorerie/transferts', 'voir' => 'finance.voir', 'modifier' => 'finance.operations'],
                ['cle' => 'depenses', 'icone' => 'depenses', 'lien' => '/gestion/depenses', 'voir' => 'finance.voir', 'modifier' => 'finance.operations'],
                ['cle' => 'devises', 'icone' => 'devises', 'lien' => '/gestion/devises', 'voir' => 'finance.voir', 'modifier' => 'finance.taux'],
            ]],
            'rapports' => ['icone' => 'rapports', 'lien' => '/gestion/tableau-de-bord', 'ecrans' => [
                ['cle' => 'tableau', 'icone' => 'rapports', 'lien' => '/gestion/tableau-de-bord', 'voir' => 'rapports.voir'],
                ['cle' => 'rapport_ventes', 'icone' => 'ventes', 'lien' => null, 'voir' => 'rapports.voir'],
                ['cle' => 'rapport_personnel', 'icone' => 'compte', 'lien' => null, 'voir' => 'rapports.voir'],
            ]],
            'carte' => ['icone' => 'carte', 'lien' => '/gestion/catalogue', 'ecrans' => [
                ['cle' => 'produits', 'icone' => 'carte', 'lien' => '/gestion/catalogue', 'voir' => 'carte.voir', 'modifier' => 'carte.modifier'],
                ['cle' => 'categories', 'icone' => 'categories', 'lien' => '/gestion/catalogue/categories', 'voir' => 'carte.voir', 'modifier' => 'carte.modifier'],
                ['cle' => 'prix', 'icone' => 'prix', 'lien' => '/gestion/grilles', 'voir' => 'carte.voir', 'modifier' => 'carte.prix'],
                ['cle' => 'medias', 'icone' => 'medias', 'lien' => '/gestion/mediatheque', 'voir' => 'carte.voir', 'modifier' => 'carte.modifier'],
                ['cle' => 'qr', 'icone' => 'qr', 'lien' => '/gestion/qr', 'voir' => 'carte.voir', 'modifier' => 'carte.modifier'],
            ]],
            'parametres' => ['icone' => 'parametres', 'lien' => null, 'ecrans' => [
                ['cle' => 'etablissements', 'icone' => 'etablissements', 'lien' => '/gestion/etablissements', 'voir' => 'parametres.etablissements'],
                ['cle' => 'utilisateurs', 'icone' => 'personnel', 'lien' => '/gestion/utilisateurs', 'voir' => 'parametres.utilisateurs'],
                ['cle' => 'compte', 'icone' => 'compte', 'lien' => '/gestion/compte', 'voir' => null],
                ['cle' => 'aide', 'icone' => 'aide', 'lien' => '/gestion/aide', 'voir' => null],
                ['cle' => 'design', 'icone' => 'design', 'lien' => '/gestion/design', 'voir' => null],
            ]],
        ];
    }

    /**
     * Écran dont le lien est le plus long préfixe du chemin (« /gestion/tresorerie/transferts »
     * l'emporte sur « /gestion/tresorerie »), avec le code de son module.
     *
     * @return array{module: string, cle: string, voir: ?string, modifier?: string}|null
     */
    public static function ecranDe(string $chemin): ?array
    {
        $trouve = null;
        $longueur = 0;
        foreach (self::tous() as $code => $module) {
            foreach ($module['ecrans'] as $ecran) {
                $lien = $ecran['lien'];
                if ($lien !== null && ($chemin === $lien || str_starts_with($chemin, $lien . '/')) && strlen($lien) > $longueur) {
                    $trouve = $ecran + ['module' => $code];
                    $longueur = strlen($lien);
                }
            }
        }
        return $trouve;
    }

    /** @return array{module: string, ecran: string}|null */
    public static function courant(string $chemin): ?array
    {
        $ecran = self::ecranDe($chemin);
        return $ecran ? ['module' => $ecran['module'], 'ecran' => $ecran['cle']] : null;
    }

    /**
     * Modules que la personne peut voir, avec seulement ses écrans permis ; le lien du module
     * pointe vers son premier écran disponible. Un module à venir reste annoncé (« Bientôt »)
     * si la personne en aura les droits.
     *
     * @return array<string, array>
     */
    public static function pour(int $utilisateurId): array
    {
        $resultat = [];
        foreach (self::tous() as $code => $module) {
            if ($code === 'caisse') {
                if (Acces::a($utilisateurId, $module['voir'])) {
                    $resultat[$code] = $module;
                }
                continue;
            }
            $ecrans = array_values(array_filter($module['ecrans'], static fn (array $e) => Acces::a($utilisateurId, $e['voir'])));
            // Un module fait seulement d'écrans ouverts à tous (Mon compte, Aide) n'est montré qu'à qui a la gestion
            $propres = array_filter($ecrans, static fn (array $e) => $e['voir'] !== null);
            if ($propres === [] && !($code === 'parametres' && Acces::aLaGestion($utilisateurId))) {
                continue;
            }
            $disponibles = array_values(array_filter($ecrans, static fn (array $e) => $e['lien'] !== null));
            $module['ecrans'] = $ecrans;
            $module['lien'] = $disponibles[0]['lien'] ?? null;
            $resultat[$code] = $module;
        }
        return $resultat;
    }
}
