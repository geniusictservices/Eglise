<div>
    <a href="{{ route('finances.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Finances') }}</a>
    <x-page-header :title="__('Virement ou change')" :description="__('Déplacer de l’argent d’un compte à un autre (dépôt en banque, retrait mobile money…) ou changer des dollars en francs, dans un même compte ou entre deux comptes.')" />

    @if (count($options) < 2)
        <div class="card p-6 text-center text-sand-700">{{ __('Il faut au moins deux comptes, ou un compte avec deux devises.') }}</div>
    @else
        <form wire:submit="save" class="mx-auto max-w-2xl space-y-5">
            <section class="card space-y-4 p-5 sm:p-6">
                <div>
                    <label for="from" class="label">{{ __('Depuis') }}</label>
                    <select wire:model.live="from" id="from" class="input">@foreach ($options as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                    @if ($fromBalance)<p class="hint">{{ __('Solde disponible : :b', ['b' => $fromBalance]) }}</p>@endif
                </div>
                <div>
                    <label for="amountOut" class="label">{{ $exchange ? __('Montant donné') : __('Montant') }} <span class="font-normal text-sand-700">({{ $fromCurrency }})</span></label>
                    <input wire:model.live.debounce.400ms="amountOut" id="amountOut" type="number" step="0.01" min="0" class="input text-lg font-semibold tabular">
                    @error('amountOut') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-center"><span class="grid size-10 place-items-center rounded-full bg-ochre-100 text-ochre-700"><x-icon name="arrow-left-right" class="size-5 rotate-90" /></span></div>
                <div>
                    <label for="to" class="label">{{ __('Vers') }}</label>
                    <select wire:model.live="to" id="to" class="input">@foreach ($options as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                    @error('to') <p class="error">{{ $message }}</p> @enderror
                </div>
                @if ($exchange)
                    <div>
                        <label for="amountIn" class="label">{{ __('Montant reçu') }} <span class="font-normal text-sand-700">({{ $toCurrency }})</span></label>
                        <input wire:model.live.debounce.400ms="amountIn" id="amountIn" type="number" step="0.01" min="0" class="input text-lg font-semibold tabular">
                        @error('amountIn') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    @if ($applied)
                        <p class="rounded-xl bg-sand-100 px-4 py-3 text-sm text-ink-800">{{ __('Taux pratiqué : :a', ['a' => $applied]) }}@if ($official) · <span class="text-sand-700">{{ __('taux du jour de la communauté : :o', ['o' => $official]) }}</span>@endif</p>
                    @endif
                @endif
            </section>
            <section class="card grid gap-4 p-5 sm:grid-cols-2 sm:p-6">
                <div><label for="occurredOn" class="label">{{ __('Date') }}</label><input wire:model="occurredOn" id="occurredOn" type="date" max="{{ today()->toDateString() }}" class="input"></div>
                <div><label for="description" class="label">{{ __('Libellé (facultatif)') }}</label><input wire:model="description" id="description" class="input" placeholder="{{ $exchange ? __('Exemple : change au marché de Birere') : __('Exemple : dépôt des offrandes à la banque') }}"></div>
            </section>
            <button class="btn-primary w-full"><x-icon name="save" class="size-4" /> {{ $exchange ? __('Enregistrer le change') : __('Enregistrer le virement') }}</button>
        </form>
    @endif
</div>
