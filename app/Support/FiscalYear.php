<?php

namespace App\Support;

use App\Models\Organization;
use Illuminate\Support\Carbon;

/**
 * L'exercice comptable et budgétaire d'une communauté. Chaque église choisit
 * son mois de début (janvier par défaut) ; sans choix, elle suit son niveau
 * supérieur. Un exercice est désigné par l'année où il commence.
 */
class FiscalYear
{
    public static function startMonth(Organization $organization): int
    {
        foreach (array_reverse($organization->lineageIds() ?: [$organization->id]) as $id) {
            $settings = $id === $organization->id ? $organization->settings : Organization::find($id)?->settings;
            if ($start = (int) ($settings['finance']['fiscal_start'] ?? 0)) {
                return max(1, min(12, $start));
            }
        }

        return 1;
    }

    /** L'exercice qui contient cette date. */
    public static function of(Organization $organization, Carbon|string $date): int
    {
        $date = Carbon::parse($date);

        return $date->month >= self::startMonth($organization) ? $date->year : $date->year - 1;
    }

    public static function current(Organization $organization): int
    {
        return self::of($organization, today());
    }

    /** @return array{0: Carbon, 1: Carbon} premier et dernier jour de l'exercice */
    public static function bounds(Organization $organization, int $year): array
    {
        $from = Carbon::create($year, self::startMonth($organization), 1)->startOfDay();

        return [$from, $from->copy()->addYear()->subDay()];
    }

    /** « 2026 » pour un exercice de janvier à décembre, sinon « 2026-2027 ». */
    public static function label(Organization $organization, int $year): string
    {
        return self::startMonth($organization) === 1 ? (string) $year : $year.'-'.($year + 1);
    }

    /** Les douze mois de l'exercice, dans l'ordre. @return Carbon[] */
    public static function months(Organization $organization, int $year): array
    {
        $from = self::bounds($organization, $year)[0];

        return array_map(fn ($i) => $from->copy()->addMonths($i), range(0, 11));
    }

    /** L'année civile d'un mois (1 à 12) de l'exercice. */
    public static function calendarYear(Organization $organization, int $year, int $month): int
    {
        return $month >= self::startMonth($organization) ? $year : $year + 1;
    }
}
