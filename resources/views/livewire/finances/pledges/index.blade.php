@php use App\Support\Money; @endphp
<div>
    <a href="{{ route('finances.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Finances') }}</a>
    <x-page-header :title="__('Promesses')" :description="__('Projets et campagnes, et les promesses de chacun : en argent ou en nature, en une fois ou par échéances.')">
        <x-slot:actions>
            @if ($canManage)
                <button type="button" wire:click="editCampaign" class="btn-secondary"><x-icon name="plus" class="size-4" /> {{ __('Campagne') }}</button>
                <a href="{{ route('finances.pledges.create', $campaign !== '' ? ['campagne' => $campaign] : []) }}" class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Promesse') }}</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- Campagnes --}}
    @if ($campaigns->isNotEmpty())
        <div class="mb-6 flex gap-3 overflow-x-auto pb-1">
            <button type="button" wire:click="$set('campaign', '')" @class(['card shrink-0 px-4 py-3 text-left text-sm font-semibold', '!border-ink-700 ring-2 ring-ink-700' => $campaign === ''])>{{ __('Toutes les promesses') }}</button>
            @foreach ($campaigns as $row)
                @php $c = $row['campaign']; $t = $row['totals']; @endphp
                <button type="button" wire:click="$set('campaign', '{{ $c->id }}')" wire:key="camp-{{ $c->id }}" @class(['card w-64 shrink-0 p-4 text-left transition hover:border-ochre-300', '!border-ink-700 ring-2 ring-ink-700' => $campaign === (string) $c->id, 'opacity-60' => ! $c->isActive()])>
                    <span class="block truncate font-semibold text-ink-800">{{ $c->name }}</span>
                    <span class="block text-xs text-sand-700">{{ __(\App\Models\Campaign::KINDS[$c->kind]) }} · {{ trans_choice(':count promesse|:count promesses', $t['count']) }}@unless ($c->isActive()) · {{ __('close') }}@endunless</span>
                    <span class="mt-3 block text-sm"><span class="font-semibold tabular text-ink-800">{{ Money::format($t['received'], 'USD') }}</span> <span class="text-sand-700">{{ __('reçus') }}</span></span>
                    @if ($t['goal'])
                        <span class="mt-1 block h-2 rounded-full bg-sand-100"><span class="block h-2 rounded-full bg-ochre-500" style="width: {{ $t['percent'] }}%"></span></span>
                        <span class="mt-1 block text-xs text-sand-700">{{ __(':p % de l’objectif (:g)', ['p' => $t['percent'], 'g' => Money::format($t['goal'], 'USD')]) }}</span>
                    @else
                        <span class="mt-1 block text-xs text-sand-700">{{ __(':p promis', ['p' => Money::format($t['promised'], 'USD')]) }}</span>
                    @endif
                </button>
            @endforeach
        </div>
    @endif

    @if ($current)
        <section class="card mb-5 p-5 sm:p-6">
            <div class="flex flex-wrap items-start gap-3">
                <div class="min-w-0 flex-1">
                    <p class="eyebrow">{{ __(\App\Models\Campaign::KINDS[$current->kind]) }}</p>
                    <h2 class="text-xl">{{ $current->name }}</h2>
                    @if ($current->description)<p class="mt-1 text-sm text-sand-700">{{ $current->description }}</p>@endif
                    <p class="mt-1 text-sm text-sand-700">{{ collect([$current->starts_on ? __('depuis le :d', ['d' => $current->starts_on->translatedFormat('j M Y')]) : null, $current->ends_on ? __('jusqu’au :d', ['d' => $current->ends_on->translatedFormat('j M Y')]) : null, $current->goal_amount ? __('objectif :g', ['g' => Money::format($current->goal_amount, $current->goal_currency)]) : null])->filter()->implode(' · ') }}</p>
                </div>
                @if ($canManage)<button type="button" wire:click="editCampaign({{ $current->id }})" class="btn-secondary !min-h-0 !py-2"><x-icon name="pencil" class="size-4" /> {{ __('Modifier') }}</button>@endif
            </div>
        </section>
    @endif

    <div class="mb-4 flex flex-wrap items-center gap-2">
        @foreach (['' => __('Toutes'), 'retard' => __('En retard'), 'active' => __('En cours'), 'fulfilled' => __('Honorées'), 'cancelled' => __('Annulées')] as $key => $label)
            <button type="button" wire:click="$set('state', '{{ $key }}')" @class(['chip', '!border-ink-700 !bg-ink-700 !text-white' => $state === $key])>{{ $label }}@if ($key === 'retard' && $lateCount) <span class="badge bg-terra-500 text-white">{{ $lateCount }}</span>@endif</button>
        @endforeach
        <input wire:model.live.debounce.300ms="search" type="search" class="input min-w-0 flex-1 sm:max-w-xs" placeholder="{{ __('Nom') }}" aria-label="{{ __('Rechercher') }}">
    </div>

    <ul class="space-y-2.5">
        @forelse ($rows as $row)
            @php $p = $row['pledge']; $g = $row['progress']; @endphp
            <li wire:key="p-{{ $p->id }}">
                <a href="{{ route('finances.pledges.show', $p) }}" class="card flex flex-wrap items-center gap-x-4 gap-y-2 p-4 transition hover:border-ochre-300">
                    <span class="ring-progress size-12" style="--v: {{ $g['percent'] }}"><span class="size-9 text-[11px]">{{ $g['percent'] }}%</span></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-semibold text-ink-800">{{ $canSeeNames ? $p->pledgerName() : __('Promesse n° :n', ['n' => $p->id]) }}</span>
                        <span class="block truncate text-sm text-sand-700">{{ $p->campaign?->name ?? __('Sans campagne') }} · {{ $p->kind === 'in_kind' ? $p->in_kind_description : __(\App\Models\Pledge::FREQUENCIES[$p->frequency]) }}</span>
                    </span>
                    <span class="text-right text-sm">
                        <span class="block font-semibold tabular text-ink-800">{{ Money::format($g['received'], $p->currency) }} / {{ Money::format($g['promised'], $p->currency) }}</span>
                        @if ($p->status === 'fulfilled')
                            <span class="badge bg-leaf-50 text-leaf-600">{{ __('Honorée') }}</span>
                        @elseif ($p->status === 'cancelled')
                            <span class="badge bg-sand-100 text-sand-700">{{ __('Annulée') }}</span>
                        @elseif ($g['late']->isPositive())
                            <span class="badge bg-terra-50 text-terra-600">{{ __('En retard de :m', ['m' => Money::format($g['late'], $p->currency)]) }}</span>
                        @else
                            <span class="badge bg-ochre-100 text-ochre-700">{{ __('Reste :m', ['m' => Money::format($g['remaining'], $p->currency)]) }}</span>
                        @endif
                    </span>
                </a>
            </li>
        @empty
            <li class="card p-8 text-center text-sand-700">{{ __('Aucune promesse ici.') }}</li>
        @endforelse
    </ul>
    @if ($pages > 1)
        <div class="mt-4 flex items-center justify-between text-sm">
            <button type="button" wire:click="previousPage" @disabled($page <= 1) class="btn-secondary">{{ __('Précédent') }}</button>
            <span class="text-sand-700">{{ __('Page :p sur :n', ['p' => $page, 'n' => $pages]) }}</span>
            <button type="button" wire:click="nextPage" @disabled($page >= $pages) class="btn-secondary">{{ __('Suivant') }}</button>
        </div>
    @endif

    <x-modal name="campaign" :title="$campaignId ? __('Modifier la campagne') : __('Nouvelle campagne')">
        <form wire:submit="saveCampaign" class="space-y-4">
            <div><label for="c-name" class="label">{{ __('Nom') }}</label><input wire:model="form.name" id="c-name" class="input" placeholder="{{ __('Exemple : Construction du nouveau temple') }}">@error('form.name') <p class="error">{{ $message }}</p> @enderror</div>
            <div><label for="c-kind" class="label">{{ __('Type') }}</label><select wire:model="form.kind" id="c-kind" class="input">@foreach (\App\Models\Campaign::KINDS as $k => $l)<option value="{{ $k }}">{{ __($l) }}</option>@endforeach</select></div>
            <div><label for="c-desc" class="label">{{ __('Description') }}</label><textarea wire:model="form.description" id="c-desc" rows="2" class="input"></textarea></div>
            <div class="grid gap-4 sm:grid-cols-[1fr_7rem]">
                <div><label for="c-goal" class="label">{{ __('Objectif (facultatif)') }}</label><input wire:model="form.goal_amount" id="c-goal" type="number" step="0.01" min="0" class="input"></div>
                <div><label for="c-cur" class="label">{{ __('Devise') }}</label><select wire:model="form.goal_currency" id="c-cur" class="input">@foreach ($currencies as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select></div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label for="c-start" class="label">{{ __('Début') }}</label><input wire:model="form.starts_on" id="c-start" type="date" class="input"></div>
                <div><label for="c-end" class="label">{{ __('Fin') }}</label><input wire:model="form.ends_on" id="c-end" type="date" class="input">@error('form.ends_on') <p class="error">{{ $message }}</p> @enderror</div>
            </div>
            @if ($campaignId)
                <div><label for="c-status" class="label">{{ __('État') }}</label><select wire:model="form.status" id="c-status" class="input"><option value="active">{{ __('En cours') }}</option><option value="closed">{{ __('Terminée') }}</option></select></div>
            @endif
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'campaign' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Enregistrer') }}</button></div>
        </form>
    </x-modal>
</div>
