<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlanPrice;
use App\Support\Platform;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Offres et tarifs. Chaque tarif a une date d'effet : une communauté qui
 * souscrit paie le tarif en vigueur ce jour-là, et ce prix reste figé
 * jusqu'à la fin de sa période ; au renouvellement, le nouveau tarif s'applique.
 */
class Pricing
{
    /** Offres actives, créées depuis config/waumini.php au premier usage. */
    public function plans(): Collection
    {
        $this->ensureCatalogue();

        return Plan::where('is_active', true)->orderBy('position')->get();
    }

    public function ensureCatalogue(): void
    {
        if (Plan::exists()) {
            return;
        }

        DB::transaction(function () {
            $position = 0;
            foreach (config('waumini.packs') as $key => $pack) {
                $plan = Plan::create([
                    'key' => $key, 'name' => $pack['name'], 'meaning' => $pack['meaning'], 'description' => $pack['for'],
                    'modules' => $pack['modules'], 'featured' => $pack['featured'] ?? false,
                    'quote_only' => $pack['prices'] === null, 'position' => $position++,
                ]);
                foreach ($pack['prices'] ?? [] as $tier => $price) {
                    PlanPrice::create(['plan_id' => $plan->id, 'tier' => $tier, 'monthly_usd' => $price, 'effective_from' => '2026-01-01', 'note' => 'Tarif de lancement']);
                }
            }
        });
    }

    /** Tarif mensuel en vigueur à une date, ou null (sur devis, ou pas de tarif). */
    public function price(Plan $plan, string $tier, ?Carbon $on = null): ?string
    {
        if ($plan->quote_only) {
            return null;
        }

        return PlanPrice::where('plan_id', $plan->id)->where('tier', $tier)
            ->whereDate('effective_from', '<=', ($on ?? today())->toDateString())
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->value('monthly_usd');
    }

    /** Grille du jour : [plan_id][tier] => prix. */
    public function grid(?Carbon $on = null): array
    {
        $grid = [];
        foreach ($this->plans() as $plan) {
            foreach (array_keys($this->tiers()) as $tier) {
                $grid[$plan->id][$tier] = $this->price($plan, $tier, $on);
            }
        }

        return $grid;
    }

    /** Changements de tarif annoncés (date d'effet à venir). */
    public function upcoming(): Collection
    {
        return PlanPrice::with('plan')->whereDate('effective_from', '>', today()->toDateString())
            ->orderBy('effective_from')->get();
    }

    /** Montant d'une période : l'année se paie (12 − mois offerts) mois. */
    public function amount(string $monthly, string $cycle): array
    {
        $months = $cycle === 'annual' ? 12 : 1;
        $billed = $cycle === 'annual' ? 12 - (int) Platform::get('annual_discount_months') : 1;

        return [$months, number_format((float) $monthly * $billed, 2, '.', '')];
    }

    /** @return array<string, string> taille => libellé */
    public function tiers(): array
    {
        return Platform::get('size_tiers');
    }
}
