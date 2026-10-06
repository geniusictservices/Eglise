@php use App\Models\Group; @endphp
<div>
    <x-page-header :title="__('Groupes')" :description="__('Cellules de quartier, chorales, groupes de prière : chaque groupe a son responsable, ses membres et ses rencontres, avec les présences.')">
        <x-slot:actions>
            @if ($canSetUp)<button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Nouveau groupe') }}</button>@endif
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-wrap gap-2">
        <input wire:model.live.debounce.300ms="search" type="search" class="input min-w-0 flex-1 basis-56" placeholder="{{ __('Nom du groupe ou du responsable') }}" aria-label="{{ __('Rechercher') }}">
        <select wire:model.live="kind" class="input w-auto" aria-label="{{ __('Type') }}">
            <option value="">{{ __('Tous les types') }}</option>
            @foreach (Group::KINDS as $k => $l)<option value="{{ $k }}">{{ __($l) }}</option>@endforeach
        </select>
    </div>

    <ul class="grid gap-3 md:grid-cols-2">
        @forelse ($groups as $g)
            @php
                $last = $g->latestMeeting;
                $expected = $last?->attendances->count() ?? 0;
                $present = $last?->attendances->where('status', 'present')->count() ?? 0;
            @endphp
            <li wire:key="g-{{ $g->id }}">
                <a href="{{ route('groups.show', $g) }}" @class(['card flex h-full flex-col gap-3 p-4 transition hover:border-ochre-300', 'opacity-60' => ! $g->is_active])>
                    <div class="flex items-start gap-3">
                        <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-ink-50 text-ink-700"><x-icon name="handshake" class="size-5" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-semibold text-ink-800">{{ $g->name }}</span>
                            <span class="block truncate text-sm text-sand-700">{{ __(Group::KINDS[$g->kind]) }}@if ($g->department) · {{ $g->department->name }}@endif @unless ($g->is_active) · {{ __('En sommeil') }}@endunless</span>
                        </span>
                        <span class="badge bg-sand-100 text-ink-700 tabular">{{ trans_choice(':count pers.|:count pers.', $g->members_count + 1) }}</span>
                    </div>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-sand-700">
                        <span class="inline-flex items-center gap-1.5"><x-icon name="badge-check" class="size-4 text-ochre-600" /> {{ $g->leader?->fullName() }}</span>
                        @if ($g->schedule())<span class="inline-flex items-center gap-1.5"><x-icon name="calendar" class="size-4" /> {{ $g->schedule() }}</span>@endif
                    </div>
                    <div class="mt-auto border-t border-sand-100 pt-2 text-xs text-sand-700">
                        @if ($last)
                            {{ __('Dernière rencontre : :d', ['d' => $last->held_on->translatedFormat('j M')]) }} ·
                            <span class="font-semibold text-ink-700 tabular">{{ __(':p présents sur :t', ['p' => $present, 't' => $expected]) }}</span>@if ($last->visitors) · {{ trans_choice(':count visiteur|:count visiteurs', $last->visitors) }}@endif
                        @else
                            {{ __('Aucune rencontre notée.') }}
                        @endif
                    </div>
                </a>
            </li>
        @empty
            <li class="card p-8 text-center text-sm text-sand-700 md:col-span-2">
                {{ trim($search) !== '' || $kind !== '' ? __('Aucun groupe ne correspond.') : __('Aucun groupe pour l’instant.') }}
            </li>
        @endforelse
    </ul>

    @if ($canSetUp)
        @include('livewire.groups.partials.form-modal', ['title' => __('Nouveau groupe'), 'withLeader' => true])
    @endif
</div>
