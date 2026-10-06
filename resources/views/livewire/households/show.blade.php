<div>
    <a href="{{ route('households.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Ménages') }}</a>

    <section class="wax wax-veil wax-veil-strong mb-5 overflow-hidden rounded-[22px] p-5 text-white sm:p-6">
        <div class="flex flex-wrap items-center gap-4">
            <span class="icon-tile size-14 bg-white/15 text-ochre-300"><x-icon name="house" class="size-7" /></span>
            <div class="min-w-0 flex-1">
                @if ($showLevel)<p class="text-sm text-ochre-300">{{ $household->organization->displayName() }}</p>@endif
                <h1 class="text-2xl font-semibold text-white">{{ $household->name }}</h1>
                <p class="text-sm text-ink-100">{{ $household->address() ?: __('Adresse non renseignée') }}@if ($household->phone) · <span class="tabular">{{ \App\Support\Phone::format($household->phone) }}</span>@endif</p>
            </div>
            @if ($canManage)
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="edit" class="btn-accent !min-h-0 !py-2"><x-icon name="pencil" class="size-4" /> {{ __('Modifier') }}</button>
                    <button type="button" wire:click="delete" wire:confirm="{{ __('Supprimer ce ménage ? Les fiches des personnes sont conservées.') }}" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="trash-2" class="size-4" /> <span class="sr-only sm:not-sr-only">{{ __('Supprimer') }}</span></button>
                </div>
            @endif
        </div>
    </section>

    <div class="grid gap-5 lg:grid-cols-[1.4fr_1fr]">
        <section class="card p-5 sm:p-6">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg">{{ trans_choice(':count personne|:count personnes', $members->count()) }}</h2>
                @if ($canManage && $members->isNotEmpty() && $household->address())
                    <button type="button" wire:click="shareAddress" wire:confirm="{{ __('Recopier l’adresse du ménage sur la fiche de chaque personne ?') }}" class="text-sm font-semibold text-ink-600 hover:underline">{{ __('Recopier l’adresse sur les fiches') }}</button>
                @endif
            </div>
            <ul class="divide-y divide-sand-100">
                @forelse ($members as $m)
                    <li class="flex flex-wrap items-center gap-3 py-3" wire:key="hm-{{ $m->id }}">
                        <a href="{{ route('members.show', $m) }}" class="flex min-w-0 flex-1 items-center gap-3">
                            @include('livewire.members.partials.avatar', ['member' => $m, 'size' => 'size-10 text-sm'])
                            <span class="min-w-0">
                                <span class="block truncate font-semibold text-ink-700">{{ $m->officialName() }}
                                    @if ($m->household_role === 'head')<x-icon name="badge-check" class="inline size-4 text-ochre-600" />@endif</span>
                                <span class="block text-sm text-sand-700">{{ collect([$m->age() !== null ? trans_choice(':count an|:count ans', $m->age()) : null, $m->status?->name])->filter()->implode(' · ') }}</span>
                            </span>
                        </a>
                        @if ($canManage)
                            <div class="flex items-center gap-2">
                                <select wire:change="setRole({{ $m->id }}, $event.target.value)" class="input !min-h-0 w-auto !py-1.5 text-sm" aria-label="{{ __('Place dans le ménage') }}">
                                    @foreach (\App\Models\Household::ROLES as $key => $label)<option value="{{ $key }}" @selected($m->household_role === $key)>{{ __($label) }}</option>@endforeach
                                </select>
                                <button type="button" wire:click="removeMember({{ $m->id }})" wire:confirm="{{ __('Retirer :name de ce ménage ?', ['name' => $m->fullName()]) }}" class="rounded-lg p-2 text-sand-500 hover:bg-terra-50 hover:text-terra-600" aria-label="{{ __('Retirer du ménage') }}"><x-icon name="x" class="size-4" /></button>
                            </div>
                        @else
                            <span class="badge bg-sand-100 text-sand-700">{{ __(\App\Models\Household::ROLES[$m->household_role] ?? '') }}</span>
                        @endif
                    </li>
                @empty
                    <li class="py-3 text-sm text-sand-700">{{ __('Personne n’est encore rattaché à ce ménage.') }}</li>
                @endforelse
            </ul>
        </section>

        @if ($canManage)
            <section class="card self-start p-5 sm:p-6">
                <h2 class="mb-3 text-lg">{{ __('Ajouter une personne') }}</h2>
                <div class="space-y-3">
                    <input wire:model.live.debounce.300ms="memberSearch" type="search" class="input" placeholder="{{ __('Nom ou numéro du membre') }}" aria-label="{{ __('Rechercher un membre') }}">
                    <select wire:model="newRole" class="input" aria-label="{{ __('Place dans le ménage') }}">
                        @foreach (\App\Models\Household::ROLES as $key => $label)<option value="{{ $key }}">{{ __($label) }}</option>@endforeach
                    </select>
                    <ul class="space-y-1">
                        @foreach ($candidates as $c)
                            <li><button type="button" wire:click="addMember({{ $c->id }})" class="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left hover:bg-sand-100">
                                @include('livewire.members.partials.avatar', ['member' => $c, 'size' => 'size-8 text-[11px]'])
                                <span class="flex-1 text-sm"><span class="font-semibold text-ink-700">{{ $c->officialName() }}</span> <span class="font-mono text-xs text-sand-700">{{ $c->number }}</span></span>
                                <x-icon name="plus" class="size-4 text-ink-600" />
                            </button></li>
                        @endforeach
                        @if (trim($memberSearch) !== '' && $candidates->isEmpty())
                            <li class="text-sm text-sand-700">{{ __('Aucun membre sans ménage ne correspond.') }}</li>
                        @endif
                    </ul>
                    <p class="hint">{{ __('Seules les personnes qui n’ont pas encore de ménage sont proposées.') }}</p>
                    <a href="{{ route('members.create') }}" class="inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="user-plus" class="size-4" /> {{ __('Inscrire une nouvelle personne') }}</a>
                </div>
            </section>
        @endif
    </div>

    @include('livewire.households.partials.form-modal', ['title' => __('Modifier le ménage')])
</div>
