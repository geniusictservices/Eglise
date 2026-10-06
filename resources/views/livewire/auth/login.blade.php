<div>
    <h1 class="page-title">{{ __('Bienvenue') }}</h1>
    <p class="mt-2 text-sand-700">{{ __('Connectez-vous avec votre numéro de téléphone.') }}</p>

    <form wire:submit="login" class="mt-8 space-y-5">
        <div>
            <label for="phone" class="label">{{ __('Numéro de téléphone') }}</label>
            <div class="relative">
                <x-icon name="phone" class="pointer-events-none absolute left-3.5 top-1/2 size-5 -translate-y-1/2 text-sand-500" />
                <input wire:model="phone" id="phone" type="tel" inputmode="tel" autocomplete="tel" autofocus
                       placeholder="0812 345 678" @class(['input pl-11', 'input-error' => $errors->has('phone')])>
            </div>
            @error('phone') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div x-data="{ show: false }">
            <label for="password" class="label">{{ __('Mot de passe') }}</label>
            <div class="relative">
                <x-icon name="lock" class="pointer-events-none absolute left-3.5 top-1/2 size-5 -translate-y-1/2 text-sand-500" />
                <input wire:model="password" id="password" :type="show ? 'text' : 'password'" autocomplete="current-password"
                       @class(['input px-11', 'input-error' => $errors->has('password')])>
                <button type="button" @click="show = !show" class="absolute right-2 top-1/2 -translate-y-1/2 rounded-lg p-2 text-sand-500 hover:text-ink-700"
                        :aria-label="show ? '{{ __('Masquer le mot de passe') }}' : '{{ __('Afficher le mot de passe') }}'">
                    <x-icon name="eye" class="size-5" x-show="!show" />
                    <x-icon name="eye-off" class="size-5" x-show="show" x-cloak />
                </button>
            </div>
            @error('password') <p class="error">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-3 text-sm">
            <input wire:model="remember" type="checkbox" class="size-5 rounded border-sand-300 text-ink-700 focus:ring-ink-500">
            {{ __('Rester connecté sur cet appareil') }}
        </label>

        <button type="submit" class="btn-primary w-full !py-3 text-base" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="login">{{ __('Se connecter') }}</span>
            <span wire:loading wire:target="login">{{ __('Connexion…') }}</span>
        </button>
    </form>

    <div class="mt-8 rounded-2xl border border-sand-200 bg-white p-4 text-sm text-sand-700">
        <p class="flex gap-2"><x-icon name="info" class="size-5 text-ink-400" />
            <span>{{ __('Mot de passe oublié ? Demandez à l’administrateur de votre communauté de le réinitialiser.') }}</span></p>
    </div>

    <p class="mt-6 flex flex-col items-center gap-2 text-center text-sm">
        <a href="{{ route('install') }}" class="font-bold text-ink-600 underline-offset-4 hover:underline">{{ __('Installer Waumini sur votre téléphone ou ordinateur') }}</a>
        <a href="{{ route('help.index') }}" class="font-bold text-ink-600 underline-offset-4 hover:underline">{{ __('Manuel d’utilisation') }}</a>
    </p>
</div>
