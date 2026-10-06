<div>
    <x-page-header :title="__('Utilisateurs')"
                   :description="__('Les personnes qui se connectent à Waumini pour :name et ses niveaux inférieurs, avec leurs rôles.', ['name' => $organization->name])">
        <x-slot:actions>
            @can('users.manage')
                <a href="{{ route('users.create') }}" class="btn-primary"><x-icon name="user-plus" class="size-4" /> {{ __('Ajouter un utilisateur') }}</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4">
        <label for="search" class="sr-only">{{ __('Rechercher') }}</label>
        <div class="relative max-w-md">
            <x-icon name="search" class="pointer-events-none absolute left-3.5 top-1/2 size-5 -translate-y-1/2 text-sand-500" />
            <input wire:model.live.debounce.300ms="search" id="search" type="search" class="input pl-11" placeholder="{{ __('Nom ou téléphone') }}">
        </div>
    </div>

    {{-- Tableau (ordinateur) --}}
    <div class="card hidden overflow-hidden md:block">
        <table class="table">
            <thead>
                <tr>
                    <th>{{ __('Nom') }}</th>
                    <th>{{ __('Téléphone') }}</th>
                    <th>{{ __('Rôles') }}</th>
                    <th>{{ __('Dernière connexion') }}</th>
                    <th><span class="sr-only">{{ __('Actions') }}</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    <tr class="hover:bg-sand-50/60">
                        <td>
                            <div class="flex items-center gap-3">
                                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-ochre-100 font-display text-xs font-semibold text-ochre-700">{{ $user->initials() }}</span>
                                <div>
                                    <p class="font-semibold text-ink-700">{{ $user->name }}</p>
                                    @unless ($user->is_active)<span class="badge bg-sand-100 text-sand-700">{{ __('Désactivé') }}</span>@endunless
                                </div>
                            </div>
                        </td>
                        <td class="tabular whitespace-nowrap">{{ $user->formattedPhone() }}</td>
                        <td>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($user->roleAssignments as $assignment)
                                    <span class="badge bg-ink-50 text-ink-700">{{ $assignment->role->name }}
                                        @if ($assignment->organization_id !== $organization->id)<span class="font-normal text-ink-400">· {{ $assignment->organization->displayName() }}</span>@endif
                                    </span>
                                @endforeach
                            </div>
                        </td>
                        <td class="whitespace-nowrap text-sand-700">{{ $user->last_login_at?->diffForHumans() ?? __('Jamais') }}</td>
                        <td class="text-right">
                            @can('users.manage')
                                <a href="{{ route('users.edit', $user) }}" class="btn-ghost !min-h-0 !px-3 !py-1.5">{{ __('Modifier') }}</a>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-10 text-center text-sand-700">{{ __('Aucun utilisateur ne correspond à cette recherche.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Cartes (téléphone) --}}
    <ul class="space-y-3 md:hidden">
        @forelse ($users as $user)
            <li>
                <a @can('users.manage') href="{{ route('users.edit', $user) }}" @endcan class="card flex items-start gap-3 p-4">
                    <span class="grid size-10 shrink-0 place-items-center rounded-full bg-ochre-100 font-display text-sm font-semibold text-ochre-700">{{ $user->initials() }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-semibold text-ink-700">{{ $user->name }}</p>
                        <p class="text-sm text-sand-700 tabular">{{ $user->formattedPhone() }}</p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach ($user->roleAssignments as $assignment)
                                <span class="badge bg-ink-50 text-ink-700">{{ $assignment->role->name }}</span>
                            @endforeach
                        </div>
                    </div>
                    @can('users.manage')<x-icon name="chevron-right" class="mt-2 size-5 text-sand-300" />@endcan
                </a>
            </li>
        @empty
            <li class="card p-6 text-center text-sand-700">{{ __('Aucun utilisateur ne correspond à cette recherche.') }}</li>
        @endforelse
    </ul>

    <div class="mt-4">{{ $users->links() }}</div>
</div>
