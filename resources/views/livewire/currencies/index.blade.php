@php use App\Support\Money; @endphp
<div>
    <x-page-header :title="__('Devises et taux')"
                   :description="__('Le dollar est la devise de base de Waumini. Pour chaque autre devise, saisissez le taux du jour : chaque montant sera affiché avec son équivalent en dollars.')">
        <x-slot:actions>
            <button type="button" class="btn-secondary" @click="$dispatch('open-modal', { name: 'add-currency' })"><x-icon name="plus" class="size-4" /> {{ __('Ajouter une devise') }}</button>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-wrap items-center gap-3 rounded-2xl border border-sand-200 bg-white p-4">
        <x-icon name="calendar" class="size-5 text-ink-400" />
        <label for="rate-date" class="text-sm font-bold text-ink-700">{{ __('Date des taux') }}</label>
        <input wire:model.live="date" id="rate-date" type="date" max="{{ today()->toDateString() }}" class="input !w-auto !min-h-0 !py-2">
        @error('date') <p class="error">{{ $message }}</p> @enderror
        <p class="text-sm text-sand-700">{{ __('Par défaut aujourd’hui. Choisissez une date passée pour corriger un oubli.') }}</p>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        <article class="card flex items-center gap-4 p-5">
            <span class="grid size-12 place-items-center rounded-xl bg-ink-700 font-display text-lg font-bold text-white">$</span>
            <div>
                <p class="font-display text-lg font-semibold text-ink-700">USD · {{ __('Dollar américain') }}</p>
                <p class="text-sm text-sand-700">{{ __('Devise de base : tous les totaux sont convertis en dollars.') }}</p>
            </div>
        </article>

        @foreach ($rows as $row)
            @php $c = $row['model']; @endphp
            <article wire:key="cur-{{ $c->id }}" @class(['card p-5', 'opacity-60' => ! $c->is_active])>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="font-display text-lg font-semibold text-ink-700">{{ $c->currency }} · {{ $c->name() }}</p>
                        @if ($row['effective'])
                            <p class="mt-0.5 text-sm text-sand-700">
                                <span class="font-bold text-ink-700 tabular">{{ Money::rate($row['effective'], $c->currency) }}</span>
                                @if ($row['today']) <span class="badge ml-1 bg-ink-50 text-ink-600">{{ __('à jour') }}</span>
                                @elseif ($row['inherited']) <span class="badge ml-1 bg-sand-100 text-sand-700">{{ __('taux du niveau supérieur') }}</span>
                                @else <span class="badge ml-1 bg-ochre-100 text-ochre-700">{{ __('du :date', ['date' => $row['own']->effective_on->translatedFormat('j M')]) }}</span>
                                @endif
                            </p>
                        @else
                            <p class="mt-0.5 text-sm font-bold text-terra-600">{{ __('Aucun taux saisi') }}</p>
                        @endif
                    </div>
                    <button type="button" class="text-xs font-bold text-sand-700 hover:text-ink-700" wire:click="toggle({{ $c->id }})">{{ $c->is_active ? __('Désactiver') : __('Réactiver') }}</button>
                </div>

                @if ($c->is_active)
                    <form wire:submit="saveRate('{{ $c->currency }}')" class="mt-4 flex items-start gap-2">
                        <div class="flex-1">
                            <label for="rate-{{ $c->currency }}" class="sr-only">{{ __('Taux pour 1 dollar') }}</label>
                            <div class="relative">
                                <span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-sand-700">1 $ =</span>
                                <input wire:model="rates.{{ $c->currency }}" id="rate-{{ $c->currency }}" inputmode="decimal" class="input pl-14 pr-14 tabular"
                                       placeholder="{{ $row['effective'] ? (string) $row['effective']->strippedOfTrailingZeros() : '2850' }}">
                                <span class="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-sm font-bold text-sand-700">{{ config("waumini.currencies.{$c->currency}.symbol") }}</span>
                            </div>
                            @error("rates.{$c->currency}") <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="btn-primary">{{ __('Enregistrer') }}</button>
                    </form>
                    <button type="button" wire:click="$set('historyFor', '{{ $c->currency }}')" class="mt-3 text-sm font-bold text-ink-600 hover:underline">{{ __('Voir l’historique') }}</button>
                @endif
            </article>
        @endforeach
    </div>

    @if ($historyFor)
        <section class="card mt-6 overflow-hidden">
            <div class="border-b border-sand-100 px-5 py-4">
                <h2 class="text-lg font-semibold">{{ __('Historique des taux · :currency', ['currency' => $historyFor]) }}</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Taux') }}</th><th>{{ __('Saisi par') }}</th></tr></thead>
                    <tbody>
                        @forelse ($history as $rate)
                            <tr>
                                <td class="whitespace-nowrap">{{ $rate->effective_on->translatedFormat('l j F Y') }}</td>
                                <td class="whitespace-nowrap font-bold text-ink-700 tabular">{{ Money::rate($rate->rate, $rate->currency) }}</td>
                                <td class="text-sand-700">{{ $rate->author?->name ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="py-6 text-center text-sand-700">{{ __('Aucun taux enregistré à ce niveau.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <x-modal name="add-currency" :title="__('Ajouter une devise')">
        <form wire:submit="addCurrency" class="space-y-4">
            <div>
                <label for="newCurrency" class="label">{{ __('Devise') }}</label>
                <select wire:model="newCurrency" id="newCurrency" class="input">
                    <option value="">{{ __('Choisir…') }}</option>
                    @foreach ($available as $code => $info)<option value="{{ $code }}">{{ $code }} · {{ $info['name'] }}</option>@endforeach
                </select>
                @error('newCurrency') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'add-currency' })">{{ __('Annuler') }}</button>
                <button type="submit" class="btn-primary">{{ __('Ajouter') }}</button>
            </div>
        </form>
    </x-modal>
</div>
