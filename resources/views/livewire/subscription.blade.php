@php $usd = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',').' $'; @endphp
<div>
    <x-page-header :title="__('Abonnement')" :description="__('Choisissez l’offre qui correspond à votre communauté. Vous pourrez en changer à tout moment.')" />

    {{-- État actuel --}}
    <section class="card mb-6 p-5 sm:p-6">
        <div class="flex flex-wrap items-center gap-4">
            @if ($root->status === 'trial')
                @php $trialPercent = max(0, min(100, (int) round(($trialDays - max(0, $daysLeft ?? 0)) / max(1, $trialDays) * 100))); @endphp
                <span class="ring-progress" style="--v: {{ $trialPercent }}"><span>{{ max(0, $daysLeft ?? 0) }} j</span></span>
            @else
                <span @class(['icon-tile size-14', 'bg-leaf-500 text-white' => $root->status === 'active', 'bg-terra-500 text-white' => $root->status !== 'active'])><x-icon :name="$root->status === 'active' ? 'badge-check' : 'triangle-alert'" class="size-7" /></span>
            @endif
            <div class="min-w-0 flex-1">
                @if ($root->status === 'trial')
                    <h2 class="text-lg">{{ $daysLeft > 0 ? trans_choice('Essai gratuit : :count jour restant|Essai gratuit : :count jours restants', $daysLeft) : __('L’essai gratuit est terminé') }}</h2>
                    <p class="text-sm text-sand-700">{{ __('Jusqu’au :date. Ensuite, sans abonnement, vous aurez encore :n jours pour régulariser avant le passage en lecture seule.', ['date' => $root->trial_ends_at?->translatedFormat('j F Y'), 'n' => $graceDays]) }}</p>
                @elseif ($current)
                    <h2 class="text-lg">{{ __('Offre :plan, jusqu’au :date', ['plan' => $current->plan->name, 'date' => $current->ends_on->translatedFormat('j F Y')]) }}</h2>
                    <p class="text-sm text-sand-700">{{ __(':tier · :cycle · :price par mois, prix garanti jusqu’à la fin de la période.', ['tier' => $tiers[$current->tier] ?? $current->tier, 'cycle' => mb_strtolower(__(\App\Models\Subscription::CYCLES[$current->cycle])), 'price' => $usd($current->monthly_usd)]) }}</p>
                @elseif ($root->status === 'active' && $latest)
                    <h2 class="text-lg">{{ __('Offre :plan, à partir du :date', ['plan' => $latest->plan->name, 'date' => $latest->starts_on->translatedFormat('j F Y')]) }}</h2>
                @elseif ($root->status === 'grace')
                    <h2 class="text-lg">{{ __('Abonnement à renouveler') }}</h2>
                    <p class="text-sm text-sand-700">{{ __('Votre communauté est dans son délai de grâce : renouvelez l’abonnement pour éviter le passage en lecture seule.') }}</p>
                @else
                    <h2 class="text-lg">{{ __('Lecture seule') }}</h2>
                    <p class="text-sm text-sand-700">{{ __('Vos données restent consultables et exportables. Contactez Genius ICT pour réactiver la communauté.') }}</p>
                @endif
                @if (! $organization->isRoot())
                    <p class="mt-1 text-sm text-sand-700">{{ __('L’abonnement de :name est géré par :root.', ['name' => $organization->name, 'root' => $root->name]) }}</p>
                @endif
            </div>
        </div>
        @if ($nextPrice && $latest)
            <p class="mt-4 rounded-xl bg-ochre-50 px-4 py-3 text-sm text-ink-800">
                <x-icon name="info" class="mr-1 inline size-4 text-ochre-600" />
                {{ __('Le tarif de votre offre passera à :new par mois à votre prochain renouvellement, le :date. D’ici là, vous gardez votre prix actuel (:old).', ['new' => $usd($nextPrice), 'date' => $latest->ends_on->copy()->addDay()->translatedFormat('j F Y'), 'old' => $usd($latest->monthly_usd)]) }}
            </p>
        @endif
    </section>

    {{-- Réglages --}}
    <div class="mb-5 flex flex-wrap items-center gap-3">
        <label for="tier" class="text-sm font-semibold text-ink-800">{{ __('Taille de la communauté') }}</label>
        <select wire:model.live="tier" id="tier" class="input !w-auto">
            @foreach ($tiers as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </select>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" wire:model.live="annual" class="size-5">
            {{ trans_choice('Payer à l’année (:count mois offert)|Payer à l’année (:count mois offerts)', $freeMonths) }}
        </label>
    </div>

    {{-- Offres --}}
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach ($plans as $plan)
            @php
                $monthly = $grid[$plan->id][$tier] ?? null;
                $yearly = $monthly !== null ? (float) $monthly * (12 - $freeMonths) : null;
                $isMine = $latest && $latest->plan_id === $plan->id;
                $change = $upcoming->firstWhere('plan_id', $plan->id);
            @endphp
            <article wire:key="plan-{{ $plan->id }}" @class(['card relative flex flex-col p-5', 'ring-2 ring-ochre-500' => $plan->featured && ! $isMine, 'ring-2 ring-leaf-500' => $isMine])>
                @if ($isMine)
                    <span class="badge absolute -top-3 left-5 bg-leaf-500 text-white">{{ __('Votre offre') }}</span>
                @elseif ($plan->featured)
                    <span class="badge absolute -top-3 left-5 bg-ochre-500 text-on-accent">{{ __('Le plus choisi') }}</span>
                @endif
                <h3 class="text-xl">{{ $plan->name }}</h3>
                <p class="text-sm italic text-sand-700">{{ __($plan->meaning) }}</p>
                <p class="mt-3 min-h-[3rem] text-sm text-sand-700">{{ __($plan->description) }}</p>
                <p class="mt-4">
                    @if ($monthly !== null)
                        @if ($annual)
                            <span class="text-3xl font-semibold text-ink-800 tabular">{{ $usd($yearly) }}</span><span class="text-sm text-sand-700"> / {{ __('an') }}</span>
                            <span class="block text-xs text-leaf-600">{{ __('soit :m par mois', ['m' => $usd($yearly / 12)]) }}</span>
                        @else
                            <span class="text-3xl font-semibold text-ink-800 tabular">{{ $usd($monthly) }}</span><span class="text-sm text-sand-700"> / {{ __('mois') }}</span>
                        @endif
                    @else
                        <span class="text-2xl font-semibold text-ink-800">{{ __('Sur devis') }}</span>
                        <span class="block text-xs text-sand-700">{{ __('dégressif selon le nombre de paroisses') }}</span>
                    @endif
                </p>
                @if ($change)
                    <p class="mt-1 text-xs text-ochre-700">{{ __(':price par mois à partir du :date', ['price' => $usd($change->monthly_usd), 'date' => $change->effective_from->translatedFormat('j M Y')]) }}</p>
                @endif
                <ul class="mt-4 flex-1 space-y-1.5 text-sm">
                    @foreach ($plan->modules ?? [] as $module)
                        <li class="flex gap-2"><x-icon name="check" class="mt-0.5 size-4 text-leaf-500" /> {{ __($module) }}</li>
                    @endforeach
                </ul>
            </article>
        @endforeach
    </div>

    <p class="mt-4 text-sm text-sand-700">{{ __('Prix en dollars américains, par communauté et par mois. Un changement de tarif ne s’applique aux communautés abonnées qu’à leur renouvellement.') }}</p>

    @if ($history->isNotEmpty())
        <section class="card mt-8 p-5 sm:p-6">
            <h2 class="mb-3 text-lg">{{ __('Vos paiements') }}</h2>
            <ul class="divide-y divide-sand-100 text-sm">
                @foreach ($history as $s)
                    <li class="flex flex-wrap items-center gap-x-3 py-2.5">
                        <span class="flex-1"><span class="font-semibold text-ink-800">{{ $s->plan->name }}</span> · {{ __('du :from au :to', ['from' => $s->starts_on->translatedFormat('j M Y'), 'to' => $s->ends_on->translatedFormat('j M Y')]) }}</span>
                        <span class="text-sand-700">{{ $s->payment_method }}</span>
                        <span class="font-semibold tabular">{{ $usd($s->amount_usd) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Souscrire --}}
    <section class="wax wax-veil wax-veil-strong mt-8 overflow-hidden rounded-[22px] p-6 text-white">
        <h2 class="text-xl text-white">{{ $latest ? __('Renouveler ou changer d’offre') : __('Souscrire') }}</h2>
        <p class="mt-1 max-w-2xl text-ink-100">{{ __('Payez par mobile money, puis déclarez votre paiement ici : Genius ICT le vérifie et enregistre votre abonnement. Une question ? Écrivez-nous.') }}</p>
        @if ($contact['payment'])<p class="mt-3 rounded-xl bg-white/10 px-3 py-2 font-mono text-sm">{{ $contact['payment'] }}</p>@endif
        @foreach ($declarations as $d)
            <p @class(['mt-3 rounded-xl px-3 py-2 text-sm', 'bg-ochre-100 text-ochre-700' => $d->status === 'pending', 'bg-terra-50 text-terra-700' => $d->status === 'rejected'])>
                {{ $d->plan->name }} · {{ $usd($d->amount) }} · {{ $d->method }} <span class="font-mono">{{ $d->reference }}</span> · {{ $d->status === 'pending' ? __('en cours de vérification') : __('non retrouvé : :r', ['r' => $d->reject_reason]) }}
            </p>
        @endforeach
        <div class="mt-4 flex flex-wrap items-center gap-3">
            @if ($canDeclare)<button type="button" wire:click="openDeclare" class="btn-accent"><x-icon name="smartphone" class="size-4" /> {{ __('Déclarer un paiement') }}</button>@endif
            @if ($whatsapp)
                <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="btn bg-white/15 text-white hover:bg-white/25"><x-icon name="message-circle" class="size-4" /> {{ __('Écrire sur WhatsApp') }}</a>
            @endif
            <span class="rounded-xl bg-white/10 px-3 py-2 text-sm select-all">{{ $contact['email'] }}</span>
        </div>
    </section>

    @if ($canDeclare)
        <x-modal name="declare" :title="__('Déclarer un paiement')">
            <form wire:submit="declare" class="space-y-4">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label for="d-plan" class="label">{{ __('Offre') }}</label><select wire:model.live="payment.plan_id" id="d-plan" class="input"><option value="">{{ __('Choisir…') }}</option>@foreach ($plans->where('quote_only', false) as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select>@error('payment.plan_id') <p class="error">{{ $message }}</p> @enderror</div>
                    <div><label for="d-tier" class="label">{{ __('Taille') }}</label><select wire:model.live="tier" id="d-tier" class="input">@foreach ($tiers as $k => $t)<option value="{{ $k }}">{{ $t }}</option>@endforeach</select></div>
                </div>
                <label class="flex items-center gap-3 text-sm"><input type="checkbox" wire:model.live="annual" class="size-4"> {{ __('Paiement annuel (:n mois offerts)', ['n' => $freeMonths]) }}</label>
                @if ($expected)
                    <p class="rounded-xl bg-ink-50 p-3 text-sm text-ink-800">{{ __('À payer : :m, pour la période du :from au :to.', ['m' => $usd($expected['amount']), 'from' => $expected['start']->translatedFormat('j F Y'), 'to' => $expected['end']->translatedFormat('j F Y')]) }}</p>
                @endif
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label for="d-amount" class="label">{{ __('Montant envoyé (USD)') }}</label><input wire:model="payment.amount" id="d-amount" type="number" step="0.01" min="0" class="input tabular">@error('payment.amount') <p class="error">{{ $message }}</p> @enderror</div>
                    <div><label for="d-method" class="label">{{ __('Envoyé par') }}</label><select wire:model="payment.method" id="d-method" class="input">@foreach (\App\Models\Subscription::PAYMENT_METHODS as $m)<option>{{ $m }}</option>@endforeach</select></div>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label for="d-ref" class="label">{{ __('ID de la transaction') }}</label><input wire:model="payment.reference" id="d-ref" class="input font-mono uppercase">@error('payment.reference') <p class="error">{{ $message }}</p> @enderror</div>
                    <div><label for="d-date" class="label">{{ __('Le') }}</label><input wire:model="payment.paid_on" id="d-date" type="date" class="input">@error('payment.paid_on') <p class="error">{{ $message }}</p> @enderror</div>
                </div>
                <div><label for="d-msg" class="label">{{ __('Message') }} <span class="font-normal text-sand-700">{{ __('(facultatif)') }}</span></label><input wire:model="payment.message" id="d-msg" class="input"></div>
                <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'declare' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Déclarer') }}</button></div>
            </form>
        </x-modal>
    @endif
</div>
