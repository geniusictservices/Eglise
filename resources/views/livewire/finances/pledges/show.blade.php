@php use App\Support\Money; $c = $pledge->currency; @endphp
<div>
    <a href="{{ route('finances.pledges', $pledge->project_id ? ['projet' => $pledge->project_id] : []) }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Promesses') }}</a>

    <section class="wax wax-veil wax-veil-strong mb-5 overflow-hidden rounded-[22px] p-5 text-white sm:p-6">
        <div class="flex flex-wrap items-center gap-5">
            <span class="ring-progress size-20" style="--v: {{ $progress['percent'] }}"><span class="size-14 text-sm">{{ $progress['percent'] }} %</span></span>
            <div class="min-w-0 flex-1">
                <p class="text-sm text-ochre-300">{{ $pledge->project?->name ?? __('Promesse générale') }} · {{ __(\App\Models\Pledge::STATUSES[$pledge->status]) }}</p>
                <h1 class="text-2xl font-semibold text-white">{{ $pledge->pledgerName() }}</h1>
                <p class="text-sm text-ink-100">
                    @if ($pledge->kind === 'in_kind') {{ $pledge->in_kind_description }} · {{ __('valeur :v', ['v' => Money::format($pledge->amount, $c)]) }}
                    @else {{ Money::format($pledge->amount, $c) }} · {{ __(\App\Models\Pledge::FREQUENCIES[$pledge->frequency]) }}@if ($pledge->installments > 1) ({{ trans_choice(':count échéance|:count échéances', $pledge->installments) }})@endif
                    @endif · {{ __('promis le :d', ['d' => $pledge->pledged_on->translatedFormat('j M Y')]) }}
                </p>
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            @if ($canPay && $pledge->kind === 'money')<button type="button" wire:click="openPayment" class="btn-accent !min-h-0 !py-2"><x-icon name="download" class="size-4" /> {{ __('Enregistrer un versement') }}</button>@endif
            @if ($canManage && $pledge->status !== 'cancelled')<button type="button" wire:click="openDelivery" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="hand-coins" class="size-4" /> {{ __('Don en nature reçu') }}</button>@endif
            @if ($canManage && $whatsapp && $pledge->status === 'active')
                <a href="{{ $whatsapp }}" target="_blank" rel="noopener" wire:click="logReminder" class="btn !min-h-0 bg-leaf-500 !py-2 text-white hover:bg-leaf-600"><x-icon name="message-circle" class="size-4" /> {{ __('Relancer sur WhatsApp') }}</a>
            @endif
            @if ($canManage)
                <a href="{{ route('finances.pledges.edit', $pledge) }}" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25 sm:ml-auto"><x-icon name="pencil" class="size-4" /> {{ __('Modifier') }}</a>
                @if ($pledge->status !== 'cancelled')<button type="button" @click="$dispatch('open-modal', { name: 'cancel' })" class="btn !min-h-0 bg-white/15 !px-3 !py-2 text-white hover:bg-white/25" aria-label="{{ __('Annuler la promesse') }}"><x-icon name="x" class="size-4" /></button>@endif
            @endif
        </div>
    </section>

    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([[__('Promis'), $progress['promised'], 'text-ink-800'], [__('Reçu'), $progress['received'], 'text-leaf-600'], [__('Reste'), $progress['remaining'], 'text-ink-800'], [__('En retard'), $progress['late'], $progress['late']->isPositive() ? 'text-terra-600' : 'text-sand-500']] as [$label, $value, $tone])
            <div class="card p-4"><p class="text-sm text-sand-700">{{ $label }}</p><p class="text-xl font-semibold tabular {{ $tone }}">{{ Money::format($value, $c) }}</p></div>
        @endforeach
    </div>
    @if ($progress['next_due'] && $pledge->status === 'active')<p class="mb-5 text-sm text-sand-700">{{ __('Prochaine échéance : :d', ['d' => $progress['next_due']->translatedFormat('l j F Y')]) }}</p>@endif

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="card p-5 sm:p-6">
            <h2 class="mb-3 text-lg">{{ __('Versements') }}</h2>
            <ul class="divide-y divide-sand-100">
                @forelse ($payments as $t)
                    <li @class(['flex items-center gap-3 py-2.5 text-sm', 'opacity-50 line-through' => $t->cancelled_at])>
                        <span class="flex-1"><span class="font-semibold text-ink-800">{{ $t->occurred_on->translatedFormat('j M Y') }}</span> <span class="text-sand-700">· {{ $t->account->name }} · {{ $t->receipt_number }}</span></span>
                        <span class="font-semibold tabular text-leaf-600">{{ Money::format($t->amount, $t->currency) }}</span>
                        @unless ($t->cancelled_at)<a href="{{ route('finances.receipt', $t) }}" target="_blank" class="rounded-lg p-1.5 text-sand-500 hover:bg-sand-100" aria-label="{{ __('Reçu') }}"><x-icon name="printer" class="size-4" /></a>@endunless
                    </li>
                @empty
                    <li class="py-2 text-sm text-sand-700">{{ __('Aucun versement pour le moment.') }}</li>
                @endforelse
            </ul>
            @if ($deliveries->isNotEmpty())
                <h3 class="mb-2 mt-5 text-sm font-semibold text-ink-700">{{ __('Dons en nature reçus') }}</h3>
                <ul class="divide-y divide-sand-100">
                    @foreach ($deliveries as $d)
                        <li class="flex items-center gap-3 py-2 text-sm"><span class="flex-1"><span class="font-semibold text-ink-800">{{ $d->description }}</span> <span class="text-sand-700">· {{ $d->received_on->translatedFormat('j M Y') }}</span></span><span class="tabular">{{ Money::format($d->value, $c) }}</span></li>
                    @endforeach
                </ul>
            @endif
        </section>

        <div class="space-y-5">
            @if ($schedule)
                <section class="card p-5 sm:p-6">
                    <h2 class="mb-3 text-lg">{{ __('Échéancier') }}</h2>
                    <ol class="max-h-72 space-y-1 overflow-y-auto text-sm">
                        @foreach ($schedule as $i => $s)
                            <li class="flex items-center gap-3">
                                <span @class(['grid size-6 place-items-center rounded-full text-xs font-semibold', 'bg-leaf-500 text-white' => $s['paid'], 'bg-terra-500 text-white' => $s['late'], 'bg-sand-100 text-sand-700' => ! $s['paid'] && ! $s['late']])>{{ $s['paid'] ? '✓' : $i + 1 }}</span>
                                <span class="flex-1">{{ $s['date']->translatedFormat('j M Y') }}</span>
                                <span class="tabular">{{ Money::format($s['amount'], $c) }}</span>
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endif
            <section class="card p-5 sm:p-6">
                <h2 class="mb-2 text-lg">{{ __('Relance') }}</h2>
                <p class="rounded-xl bg-leaf-50 p-3 text-sm text-ink-800">{{ $message }}</p>
                @if (! $whatsapp)<p class="mt-2 text-sm text-terra-600">{{ __('Pas de numéro de téléphone pour cette personne.') }}</p>@endif
                @if ($reminders->isNotEmpty())
                    <p class="mt-3 text-xs text-sand-700">{{ __('Dernières relances :') }} {{ $reminders->map(fn ($r) => $r->created_at->translatedFormat('j M').' ('.$r->user?->name.')')->implode(', ') }}</p>
                @endif
                <p class="hint">{{ __('Le message s’ouvre dans WhatsApp, sur votre téléphone : vous pouvez le relire avant de l’envoyer.') }}</p>
            </section>
            @if ($pledge->notes)<section class="card p-5 text-sm"><p class="whitespace-pre-line text-ink-800">{{ $pledge->notes }}</p></section>@endif
        </div>
    </div>

    <x-modal name="payment" :title="__('Enregistrer un versement')">
        <form wire:submit="pay" class="space-y-4">
            <div><label for="py-account" class="label">{{ __('Compte') }}</label><select wire:model.live="accountId" id="py-account" class="input">@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
            <div class="grid gap-4 sm:grid-cols-[7rem_1fr]">
                <div><label for="py-cur" class="label">{{ __('Devise') }}</label><select wire:model="currency" id="py-cur" class="input">@foreach ($currencies as $cur)<option value="{{ $cur }}">{{ $cur }}</option>@endforeach</select></div>
                <div><label for="py-amount" class="label">{{ __('Montant') }}</label><input wire:model="amount" id="py-amount" type="number" step="0.01" min="0" class="input text-lg font-semibold tabular">@error('amount') <p class="error">{{ $message }}</p> @enderror</div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label for="py-date" class="label">{{ __('Date') }}</label><input wire:model="paidOn" id="py-date" type="date" max="{{ today()->toDateString() }}" class="input"></div>
                <div><label for="py-method" class="label">{{ __('Moyen') }}</label><select wire:model.live="paymentMethod" id="py-method" class="input">@foreach (\App\Models\FinanceTransaction::PAYMENT_METHODS as $k => $l)<option value="{{ $k }}">{{ __($l) }}</option>@endforeach</select></div>
            </div>
            @if ($paymentMethod !== 'cash')<div><label for="py-ref" class="label">{{ __('ID de la transaction') }}</label><input wire:model="reference" id="py-ref" class="input font-mono">@error('reference') <p class="error">{{ $message }}</p> @enderror</div>@endif
            @if ($currency && $currency !== $pledge->currency)<p class="hint">{{ __('Le versement sera compté sur la promesse en :c, au taux du jour.', ['c' => $pledge->currency]) }}</p>@endif
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'payment' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Enregistrer') }}</button></div>
        </form>
    </x-modal>

    <x-modal name="delivery" :title="__('Don en nature reçu')">
        <form wire:submit="deliver" class="space-y-4">
            <div><label for="dl-desc" class="label">{{ __('Ce qui a été reçu') }}</label><input wire:model="deliveryDescription" id="dl-desc" class="input" placeholder="{{ __('Exemple : 10 sacs de ciment') }}">@error('deliveryDescription') <p class="error">{{ $message }}</p> @enderror</div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label for="dl-value" class="label">{{ __('Valeur estimée (:c)', ['c' => $pledge->currency]) }}</label><input wire:model="deliveryValue" id="dl-value" type="number" step="0.01" min="0" class="input">@error('deliveryValue') <p class="error">{{ $message }}</p> @enderror</div>
                <div><label for="dl-date" class="label">{{ __('Date') }}</label><input wire:model="deliveryOn" id="dl-date" type="date" max="{{ today()->toDateString() }}" class="input"></div>
            </div>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'delivery' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Enregistrer') }}</button></div>
        </form>
    </x-modal>

    <x-modal name="cancel" :title="__('Annuler la promesse')">
        <form wire:submit="cancel" class="space-y-4">
            <div><label for="cn-reason" class="label">{{ __('Motif') }}</label><input wire:model="cancelReason" id="cn-reason" class="input">@error('cancelReason') <p class="error">{{ $message }}</p> @enderror</div>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'cancel' })">{{ __('Retour') }}</button><button class="btn-danger">{{ __('Annuler la promesse') }}</button></div>
        </form>
    </x-modal>
</div>
