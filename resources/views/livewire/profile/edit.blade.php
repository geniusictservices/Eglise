<div>
    <x-page-header :title="__('Mon profil')" />

    @if ($user->must_change_password)
        <div class="mb-6 flex gap-3 rounded-2xl border border-ochre-300 bg-ochre-50 p-4 text-sm">
            <x-icon name="key-round" class="size-5 text-ochre-700" />
            <p><span class="font-bold text-ink-700">{{ __('Choisissez votre mot de passe.') }}</span> {{ __('Vous vous êtes connecté avec un mot de passe provisoire : remplacez-le par un mot de passe que vous seul connaissez.') }}</p>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <form wire:submit="saveProfile" class="card space-y-4 self-start p-5 sm:p-6">
            <h2 class="text-lg font-semibold">{{ __('Informations') }}</h2>
            <div>
                <label for="name" class="label">{{ __('Nom complet') }}</label>
                <input wire:model="name" id="name" class="input" required>
                @error('name') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <p class="label">{{ __('Téléphone') }}</p>
                <p class="rounded-xl bg-sand-50 px-3.5 py-2.5 tabular">{{ $user->formattedPhone() }}</p>
                <p class="hint">{{ __('Pour changer de numéro, demandez à l’administrateur.') }}</p>
            </div>
            <div>
                <label for="email" class="label">{{ __('E-mail') }} <span class="font-normal text-sand-500">({{ __('facultatif') }})</span></label>
                <input wire:model="email" id="email" type="email" class="input">
                @error('email') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="locale" class="label">{{ __('Langue de l’application') }}</label>
                <select wire:model="locale" id="locale" class="input">@foreach ($locales as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select>
            </div>
            <div class="flex justify-end"><button type="submit" class="btn-primary">{{ __('Enregistrer') }}</button></div>
        </form>

        <form wire:submit="changePassword" class="card space-y-4 self-start p-5 sm:p-6">
            <h2 class="text-lg font-semibold">{{ __('Mot de passe') }}</h2>
            <div>
                <label for="currentPassword" class="label">{{ __('Mot de passe actuel') }}</label>
                <input wire:model="currentPassword" id="currentPassword" type="password" autocomplete="current-password" class="input">
                @error('currentPassword') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="password" class="label">{{ __('Nouveau mot de passe') }}</label>
                <input wire:model="password" id="password" type="password" autocomplete="new-password" class="input">
                <p class="hint">{{ __('Au moins 8 caractères, avec des lettres et des chiffres.') }}</p>
                @error('password') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="passwordConfirmation" class="label">{{ __('Confirmer le nouveau mot de passe') }}</label>
                <input wire:model="passwordConfirmation" id="passwordConfirmation" type="password" autocomplete="new-password" class="input">
            </div>
            <div class="flex justify-end"><button type="submit" class="btn-primary">{{ __('Changer le mot de passe') }}</button></div>
        </form>
    </div>

    <section id="empreinte" class="card mt-6 p-5 sm:p-6" x-data="passkeyRegister"
             x-on:passkey-confirmed.window="register($event.detail.name, () => $wire.passkeyAdded())">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-2xl">
                <h2 class="flex items-center gap-2 text-lg font-semibold"><x-icon name="fingerprint" class="size-5 text-ink-400" /> {{ __('Connexion par empreinte') }}</h2>
                <p class="mt-1 text-sm text-sand-700">{{ __('Connectez-vous sans taper votre mot de passe : avec l’empreinte digitale ou le visage sur votre téléphone, ou avec Windows Hello sur l’ordinateur. Activez-la sur chaque appareil que vous utilisez.') }}</p>
            </div>
            <button type="button" class="btn-secondary" x-show="supported" @click="$dispatch('open-modal', { name: 'add-passkey' })">
                <x-icon name="plus" class="size-4" /> {{ __('Activer sur cet appareil') }}
            </button>
        </div>
        <p class="mt-3 text-sm font-bold text-terra-600" x-show="!supported" x-cloak>{{ __('Ce navigateur ne permet pas la connexion par empreinte.') }}</p>
        <p class="error" x-show="error" x-text="error" x-cloak></p>

        <ul class="mt-4 divide-y divide-sand-100 rounded-xl border border-sand-200">
            @forelse ($passkeys as $passkey)
                <li class="flex items-center gap-3 px-4 py-3" wire:key="pk-{{ $passkey->id }}">
                    <x-icon name="smartphone" class="size-5 text-ink-400" />
                    <div class="min-w-0 flex-1">
                        <p class="font-bold text-ink-700">{{ $passkey->name }}</p>
                        <p class="text-xs text-sand-700">{{ __('Activée :date', ['date' => $passkey->created_at->diffForHumans()]) }}@if ($passkey->last_used_at) · {{ __('dernière utilisation :date', ['date' => $passkey->last_used_at->diffForHumans()]) }}@endif</p>
                    </div>
                    <button type="button" class="rounded-lg p-2 text-sand-500 hover:bg-terra-50 hover:text-terra-600" wire:click="deletePasskey({{ $passkey->id }})"
                            wire:confirm="{{ __('Retirer « :name » ? Cet appareil devra de nouveau utiliser le mot de passe.', ['name' => $passkey->name]) }}" aria-label="{{ __('Retirer') }}">
                        <x-icon name="trash-2" class="size-4" />
                    </button>
                </li>
            @empty
                <li class="px-4 py-3 text-sm text-sand-700">{{ __('Aucun appareil pour le moment.') }}</li>
            @endforelse
        </ul>

        <x-modal name="add-passkey" :title="__('Activer l’empreinte sur cet appareil')">
            <form wire:submit="confirmForPasskey" class="space-y-4">
                <div>
                    <label for="passkeyName" class="label">{{ __('Nom de l’appareil') }}</label>
                    <input wire:model="passkeyName" id="passkeyName" class="input" placeholder="{{ __('Mon téléphone, Ordinateur du secrétariat…') }}">
                    @error('passkeyName') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="passkeyPassword" class="label">{{ __('Votre mot de passe, pour confirmer') }}</label>
                    <input wire:model="passkeyPassword" id="passkeyPassword" type="password" autocomplete="current-password" class="input">
                    @error('passkeyPassword') <p class="error">{{ $message }}</p> @enderror
                </div>
                <p class="text-sm text-sand-700">{{ __('Votre appareil va ensuite vous demander votre empreinte, votre visage ou votre code.') }}</p>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'add-passkey' })">{{ __('Annuler') }}</button>
                    <button type="submit" class="btn-primary">{{ __('Continuer') }}</button>
                </div>
            </form>
        </x-modal>
    </section>
</div>
