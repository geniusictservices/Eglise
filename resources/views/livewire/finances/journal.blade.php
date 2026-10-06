@php use App\Support\Money; @endphp
<div>
    <a href="{{ route('finances.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Finances') }}</a>
    <x-page-header :title="__('Opérations')" :description="__('Chaque mouvement d’argent, avec son équivalent en dollars au taux du jour. Une erreur ne s’efface pas : elle s’annule, avec son motif.')">
        <x-slot:actions>
            @can('finance.income')<a href="{{ route('finances.income') }}" class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Recette') }}</a>@endcan
            @can('finance.exchange')<a href="{{ route('finances.transfer') }}" class="btn-secondary"><x-icon name="arrow-left-right" class="size-4" /> {{ __('Virement ou change') }}</a>@endcan
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <div class="flex items-center rounded-2xl border border-sand-200 bg-white p-1">
            <button type="button" wire:click="shiftMonth(-1)" class="rounded-xl p-2 hover:bg-sand-50" aria-label="{{ __('Mois précédent') }}"><x-icon name="chevron-left" class="size-4" /></button>
            <span class="min-w-32 px-2 text-center text-sm font-semibold capitalize text-ink-800">{{ $monthLabel }}</span>
            <button type="button" wire:click="shiftMonth(1)" class="rounded-xl p-2 hover:bg-sand-50" aria-label="{{ __('Mois suivant') }}"><x-icon name="chevron-right" class="size-4" /></button>
        </div>
        <select wire:model.live="account" class="input !w-auto" aria-label="{{ __('Compte') }}">
            <option value="">{{ __('Tous les comptes') }}</option>
            @foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach
        </select>
        <select wire:model.live="kind" class="input !w-auto" aria-label="{{ __('Type') }}">
            <option value="">{{ __('Toutes les opérations') }}</option>
            <option value="recettes">{{ __('Recettes') }}</option>
            <option value="depenses">{{ __('Dépenses') }}</option>
            <option value="mouvements">{{ __('Virements et change') }}</option>
        </select>
        <select wire:model.live="category" class="input !w-auto" aria-label="{{ __('Catégorie') }}">
            <option value="">{{ __('Toutes les catégories') }}</option>
            @foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
        </select>
        <input wire:model.live.debounce.300ms="search" type="search" class="input min-w-0 flex-1 sm:max-w-xs" placeholder="{{ __('Libellé, n° de reçu, ID…') }}" aria-label="{{ __('Rechercher') }}">
    </div>

    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
        <div class="card p-4"><p class="text-sm text-sand-700">{{ __('Recettes du mois') }}</p><p class="text-xl font-semibold tabular text-leaf-600">{{ Money::format($totals->income ?? 0, 'USD') }}</p></div>
        <div class="card p-4"><p class="text-sm text-sand-700">{{ __('Dépenses du mois') }}</p><p class="text-xl font-semibold tabular text-terra-600">{{ Money::format($totals->expense ?? 0, 'USD') }}</p></div>
        <div class="card col-span-2 p-4 sm:col-span-1"><p class="text-sm text-sand-700">{{ __('Résultat') }}</p><p class="text-xl font-semibold tabular text-ink-800">{{ Money::format(($totals->income ?? 0) - ($totals->expense ?? 0), 'USD') }}</p></div>
    </div>

    <div class="card overflow-hidden">
        <ul class="divide-y divide-sand-100">
            @forelse ($transactions as $t)
                @php
                    $in = $t->isInflow();
                    $who = $t->member_id ? ($canSeeNames ? $t->member?->fullName() : __('Contribution nominative')) : ($t->payer_name && $canSeeNames ? $t->payer_name : ($t->department?->name));
                @endphp
                <li @class(['flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3', 'opacity-60' => $t->cancelled_at]) wire:key="t-{{ $t->id }}">
                    <span @class(['icon-tile size-9', 'bg-leaf-50 text-leaf-600' => $in, 'bg-terra-50 text-terra-600' => ! $in])>
                        <x-icon :name="str_starts_with($t->type, 'exchange') ? 'arrow-left-right' : (str_starts_with($t->type, 'transfer') ? 'refresh-cw' : ($in ? 'download' : 'upload'))" class="size-4" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p @class(['truncate font-semibold text-ink-800', 'line-through' => $t->cancelled_at])>
                            {{ $t->category?->name ?? __(\App\Models\FinanceTransaction::TYPES[$t->type]) }}@if ($who) · <span class="font-normal">{{ $who }}</span>@endif
                        </p>
                        <p class="truncate text-xs text-sand-700">
                            {{ $t->occurred_on->translatedFormat('j M') }} · {{ $t->account->name }}@if ($t->description) · {{ $t->description }}@endif @if ($t->receipt_number) · {{ $t->receipt_number }}@endif
                            @if ($t->external_reference) · {{ __('ID :r', ['r' => $t->external_reference]) }}@endif
                        </p>
                        @if ($t->cancelled_at)<p class="text-xs text-terra-600">{{ __('Annulée le :d : :r', ['d' => $t->cancelled_at->translatedFormat('j M'), 'r' => $t->cancel_reason]) }}</p>@endif
                    </div>
                    <div class="text-right">
                        <p @class(['font-semibold tabular', 'text-leaf-600' => $in, 'text-terra-600' => ! $in, 'line-through' => $t->cancelled_at])>{{ $in ? '+' : '−' }} {{ Money::format($t->amount, $t->currency) }}</p>
                        @if ($t->currency !== 'USD')<p class="text-xs tabular text-sand-700">≈ {{ Money::format($t->usd_amount, 'USD') }}</p>@endif
                    </div>
                    <div class="flex items-center gap-1">
                        @if ($t->type === 'income' && ! $t->cancelled_at)
                            <a href="{{ route('finances.receipt', $t) }}" target="_blank" class="rounded-lg p-2 text-sand-500 hover:bg-sand-100 hover:text-ink-700" aria-label="{{ __('Reçu') }}"><x-icon name="printer" class="size-4" /></a>
                        @endif
                        @if ($cancellable[$t->id] ?? false)
                            <button type="button" wire:click="askCancel({{ $t->id }})" class="rounded-lg p-2 text-sand-500 hover:bg-terra-50 hover:text-terra-600" aria-label="{{ __('Annuler l’opération') }}"><x-icon name="undo-2" class="size-4" /></button>
                        @endif
                    </div>
                </li>
            @empty
                <li class="px-4 py-10 text-center text-sand-700">{{ __('Aucune opération ce mois-ci.') }}</li>
            @endforelse
        </ul>
    </div>
    <div class="mt-4">{{ $transactions->links() }}</div>

    <x-modal name="cancel" :title="__('Annuler l’opération')">
        <form wire:submit="cancel" class="space-y-4">
            @if ($pending)
                <p class="text-sm text-ink-800">{{ __(':type de :amount du :date.', ['type' => __(\App\Models\FinanceTransaction::TYPES[$pending->type]), 'amount' => Money::format($pending->amount, $pending->currency), 'date' => $pending->occurred_on->translatedFormat('j F Y')]) }}
                    @if ($pending->group_uuid) {{ __('Les deux côtés du virement ou du change seront annulés.') }}@endif</p>
            @endif
            <div><label for="cancelReason" class="label">{{ __('Motif') }}</label><input wire:model="cancelReason" id="cancelReason" class="input" placeholder="{{ __('Exemple : montant mal saisi, ressaisi correctement') }}">@error('cancelReason') <p class="error">{{ $message }}</p> @enderror</div>
            <p class="hint">{{ __('L’opération reste dans le journal, barrée, avec ce motif. Elle ne compte plus dans les soldes.') }}</p>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'cancel' })">{{ __('Retour') }}</button><button class="btn-danger">{{ __('Annuler l’opération') }}</button></div>
        </form>
    </x-modal>
</div>
