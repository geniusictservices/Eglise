<div>
    <x-page-header :title="__('Équipe Genius ICT')" :description="__('Les personnes de Genius ICT qui accèdent à cet espace, et ce que chacune peut faire.')" />

    @if ($temporaryPassword)
        <div class="mb-5 rounded-2xl border border-leaf-100 bg-leaf-50 p-4 text-sm text-ink-800">
            {{ __('Mot de passe provisoire, à communiquer à la personne (il ne sera plus affiché) :') }}
            <code class="ml-1 select-all rounded bg-white px-2 py-1 font-mono text-base font-semibold text-ink-800">{{ $temporaryPassword }}</code>
        </div>
    @endif

    <div class="grid gap-5 lg:grid-cols-[1.4fr_1fr]">
        <section class="card p-5 sm:p-6">
            <ul class="divide-y divide-sand-100">
                @foreach ($staff as $member)
                    <li class="flex flex-wrap items-center gap-3 py-3" wire:key="s-{{ $member->id }}">
                        <span class="grid size-10 shrink-0 place-items-center rounded-full bg-ochre-100 text-sm font-semibold text-ochre-700">{{ $member->initials() }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-semibold text-ink-700">{{ $member->name }}</span>
                            <span class="block text-sm tabular text-sand-700">{{ $member->formattedPhone() }}</span>
                        </span>
                        <select wire:change="setRole({{ $member->id }}, $event.target.value)" class="input !min-h-0 w-auto !py-1.5 text-sm" aria-label="{{ __('Rôle') }}">
                            @foreach ($roles as $key => $role)<option value="{{ $key }}" @selected($member->platform_role === $key)>{{ __($role['name']) }}</option>@endforeach
                        </select>
                        @unless ($member->is(auth()->user()))
                            <button type="button" wire:click="remove({{ $member->id }})" wire:confirm="{{ __('Retirer :name de l’équipe ?', ['name' => $member->name]) }}" class="rounded-lg p-2 text-sand-500 hover:bg-terra-50 hover:text-terra-600" aria-label="{{ __('Retirer') }}"><x-icon name="x" class="size-4" /></button>
                        @endunless
                    </li>
                @endforeach
            </ul>
        </section>

        <div class="space-y-5">
            <section class="card p-5 sm:p-6">
                <h2 class="mb-3 text-lg">{{ __('Ajouter une personne') }}</h2>
                <form wire:submit="add" class="space-y-3">
                    <div><label for="st-phone" class="label">{{ __('Téléphone') }}</label><input wire:model="phone" id="st-phone" type="tel" class="input">@error('phone') <p class="error">{{ $message }}</p> @enderror
                        <p class="hint">{{ __('Si la personne a déjà un compte Waumini, il suffit de son numéro.') }}</p></div>
                    <div><label for="st-name" class="label">{{ __('Nom') }}</label><input wire:model="name" id="st-name" class="input">@error('name') <p class="error">{{ $message }}</p> @enderror</div>
                    <div><label for="st-role" class="label">{{ __('Rôle') }}</label>
                        <select wire:model="role" id="st-role" class="input">@foreach ($roles as $key => $r)<option value="{{ $key }}">{{ __($r['name']) }}</option>@endforeach</select></div>
                    <button class="btn-primary w-full"><x-icon name="user-plus" class="size-4" /> {{ __('Ajouter') }}</button>
                </form>
            </section>
            <section class="card p-5 text-sm sm:p-6">
                <h2 class="mb-3 text-lg">{{ __('Les rôles') }}</h2>
                @foreach ($roles as $role)
                    <p class="mt-2 font-semibold text-ink-800">{{ __($role['name']) }}</p>
                    <p class="text-sand-700">{{ in_array('*', $role['permissions'], true) ? __('Tout, y compris l’équipe.') : collect($role['permissions'])->map(fn ($p) => __($permissions[$p]))->implode(' ; ') }}</p>
                @endforeach
            </section>
        </div>
    </div>
</div>
