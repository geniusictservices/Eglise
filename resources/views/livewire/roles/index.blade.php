<div>
    <x-page-header :title="__('Rôles et permissions')"
                   :description="__('Un rôle regroupe des permissions. Waumini propose des rôles modèles ; modifiez-les ou créez les vôtres, par exemple « Trésorier adjoint ».')">
        <x-slot:actions>
            <a href="{{ route('roles.create') }}" class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Créer un rôle') }}</a>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($roles as $role)
            @php $editable = $role->organization_id === $organization->id && ! $role->is_locked; @endphp
            <article class="card flex flex-col p-5">
                <div class="flex items-start justify-between gap-3">
                    <h2 class="text-lg font-semibold">{{ $role->name }}</h2>
                    @if ($role->is_locked)
                        <span class="badge bg-ink-700 text-white"><x-icon name="lock" class="size-3" /> {{ __('Complet') }}</span>
                    @endif
                </div>
                <p class="mt-2 flex-1 text-sm text-sand-700">{{ $role->description }}</p>
                <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                    <span class="text-ink-700"><span class="font-semibold tabular">{{ count($role->grantedPermissions()) }}</span>/{{ $total }} {{ __('permissions') }}</span>
                    <span class="text-ink-700"><span class="font-semibold tabular">{{ $role->assignments_count }}</span> {{ trans_choice('attribution|attributions', $role->assignments_count) }}</span>
                </div>
                @if ($role->organization_id !== $organization->id)
                    <p class="mt-2 text-xs text-sand-500">{{ __('Défini par :name', ['name' => $role->organization->name]) }}</p>
                @endif
                <div class="mt-4 flex gap-2 border-t border-sand-100 pt-4">
                    <a href="{{ route('roles.edit', $role) }}" class="btn-secondary !min-h-0 flex-1 !py-2">{{ $editable ? __('Modifier') : __('Voir') }}</a>
                    <a href="{{ route('roles.create', ['depuis' => $role->id]) }}" class="btn-ghost !min-h-0 !py-2" title="{{ __('Créer un rôle à partir de celui-ci') }}">{{ __('Dupliquer') }}</a>
                </div>
            </article>
        @endforeach
    </div>
</div>
