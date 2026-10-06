<div>
    <x-page-header :title="__('Membres')"
                   :description="__('Le registre des fidèles de :name.', ['name' => $organization->name])">
        <x-slot:actions>
            <a href="{{ route('households.index') }}" class="btn-secondary"><x-icon name="house" class="size-4" /> {{ __('Ménages') }}</a>
            @can('members.settings')
                <a href="{{ route('members.settings') }}" class="btn-secondary" aria-label="{{ __('Réglages du registre') }}"><x-icon name="settings" class="size-4" /><span class="hidden sm:inline">{{ __('Réglages') }}</span></a>
            @endcan
            @if ($canManage)
                <a href="{{ route('members.create') }}" class="btn-primary"><x-icon name="user-plus" class="size-4" /> {{ __('Ajouter un membre') }}</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- Effectifs --}}
    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-4">
        @foreach ([
            ['label' => __('Effectif'), 'value' => $stats['total'], 'icon' => 'users', 'tone' => 'bg-ink-700 text-white', 'hint' => __(':count inscrits au total', ['count' => number_format($stats['all'], 0, ',', ' ')])],
            ['label' => __('Femmes · Hommes'), 'value' => number_format($stats['women'], 0, ',', ' ').' · '.number_format($stats['men'], 0, ',', ' '), 'icon' => 'users-round', 'tone' => 'bg-terra-500 text-white', 'hint' => __('dans l’effectif')],
            ['label' => __('Nouveaux en :year', ['year' => now()->year]), 'value' => $stats['new'], 'icon' => 'sparkles', 'tone' => 'bg-ochre-500 text-on-accent', 'hint' => null],
            ['label' => __('Ménages'), 'value' => $stats['households'], 'icon' => 'house', 'tone' => 'bg-leaf-500 text-white', 'hint' => null],
        ] as $stat)
            <div class="card grid content-start gap-0.5 p-3.5 lg:gap-1 lg:p-5">
                <span class="icon-tile size-9 lg:size-10 {{ $stat['tone'] }}"><x-icon :name="$stat['icon']" class="size-5" /></span>
                <span class="mt-2 text-xs text-sand-700 sm:text-sm">{{ $stat['label'] }}</span>
                <span class="text-xl font-semibold text-ink-800 tabular lg:text-2xl">{{ is_int($stat['value']) ? number_format($stat['value'], 0, ',', ' ') : $stat['value'] }}</span>
                @if ($stat['hint'])<span class="text-xs text-sand-700">{{ $stat['hint'] }}</span>@endif
            </div>
        @endforeach
    </div>

    {{-- Recherche et filtres --}}
    <div class="mb-4 space-y-3" x-data="{ filters: @js($filtered && $search === '') }">
        <div class="flex gap-2">
            <label for="search" class="sr-only">{{ __('Rechercher') }}</label>
            <div class="relative min-w-0 flex-1 lg:max-w-md">
                <x-icon name="search" class="pointer-events-none absolute left-3.5 top-1/2 size-5 -translate-y-1/2 text-sand-500" />
                <input wire:model.live.debounce.300ms="search" id="search" type="search" class="input pl-11" placeholder="{{ __('Nom, numéro ou téléphone') }}">
            </div>
            <button type="button" class="btn-secondary lg:hidden" @click="filters = ! filters" :aria-expanded="filters" aria-controls="member-filters">
                <x-icon name="sliders-horizontal" class="size-4" /> <span class="sr-only sm:not-sr-only">{{ __('Filtres') }}</span>
            </button>
        </div>
        <div id="member-filters" class="grid gap-2 sm:grid-cols-2 lg:!grid lg:grid-cols-5" :class="filters ? 'grid' : 'hidden'">
            <select wire:model.live="status" class="input" aria-label="{{ __('Statut') }}">
                <option value="">{{ __('Statut : tous') }}</option>
                @foreach ($statuses as $s)
                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                @endforeach
                <option value="aucun">{{ __('Sans statut') }}</option>
            </select>
            <select wire:model.live="gender" class="input" aria-label="{{ __('Sexe') }}">
                <option value="">{{ __('Sexe : tous') }}</option>
                <option value="F">{{ __('Femmes') }}</option>
                <option value="M">{{ __('Hommes') }}</option>
            </select>
            <select wire:model.live="district" class="input" aria-label="{{ __('Quartier') }}">
                <option value="">{{ __('Quartier : tous') }}</option>
                @foreach ($districts as $d)
                    <option value="{{ $d }}">{{ $d }}</option>
                @endforeach
            </select>
            <select wire:model.live="department" class="input" aria-label="{{ __('Département') }}">
                <option value="">{{ __('Département : tous') }}</option>
                @foreach ($departments as $d)
                    <option value="{{ $d->id }}">{{ $d->name }}</option>
                @endforeach
            </select>
            @if ($hasChildren)
                <select wire:model.live="scope" class="input" aria-label="{{ __('Niveaux') }}">
                    <option value="ici">{{ __('Ce niveau seul') }}</option>
                    <option value="tout">{{ __('Tous les niveaux') }}</option>
                </select>
            @endif
        </div>
        @if ($filtered)
            <p class="flex flex-wrap items-center gap-2 text-sm text-sand-700">
                {{ trans_choice(':count membre trouvé|:count membres trouvés', $members->total(), ['count' => number_format($members->total(), 0, ',', ' ')]) }}
                <button type="button" wire:click="resetFilters" class="font-semibold text-ink-600 underline decoration-ochre-300 underline-offset-4">{{ __('Effacer les filtres') }}</button>
            </p>
        @endif
    </div>

    @if ($stats['all'] === 0 && ! $filtered)
        {{-- Registre vide --}}
        <div class="card flex flex-col items-center px-6 py-12 text-center">
            <span class="icon-tile size-14 bg-ochre-100 text-ochre-700"><x-icon name="contact-round" class="size-7" /></span>
            <h2 class="mt-4 text-xl">{{ __('Le registre est encore vide') }}</h2>
            <p class="mt-1 max-w-md text-sand-700">{{ __('Ajoutez vos membres un par un, ou importez votre ancien registre depuis Excel avec l’aide de Genius ICT.') }}</p>
            @if ($canManage)
                <a href="{{ route('members.create') }}" class="btn-primary mt-5"><x-icon name="user-plus" class="size-4" /> {{ __('Ajouter le premier membre') }}</a>
            @endif
        </div>
    @else
        {{-- Tableau (ordinateur) --}}
        <div class="card hidden overflow-hidden md:block">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('Nom') }}</th>
                        <th>{{ __('Numéro') }}</th>
                        <th>{{ __('Statut') }}</th>
                        <th>{{ __('Téléphone') }}</th>
                        <th>{{ $scope === 'tout' ? __('Niveau') : __('Quartier') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($members as $member)
                        <tr class="cursor-pointer hover:bg-sand-50/60" wire:key="m-{{ $member->id }}" onclick="window.location='{{ route('members.show', $member) }}'">
                            <td>
                                <a href="{{ route('members.show', $member) }}" class="flex items-center gap-3">
                                    @include('livewire.members.partials.avatar', ['member' => $member, 'size' => 'size-9 text-xs'])
                                    <span>
                                        <span class="block font-semibold text-ink-700">{{ $member->officialName() }}</span>
                                        <span class="block text-xs text-sand-700">
                                            {{ collect([$member->gender === 'F' ? __('Femme') : ($member->gender === 'M' ? __('Homme') : null), $member->age() !== null ? trans_choice(':count an|:count ans', $member->age()) : null, $member->household?->name])->filter()->implode(' · ') }}
                                        </span>
                                    </span>
                                </a>
                            </td>
                            <td class="whitespace-nowrap font-mono text-xs text-ink-700">{{ $member->number }}</td>
                            <td><x-status-badge :status="$member->status" /></td>
                            <td class="tabular whitespace-nowrap">{{ \App\Support\Phone::format($member->phone) }}</td>
                            <td class="text-sand-700">{{ $scope === 'tout' ? $member->organization->displayName() : $member->district }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-10 text-center text-sand-700">{{ __('Aucun membre ne correspond à cette recherche.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Cartes (téléphone) --}}
        <ul class="space-y-2.5 md:hidden">
            @forelse ($members as $member)
                <li wire:key="mm-{{ $member->id }}">
                    <a href="{{ route('members.show', $member) }}" class="card flex items-center gap-3 p-3.5">
                        @include('livewire.members.partials.avatar', ['member' => $member, 'size' => 'size-11 text-sm'])
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold text-ink-700">{{ $member->officialName() }}</p>
                            <p class="truncate text-sm text-sand-700"><span class="font-mono text-xs">{{ $member->number }}</span>@if ($member->phone) · <span class="tabular">{{ \App\Support\Phone::format($member->phone) }}</span>@endif</p>
                            <x-status-badge :status="$member->status" class="mt-1.5" />
                        </div>
                        <x-icon name="chevron-right" class="size-5 shrink-0 text-sand-300" />
                    </a>
                </li>
            @empty
                <li class="card p-6 text-center text-sand-700">{{ __('Aucun membre ne correspond à cette recherche.') }}</li>
            @endforelse
        </ul>

        <div class="mt-4">{{ $members->links() }}</div>
    @endif
</div>
