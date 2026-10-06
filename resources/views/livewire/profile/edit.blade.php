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
</div>
