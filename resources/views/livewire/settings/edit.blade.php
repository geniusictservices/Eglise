<div>
    <x-page-header :title="__('Paramètres')" :eyebrow="$organization->level_label.' · '.$organization->name" />

    <div class="mb-6 flex gap-1 overflow-x-auto rounded-2xl border border-sand-200 bg-white p-1" role="tablist">
        @foreach (['general' => __('Informations'), 'apparence' => __('Apparence'), 'libelles' => __('Libellés'), 'support' => __('Support')] as $key => $label)
            <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    @class(['flex-1 whitespace-nowrap rounded-xl px-4 py-2 text-sm font-semibold', 'bg-ink-700 text-white' => $tab === $key, 'text-ink-600 hover:bg-sand-50' => $tab !== $key])>{{ $label }}</button>
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
    @elseif ($tab === 'apparence')
        <div class="grid gap-6 lg:grid-cols-[1.1fr_1fr]">
            <form wire:submit="saveTheme" class="card space-y-6 p-5 sm:p-6">
                <div>
                    <h2 class="text-lg">{{ __('Couleurs de votre espace') }}</h2>
                    <p class="mt-1 text-sm text-sand-700">{{ __('Elles s’appliquent à tous les utilisateurs de :name et de ses niveaux inférieurs, sur téléphone comme sur ordinateur.', ['name' => $organization->name]) }}</p>
                    @unless ($hasOwnTheme)
                        <p class="mt-2 text-sm text-ochre-700">{{ $organization->isRoot() ? __('Vous utilisez les couleurs de Waumini.') : __('Vous utilisez les couleurs de votre niveau supérieur.') }}</p>
                    @endunless
                </div>

                <fieldset>
                    <legend class="label">{{ __('Palettes prêtes') }}</legend>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-2 xl:grid-cols-4">
                        @foreach ($presets as $key => $palette)
                            <button type="button" wire:click="choosePreset('{{ $key }}')" @class(['rounded-2xl border-[1.5px] p-2 text-left transition', 'border-ochre-500 ring-2 ring-ochre-500/30' => $preset === $key, 'border-sand-300 hover:border-ochre-300' => $preset !== $key]) aria-pressed="{{ $preset === $key ? 'true' : 'false' }}">
                                <span class="flex h-10 overflow-hidden rounded-xl">
                                    <span class="flex-[3]" style="background: {{ $palette['primary'] }}"></span>
                                    <span class="flex-1" style="background: {{ $palette['accent'] }}"></span>
                                </span>
                                <span class="mt-1.5 block text-sm font-medium text-ink-800">{{ __($palette['name']) }}</span>
                            </button>
                        @endforeach
                    </div>
                </fieldset>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="primaryColor" class="label">{{ __('Couleur principale') }}</label>
                        <div class="flex gap-2">
                            <input type="color" wire:model.live="primaryColor" class="h-11 w-14 shrink-0 cursor-pointer rounded-xl border border-sand-300 bg-white p-1" aria-label="{{ __('Choisir la couleur principale') }}">
                            <input wire:model.live.debounce.500ms="primaryColor" id="primaryColor" class="input font-mono uppercase" maxlength="7">
                        </div>
                        <p class="hint">{{ __('En-têtes, menu, boutons. Choisissez une couleur foncée.') }}</p>
                        @error('primaryColor') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="accentColor" class="label">{{ __('Couleur d’accent') }}</label>
                        <div class="flex gap-2">
                            <input type="color" wire:model.live="accentColor" class="h-11 w-14 shrink-0 cursor-pointer rounded-xl border border-sand-300 bg-white p-1" aria-label="{{ __('Choisir la couleur d’accent') }}">
                            <input wire:model.live.debounce.500ms="accentColor" id="accentColor" class="input font-mono uppercase" maxlength="7">
                        </div>
                        <p class="hint">{{ __('Élément actif, action principale, points du motif.') }}</p>
                        @error('accentColor') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <label class="flex items-center gap-3">
                    <input type="checkbox" wire:model.live="showPattern" class="size-5">
                    <span class="text-sm font-medium text-ink-800">{{ __('Afficher le motif wax dans les en-têtes et le menu') }}</span>
                </label>

                <div class="flex flex-wrap justify-end gap-2">
                    @if ($hasOwnTheme)
                        <button type="button" class="btn-ghost mr-auto" wire:click="resetTheme" wire:confirm="{{ __('Revenir aux couleurs par défaut ?') }}">{{ __('Couleurs par défaut') }}</button>
                    @endif
                    <button type="submit" class="btn-primary"><x-icon name="save" class="size-4" /> {{ __('Enregistrer l’apparence') }}</button>
                </div>
            </form>

            {{-- Aperçu en direct, avec les couleurs choisies --}}
            <section aria-label="{{ __('Aperçu') }}" style="{{ collect($preview->variables())->map(fn ($v, $k) => "$k:$v")->implode(';') }}" class="self-start">
                <p class="label">{{ __('Aperçu') }}</p>
                <div class="mx-auto w-full max-w-[300px] overflow-hidden rounded-[30px] border-[7px] border-ink-900 bg-sand-50 shadow-xl">
                    <div class="wax wax-veil px-4 pb-6 pt-4 text-white" @unless ($preview->pattern) style="background-image:none" @endunless>
                        <div class="flex items-center gap-2"><x-icon name="menu" class="size-5" /><span class="text-sm font-semibold">{{ $organization->displayName() }}</span><span class="ml-auto grid size-7 place-items-center rounded-full bg-ochre-500 text-[11px] font-semibold text-on-accent">{{ auth()->user()->initials() }}</span></div>
                        <p class="mt-4 text-xs text-ink-100">{{ __('Dimanche 11 octobre') }}</p>
                        <p class="text-lg font-semibold">{{ __('Bonjour !') }}</p>
                    </div>
                    <div class="relative z-10 -mt-4 grid grid-cols-2 gap-2 px-3">
                        <div class="card p-3"><span class="icon-tile size-8 bg-ink-700 text-white"><x-icon name="users" class="size-4" /></span><p class="mt-1 text-[11px] text-sand-700">{{ __('Membres') }}</p><p class="font-semibold text-ink-800">1 248</p></div>
                        <div class="card p-3"><span class="icon-tile size-8 bg-ochre-500 text-on-accent"><x-icon name="coins" class="size-4" /></span><p class="mt-1 text-[11px] text-sand-700">{{ __('Collecte') }}</p><p class="font-semibold text-ink-800">1 850 $</p></div>
                    </div>
                    <div class="space-y-2 p-3">
                        <span class="btn-accent w-full !min-h-0 !py-2 text-xs">{{ __('Saisir la collecte') }}</span>
                        <span class="btn-primary w-full !min-h-0 !py-2 text-xs">{{ __('Enregistrer') }}</span>
                    </div>
                    <div class="m-2 grid grid-cols-3 rounded-2xl bg-ink-700 py-2 text-center text-[10px] text-ink-200">
                        <span class="font-semibold text-ochre-500">{{ __('Accueil') }}</span><span>{{ __('Membres') }}</span><span>{{ __('Plus') }}</span>
                    </div>
                </div>
            </section>
        </div>
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
                    <span class="font-semibold text-ink-700">{{ __('Autoriser l’accès du support') }}</span>
                </label>
                @if ($organization->support_access_until?->isFuture())
                    <p class="mt-2 text-sm text-ochre-700">{{ __('Accès autorisé jusqu’au :date.', ['date' => $organization->support_access_until->timezone($organization->timezone)->translatedFormat('j F Y à H:i')]) }}</p>
                @endif
            @endcan
        </section>
    @endif
</div>
