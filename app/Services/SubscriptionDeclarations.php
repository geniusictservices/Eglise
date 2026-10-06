<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionDeclaration;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Le paiement de l'abonnement déclaré par la communauté : elle envoie
 * l'argent par mobile money, puis indique l'offre, la période et l'ID de la
 * transaction. Genius ICT vérifie, puis valide (la période est enregistrée)
 * ou rejette avec un motif.
 */
class SubscriptionDeclarations
{
    public function __construct(private Subscriptions $subscriptions, private Pricing $pricing, private Notifier $notifier) {}

    /** Ce que la communauté doit payer pour cette offre, à partir du début de sa prochaine période. */
    public function expected(Organization $root, Plan $plan, string $tier, string $cycle): ?array
    {
        $latest = $this->subscriptions->latest($root);
        $start = $latest && $latest->ends_on->gte(today()) ? $latest->ends_on->copy()->addDay() : today();
        $monthly = $this->pricing->price($plan, $tier, $start);
        if ($monthly === null) {
            return null;
        }
        [$months, $amount] = $this->pricing->amount($monthly, $cycle);

        return ['start' => $start, 'end' => $start->copy()->addMonthsNoOverflow($months)->subDay(), 'monthly' => $monthly, 'amount' => $amount];
    }

    public function declare(Organization $root, Plan $plan, string $tier, string $cycle, array $payment): SubscriptionDeclaration
    {
        abort_unless($root->isRoot(), 422);
        $expected = $this->expected($root, $plan, $tier, $cycle)
            ?? throw new InvalidArgumentException(__('Cette offre est sur devis : contactez Genius ICT pour convenir du prix.'));
        $reference = strtoupper(preg_replace('/\s+/', '', (string) $payment['reference']));
        if (SubscriptionDeclaration::where('reference', $reference)->where('status', '!=', 'rejected')->exists()
            || Subscription::where('payment_reference', $reference)->exists()) {
            throw new InvalidArgumentException(__('Ce paiement (:r) a déjà été déclaré.', ['r' => $reference]));
        }

        $declaration = SubscriptionDeclaration::create([
            'organization_id' => $root->id, 'plan_id' => $plan->id, 'tier' => $tier, 'cycle' => $cycle, 'expected_usd' => $expected['amount'],
            'amount' => $payment['amount'], 'currency' => $payment['currency'] ?? 'USD', 'method' => $payment['method'], 'reference' => $reference,
            'paid_on' => $payment['paid_on'], 'message' => trim((string) ($payment['message'] ?? '')) ?: null, 'declared_by' => auth()->id(),
        ]);
        $this->notifier->send(null, $this->notifier->staffWith('admin.subscriptions'), "subscription-declaration.{$declaration->id}", [
            'title' => __('Paiement d’abonnement à vérifier'),
            'body' => __(':c · :m :cur · :op :r', ['c' => $root->name, 'm' => $declaration->amount, 'cur' => $declaration->currency, 'op' => $declaration->method, 'r' => $reference]),
            'url' => route('admin.communities.show', $root), 'icon' => 'smartphone']);

        return $declaration;
    }

    /** Genius ICT a retrouvé l'argent : la période d'abonnement est enregistrée. */
    public function validate(SubscriptionDeclaration $declaration, User $by): Subscription
    {
        $this->expectPending($declaration);

        return DB::transaction(function () use ($declaration, $by) {
            $declaration->loadMissing(['organization', 'plan']);
            $subscription = $this->subscriptions->record($declaration->organization, $declaration->plan, $declaration->tier, $declaration->cycle, [
                'method' => $declaration->method, 'reference' => $declaration->reference,
                'notes' => __('Déclaré par la communauté le :d', ['d' => $declaration->created_at->translatedFormat('j F Y')]).($declaration->message ? ' · '.$declaration->message : ''),
            ]);
            $declaration->update(['status' => 'validated', 'subscription_id' => $subscription->id, 'reviewed_by' => $by->id, 'reviewed_at' => now()]);
            $this->notifier->settle("subscription-declaration.{$declaration->id}");
            $this->tellCommunity($declaration, __('Paiement reçu, merci !'), __('Votre abonnement court jusqu’au :d.', ['d' => $subscription->ends_on->translatedFormat('j F Y')]));

            return $subscription;
        });
    }

    public function reject(SubscriptionDeclaration $declaration, User $by, string $reason): void
    {
        $this->expectPending($declaration);
        $declaration->update(['status' => 'rejected', 'reject_reason' => $reason, 'reviewed_by' => $by->id, 'reviewed_at' => now()]);
        $this->notifier->settle("subscription-declaration.{$declaration->id}");
        $this->tellCommunity($declaration, __('Paiement d’abonnement non retrouvé'), $reason);
    }

    private function tellCommunity(SubscriptionDeclaration $declaration, string $title, string $body): void
    {
        $root = $declaration->organization;
        $this->notifier->send($root, $this->notifier->withPermission($root, 'organization.settings')->push(User::find($declaration->declared_by))->filter(),
            "subscription-declaration.{$declaration->id}.result", ['title' => $title, 'body' => $body, 'url' => route('subscription'), 'icon' => 'badge-check']);
    }

    private function expectPending(SubscriptionDeclaration $declaration): void
    {
        if ($declaration->status !== 'pending') {
            throw new InvalidArgumentException(__('Cette déclaration a déjà été traitée.'));
        }
    }

    /** Le début de la période qui suivrait, pour l'afficher à la communauté. */
    public function nextStart(Organization $root): Carbon
    {
        $latest = $this->subscriptions->latest($root);

        return $latest && $latest->ends_on->gte(today()) ? $latest->ends_on->copy()->addDay() : today();
    }
}
