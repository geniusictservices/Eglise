<?php

namespace App\Livewire\Admin;

use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Services\AuditLogger;
use App\Services\Pricing as PricingService;
use App\Support\Platform;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Offres et tarifs. Un nouveau tarif prend effet à sa date pour les
 * nouvelles souscriptions ; les abonnés le paient à leur renouvellement.
 */
#[Layout('layouts::admin')]
#[Title('Offres et tarifs')]
class Pricing extends Component
{
    /** Nouvelle grille : [plan_id][tier] => prix. */
    public array $grid = [];

    public string $effectiveFrom = '';

    public string $note = '';

    // Offre en cours de modification
    public ?int $planId = null;

    public array $plan = [];

    // Réglages de facturation
    public array $tiers = [];

    public int $discountMonths = 2;

    public function mount(PricingService $pricing): void
    {
        $this->authorize('admin.pricing');
        $this->fillGrid($pricing);
        $this->tiers = $pricing->tiers();
        $this->discountMonths = (int) Platform::get('annual_discount_months');
    }

    private function fillGrid(PricingService $pricing): void
    {
        $this->grid = collect($pricing->grid())->map(fn ($row) => array_map(fn ($p) => $p === null ? '' : (string) (float) $p, $row))->all();
        $this->effectiveFrom = today()->toDateString();
        $this->note = '';
    }

    public function savePrices(PricingService $pricing): void
    {
        $this->authorize('admin.pricing');
        $this->validate([
            'grid.*.*' => 'nullable|numeric|min:0|max:100000',
            'effectiveFrom' => 'required|date|after_or_equal:today',
            'note' => 'nullable|string|max:255',
        ], ['effectiveFrom.after_or_equal' => __('Un tarif ne peut pas prendre effet dans le passé.')], ['grid.*.*' => __('prix'), 'effectiveFrom' => __('date d’effet')]);

        $from = Carbon::parse($this->effectiveFrom);
        $changes = 0;

        DB::transaction(function () use ($pricing, $from, &$changes) {
            foreach ($pricing->plans()->where('quote_only', false) as $plan) {
                foreach (array_keys($pricing->tiers()) as $tier) {
                    $new = $this->grid[$plan->id][$tier] ?? '';
                    if ($new === '' || (float) $new === (float) $pricing->price($plan, $tier, $from)) {
                        continue;
                    }
                    // Un même jour, le dernier tarif saisi remplace le précédent.
                    PlanPrice::where('plan_id', $plan->id)->where('tier', $tier)->whereDate('effective_from', $from)->delete();
                    PlanPrice::create(['plan_id' => $plan->id, 'tier' => $tier, 'monthly_usd' => $new, 'effective_from' => $from,
                        'note' => trim($this->note) ?: null, 'created_by' => auth()->id()]);
                    $changes++;
                }
            }
        });

        $this->fillGrid($pricing);
        $this->dispatch('notify', message: $changes
            ? trans_choice(':count tarif modifié.|:count tarifs modifiés.', $changes)
            : __('Aucun tarif n’a changé.'), type: $changes ? 'success' : 'error');
    }

    public function cancelPrice(int $id, PricingService $pricing): void
    {
        $this->authorize('admin.pricing');
        $price = PlanPrice::whereDate('effective_from', '>', today())->findOrFail($id);
        $price->delete();
        $this->fillGrid($pricing);
        $this->dispatch('notify', message: __('Changement de tarif annulé.'), type: 'success');
    }

    public function editPlan(int $id): void
    {
        $this->authorize('admin.pricing');
        $plan = Plan::findOrFail($id);
        $this->planId = $plan->id;
        $this->plan = [
            'name' => $plan->name, 'meaning' => (string) $plan->meaning, 'description' => (string) $plan->description,
            'modules' => implode("\n", $plan->modules ?? []), 'featured' => $plan->featured, 'quote_only' => $plan->quote_only, 'is_active' => $plan->is_active,
        ];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'plan');
    }

    public function savePlan(): void
    {
        $this->authorize('admin.pricing');
        $data = $this->validate([
            'plan.name' => 'required|string|max:60',
            'plan.meaning' => 'nullable|string|max:60',
            'plan.description' => 'nullable|string|max:255',
            'plan.modules' => 'nullable|string|max:2000',
            'plan.featured' => 'boolean',
            'plan.quote_only' => 'boolean',
            'plan.is_active' => 'boolean',
        ], attributes: ['plan.name' => __('nom')])['plan'];

        $data['modules'] = collect(preg_split('/\r?\n/', (string) $data['modules']))->map(fn ($l) => trim($l))->filter()->values()->all();
        if ($data['featured']) {
            Plan::whereKeyNot($this->planId)->update(['featured' => false]);
        }
        Plan::findOrFail($this->planId)->update($data);

        $this->dispatch('close-modal', name: 'plan');
        $this->dispatch('notify', message: __('Offre enregistrée.'), type: 'success');
    }

    public function saveBilling(): void
    {
        $this->authorize('admin.pricing');
        $this->validate(['tiers.*' => 'required|string|max:60', 'discountMonths' => 'required|integer|min:0|max:6'],
            attributes: ['tiers.*' => __('taille'), 'discountMonths' => __('mois offerts')]);

        Platform::set('size_tiers', array_intersect_key($this->tiers, config('waumini.size_tiers')));
        Platform::set('annual_discount_months', $this->discountMonths);
        app(AuditLogger::class)->record('platform_settings', null, [], ['size_tiers' => $this->tiers, 'annual_discount_months' => $this->discountMonths],
            __('a modifié les réglages de facturation'));
        $this->dispatch('notify', message: __('Réglages de facturation enregistrés.'), type: 'success');
    }

    public function render(PricingService $pricing)
    {
        return view('livewire.admin.pricing', [
            'plans' => Plan::orderBy('position')->get(),
            'current' => $pricing->grid(),
            'tierLabels' => $pricing->tiers(),
            'upcoming' => $pricing->upcoming(),
            'history' => PlanPrice::with(['plan', 'author'])->whereDate('effective_from', '<=', today())->latest('effective_from')->latest('id')->limit(20)->get(),
            'subscribers' => Subscription::whereDate('ends_on', '>=', today())->distinct('organization_id')->count('organization_id'),
        ]);
    }
}
