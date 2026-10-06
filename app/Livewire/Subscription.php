<?php

namespace App\Livewire;

use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription as SubscriptionPeriod;
use App\Models\SubscriptionDeclaration;
use App\Services\Pricing;
use App\Services\SubscriptionDeclarations;
use App\Services\Subscriptions;
use App\Support\Platform;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
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

    /** Le paiement que la communauté déclare. */
    public array $payment = [];

    public function mount(Subscriptions $subscriptions): void
    {
        $this->authorize('organization.settings');
        $latest = $subscriptions->latest(current_organization()->root());
        if ($latest) {
            $this->tier = $latest->tier;
            $this->annual = $latest->cycle === 'annual';
        }
    }

    public function openDeclare(Subscriptions $subscriptions, Pricing $pricing): void
    {
        $this->authorizeDeclare();
        $latest = $subscriptions->latest(current_organization()->root());
        $this->payment = ['plan_id' => (string) ($latest->plan_id ?? $pricing->plans()->where('quote_only', false)->firstWhere('featured', true)?->id ?? ''),
            'amount' => '', 'method' => SubscriptionPeriod::PAYMENT_METHODS[0], 'reference' => '', 'paid_on' => today()->toDateString(), 'message' => ''];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'declare');
    }

    public function declare(SubscriptionDeclarations $declarations, Pricing $pricing): void
    {
        $this->authorizeDeclare();
        $this->validate([
            'payment.plan_id' => ['required', Rule::exists('plans', 'id')->where('is_active', true)],
            'tier' => ['required', Rule::in(array_keys($pricing->tiers()))],
            'payment.amount' => 'required|numeric|min:0.01', 'payment.method' => ['required', Rule::in(SubscriptionPeriod::PAYMENT_METHODS)],
            'payment.reference' => 'required|string|max:100', 'payment.paid_on' => 'required|date|before_or_equal:today|after:-90 days',
            'payment.message' => 'nullable|string|max:255',
        ], attributes: ['payment.plan_id' => __('offre'), 'payment.amount' => __('montant'), 'payment.reference' => __('ID de la transaction'), 'payment.paid_on' => __('date')]);
        try {
            $declarations->declare(current_organization()->root(), Plan::findOrFail($this->payment['plan_id']), $this->tier, $this->annual ? 'annual' : 'monthly', $this->payment + ['currency' => 'USD']);
        } catch (InvalidArgumentException $e) {
            $this->addError('payment.reference', $e->getMessage());

            return;
        }
        $this->dispatch('close-modal', name: 'declare');
        $this->dispatch('notify', message: __('Paiement déclaré : Genius ICT le vérifie et enregistre votre abonnement.'), type: 'success');
    }

    private function authorizeDeclare(): void
    {
        abort_unless(Gate::allows('organization.settings', current_organization()->root()), 403);
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
            'canDeclare' => Gate::allows('organization.settings', $root),
            'declarations' => SubscriptionDeclaration::with('plan')->where('organization_id', $root->id)
                ->where(fn ($q) => $q->where('status', 'pending')->orWhere(fn ($q) => $q->where('status', 'rejected')->where('reviewed_at', '>=', now()->subDays(30))))->latest()->get(),
            'expected' => ($plan = Plan::find($this->payment['plan_id'] ?? null)) ? app(SubscriptionDeclarations::class)->expected($root, $plan, $this->tier, $this->annual ? 'annual' : 'monthly') : null,
            'whatsapp' => Platform::whatsapp(__('Bonjour Genius ICT, je souhaite souscrire à Waumini pour :name.', ['name' => $root->name])),
        ]);
    }
}
