<div>
    <a href="{{ route('roles.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Rôles et permissions') }}</a>
    <x-page-header :title="$role ? $role->name : __('Nouveau rôle')" />

    @if ($readOnly)
        <div class="mb-6 flex gap-3 rounded-2xl border border-sand-200 bg-white p-4 text-sm text-sand-700">
            <x-icon name="info" class="size-5 text-ink-400" />
            <p>{{ $role->is_locked ? __('Le rôle Administrateur donne toujours accès à tout ; il ne peut pas être modifié.') : __('Ce rôle est défini par un niveau supérieur. Dupliquez-le pour l’adapter à votre communauté.') }}</p>
        </div>
    @endif

    <form wire:submit="save" class="space-y-6">
        <fieldset class="card grid gap-4 p-5 sm:grid-cols-2 sm:p-6" @disabled($readOnly)>
            <div>
                <label for="name" class="label">{{ __('Nom du rôle') }}</label>
                <input wire:model="name" id="name" class="input" placeholder="{{ __('Trésorier adjoint') }}" required>
                @error('name') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="description" class="label">{{ __('Description') }}</label>
                <input wire:model="description" id="description" class="input" placeholder="{{ __('Ce que fait cette personne') }}">
            </div>
        </fieldset>

        <div>
            <div class="mb-3 flex items-end justify-between gap-3">
                <h2 class="text-lg font-semibold">{{ __('Permissions') }}</h2>
                <p class="text-sm text-sand-700"><span class="font-semibold text-ink-700 tabular">{{ count($permissions) }}</span> {{ __('cochées') }}</p>
            </div>
            @error('permissions') <p class="error mb-3">{{ $message }}</p> @enderror
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($groups as $key => $group)
                    @php $checked = count(array_intersect(array_keys($group['items']), $permissions)); @endphp
                    <fieldset class="card p-5" @disabled($readOnly)>
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <legend class="font-display font-semibold text-ink-700">{{ __($group['label']) }}</legend>
                            @unless ($readOnly)
                                <button type="button" wire:click="toggleGroup('{{ $key }}')" class="text-xs font-semibold text-ink-600 hover:underline">
                                    {{ $checked === count($group['items']) ? __('Tout décocher') : __('Tout cocher') }}
                                </button>
                            @endunless
                        </div>
                        <div class="space-y-1">
                            @foreach ($group['items'] as $permission => $label)
                                <label class="flex cursor-pointer items-start gap-3 rounded-lg px-2 py-2 hover:bg-sand-50">
                                    <input type="checkbox" wire:model.live="permissions" value="{{ $permission }}" class="mt-0.5 size-5 shrink-0 rounded border-sand-300 text-ink-700">
                                    <span class="text-sm">{{ __($label) }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach
            </div>
        </div>

        @unless ($readOnly)
            <div class="sticky bottom-20 z-10 flex flex-wrap justify-end gap-2 rounded-2xl border border-sand-200 bg-white/95 p-3 backdrop-blur lg:bottom-4">
                @if ($role)
                    <button type="button" class="btn-ghost mr-auto text-terra-600" wire:click="delete" wire:confirm="{{ __('Supprimer ce rôle ?') }}"><x-icon name="trash-2" class="size-4" /> {{ __('Supprimer') }}</button>
                @endif
                <a href="{{ route('roles.index') }}" class="btn-ghost">{{ __('Annuler') }}</a>
                <button type="submit" class="btn-primary"><x-icon name="save" class="size-4" /> {{ __('Enregistrer le rôle') }}</button>
            </div>
        @endunless
    </form>
</div>
