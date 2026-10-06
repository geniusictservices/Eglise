<div>
    <x-page-header :title="__('Paramètres')" :eyebrow="$organization->level_label.' · '.$organization->name" />

    <div class="mb-6 flex gap-1 overflow-x-auto rounded-2xl border border-sand-200 bg-white p-1" role="tablist">
        @foreach (['general' => __('Informations'), 'libelles' => __('Libellés'), 'support' => __('Support')] as $key => $label)
            <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    @class(['flex-1 whitespace-nowrap rounded-xl px-4 py-2 text-sm font-bold', 'bg-ink-700 text-white' => $tab === $key, 'text-ink-600 hover:bg-sand-50' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'general')
        <form wire:submit="saveGeneral" class="card grid gap-4 p-5 sm:grid-cols-2 sm:p-6">
            <div class="sm:col-span-2">
                <label for="name" class="label">{{ __('Nom complet') }}</label>
                <input wire:model="name" id="name" class="input" required>
                @error('name') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="shortName" class="label">{{ __('Nom court') }}</label>
                <input wire:model="shortName" id="shortName" class="input" placeholder="{{ __('Utilisé dans les menus') }}">
            </div>
            <div>
                <label for="levelLabel" class="label">{{ __('Type de niveau') }}</label>
                <input wire:model="levelLabel" id="levelLabel" class="input" list="levels" required>
                <datalist id="levels">@foreach (config('waumini.level_suggestions') as $s)<option value="{{ $s }}">@endforeach</datalist>
            </div>
            <div>
                <label for="phone" class="label">{{ __('Téléphone') }}</label>
                <input wire:model="phone" id="phone" type="tel" class="input">
            </div>
            <div>
                <label for="email" class="label">{{ __('E-mail') }}</label>
                <input wire:model="email" id="email" type="email" class="input">
                @error('email') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="province" class="label">{{ __('Province') }}</label>
                <input wire:model="province" id="province" class="input" placeholder="Nord-Kivu">
            </div>
            <div>
                <label for="city" class="label">{{ __('Ville') }}</label>
                <input wire:model="city" id="city" class="input" placeholder="Goma">
            </div>
            <div class="sm:col-span-2">
                <label for="address" class="label">{{ __('Adresse') }}</label>
                <input wire:model="address" id="address" class="input" placeholder="{{ __('Quartier, avenue, numéro') }}">
            </div>
            <div>
                <label for="locale" class="label">{{ __('Langue par défaut') }}</label>
                <select wire:model="locale" id="locale" class="input">@foreach ($locales as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select>
            </div>
            <div>
                <label for="timezone" class="label">{{ __('Fuseau horaire') }}</label>
                <select wire:model="timezone" id="timezone" class="input">
                    <option value="Africa/Lubumbashi">{{ __('Est de la RDC (Goma, Bukavu, Lubumbashi)') }}</option>
                    <option value="Africa/Kinshasa">{{ __('Ouest de la RDC (Kinshasa, Matadi)') }}</option>
                    <option value="Africa/Kigali">Kigali</option><option value="Africa/Kampala">Kampala</option>
                    <option value="Africa/Bujumbura">Bujumbura</option><option value="Africa/Nairobi">Nairobi</option>
                    <option value="Europe/Brussels">Bruxelles</option><option value="Europe/Paris">Paris</option><option value="UTC">UTC</option>
                </select>
            </div>
            <div class="flex justify-end sm:col-span-2">
                <button type="submit" class="btn-primary"><x-icon name="save" class="size-4" /> {{ __('Enregistrer') }}</button>
            </div>
        </form>
    @elseif ($tab === 'libelles')
        <form wire:submit="saveTerms" class="card p-5 sm:p-6">
            <p class="max-w-2xl text-sm text-sand-700">{{ __('Waumini utilise vos propres mots. Renommez un libellé et il change partout dans l’application, pour votre communauté et ses niveaux inférieurs. Laissez vide pour garder le libellé par défaut.') }}</p>
            <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($defaults as $key => $default)
                    <div>
                        <label for="term-{{ $key }}" class="label">{{ $default }}</label>
                        <input wire:model="terms.{{ $key }}" id="term-{{ $key }}" class="input" placeholder="{{ $inherited[$key] }}">
                    </div>
                @endforeach
            </div>
            <div class="mt-6 flex justify-end">
                <button type="submit" class="btn-primary"><x-icon name="save" class="size-4" /> {{ __('Enregistrer les libellés') }}</button>
            </div>
        </form>
    @else
        <section class="card p-5 sm:p-6">
            <h2 class="text-lg font-semibold">{{ __('Accès du support Genius ICT') }}</h2>
            <p class="mt-1 max-w-2xl text-sm text-sand-700">{{ __('Pour vous aider, un agent Genius ICT peut voir votre communauté comme vous la voyez. Il n’y accède qu’avec votre accord, pendant 7 jours au plus, et vous pouvez retirer cet accord à tout moment. Chaque accès est inscrit au journal. Les notes pastorales confidentielles ne lui sont jamais visibles.') }}</p>
            @can('support.grant')
                <label class="mt-5 flex items-center gap-3">
                    <input wire:model.live="supportAccess" type="checkbox" class="size-6 rounded border-sand-300 text-ink-700">
                    <span class="font-bold text-ink-700">{{ __('Autoriser l’accès du support') }}</span>
                </label>
                @if ($organization->support_access_until?->isFuture())
                    <p class="mt-2 text-sm text-ochre-700">{{ __('Accès autorisé jusqu’au :date.', ['date' => $organization->support_access_until->timezone($organization->timezone)->translatedFormat('j F Y à H:i')]) }}</p>
                @endif
            @endcan
        </section>
    @endif
</div>
