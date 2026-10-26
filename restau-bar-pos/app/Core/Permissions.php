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
            'caisse' => ['caisse.vendre', 'caisse.encaisser', 'caisse.credit', 'caisse.rembourser', 'caisse.remise', 'caisse.annuler', 'caisse.appareils'],
            'ventes' => ['ventes.voir', 'ventes.annuler', 'clients.voir', 'clients.modifier', 'dettes.voir', 'dettes.rembourser', 'dettes.gerer'],
            'achats' => ['achats.voir'],
            'stock' => ['stock.voir'],
            'personnel' => ['personnel.voir', 'personnel.paie'],
            'finance' => ['finance.voir', 'finance.operations', 'finance.comptes', 'finance.taux'],
            'rapports' => ['rapports.voir', 'rapports.marges'],
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
