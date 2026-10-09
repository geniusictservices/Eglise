<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Payee;
use App\Models\PayItem;
use App\Models\PaySchedule;
use Illuminate\Support\Collection;

/** La paie : rythmes, éléments et calcul du bulletin d'une personne. */
class Payroll
{
    /** Les rythmes de l'église ; « Tous les mois » est créé au premier usage. */
    public function schedules(Organization $organization): Collection
    {
        $query = fn () => PaySchedule::withoutOrganizationScope()->where('organization_id', $organization->id);
        if (! $query()->exists()) {
            PaySchedule::create(['organization_id' => $organization->id, 'name' => 'Mensuel', 'unit' => 'month', 'every' => 1]);
        }

        return $query()->orderByDesc('is_active')->orderBy('id')->get();
    }

    /**
     * Les éléments qui s'appliquent à une personne, avec leur valeur.
     *
     * @return Collection<int, array{item: PayItem, value: float}>
     */
    public function itemsFor(Payee $payee): Collection
    {
        $own = $payee->items()->get()->keyBy('pay_item_id');

        return PayItem::withoutOrganizationScope()->where('organization_id', $payee->organization_id)->where('is_active', true)->orderBy('kind')->orderBy('position')->get()
            ->filter(fn (PayItem $item) => $item->applies_to_all ? ! ($own[$item->id]->is_excluded ?? false) : isset($own[$item->id]) && ! $own[$item->id]->is_excluded)
            ->map(fn (PayItem $item) => ['item' => $item, 'value' => (float) ($own[$item->id]->value ?? $item->default_value)])
            ->values();
    }

    /**
     * Le calcul d'un bulletin : base (× prestations), gains, brut, retenues, net.
     * Les ajustements ponctuels s'ajoutent ; les avances sont retenues en dernier,
     * sans jamais rendre le net négatif.
     *
     * @param  array<int, array{label: string, kind: string, amount: float|string}>  $adjustments
     * @param  array<int, array{id: int, label: string, amount: float}>  $advances  retenues d'avances prévues
     */
    public function compute(Payee $payee, float $quantity = 1, array $adjustments = [], array $advances = []): array
    {
        $round = fn (float $v) => round($v, (int) config("waumini.currencies.{$payee->currency}.decimals", 2));
        $base = $round((float) $payee->base_amount * ($payee->schedule?->isPerService() ? $quantity : 1));
        $lines = [];
        $items = $this->itemsFor($payee);

        $earnings = 0.0;
        foreach ($items->where('item.kind', 'earning') as ['item' => $item, 'value' => $value]) {
            $amount = $round($item->calculation === 'fixed' ? $value : $base * $value / 100);
            $lines[] = ['label' => $item->name, 'kind' => 'earning', 'amount' => $amount, 'statutory' => false];
            $earnings += $amount;
        }
        foreach ($adjustments as $a) {
            if ($a['kind'] === 'earning' && (float) $a['amount'] > 0) {
                $lines[] = ['label' => $a['label'], 'kind' => 'earning', 'amount' => $round((float) $a['amount']), 'statutory' => false];
                $earnings += $round((float) $a['amount']);
            }
        }
        $gross = $round($base + $earnings);

        $deductions = 0.0;
        foreach ($items->where('item.kind', 'deduction') as ['item' => $item, 'value' => $value]) {
            $amount = $round(match ($item->calculation) {
                'fixed' => $value,
                'percent_base' => $base * $value / 100,
                default => $gross * $value / 100,
            });
            $lines[] = ['label' => $item->name, 'kind' => 'deduction', 'amount' => $amount, 'statutory' => $item->is_statutory];
            $deductions += $amount;
        }
        foreach ($adjustments as $a) {
            if ($a['kind'] === 'deduction' && (float) $a['amount'] > 0) {
                $lines[] = ['label' => $a['label'], 'kind' => 'deduction', 'amount' => $round((float) $a['amount']), 'statutory' => false];
                $deductions += $round((float) $a['amount']);
            }
        }

        // Les avances : retenues sur ce qui reste.
        $available = max(0, $gross - $deductions);
        $advanceLines = [];
        foreach ($advances as $a) {
            $amount = $round(min($a['amount'], $available));
            if ($amount > 0) {
                $advanceLines[] = ['advance_id' => $a['id'], 'label' => $a['label'], 'amount' => $amount];
                $available -= $amount;
            }
        }
        $advanceTotal = $round(array_sum(array_column($advanceLines, 'amount')));

        return [
            'base' => $base, 'quantity' => $quantity, 'lines' => $lines, 'advances' => $advanceLines,
            'gross' => $gross, 'deductions' => $round($deductions), 'advance_total' => $advanceTotal,
            'net' => $round(max(0, $gross - $deductions - $advanceTotal)),
        ];
    }
}
