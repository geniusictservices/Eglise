<?php

namespace App\Livewire;

use App\Models\PlanPrice;
use App\Models\Subscription as SubscriptionPeriod;
use App\Services\Pricing;
use App\Services\Subscriptions;
use App\Support\Platform;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * État de l'abonnement et offres. Les prix ne sont montrés qu'ici, dans
 * l'application (jamais sur le site public). Ils sont fixés par Genius ICT.
 */
#[Title('Abonnement')]
class Subscription extends Component
{
    public string $tier = 'small';

    public bool $annual = false;

    public function mount(Subscriptions $subscriptions): void
    {
        $this->authorize('organization.settings');
        $latest = $subscriptions->latest(current_organization()->root());
        if ($latest) {
            $this->tier = $latest->tier;
            $this->annual = $latest->cycle === 'annual';
        }
    }

    public function render(Pricing $pricing, Subscriptions $subscriptions)
    {
        $organization = current_organization();
        $root = $organization->root();
        $current = $subscriptions->current($root);
        $latest = $subscriptions->latest($root);

        // Tarif annoncé pour l'offre de la communauté : il s'appliquera au renouvellement.
        $nextPrice = $latest ? PlanPrice::where('plan_id', $latest->plan_id)->where('tier', $latest->tier)
            ->whereDate('effective_from', '<=', $latest->ends_on->copy()->addDay())
            ->orderByDesc('effective_from')->orderByDesc('id')->value('monthly_usd') : null;

        return view('livewire.subscription', [
            'organization' => $organization,
            'root' => $root,
            'current' => $current,
            'latest' => $latest,
            'nextPrice' => $nextPrice !== null && $latest && (float) $nextPrice !== (float) $latest->monthly_usd ? $nextPrice : null,
            'daysLeft' => $root->trial_ends_at ? (int) now()->diffInDays($root->trial_ends_at, false) : null,
            'graceDays' => (int) Platform::get('grace_days'),
            'trialDays' => (int) Platform::get('trial_days'),
            'plans' => $pricing->plans(),
            'grid' => $pricing->grid(),
            'upcoming' => $pricing->upcoming()->where('tier', $this->tier),
            'tiers' => $pricing->tiers(),
            'freeMonths' => (int) Platform::get('annual_discount_months'),
            'contact' => Platform::contact(),
            'history' => SubscriptionPeriod::with('plan')->where('organization_id', $root->id)->latest('starts_on')->get(),
            'whatsapp' => Platform::whatsapp(__('Bonjour Genius ICT, je souhaite souscrire à Waumini pour :name.', ['name' => $root->name])),
        ]);
    }
}
