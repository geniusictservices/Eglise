<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Permissions qu'un rôle peut donner, groupées par module (libellés : lang/<langue>/utilisateurs.php, « perm.<code> »).
 * Les rôles enregistrent ces codes dans role_permissions ; le rôle « tout » (propriétaire) les a toutes.
 * Les permissions des modules pas encore construits sont déjà là, pour préparer les rôles.
 */
final class Permissions
{
    /** @return array<string, list<string>> module → codes */
    public static function parModule(): array
    {
        return [
            'caisse' => ['caisse.vendre', 'caisse.encaisser', 'caisse.credit', 'caisse.rembourser', 'caisse.remise', 'caisse.annuler', 'caisse.appareils', 'caisse.minimum'],
            'ventes' => ['ventes.voir', 'ventes.annuler', 'clients.voir', 'clients.modifier', 'dettes.voir', 'dettes.rembourser', 'dettes.gerer', 'ventes.gros'],
            // Salle et cuisine : la caisse (notes, envoi en cuisine) demande seulement « caisse.vendre »
            'salle' => ['salle.voir', 'salle.plan', 'salle.reservations', 'salle.services'],
            'cuisine' => ['cuisine.voir', 'cuisine.ruptures', 'cuisine.parametrer'],
            'achats' => ['achats.voir', 'achats.fournisseurs', 'achats.commander', 'achats.recevoir', 'achats.payer', 'achats.marche'],
            'stock' => ['stock.voir', 'stock.transferer', 'stock.inventorier', 'stock.valider', 'stock.ajuster', 'stock.recettes', 'stock.parametrer'],
            'personnel' => ['personnel.voir', 'personnel.gerer', 'personnel.presence', 'personnel.paie', 'personnel.payer'],
            'finance' => ['finance.voir', 'finance.operations', 'finance.comptes', 'finance.taux'],
            'rapports' => ['rapports.voir', 'rapports.marges'],
            'evenements' => ['evenements.voir', 'evenements.gerer'],
            // Nuit (lounge, nightclub) : formules, minimums, billetterie et entrée, invités et promoteurs, vestiaire
            'nightclub' => ['nuit.voir', 'nuit.parametrer', 'entrees.vendre', 'entrees.controler', 'invites.gerer', 'invites.pointer',
                'promoteurs.gerer', 'promoteur.espace', 'vestiaire.gerer'],
            'cashless' => ['cashless.recharger', 'cashless.gerer'],
            'fidelite' => ['fidelite.parametrer'],
            'carte' => ['carte.voir', 'carte.modifier', 'carte.prix'],
            'parametres' => ['parametres.etablissements', 'parametres.utilisateurs', 'parametres.messagerie'],
        ];
    }

    /** @return list<string> */
    public static function toutes(): array
    {
        return array_merge(...array_values(self::parModule()));
    }

    public static function existe(string $code): bool
    {
        return in_array($code, self::toutes(), true);
    }
}
