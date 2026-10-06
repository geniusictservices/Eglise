<?php

namespace App\Livewire\Admin\Communities;

use App\Models\Member;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\RoleAssignment;
use App\Models\Subscription;
use App\Models\SubscriptionDeclaration;
use App\Models\SupportTicket;
use App\Services\AuditLogger;
use App\Services\Pricing;
use App\Services\SubscriptionDeclarations;
use App\Services\Subscriptions;
use App\Support\SupportAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Une communauté vue par Genius ICT : abonnement, paiements, essai. */
#[Layout('layouts::admin')]
class Show extends Component
{
    public Organization $organization;

    public array $payment = [];

    public int $trialDays = 15;

    public ?int $rejectingId = null;

    public string $rejectReason = '';

    public function mount(Organization $organization): void
    {
        $this->authorize('admin.communities');
        abort_unless($organization->isRoot(), 404);
        $this->organization = $organization;
        $this->resetPayment();
    }

    private function resetPayment(): void
    {
        $latest = app(Subscriptions::class)->latest($this->organization);
        $this->payment = [
            'plan_id' => (string) ($latest->plan_id ?? app(Pricing::class)->plans()->firstWhere('featured', true)?->id),
            'tier' => $latest->tier ?? 'small',
            'cycle' => $latest->cycle ?? 'monthly',
            'starts_on' => '',
            'monthly' => '',
            'method' => 'M-Pesa',
            'reference' => '',
            'notes' => '',
        ];
    }

    /** Début de la prochaine période : à la suite de la précédente, sinon aujourd'hui. */
    private function nextStart(): Carbon
    {
        if ($this->payment['starts_on']) {
            return Carbon::parse($this->payment['starts_on']);
        }
        $latest = app(Subscriptions::class)->latest($this->organization);

        return $latest && $latest->ends_on->gte(today()) ? $latest->ends_on->copy()->addDay() : today();
    }

    public function recordPayment(Subscriptions $subscriptions, Pricing $pricing): void
    {
        $this->authorize('admin.subscriptions');
        $plan = Plan::find($this->payment['plan_id']);

        $this->validate([
            'payment.plan_id' => ['required', Rule::exists('plans', 'id')],
            'payment.tier' => ['required', Rule::in(array_keys($pricing->tiers()))],
            'payment.cycle' => ['required', Rule::in(array_keys(Subscription::CYCLES))],
            'payment.starts_on' => 'nullable|date',
            'payment.monthly' => [Rule::requiredIf((bool) $plan?->quote_only), 'nullable', 'numeric', 'min:0'],
            'payment.method' => 'nullable|string|max:30',
            'payment.reference' => 'nullable|string|max:100',
            'payment.notes' => 'nullable|string|max:1000',
        ], ['payment.monthly.required' => __('Offre sur devis : indiquez le prix mensuel convenu.')], ['payment.reference' => __('référence')]);

        $subscription = $subscriptions->record($this->organization, $plan, $this->payment['tier'], $this->payment['cycle'], [
            'method' => $this->payment['method'] ?: null, 'reference' => trim($this->payment['reference']) ?: null, 'notes' => trim($this->payment['notes']) ?: null,
        ], $this->payment['starts_on'] ? Carbon::parse($this->payment['starts_on']) : null, $this->payment['monthly'] !== '' ? (string) $this->payment['monthly'] : null);

        $this->organization->refresh();
        $this->resetPayment();
        $this->dispatch('close-modal', name: 'payment');
        $this->dispatch('notify', message: __('Abonnement enregistré jusqu’au :date.', ['date' => $subscription->ends_on->translatedFormat('j F Y')]), type: 'success');
    }

    public function validateDeclaration(SubscriptionDeclarations $declarations, int $id): void
    {
        $this->authorize('admin.subscriptions');
        try {
            $subscription = $declarations->validate(SubscriptionDeclaration::where('organization_id', $this->organization->id)->findOrFail($id), auth()->user());
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');

            return;
        }
        $this->organization->refresh();
        $this->dispatch('notify', message: __('Paiement validé : abonnement jusqu’au :date.', ['date' => $subscription->ends_on->translatedFormat('j F Y')]), type: 'success');
    }

