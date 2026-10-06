<div>
    <a href="{{ route('users.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-bold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Utilisateurs') }}</a>
    <x-page-header :title="$user ? $user->name : __('Nouvel utilisateur')"
                   :description="$user ? null : __('La personne se connectera avec son numéro de téléphone et un mot de passe provisoire, qu’elle changera à la première connexion.')" />

    @if ($temporaryPassword)
        <div class="card mb-6 border-ochre-300 bg-ochre-50 p-5" x-data="{ copied: false }">
            <p class="font-bold text-ink-700">{{ __('Mot de passe provisoire') }}</p>
            <p class="mt-1 text-sm text-sand-700">{{ __('Communiquez-le à :name, de vive voix ou par WhatsApp. Il ne sera plus affiché.', ['name' => $user->name]) }}</p>
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <code class="rounded-xl bg-white px-4 py-2 font-mono text-2xl font-bold tracking-wider text-ink-700">{{ $temporaryPassword }}</code>
                <button type="button" class="btn-secondary" @click="navigator.clipboard?.writeText(@js($temporaryPassword)).then(() => copied = true)">
                    <x-icon name="check" class="size-4" x-show="copied" x-cloak /> <span x-text="copied ? '{{ __('Copié') }}' : '{{ __('Copier') }}'"></span>
                </button>
                <a target="_blank" rel="noopener" class="btn-secondary"
                   href="https://wa.me/{{ ltrim($user->phone, '+') }}?text={{ rawurlencode(__("Bonjour :name, votre compte Waumini est prêt. Connectez-vous sur :url avec votre numéro et le mot de passe provisoire : :password", ['name' => $user->name, 'url' => url('/'), 'password' => $temporaryPassword])) }}">
                    <x-icon name="share" class="size-4" /> {{ __('Envoyer sur WhatsApp') }}
                </a>
            </div>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-[1fr_1.1fr]">
        <form wire:submit="save" class="card space-y-4 self-start p-5 sm:p-6">
            <h2 class="text-lg font-semibold">{{ __('Identité') }}</h2>
            <div>
                <label for="name" class="label">{{ __('Nom complet') }}</label>
                <input wire:model="name" id="name" class="input" autocomplete="off" required>
                @error('name') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="phone" class="label">{{ __('Téléphone') }}</label>
                <input wire:model="phone" id="phone" type="tel" inputmode="tel" class="input" placeholder="0812 345 678" required>
                <p class="hint">{{ __('C’est son identifiant de connexion.') }}</p>
                @error('phone') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="email" class="label">{{ __('E-mail') }} <span class="font-normal text-sand-500">({{ __('facultatif') }})</span></label>
                <input wire:model="email" id="email" type="email" class="input">
                @error('email') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="locale" class="label">{{ __('Langue') }}</label>
                <select wire:model="locale" id="locale" class="input">
                    @foreach ($locales as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
                </select>
            </div>

            @unless ($user)
                <div class="rounded-xl bg-sand-50 p-4">
                    <p class="label">{{ __('Premier rôle') }}</p>
                    @include('livewire.users.role-picker')
                </div>
            @else
                <label class="flex items-center gap-3 text-sm">
                    <input wire:model="isActive" type="checkbox" class="size-5 rounded border-sand-300 text-ink-700">
                    {{ __('Compte actif (décochez pour bloquer l’accès sans rien supprimer)') }}
                </label>
                @error('isActive') <p class="error">{{ $message }}</p> @enderror
            @endunless

            <div class="flex flex-wrap justify-end gap-2 pt-2">
                @if ($user)
                    <button type="button" class="btn-ghost mr-auto" wire:click="resetPassword" wire:confirm="{{ __('Créer un nouveau mot de passe provisoire pour :name ?', ['name' => $user->name]) }}">
                        <x-icon name="key-round" class="size-4" /> {{ __('Réinitialiser le mot de passe') }}
                    </button>
                @endif
                <button type="submit" class="btn-primary"><x-icon name="save" class="size-4" /> {{ $user ? __('Enregistrer') : __('Créer le compte') }}</button>
            </div>
        </form>

        @if ($user)
            <section class="card self-start p-5 sm:p-6">
                <h2 class="text-lg font-semibold">{{ __('Rôles') }}</h2>
                <p class="mt-1 text-sm text-sand-700">{{ __('Un rôle s’applique à un niveau précis. Cochez « niveaux inférieurs » pour qu’il couvre aussi les régions, secteurs ou paroisses en dessous.') }}</p>

                <ul class="mt-4 divide-y divide-sand-100 rounded-xl border border-sand-200">
                    @forelse ($assignments as $assignment)
                        <li class="flex items-center gap-3 px-4 py-3">
                            <x-icon name="shield-check" class="size-5 text-ink-400" />
                            <div class="min-w-0 flex-1">
                                <p class="font-bold text-ink-700">{{ $assignment->role->name }}</p>
                                <p class="text-xs text-sand-700">{{ $assignment->organization->name }}@if ($assignment->includes_descendants) · {{ __('et niveaux inférieurs') }}@endif</p>
                            </div>
                            <button type="button" class="rounded-lg p-2 text-sand-500 hover:bg-terra-50 hover:text-terra-600" wire:click="removeRole({{ $assignment->id }})"
                                    wire:confirm="{{ __('Retirer le rôle :role ?', ['role' => $assignment->role->name]) }}" aria-label="{{ __('Retirer') }}">
                                <x-icon name="trash-2" class="size-4" />
                            </button>
                        </li>
                    @empty
                        <li class="px-4 py-3 text-sm text-sand-700">{{ __('Aucun rôle.') }}</li>
                    @endforelse
                </ul>

                <form wire:submit="addRole" class="mt-5 rounded-xl bg-sand-50 p-4">
                    <p class="label">{{ __('Attribuer un rôle') }}</p>
                    @include('livewire.users.role-picker')
                    <div class="mt-3 flex justify-end">
                        <button type="submit" class="btn-secondary"><x-icon name="plus" class="size-4" /> {{ __('Attribuer') }}</button>
                    </div>
                </form>
            </section>
        @endif
    </div>
</div>
