@php use App\Support\Money; @endphp
<div>
    <a href="{{ route('finances.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Finances') }}</a>
    <x-page-header :title="__('Promesses')" :description="__('Les promesses de chacun pour les projets de la communauté : en argent ou en nature, en une fois ou par échéances.')">
        <x-slot:actions>
            @if ($canManage)
                <a href="{{ route('finances.pledges.create', $project !== '' ? ['projet' => $project] : []) }}" class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Promesse') }}</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- Les projets qui reçoivent des promesses --}}
    @if ($projects->isNotEmpty())
        <div class="mb-6 flex gap-3 overflow-x-auto pb-1">
            <button type="button" wire:click="$set('project', '')" @class(['card shrink-0 px-4 py-3 text-left text-sm font-semibold', '!border-ink-700 ring-2 ring-ink-700' => $project === ''])>{{ __('Toutes les promesses') }}</button>
            @foreach ($projects as $row)
                @php $c = $row['project']; $t = $row['totals']; @endphp
                <button type="button" wire:click="$set('project', '{{ $c->id }}')" wire:key="proj-{{ $c->id }}" @class(['card w-64 shrink-0 p-4 text-left transition hover:border-ochre-300', '!border-ink-700 ring-2 ring-ink-700' => $project === (string) $c->id, 'opacity-60' => ! $c->isActive()])>
                    <span class="block truncate font-semibold text-ink-800">{{ $c->name }}</span>
                    <span class="block text-xs text-sand-700">{{ __(\App\Models\Project::KINDS[$c->kind] ?? '') }} · {{ trans_choice(':count promesse|:count promesses', $row['count']) }}</span>
                    <span class="mt-3 block text-sm"><span class="font-semibold tabular text-ink-800">{{ Money::format($t['received'] + $t['in_kind'], 'USD') }}</span> <span class="text-sand-700">{{ __('reçus') }}</span></span>
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
        <a href="{{ route('projects.show', $current) }}" class="card mb-5 flex items-center gap-3 p-4 hover:border-ochre-300">
            <x-icon name="milestone" class="size-5 text-ochre-600" />
            <span class="min-w-0 flex-1"><span class="block font-semibold text-ink-800">{{ $current->name }}</span><span class="block text-sm text-sand-700">{{ __('Ouvrir la fiche du projet : ses années, ses dons, ses dépenses.') }}</span></span>
            <x-icon name="chevron-right" class="size-4 text-sand-500" />
        </a>
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
                        <span class="block truncate text-sm text-sand-700">{{ $p->project?->name ?? __('Sans projet') }} · {{ $p->kind === 'in_kind' ? $p->in_kind_description : __(\App\Models\Pledge::FREQUENCIES[$p->frequency]) }}</span>
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

</div>