    public function askReject(int $id): void
    {
        $this->authorize('admin.subscriptions');
        $this->rejectingId = SubscriptionDeclaration::where('organization_id', $this->organization->id)->findOrFail($id)->id;
        $this->rejectReason = '';
        $this->dispatch('open-modal', name: 'reject-declaration');
    }

    public function rejectDeclaration(SubscriptionDeclarations $declarations): void
    {
        $this->authorize('admin.subscriptions');
        $this->validate(['rejectReason' => 'required|string|max:255'], attributes: ['rejectReason' => __('motif')]);
        $declarations->reject(SubscriptionDeclaration::where('organization_id', $this->organization->id)->findOrFail($this->rejectingId), auth()->user(), $this->rejectReason);
        $this->dispatch('close-modal', name: 'reject-declaration');
        $this->dispatch('notify', message: __('Déclaration rejetée ; la communauté est prévenue.'), type: 'success');
    }

    /** Entrer dans la communauté avec son accord, en lecture seule. */
    public function openSupport(SupportAccess $support, int $id)
    {
        $this->authorize('admin.support');
        $organization = Organization::query()->subtreeOf($this->organization)->findOrFail($id);
        try {
            $support->start(auth()->user(), $organization);
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');

            return null;
        }

        return redirect()->route('dashboard');
    }

    public function extendTrial(Subscriptions $subscriptions): void
    {
        $this->authorize('admin.subscriptions');
        $this->validate(['trialDays' => 'required|integer|min:1|max:180']);
        $subscriptions->extendTrial($this->organization, $this->trialDays);
        $this->organization->refresh();
        $this->dispatch('notify', message: __('Essai prolongé jusqu’au :date.', ['date' => $this->organization->trial_ends_at->translatedFormat('j F Y')]), type: 'success');
    }

    public function toggleSuspension(Subscriptions $subscriptions): void
    {
        $this->authorize('admin.subscriptions');
        if ($this->organization->status === 'suspended') {
            Organization::query()->subtreeOf($this->organization)->update(['status' => 'trial']);
            $subscriptions->refreshStatus($this->organization);
        } else {
            Organization::query()->subtreeOf($this->organization)->update(['status' => 'suspended']);
        }
        $this->organization->refresh();
        app(AuditLogger::class)->record($this->organization->status === 'suspended' ? 'suspended' : 'reactivated', $this->organization);
    }

    public function render(Pricing $pricing)
    {
        $org = $this->organization;
        $ids = Organization::query()->subtreeOf($org)->pluck('id');
        $plan = Plan::find($this->payment['plan_id']);
        $start = $this->nextStart();
        $monthly = $plan ? ($this->payment['monthly'] !== '' ? (string) $this->payment['monthly'] : $pricing->price($plan, $this->payment['tier'], $start)) : null;

        return view('livewire.admin.communities.show', [
            'levels' => $ids->count() - 1,
            'members' => Member::withoutOrganizationScope()->whereIn('organization_id', $ids)->count(),
            'admins' => RoleAssignment::with(['user', 'role'])->where('organization_id', $org->id)->get()
                ->filter(fn ($a) => $a->role?->key === 'administrateur'),
            'subscriptions' => Subscription::with(['plan', 'recorder'])->where('organization_id', $org->id)->latest('starts_on')->get(),
            'plans' => $pricing->plans(),
            'tiers' => $pricing->tiers(),
            'quote' => $monthly !== null ? ['start' => $start, 'monthly' => $monthly, 'amount' => $pricing->amount($monthly, $this->payment['cycle'])[1]] : null,
            'canBill' => Gate::allows('admin.subscriptions'),
            'declarations' => SubscriptionDeclaration::with(['plan', 'declarer'])->where('organization_id', $org->id)->where('status', 'pending')->latest()->get(),
            'supportGrants' => Organization::query()->subtreeOf($org)->where('support_access_until', '>', now())->orderBy('depth')->get(),
            'canSupport' => Gate::allows('admin.support'),
            'tickets' => SupportTicket::whereIn('organization_id', $ids)->latest('last_activity_at')->limit(5)->get(),
        ])->title($org->name);
    }
}
