<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Platform;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Abonnements des communautés et état qui en découle (essai, actif, délai de grâce, lecture seule). */
class Subscriptions
{
    public function __construct(private Pricing $pricing) {}

    public function current(Organization $root): ?Subscription
    {
        return Subscription::with('plan')->where('organization_id', $root->id)
            ->whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today())
            ->latest('ends_on')->first();
    }

    public function latest(Organization $root): ?Subscription
    {
        return Subscription::with('plan')->where('organization_id', $root->id)->latest('ends_on')->latest('id')->first();
    }

    /**
     * Enregistre une période payée. Le prix est celui en vigueur au début
     * de la période ; il ne change plus ensuite. Sans date, la période suit
     * la précédente (renouvellement) ou commence aujourd'hui.
     */
    public function record(Organization $root, Plan $plan, string $tier, string $cycle, array $payment = [], ?Carbon $startsOn = null, ?string $monthlyOverride = null): Subscription
    {
        abort_unless($root->isRoot(), 422);

        $previous = $this->latest($root);
        $startsOn ??= $previous && $previous->ends_on->gte(today()) ? $previous->ends_on->copy()->addDay() : today();
        $monthly = $monthlyOverride ?? $this->pricing->price($plan, $tier, $startsOn);

        if ($monthly === null) {
            throw new InvalidArgumentException(__('Cette offre est sur devis : indiquez le prix convenu.'));
        }

        [$months, $amount] = $this->pricing->amount($monthly, $cycle);

        return DB::transaction(function () use ($root, $plan, $tier, $cycle, $payment, $startsOn, $monthly, $months, $amount) {
            $subscription = Subscription::create([
                'organization_id' => $root->id, 'plan_id' => $plan->id, 'tier' => $tier, 'cycle' => $cycle,
                'months' => $months, 'monthly_usd' => $monthly, 'amount_usd' => $amount,
                'starts_on' => $startsOn, 'ends_on' => $startsOn->copy()->addMonthsNoOverflow($months)->subDay(),
                'payment_method' => $payment['method'] ?? null, 'payment_reference' => $payment['reference'] ?? null,
                'notes' => $payment['notes'] ?? null, 'recorded_by' => auth()->id(),
            ]);
            $this->refreshStatus($root);

            return $subscription;
        });
    }

    /** Prolonge l'essai gratuit d'une communauté. */
    public function extendTrial(Organization $root, int $days): void
    {
        $root->update(['trial_ends_at' => max(now(), $root->trial_ends_at ?? now())->copy()->addDays($days)]);
        $this->refreshStatus($root);
    }

    /**
     * Recalcule l'état d'une communauté et de ses niveaux :
     * actif pendant un abonnement, essai, puis délai de grâce, puis lecture seule.
     */
    public function refreshStatus(Organization $root): string
    {
        $root->refresh();
        if ($root->status === 'suspended') {
            return 'suspended';
        }

        $grace = (int) Platform::get('grace_days');
        $latest = $this->latest($root);
        $paidUntil = $latest?->ends_on;
        $trialUntil = $root->trial_ends_at;

        // La dernière échéance : fin de la période payée ou de l'essai (un essai prolongé par Genius ICT
        // après un abonnement expiré compte aussi).
        $lastEnd = collect([$paidUntil, $trialUntil])->filter()->max();

        $status = match (true) {
            $paidUntil && $paidUntil->gte(today()) && $latest->starts_on->lte(today()) => 'active',
            $paidUntil && $paidUntil->gte(today()) => 'active', // période payée d'avance
            $trialUntil && $trialUntil->gte(now()) => 'trial',
            $lastEnd && $lastEnd->copy()->addDays($grace)->endOfDay()->gte(now()) => 'grace', // le dernier jour de grâce compte en entier
            default => 'read_only',
        };

        Organization::query()->subtreeOf($root)->where('status', '!=', 'suspended')->update(['status' => $status]);

        return $status;
    }
}
