<div>
    <a href="{{ route('departments.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Départements') }}</a>

    <section class="wax wax-veil wax-veil-strong mb-5 overflow-hidden rounded-[22px] p-5 text-white sm:p-6">
        <div class="flex flex-wrap items-center gap-4">
            <span class="icon-tile size-14 bg-white/15 text-ochre-300"><x-icon :name="$department->kind === 'administrative' ? 'briefcase' : 'users-round'" class="size-7" /></span>
            <div class="min-w-0 flex-1">
                <p class="text-sm text-ochre-300">{{ __(\App\Models\Department::KINDS[$department->kind]) }}@unless ($department->is_active) · {{ __('En sommeil') }}@endunless</p>
                <h1 class="text-2xl font-semibold text-white">{{ $department->name }}</h1>
                @if ($department->description)<p class="mt-1 max-w-2xl text-sm text-ink-100">{{ $department->description }}</p>@endif
            </div>
            @if ($canManage)
                <div class="flex w-full flex-wrap gap-2 sm:w-auto">
                    <button type="button" wire:click="edit" class="btn-accent !min-h-0 !py-2"><x-icon name="pencil" class="size-4" /> {{ __('Modifier') }}</button>
                    @unless ($department->is_system)
                        <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                            <button type="button" @click="open = ! open" class="btn !min-h-0 bg-white/15 !px-3 !py-2 text-white hover:bg-white/25" aria-label="{{ __('Plus d’actions') }}"><x-icon name="ellipsis" class="size-4" /></button>
                            <div x-cloak x-show="open" x-transition class="absolute right-0 z-20 mt-2 w-64 rounded-2xl border border-sand-200 bg-white p-1.5 text-ink-800 shadow-xl">
                                <button type="button" wire:click="toggleActive" class="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left text-sm hover:bg-sand-50">
                                    <x-icon :name="$department->is_active ? 'archive' : 'archive-restore'" class="size-4" /> {{ $department->is_active ? __('Mettre en sommeil') : __('Réactiver') }}
                                </button>
                                <button type="button" wire:click="delete" wire:confirm="{{ __('Supprimer ce département ?') }}" class="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left text-sm text-terra-600 hover:bg-terra-50">
                                    <x-icon name="trash-2" class="size-4" /> {{ __('Supprimer') }}
                                </button>
                            </div>
                        </div>
                    @endunless
                </div>
            @endif
        </div>
    </section>

    <div class="grid gap-5 lg:grid-cols-[1.4fr_1fr]">
        <section class="card p-5 sm:p-6">
            <h2 class="mb-3 text-lg">{{ trans_choice(':count membre|:count membres', $members->count()) }}</h2>
            <ul class="divide-y divide-sand-100">
                @forelse ($members as $m)
                    <li class="flex flex-wrap items-center gap-3 py-3" wire:key="dm-{{ $m->id }}">
                        <a href="{{ route('members.show', $m) }}" class="flex min-w-0 basis-full items-center gap-3 sm:basis-auto sm:flex-1">
                            @include('livewire.members.partials.avatar', ['member' => $m, 'size' => 'size-10 text-sm'])
                            <span class="min-w-0">
                                <span class="block truncate font-semibold text-ink-700">{{ $m->officialName() }}
                                    @if ($m->pivot->role === 'leader')<x-icon name="badge-check" class="inline size-4 text-ochre-600" />@endif</span>
                                <span class="block text-sm text-sand-700 tabular">{{ \App\Support\Phone::format($m->phone) ?: $m->number }}</span>
                            </span>
                        </a>
                        @if ($canManage)
                            <div class="ml-[52px] flex items-center gap-2 sm:ml-0">
                                <select wire:change="setRole({{ $m->id }}, $event.target.value)" class="input !min-h-0 w-auto !py-1.5 text-sm" aria-label="{{ __('Rôle dans le département') }}">
                                    @foreach (\App\Models\Department::ROLES as $key => $label)<option value="{{ $key }}" @selected($m->pivot->role === $key)>{{ __($label) }}</option>@endforeach
                                </select>
                                <button type="button" wire:click="removeMember({{ $m->id }})" wire:confirm="{{ __('Retirer :name du département ?', ['name' => $m->fullName()]) }}" class="rounded-lg p-2 text-sand-500 hover:bg-terra-50 hover:text-terra-600" aria-label="{{ __('Retirer') }}"><x-icon name="x" class="size-4" /></button>
                            </div>
                        @else
                            <span class="badge bg-sand-100 text-sand-700">{{ __(\App\Models\Department::ROLES[$m->pivot->role] ?? '') }}</span>
                        @endif
                    </li>
                @empty
                    <li class="py-3 text-sm text-sand-700">{{ __('Aucun membre pour l’instant.') }}</li>
                @endforelse
            </ul>
        </section>

        <div class="space-y-5">
            @if ($canManage)
                <section class="card p-5 sm:p-6">
                    <h2 class="mb-3 text-lg">{{ __('Ajouter un membre') }}</h2>
                    <div class="space-y-3">
                        <input wire:model.live.debounce.300ms="memberSearch" type="search" class="input" placeholder="{{ __('Nom, numéro ou téléphone') }}" aria-label="{{ __('Rechercher un membre') }}">
                        <select wire:model="newRole" class="input" aria-label="{{ __('Rôle dans le département') }}">
                            @foreach (\App\Models\Department::ROLES as $key => $label)<option value="{{ $key }}">{{ __($label) }}</option>@endforeach
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
                                <li class="text-sm text-sand-700">{{ __('Aucun membre ne correspond.') }}</li>
                            @endif
                        </ul>
                    </div>
                </section>
            @endif
            <section class="card p-5 sm:p-6">
                <h2 class="mb-3 text-lg">{{ __('Groupes du département') }}</h2>
                <ul class="space-y-2">
                    @forelse ($groups as $g)
                        <li><a href="{{ route('groups.show', $g) }}" class="flex items-center gap-3 rounded-xl px-2 py-1.5 text-sm hover:bg-sand-50">
                            <span class="icon-tile size-9 bg-ink-50 text-ink-700"><x-icon name="handshake" class="size-4" /></span>
                            <span class="min-w-0 flex-1"><span class="block truncate font-semibold text-ink-800">{{ $g->name }}</span><span class="block truncate text-xs text-sand-700">{{ $g->leader?->fullName() }} · {{ trans_choice(':count personne|:count personnes', $g->members_count + 1) }}</span></span>
                        </a></li>
                    @empty
                        <li class="text-sm text-sand-700">{{ __('Aucun groupe rattaché à ce département.') }}</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>

    @include('livewire.departments.partials.form-modal', ['title' => __('Modifier le département'), 'system' => $department->is_system])
</div>
