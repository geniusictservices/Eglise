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
    /** Toujours inclus dans une formule d'abonnement : sans eux, on ne peut ni se connecter ni gérer son compte. */
    public const TOUJOURS = ['parametres'];

    /** Modules annoncés, pas encore construits, qu'une formule peut déjà inclure (console super admin). */
    public const A_VENIR = ['cuisine', 'nightclub', 'evenements', 'cashless'];

    /**
     * Modules qu'une formule d'abonnement peut inclure ou non : ceux de tous() (sauf TOUJOURS), puis ceux à venir.
     * Seule source de cette liste (console : formules ; instance : Plateforme::moduleInclus()).
     * @return list<string>
     */
    public static function offre(): array
    {
        return array_values(array_unique(array_merge(array_diff(array_keys(self::tous()), self::TOUJOURS), self::A_VENIR)));
    }

    /** @return array<string, array{icone: string, lien: ?string, voir?: string, ecrans: list<array{cle: string, icone: string, lien: ?string, voir: ?string, modifier?: string}>}> */
    public static function tous(): array
    {
        return [
            'caisse' => ['icone' => 'caisse', 'lien' => '/caisse', 'voir' => 'caisse.vendre', 'ecrans' => []],
            'ventes' => ['icone' => 'ventes', 'lien' => null, 'ecrans' => [
                ['cle' => 'tickets', 'icone' => 'ventes', 'lien' => '/gestion/ventes', 'voir' => 'ventes.voir', 'modifier' => 'ventes.annuler'],
                ['cle' => 'clients', 'icone' => 'clients', 'lien' => '/gestion/clients', 'voir' => 'clients.voir', 'modifier' => 'clients.modifier'],
                ['cle' => 'dettes', 'icone' => 'dettes', 'lien' => '/gestion/dettes', 'voir' => 'dettes.voir', 'modifier' => 'dettes.gerer'],
                ['cle' => 'sessions', 'icone' => 'caisse', 'lien' => '/gestion/sessions-caisse', 'voir' => 'ventes.voir', 'modifier' => 'ventes.voir'],
                ['cle' => 'remboursements', 'icone' => 'remboursement', 'lien' => '/gestion/remboursements', 'voir' => 'dettes.voir', 'modifier' => 'dettes.voir'],   // saisie (dettes.rembourser) et annulation (dettes.gerer) vérifiées par l'écran
                // Garanties demandées à la personne connectée : chacun répond pour lui-même
                ['cle' => 'garanties', 'icone' => 'garantie', 'lien' => '/gestion/garanties', 'voir' => null],
            ]],
            // Achats et stock : les droits plus fins (commander, recevoir, payer, transférer…) sont vérifiés
            // par chaque action, pour l'établissement concerné
            'achats' => ['icone' => 'achats', 'lien' => null, 'ecrans' => [
                ['cle' => 'receptions', 'icone' => 'stock', 'lien' => '/gestion/achats/receptions', 'voir' => 'achats.voir'],
                ['cle' => 'commandes', 'icone' => 'achats', 'lien' => '/gestion/achats/commandes', 'voir' => 'achats.voir'],
                ['cle' => 'fournisseurs', 'icone' => 'etablissements', 'lien' => '/gestion/achats/fournisseurs', 'voir' => 'achats.voir'],
                ['cle' => 'dettes_fournisseurs', 'icone' => 'dettes', 'lien' => '/gestion/achats/dettes', 'voir' => 'achats.voir'],
                ['cle' => 'prix_achats', 'icone' => 'prix', 'lien' => '/gestion/achats/prix', 'voir' => 'achats.voir'],
            ]],
            'stock' => ['icone' => 'stock', 'lien' => null, 'ecrans' => [
                ['cle' => 'etat_stock', 'icone' => 'stock', 'lien' => '/gestion/stock', 'voir' => 'stock.voir'],
                ['cle' => 'mouvements_stock', 'icone' => 'journal', 'lien' => '/gestion/stock/mouvements', 'voir' => 'stock.voir'],
                ['cle' => 'transferts_stock', 'icone' => 'transferts', 'lien' => '/gestion/stock/transferts', 'voir' => 'stock.voir'],
                ['cle' => 'inventaires', 'icone' => 'inventaire', 'lien' => '/gestion/stock/inventaires', 'voir' => 'stock.voir'],
                ['cle' => 'pertes', 'icone' => 'perte', 'lien' => '/gestion/stock/pertes', 'voir' => 'stock.voir'],
                ['cle' => 'recettes', 'icone' => 'recette', 'lien' => '/gestion/stock/recettes', 'voir' => 'stock.voir'],
                ['cle' => 'emballages', 'icone' => 'emballage', 'lien' => '/gestion/stock/emballages', 'voir' => 'stock.voir'],
                ['cle' => 'depots', 'icone' => 'lieux', 'lien' => '/gestion/stock/lieux', 'voir' => 'stock.voir'],
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
                ['cle' => 'rapport_ventes', 'icone' => 'ventes', 'lien' => '/gestion/rapports/ventes', 'voir' => 'rapports.voir'],
                ['cle' => 'rapport_creances', 'icone' => 'dettes', 'lien' => '/gestion/rapports/creances', 'voir' => 'rapports.voir'],
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
                ['cle' => 'messagerie', 'icone' => 'messagerie', 'lien' => '/gestion/messagerie', 'voir' => 'parametres.messagerie'],
                // Accord d'assistance donné à la plateforme (montré seulement quand la console existe)
                ['cle' => 'assistance', 'icone' => 'aide', 'lien' => '/gestion/assistance', 'voir' => 'parametres.utilisateurs'],
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
            // Module que la formule d'abonnement n'inclut pas : masqué (console super admin)
            if (!Plateforme::moduleInclus($code)) {
                continue;
            }
            if ($code === 'caisse') {
                if (Acces::a($utilisateurId, $module['voir'])) {
                    $resultat[$code] = $module;
                }
                continue;
            }
            $ecrans = array_values(array_filter($module['ecrans'], static fn (array $e) => Acces::a($utilisateurId, $e['voir'])
                && ($e['cle'] !== 'assistance' || Plateforme::active())));
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
