<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Journée d'exploitation (cahier des charges, section 5) : un service qui passe minuit compte pour la journée
 * où il a commencé. L'heure de fin de journée se règle par établissement (etablissements.fin_journee, 05:00
 * par défaut, 07:00 pour un nightclub) : avec 07:00, une vente du samedi à 2 h appartient au vendredi.
 *
 * À utiliser par les rapports : du() pour une date, bornes() pour une période en SQL (« cree_le >= ? AND
 * cree_le < ? »), sql() pour regrouper par journée dans une requête.
 */
final class Journee
{
    public const DEFAUT = '05:00:00';

    /** Heure de fin de journée de l'établissement, « HH:MM:SS ». */
    public static function fin(?array $etab): string
    {
        $fin = (string) ($etab['fin_journee'] ?? self::DEFAUT);
        return preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $fin) ? substr($fin . ':00', 0, 8) : self::DEFAUT;
    }

    /** Journée d'exploitation (Y-m-d) d'un moment : la veille s'il est avant l'heure de fin de journée. */
    public static function du(\DateTimeInterface|string $moment, ?array $etab): string
    {
        $date = $moment instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($moment) : new \DateTimeImmutable($moment);
        [$h, $m, $s] = array_map('intval', explode(':', self::fin($etab)));
        return $date->modify(sprintf('-%d hours -%d minutes -%d seconds', $h, $m, $s))->format('Y-m-d');
    }

    /** Journée d'exploitation en cours. */
    public static function aujourdhui(?array $etab): string
    {
        return self::du(new \DateTimeImmutable(), $etab);
    }

    /**
     * Début (inclus) et fin (exclue) d'une journée ou d'une période de journées, en « Y-m-d H:i:s » :
     * du vendredi 07:00 au samedi 07:00 pour la journée du vendredi.
     * @return array{0: string, 1: string}
     */
    public static function bornes(string $jour, ?array $etab, ?string $dernierJour = null): array
    {
        $fin = self::fin($etab);
        $debut = new \DateTimeImmutable($jour . ' ' . $fin);
        $arret = (new \DateTimeImmutable(($dernierJour ?? $jour) . ' ' . $fin))->modify('+1 day');
        return [$debut->format('Y-m-d H:i:s'), $arret->format('Y-m-d H:i:s')];
    }

    /**
     * Expression SQL de la journée d'exploitation d'une colonne DATETIME, pour regrouper par journée :
     * « DATE(SUBTIME(v.cree_le, e.fin_journee)) ». $fin : une colonne (« e.fin_journee ») ou une heure fixe.
     */
    public static function sql(string $colonne, string $fin = 'e.fin_journee'): string
    {
        $heure = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $fin) ? "'" . $fin . "'" : $fin;
        return 'DATE(SUBTIME(' . $colonne . ', ' . $heure . '))';
    }
}
