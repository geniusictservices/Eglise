<?php

namespace App\Services;

use App\Models\ExchangeRate;
use App\Models\Organization;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Taux de change d'une organisation et conversions vers le dollar.
 *
 * Le taux exprime le nombre d'unités de la devise pour 1 USD (ex. 2 850 CDF).
 * Si l'organisation n'a pas saisi de taux, on prend celui de son niveau
 * supérieur le plus proche (la paroisse hérite du taux de sa région).
 */
class ExchangeRateService
{
    public function base(): string
    {
        return config('waumini.base_currency');
    }

    /** Taux applicable à une date donnée, ou null s'il n'en existe aucun. */
    public function rate(Organization $organization, string $currency, ?CarbonInterface $on = null): ?BigDecimal
    {
        if ($currency === $this->base()) {
            return BigDecimal::one();
        }

        $on ??= now();
        $lineage = array_reverse($organization->lineageIds());

        foreach ($lineage as $organizationId) {
            $rate = ExchangeRate::withoutOrganizationScope()
                ->where('organization_id', $organizationId)
                ->where('currency', $currency)
                ->whereDate('effective_on', '<=', $on->toDateString())
                ->orderByDesc('effective_on')
                ->value('rate');

            if ($rate !== null) {
                return BigDecimal::of($rate);
            }
        }

        return null;
    }

    /** Équivalent en dollars d'un montant. */
    public function toBase(Organization $organization, string|int|float $amount, string $currency, ?CarbonInterface $on = null): BigDecimal
    {
        $rate = $this->rate($organization, $currency, $on)
            ?? throw new RuntimeException("Aucun taux du jour pour {$currency}.");

        return BigDecimal::of((string) $amount)->dividedBy($rate, 2, RoundingMode::HalfUp);
    }

    /** Montant en devise pour un montant en dollars. */
    public function fromBase(Organization $organization, string|int|float $usd, string $currency, ?CarbonInterface $on = null): BigDecimal
    {
        $rate = $this->rate($organization, $currency, $on)
            ?? throw new RuntimeException("Aucun taux du jour pour {$currency}.");
        $decimals = config("waumini.currencies.{$currency}.decimals", 2);

        return BigDecimal::of((string) $usd)->multipliedBy($rate)->toScale($decimals, RoundingMode::HalfUp);
    }

    /** Enregistre (ou corrige) le taux du jour. */
    public function setRate(Organization $organization, string $currency, string|float $rate, ?CarbonInterface $on = null): ExchangeRate
    {
        if (BigDecimal::of((string) $rate)->isLessThanOrEqualTo(0)) {
            throw new RuntimeException('Le taux doit être positif.');
        }

        return ExchangeRate::withoutOrganizationScope()->updateOrCreate(
            ['organization_id' => $organization->id, 'currency' => $currency, 'effective_on' => ($on ?? now())->toDateString()],
            ['rate' => (string) $rate, 'created_by' => auth()->id()],
        );
    }
}
