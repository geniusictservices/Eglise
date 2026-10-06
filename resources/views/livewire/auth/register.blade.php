<div>
    <p class="eyebrow">{{ __('Essai gratuit de 30 jours') }}</p>
    <h1 class="page-title mt-1">{{ __('Créer le compte de mon église') }}</h1>
    <p class="mt-2 text-sand-700">{{ __('Deux minutes suffisent. Vous deviendrez l’administrateur de votre communauté sur Waumini.') }}</p>

    <ol class="mt-6 flex items-center gap-3 text-sm" aria-label="{{ __('Étapes') }}">
        @foreach ([1 => __('Votre communauté'), 2 => __('Votre compte')] as $n => $label)
            <li class="flex items-center gap-2">
                <span @class(['grid size-7 place-items-center rounded-full text-xs font-semibold', 'bg-ochre-500 text-[#2A1B04]' => $step >= $n, 'bg-sand-200 text-sand-700' => $step < $n])>{{ $n }}</span>
                <span @class(['font-semibold text-ink-800' => $step === $n, 'text-sand-700' => $step !== $n])>{{ $label }}</span>
            </li>
            @if ($n === 1)<li class="h-px w-8 bg-sand-300" aria-hidden="true"></li>@endif
        @endforeach
    </ol>

    @if ($step === 1)
        <form wire:submit="next" class="mt-6 space-y-5">
            <fieldset>
                <legend class="label">{{ __('Vous inscrivez…') }}</legend>
                <div class="space-y-2">
                    @foreach ($kinds as $value => $option)
                        <label @class(['flex cursor-pointer items-start gap-3 rounded-2xl border-[1.5px] p-3.5', 'border-ochre-500 bg-ochre-50' => $this->kind === $value, 'border-sand-300 bg-white' => $this->kind !== $value])>
                            <input type="radio" wire:model.live="kind" value="{{ $value }}" class="mt-0.5 size-5">
                            <span class="text-sm font-medium text-ink-800">{{ __($option['label']) }}</span>
                        </label>
                    @endforeach
                </div>
                @if ($this->kind === 'parish')
                    <p class="hint">{{ __('Après l’inscription, vous demanderez à rejoindre votre siège avec son code de rattachement. Vos données resteront les vôtres.') }}</p>
                @endif
            </fieldset>
            <div>
                <label for="communityName" class="label">{{ __('Nom de la communauté') }}</label>
                <input wire:model="communityName" id="communityName" class="input" placeholder="{{ __('Église Béthel de Ndosho') }}">
                @error('communityName') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="city" class="label">{{ __('Ville') }}</label>
                    <input wire:model="city" id="city" class="input" placeholder="Goma">
                    @error('city') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="province" class="label">{{ __('Province') }} <span class="font-normal text-sand-500">({{ __('facultatif') }})</span></label>
                    <input wire:model="province" id="province" class="input" placeholder="Nord-Kivu">
                </div>
            </div>
            <button type="submit" class="btn-primary w-full !py-3 text-base">{{ __('Continuer') }} <x-icon name="arrow-right" class="size-4" /></button>
        </form>
    @else
        <form wire:submit="register" class="mt-6 space-y-5">
            <p class="rounded-2xl bg-white p-3.5 text-sm text-sand-700 ring-1 ring-sand-200">
                <span class="font-semibold text-ink-800">{{ $communityName }}</span> · {{ $city }}
                <button type="button" wire:click="back" class="ml-1 font-semibold text-ink-600 underline-offset-4 hover:underline">{{ __('Modifier') }}</button>
            </p>
            <div>
                <label for="name" class="label">{{ __('Votre nom complet') }}</label>
                <input wire:model="name" id="name" class="input" autocomplete="name">
                @error('name') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="phone" class="label">{{ __('Votre numéro de téléphone') }}</label>
                <input wire:model="phone" id="phone" type="tel" inputmode="tel" class="input" placeholder="0812 345 678" autocomplete="tel">
                <p class="hint">{{ __('C’est votre identifiant pour vous connecter.') }}</p>
                @error('phone') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="password" class="label">{{ __('Mot de passe') }}</label>
                    <input wire:model="password" id="password" type="password" class="input" autocomplete="new-password">
                    @error('password') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="passwordConfirmation" class="label">{{ __('Confirmer') }}</label>
                    <input wire:model="passwordConfirmation" id="passwordConfirmation" type="password" class="input" autocomplete="new-password">
                </div>
            </div>
            <p class="hint -mt-3">{{ __('Au moins 8 caractères, avec des lettres et des chiffres.') }}</p>
            <label class="flex items-start gap-3 text-sm">
                <input wire:model="accept" type="checkbox" class="mt-0.5 size-5">
                <span>{!! __('J’accepte les :terms et la :privacy de Waumini.', ['terms' => '<a href="'.route('legal.terms').'" target="_blank" class="font-semibold text-ink-600 underline">'.e(__('conditions d’utilisation')).'</a>', 'privacy' => '<a href="'.route('legal.privacy').'" target="_blank" class="font-semibold text-ink-600 underline">'.e(__('politique de confidentialité')).'</a>']) !!}</span>
            </label>
            @error('accept') <p class="error -mt-3">{{ $message }}</p> @enderror
            <div class="hidden" aria-hidden="true"><input wire:model="website" tabindex="-1" autocomplete="off"></div>
            <button type="submit" class="btn-accent w-full !py-3 text-base" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="register">{{ __('Créer le compte et commencer l’essai') }}</span>
                <span wire:loading wire:target="register">{{ __('Création…') }}</span>
            </button>
        </form>
    @endif

    <p class="mt-8 text-center text-sm text-sand-700">{{ __('Déjà un compte ?') }} <a href="{{ route('login') }}" class="font-semibold text-ink-600 hover:underline">{{ __('Se connecter') }}</a></p>
</div>
