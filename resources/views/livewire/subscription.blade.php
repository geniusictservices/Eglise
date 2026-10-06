<div>
    <x-page-header :title="__('Abonnement')" :description="__('Choisissez l’offre qui correspond à votre communauté. Vous pourrez en changer à tout moment.')" />

    {{-- État actuel --}}
    <section class="card mb-6 flex flex-wrap items-center gap-4 p-5 sm:p-6">
        @php $trialPercent = $daysLeft !== null ? max(0, min(100, (int) round((30 - $daysLeft) / 30 * 100))) : 100; @endphp
        <span class="ring-progress" style="--v: {{ $trialPercent }}"><span>{{ max(0, $daysLeft ?? 0) }} j</span></span>
        <div class="min-w-0 flex-1">
            @if ($root->status === 'trial')
                <h2 class="text-lg">{{ $daysLeft > 0 ? trans_choice('Essai gratuit : :count jour restant|Essai gratuit : :count jours restants', $daysLeft) : __('L’essai gratuit est terminé') }}</h2>
                <p class="text-sm text-sand-700">{{ __('Jusqu’au :date. Ensuite, sans abonnement, vous aurez encore un mois pour régulariser avant le passage en lecture seule.', ['date' => $root->trial_ends_at?->translatedFormat('j F Y')]) }}</p>
            @else
                <h2 class="text-lg">{{ __('Abonnement en cours') }}</h2>
            @endif
            @if (! $organization->isRoot())
                <p class="mt-1 text-sm text-sand-700">{{ __('L’abonnement de :name est géré par :root.', ['name' => $organization->name, 'root' => $root->name]) }}</p>
            @endif
        </div>
    </section>

    {{-- Réglages --}}
    <div class="mb-5 flex flex-wrap items-center gap-3">
        <label for="tier" class="text-sm font-semibold text-ink-800">{{ __('Taille de la communauté') }}</label>
        <select wire:model.live="tier" id="tier" class="input !w-auto">
            @foreach ($tiers as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </select>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" wire:model.live="annual" class="size-5">
            {{ __('Payer à l’année (:n mois offerts)', ['n' => $freeMonths]) }}
        </label>
    </div>

    {{-- Offres --}}
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach ($packs as $key => $pack)
            @php
                $monthly = $pack['prices'][$tier] ?? null;
                $yearly = $monthly ? $monthly * (12 - $freeMonths) : null;
            @endphp
            <article @class(['card relative flex flex-col p-5', 'ring-2 ring-ochre-500' => ! empty($pack['featured'])])>
                @if (! empty($pack['featured']))
                    <span class="badge absolute -top-3 left-5 bg-ochre-500 text-[#2A1B04]">{{ __('Le plus choisi') }}</span>
                @endif
                <h3 class="text-xl">{{ $pack['name'] }}</h3>
                <p class="text-sm italic text-sand-700">{{ __($pack['meaning']) }}</p>
                <p class="mt-3 min-h-[3rem] text-sm text-sand-700">{{ __($pack['for']) }}</p>
                <p class="mt-4">
                    @if ($monthly)
                        @if ($annual)
                            <span class="text-3xl font-semibold text-ink-800 tabular">{{ $yearly }} $</span><span class="text-sm text-sand-700"> / {{ __('an') }}</span>
                            <span class="block text-xs text-leaf-600">{{ __('soit :m $ par mois', ['m' => number_format($yearly / 12, 2, ',', ' ')]) }}</span>
                        @else
                            <span class="text-3xl font-semibold text-ink-800 tabular">{{ $monthly }} $</span><span class="text-sm text-sand-700"> / {{ __('mois') }}</span>
                        @endif
                    @else
                        <span class="text-2xl font-semibold text-ink-800">{{ __('Sur devis') }}</span>
                        <span class="block text-xs text-sand-700">{{ __('dégressif selon le nombre de paroisses') }}</span>
                    @endif
                </p>
                <ul class="mt-4 flex-1 space-y-1.5 text-sm">
                    @foreach ($pack['modules'] as $module)
                        <li class="flex gap-2"><x-icon name="check" class="mt-0.5 size-4 text-leaf-500" /> {{ __($module) }}</li>
                    @endforeach
                </ul>
            </article>
        @endforeach
    </div>

    <p class="mt-4 text-sm text-sand-700">{{ __('Prix indicatifs en dollars américains, par communauté et par mois. Les SMS, s’ils sont utilisés plus tard, seront facturés au réel.') }}</p>

    {{-- Souscrire --}}
    <section class="wax wax-veil wax-veil-strong mt-8 overflow-hidden rounded-[22px] p-6 text-white">
        <h2 class="text-xl text-white">{{ __('Souscrire') }}</h2>
        <p class="mt-1 max-w-2xl text-ink-100">{{ __('Contactez Genius ICT pour choisir votre offre. Le paiement se fait par mobile money ; bientôt, vous pourrez déclarer votre paiement directement ici.') }}</p>
        <div class="mt-4 flex flex-wrap items-center gap-3">
            @if ($whatsapp)
                <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="btn-accent"><x-icon name="share" class="size-4" /> {{ __('Écrire sur WhatsApp') }}</a>
            @endif
            <span class="rounded-xl bg-white/10 px-3 py-2 text-sm select-all">{{ $contact['email'] }}</span>
        </div>
    </section>
</div>
