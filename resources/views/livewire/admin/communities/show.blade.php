@php $usd = fn ($v) => number_format((float) $v, 2, ',', ' ').' $'; @endphp
<div>
    <a href="{{ route('admin.communities') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Communautés') }}</a>

    <section class="wax wax-veil wax-veil-strong mb-5 overflow-hidden rounded-[22px] p-5 text-white sm:p-6">
        <div class="flex flex-wrap items-center gap-4">
            <span class="grid size-14 shrink-0 place-items-center rounded-2xl bg-ochre-500 text-lg font-semibold text-on-accent">{{ $organization->initials() }}</span>
            <div class="min-w-0 flex-1">
                <p class="text-sm text-ochre-300">{{ $organization->level_label }}@if ($organization->city) · {{ $organization->city }}@endif</p>
                <h1 class="text-2xl font-semibold text-white">{{ $organization->name }}</h1>
                <x-org-status :status="$organization->status" class="mt-1 !bg-white !text-ink-800" />
            </div>
            @if ($canBill)
                <div class="flex w-full flex-wrap gap-2 sm:w-auto">
                    <button type="button" @click="$dispatch('open-modal', { name: 'payment' })" class="btn-accent !min-h-0 !py-2"><x-icon name="banknote" class="size-4" /> {{ __('Enregistrer un paiement') }}</button>
                    <button type="button" wire:click="toggleSuspension" wire:confirm="{{ $organization->status === 'suspended' ? __('Réactiver cette communauté ?') : __('Suspendre cette communauté ? Elle passera en lecture seule.') }}" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25">
                        {{ $organization->status === 'suspended' ? __('Réactiver') : __('Suspendre') }}
                    </button>
                </div>
            @endif
        </div>
    </section>

    @if ($declarations->isNotEmpty())
        <section class="mb-5 rounded-[18px] border border-ochre-300 bg-ochre-50 p-5 sm:p-6">
            <h2 class="mb-3 flex items-center gap-2 text-lg"><x-icon name="smartphone" class="size-5 text-ochre-600" /> {{ __('Paiements déclarés à vérifier') }}</h2>
            <ul class="space-y-3">
                @foreach ($declarations as $d)
                    <li class="flex flex-wrap items-center gap-3 rounded-2xl bg-white p-4">
                        <div class="min-w-0 flex-1 basis-64 text-sm">
                            <p class="font-semibold text-ink-800">{{ $d->plan->name }} · {{ $tiers[$d->tier] ?? $d->tier }} · {{ __(\App\Models\Subscription::CYCLES[$d->cycle]) }}</p>
                            <p class="text-sand-700">{{ __(':m envoyés par :op le :d', ['m' => $usd($d->amount), 'op' => $d->method, 'd' => $d->paid_on->translatedFormat('j M Y')]) }} · <span class="font-mono text-ink-800">{{ $d->reference }}</span></p>
                            <p @class(['text-xs', 'text-terra-700 font-semibold' => (float) $d->amount < (float) $d->expected_usd, 'text-sand-700' => (float) $d->amount >= (float) $d->expected_usd])>{{ __('Attendu : :e', ['e' => $usd($d->expected_usd)]) }}@if ($d->declarer) · {{ __('déclaré par :n', ['n' => $d->declarer->name]) }}@endif @if ($d->message) · « {{ $d->message }} »@endif</p>
                        </div>
                        @if ($canBill)
                            <div class="flex gap-2">
                                <button type="button" wire:click="validateDeclaration({{ $d->id }})" wire:confirm="{{ __('L’argent est bien arrivé avec cet ID ? L’abonnement sera enregistré.') }}" class="btn-primary !min-h-0 !py-2 text-sm">{{ __('Valider') }}</button>
                                <button type="button" wire:click="askReject({{ $d->id }})" class="btn-ghost !min-h-0 !py-2 text-sm text-terra-700">{{ __('Rejeter') }}</button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="grid gap-5 lg:grid-cols-[1.4fr_1fr]">
        <section class="card p-5 sm:p-6">
            <h2 class="mb-3 text-lg">{{ __('Abonnements et paiements') }}</h2>
            <ul class="divide-y divide-sand-100">
                @forelse ($subscriptions as $s)
                    <li class="py-3 text-sm">
                        <div class="flex flex-wrap items-baseline gap-x-3">
                            <span class="font-semibold text-ink-800">{{ $s->plan->name }} · {{ __(\App\Models\Subscription::CYCLES[$s->cycle]) }}</span>
                            @if ($s->isCurrent())<span class="badge bg-leaf-50 text-leaf-600">{{ __('En cours') }}</span>@endif
                            <span class="ml-auto font-semibold tabular text-ink-800">{{ $usd($s->amount_usd) }}</span>
                        </div>
                        <p class="text-sand-700">{{ __('Du :from au :to', ['from' => $s->starts_on->translatedFormat('j M Y'), 'to' => $s->ends_on->translatedFormat('j M Y')]) }} · {{ $tiers[$s->tier] ?? $s->tier }} · {{ __(':p par mois', ['p' => $usd($s->monthly_usd)]) }}</p>
                        <p class="text-xs text-sand-700">{{ collect([$s->payment_method, $s->payment_reference ? __('réf. :r', ['r' => $s->payment_reference]) : null, $s->recorder ? __('enregistré par :n', ['n' => $s->recorder->name]) : null])->filter()->implode(' · ') }}</p>
                    </li>
                @empty
                    <li class="py-2 text-sm text-sand-700">{{ __('Aucun paiement enregistré.') }}</li>
                @endforelse
            </ul>
        </section>

        <div class="space-y-5">
            <section class="card p-5 sm:p-6">
                <h2 class="mb-3 text-lg">{{ __('La communauté') }}</h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-sand-700">{{ __('Inscrite le') }}</dt><dd class="font-semibold">{{ $organization->created_at->translatedFormat('j F Y') }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-sand-700">{{ __('Fin de l’essai') }}</dt><dd class="font-semibold">{{ $organization->trial_ends_at?->translatedFormat('j F Y') ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-sand-700">{{ __('Niveaux') }}</dt><dd class="font-semibold">{{ $levels }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-sand-700">{{ __('Membres inscrits') }}</dt><dd class="font-semibold">{{ number_format($members, 0, ',', ' ') }}</dd></div>
                    @if ($organization->phone)<div class="flex justify-between gap-3"><dt class="text-sand-700">{{ __('Téléphone') }}</dt><dd class="font-semibold tabular">{{ \App\Support\Phone::format($organization->phone) }}</dd></div>@endif
                </dl>
                <h3 class="mb-2 mt-5 text-sm font-semibold text-ink-700">{{ __('Administrateurs') }}</h3>
                <ul class="space-y-1.5 text-sm">
                    @foreach ($admins as $a)
                        <li class="flex items-center gap-2">
                            <span class="flex-1">{{ $a->user->name }} <span class="tabular text-sand-700">{{ $a->user->formattedPhone() }}</span></span>
                            <a href="https://wa.me/{{ ltrim($a->user->phone, '+') }}" target="_blank" rel="noopener" class="font-semibold text-leaf-600 hover:underline">WhatsApp</a>
                        </li>
                    @endforeach
                </ul>
            </section>
            <section class="card p-5 sm:p-6">
                <h2 class="mb-1 text-lg">{{ __('Accès du support') }}</h2>
                @forelse ($supportGrants as $g)
                    <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                        <span class="min-w-0 flex-1">{{ __(':n, jusqu’au :d', ['n' => $g->name, 'd' => $g->support_access_until->translatedFormat('j M à H:i')]) }}</span>
                        @if ($canSupport)<button type="button" wire:click="openSupport({{ $g->id }})" class="btn-secondary !min-h-0 !py-1.5 text-sm"><x-icon name="eye" class="size-4" /> {{ __('Ouvrir') }}</button>@endif
                    </div>
                @empty
                    <p class="text-sm text-sand-700">{{ __('La communauté n’a pas autorisé l’accès du support. Elle peut l’ouvrir dans Paramètres › Support.') }}</p>
                @endforelse
                @if ($tickets->isNotEmpty())
                    <h3 class="mb-2 mt-5 text-sm font-semibold text-ink-700">{{ __('Tickets') }}</h3>
                    <ul class="space-y-1.5 text-sm">
                        @foreach ($tickets as $t)
                            <li class="flex gap-2"><a href="{{ route('admin.tickets.show', $t) }}" class="min-w-0 flex-1 truncate font-semibold text-ink-700 hover:underline">{{ $t->number }} · {{ $t->subject }}</a><span class="text-sand-700">{{ __(\App\Models\SupportTicket::STATUSES[$t->status]) }}</span></li>
                        @endforeach
                    </ul>
                @endif
            </section>
            @if ($canBill && in_array($organization->status, ['trial', 'grace', 'read_only'], true))
                <section class="card p-5 sm:p-6">
                    <h2 class="mb-3 text-lg">{{ __('Prolonger l’essai') }}</h2>
                    <form wire:submit="extendTrial" class="flex items-end gap-2">
                        <div class="flex-1"><label for="trialDays" class="label">{{ __('Jours supplémentaires') }}</label><input wire:model="trialDays" id="trialDays" type="number" min="1" max="180" class="input"></div>
                        <button class="btn-secondary">{{ __('Prolonger') }}</button>
                    </form>
                </section>
            @endif
        </div>
    </div>

    @if ($canBill)
        <x-modal name="payment" :title="__('Enregistrer un paiement')" max-width="max-w-xl">
            <form wire:submit="recordPayment" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="p-plan" class="label">{{ __('Offre') }}</label>
                        <select wire:model.live="payment.plan_id" id="p-plan" class="input">
                            @foreach ($plans as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label for="p-tier" class="label">{{ __('Taille') }}</label>
                        <select wire:model.live="payment.tier" id="p-tier" class="input">
                            @foreach ($tiers as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label for="p-cycle" class="label">{{ __('Durée') }}</label>
                        <select wire:model.live="payment.cycle" id="p-cycle" class="input">
                            @foreach (\App\Models\Subscription::CYCLES as $k => $l)<option value="{{ $k }}">{{ __($l) }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label for="p-start" class="label">{{ __('Début de la période') }}</label>
                        <input wire:model.live="payment.starts_on" id="p-start" type="date" class="input">
                        <p class="hint">{{ __('Vide : à la suite de la période en cours, sinon aujourd’hui.') }}</p>
                    </div>
                    @if ($plans->firstWhere('id', (int) $payment['plan_id'])?->quote_only)
                        <div class="sm:col-span-2">
                            <label for="p-monthly" class="label">{{ __('Prix mensuel convenu (USD)') }}</label>
                            <input wire:model.live.debounce.400ms="payment.monthly" id="p-monthly" type="number" step="0.01" min="0" class="input">
                            @error('payment.monthly') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                    <div>
                        <label for="p-method" class="label">{{ __('Moyen de paiement') }}</label>
                        <select wire:model="payment.method" id="p-method" class="input">
                            @foreach (\App\Models\Subscription::PAYMENT_METHODS as $m)<option value="{{ $m }}">{{ $m }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label for="p-ref" class="label">{{ __('Référence de la transaction') }}</label>
                        <input wire:model="payment.reference" id="p-ref" class="input font-mono">
                    </div>
                </div>
                <div><label for="p-notes" class="label">{{ __('Remarques') }}</label><textarea wire:model="payment.notes" id="p-notes" rows="2" class="input"></textarea></div>
                @if ($quote)
                    <div class="rounded-2xl bg-leaf-50 p-4 text-sm text-ink-800">
                        <p>{{ __('Tarif en vigueur le :date : :p par mois.', ['date' => $quote['start']->translatedFormat('j F Y'), 'p' => $usd($quote['monthly'])]) }}</p>
                        <p class="mt-1 text-lg font-semibold">{{ __('Montant à encaisser : :a', ['a' => $usd($quote['amount'])]) }}</p>
                        <p class="mt-1 text-xs text-sand-700">{{ __('Ce prix reste figé jusqu’à la fin de la période, même si les tarifs changent.') }}</p>
                    </div>
                @endif
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'payment' })">{{ __('Annuler') }}</button>
                    <button class="btn-primary">{{ __('Enregistrer') }}</button>
                </div>
            </form>
        </x-modal>
    @endif
    @if ($canBill)
        <x-modal name="reject-declaration" :title="__('Rejeter la déclaration')">
            <form wire:submit="rejectDeclaration" class="space-y-4">
                <div><label for="rj-reason" class="label">{{ __('Motif, envoyé à la communauté') }}</label><input wire:model="rejectReason" id="rj-reason" class="input" placeholder="{{ __('Aucun paiement reçu avec cet ID') }}">@error('rejectReason') <p class="error">{{ $message }}</p> @enderror</div>
                <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'reject-declaration' })">{{ __('Annuler') }}</button><button class="btn-danger">{{ __('Rejeter') }}</button></div>
            </form>
        </x-modal>
    @endif
</div>
