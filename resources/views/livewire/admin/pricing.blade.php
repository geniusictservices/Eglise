@php $usd = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',').' $'; @endphp
<div class="space-y-6">
    <x-page-header :title="__('Offres et tarifs')" :description="__('Prix mensuels en dollars, affichés aux communautés dans l’application (jamais sur le site public).')" />

    <div class="rounded-2xl border border-ochre-300 bg-ochre-50 p-4 text-sm text-ink-800">
        <p class="flex gap-2"><x-icon name="info" class="mt-0.5 size-4 shrink-0 text-ochre-600" />
            <span>{{ __('Un nouveau tarif s’applique à partir de sa date d’effet aux communautés qui ne sont pas encore abonnées. Les :count communautés abonnées gardent leur prix jusqu’à la fin de leur période : le nouveau tarif s’appliquera à leur prochain renouvellement.', ['count' => $subscribers]) }}</span></p>
    </div>

    {{-- Grille --}}
    <section class="card p-5 sm:p-6">
        <h2 class="text-lg">{{ __('Modifier les tarifs') }}</h2>
        <p class="mb-4 text-sm text-sand-700">{{ __('Changez les prix voulus, choisissez la date d’effet, puis enregistrez. Les autres prix restent inchangés.') }}</p>
        <form wire:submit="savePrices">
            <div class="overflow-x-auto">
                <table class="table min-w-[36rem]">
                    <thead><tr><th>{{ __('Offre') }}</th>@foreach ($tierLabels as $label)<th>{{ $label }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($plans as $plan)
                            <tr wire:key="p-{{ $plan->id }}">
                                <td>
                                    <button type="button" wire:click="editPlan({{ $plan->id }})" class="text-left">
                                        <span class="block font-semibold text-ink-700 hover:underline">{{ $plan->name }} <x-icon name="pencil" class="inline size-3.5 text-sand-500" /></span>
                                        <span class="text-xs text-sand-700">{{ $plan->meaning }}@unless ($plan->is_active) · {{ __('masquée') }}@endunless</span>
                                    </button>
                                </td>
                                @foreach (array_keys($tierLabels) as $tier)
                                    <td>
                                        @if ($plan->quote_only)
                                            <span class="text-sm text-sand-700">{{ __('Sur devis') }}</span>
                                        @else
                                            <div class="relative w-28">
                                                <input wire:model="grid.{{ $plan->id }}.{{ $tier }}" type="number" step="0.01" min="0" class="input pr-7 text-right tabular" aria-label="{{ $plan->name }} · {{ $tierLabels[$tier] }}">
                                                <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-sand-500">$</span>
                                            </div>
                                            @if ((string) (float) ($current[$plan->id][$tier] ?? 0) !== (string) ($grid[$plan->id][$tier] ?? ''))
                                                <span class="text-xs text-ochre-700">{{ __('actuel : :p', ['p' => $usd($current[$plan->id][$tier] ?? null)]) }}</span>
                                            @endif
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @error('grid.*.*') <p class="error">{{ $message }}</p> @enderror
            <div class="mt-4 grid gap-4 sm:grid-cols-[12rem_1fr_auto] sm:items-end">
                <div>
                    <label for="effectiveFrom" class="label">{{ __('Date d’effet') }}</label>
                    <input wire:model="effectiveFrom" id="effectiveFrom" type="date" min="{{ today()->toDateString() }}" class="input">
                    @error('effectiveFrom') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="note" class="label">{{ __('Motif (facultatif)') }}</label>
                    <input wire:model="note" id="note" class="input" placeholder="{{ __('Exemple : ajustement au taux du dollar') }}">
                </div>
                <button class="btn-primary"><x-icon name="save" class="size-4" /> {{ __('Enregistrer les tarifs') }}</button>
            </div>
        </form>
    </section>

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="card p-5 sm:p-6">
            <h2 class="mb-3 text-lg">{{ __('Changements annoncés') }}</h2>
            <ul class="divide-y divide-sand-100 text-sm">
                @forelse ($upcoming as $p)
                    <li class="flex items-center gap-3 py-2.5">
                        <span class="flex-1"><span class="font-semibold text-ink-800">{{ $p->plan->name }}</span> · {{ $tierLabels[$p->tier] ?? $p->tier }}
                            <span class="block text-xs text-sand-700">{{ __('à partir du :date', ['date' => $p->effective_from->translatedFormat('j F Y')]) }}@if ($p->note) · {{ $p->note }}@endif</span></span>
                        <span class="font-semibold tabular">{{ $usd($p->monthly_usd) }}</span>
                        <button type="button" wire:click="cancelPrice({{ $p->id }})" wire:confirm="{{ __('Annuler ce changement de tarif ?') }}" class="rounded-lg p-1.5 text-sand-500 hover:bg-terra-50 hover:text-terra-600" aria-label="{{ __('Annuler') }}"><x-icon name="x" class="size-4" /></button>
                    </li>
                @empty
                    <li class="py-2 text-sand-700">{{ __('Aucun changement de tarif à venir.') }}</li>
                @endforelse
            </ul>
        </section>
        <section class="card p-5 sm:p-6">
            <h2 class="mb-3 text-lg">{{ __('Historique des tarifs') }}</h2>
            <ul class="divide-y divide-sand-100 text-sm">
                @foreach ($history as $p)
                    <li class="flex items-center gap-3 py-2">
                        <span class="flex-1"><span class="font-semibold text-ink-800">{{ $p->plan->name }}</span> · {{ $tierLabels[$p->tier] ?? $p->tier }}
                            <span class="block text-xs text-sand-700">{{ __('depuis le :date', ['date' => $p->effective_from->translatedFormat('j M Y')]) }}{{ $p->author ? ' · '.$p->author->name : '' }}@if ($p->note) · {{ $p->note }}@endif</span></span>
                        <span class="font-semibold tabular">{{ $usd($p->monthly_usd) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    <section class="card p-5 sm:p-6">
        <h2 class="text-lg">{{ __('Facturation') }}</h2>
        <form wire:submit="saveBilling" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
            @foreach ($tiers as $key => $label)
                <div><label for="tier-{{ $key }}" class="label">{{ ['small' => __('Petite communauté'), 'medium' => __('Communauté moyenne'), 'large' => __('Grande communauté')][$key] ?? $key }}</label><input wire:model="tiers.{{ $key }}" id="tier-{{ $key }}" class="input"></div>
            @endforeach
            <div><label for="discountMonths" class="label">{{ __('Mois offerts à l’année') }}</label><input wire:model="discountMonths" id="discountMonths" type="number" min="0" max="6" class="input"></div>
            <div class="sm:col-span-2 lg:col-span-4 flex justify-end"><button class="btn-secondary">{{ __('Enregistrer') }}</button></div>
        </form>
    </section>

    <x-modal name="plan" :title="__('Modifier l’offre')">
        <form wire:submit="savePlan" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label for="pl-name" class="label">{{ __('Nom') }}</label><input wire:model="plan.name" id="pl-name" class="input">@error('plan.name') <p class="error">{{ $message }}</p> @enderror</div>
                <div><label for="pl-meaning" class="label">{{ __('Sens du nom') }}</label><input wire:model="plan.meaning" id="pl-meaning" class="input"></div>
            </div>
            <div><label for="pl-desc" class="label">{{ __('Pour qui') }}</label><input wire:model="plan.description" id="pl-desc" class="input"></div>
            <div><label for="pl-mod" class="label">{{ __('Ce qui est inclus (une ligne par élément)') }}</label><textarea wire:model="plan.modules" id="pl-mod" rows="5" class="input"></textarea></div>
            <label class="flex items-center gap-3 text-sm"><input type="checkbox" wire:model="plan.featured" class="size-5"> {{ __('Mettre en avant (« Le plus choisi »)') }}</label>
            <label class="flex items-center gap-3 text-sm"><input type="checkbox" wire:model="plan.quote_only" class="size-5"> {{ __('Sur devis (pas de prix affiché)') }}</label>
            <label class="flex items-center gap-3 text-sm"><input type="checkbox" wire:model="plan.is_active" class="size-5"> {{ __('Proposée aux communautés') }}</label>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'plan' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Enregistrer') }}</button></div>
        </form>
    </x-modal>
</div>
