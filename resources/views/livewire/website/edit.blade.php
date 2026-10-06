<div>
    <x-page-header :title="__('Site vitrine')" :description="__('Le site de la communauté sur Internet. Vous écrivez quelques textes ; le programme, les événements, les annonces et les prédications viennent de Waumini et se mettent à jour tout seuls.')">
        <x-slot:actions><a href="{{ $address }}" target="_blank" rel="noopener" class="btn-secondary"><x-icon name="external-link" class="size-4" /> {{ $website?->is_published ? __('Voir le site') : __('Aperçu') }}</a></x-slot:actions>
    </x-page-header>

    <section @class(['mb-5 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-2xl p-4', 'bg-leaf-50' => $website?->is_published, 'bg-ochre-50' => ! $website?->is_published])>
        <x-icon name="globe" @class(['size-6 shrink-0', 'text-leaf-600' => $website?->is_published, 'text-ochre-700' => ! $website?->is_published]) />
        <div class="min-w-0 flex-1 basis-60">
            <p class="font-semibold text-ink-800">{{ $website?->is_published ? __('Le site est en ligne') : __('Le site n’est pas encore publié') }}</p>
            <p class="break-all font-mono text-sm text-sand-700">{{ $address }}</p>
        </div>
        <label class="flex items-center gap-3 text-sm font-semibold text-ink-800"><input type="checkbox" wire:model="form.is_published" class="size-5"> {{ __('Publier le site') }}</label>
    </section>

    <div class="mb-6 flex gap-1 overflow-x-auto rounded-2xl border border-sand-200 bg-white p-1" role="tablist">
        @foreach ($tabs as $key => $label)
            <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    @class(['flex-1 whitespace-nowrap rounded-xl px-4 py-2 text-sm font-semibold', 'bg-ink-700 text-white' => $tab === $key, 'text-ink-600 hover:bg-sand-50' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    <form wire:submit="save" class="space-y-5">
        @if ($tab === 'general')
            <section class="card p-5 sm:p-6">
                <h2 class="mb-1 text-lg">{{ __('Mise en page') }}</h2>
                <p class="mb-4 text-sm text-sand-700">{{ __('Les couleurs et le logo sont ceux de la communauté (Paramètres › Apparence).') }}</p>
                <div class="grid gap-3 sm:grid-cols-3">
                    @foreach (\App\Models\Website::THEMES as $key => $t)
                        <label @class(['cursor-pointer overflow-hidden rounded-2xl border-2 transition', 'border-ink-700' => $form['theme'] === $key, 'border-sand-200 hover:border-sand-300' => $form['theme'] !== $key])>
                            <input type="radio" wire:model.live="form.theme" value="{{ $key }}" class="sr-only">
                            <span @class(['flex h-20 items-end p-3', 'wax wax-veil' => $key === 'chaleureux', 'bg-ink-50' => $key === 'lumiere', 'bg-ink-900' => $key === 'solennel'])>
                                <span @class(['text-lg font-bold', 'text-white' => $key !== 'lumiere', 'text-ink-800' => $key === 'lumiere', 'font-serif' => $key === 'solennel'])>{{ __('Bienvenue') }}</span>
                            </span>
                            <span class="block p-3"><span class="block font-semibold text-ink-800">{{ __($t['name']) }}</span><span class="block text-xs text-sand-700">{{ __($t['hint']) }}</span></span>
                        </label>
                    @endforeach
                </div>
            </section>
            <section class="card space-y-4 p-5 sm:p-6">
                <h2 class="text-lg">{{ __('La page d’accueil') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label for="w-title" class="label">{{ __('Grand titre') }}</label><input wire:model="form.welcome_title" id="w-title" class="input">@error('form.welcome_title') <p class="error">{{ $message }}</p> @enderror</div>
                    <div><label for="w-tag" class="label">{{ __('Sous le nom') }}</label><input wire:model="form.tagline" id="w-tag" class="input" placeholder="{{ __('Goma, Nord-Kivu · Un peuple qui prie') }}"></div>
                </div>
                <div><label for="w-wel" class="label">{{ __('Mot d’accueil') }}</label><textarea wire:model="form.welcome_text" id="w-wel" rows="3" class="input"></textarea></div>
                <div>
                    <label for="w-cover" class="label">{{ __('Photo d’accueil') }} <span class="font-normal text-sand-700">{{ __('(facultative : l’église, l’assemblée, la chorale…)') }}</span></label>
                    <div class="flex flex-wrap items-center gap-3">
                        @if ($cover)
                            <img src="{{ $cover->temporaryUrl() }}" alt="" class="h-20 w-32 rounded-xl object-cover">
                        @elseif ($website?->cover_path)
                            <img src="{{ route('website.cover', $organization->slug) }}" alt="" class="h-20 w-32 rounded-xl object-cover">
                            <button type="button" wire:click="removeCover" class="btn-ghost text-sm text-terra-700">{{ __('Retirer') }}</button>
                        @endif
                        <input wire:model="cover" id="w-cover" type="file" accept="image/*" class="min-w-0 text-sm">
                    </div>
                    @error('cover') <p class="error">{{ $message }}</p> @enderror
                </div>
            </section>
        @elseif ($tab === 'pages')
            <section class="card p-5 sm:p-6">
                <h2 class="mb-1 text-lg">{{ __('Les pages du site') }}</h2>
                <p class="mb-4 text-sm text-sand-700">{{ __('L’accueil est toujours là. Cochez les autres pages à montrer.') }}</p>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach (\App\Models\Website::PAGES as $key => $label)
                        @continue($key === 'paroisses' && ! $hasChildren)
                        <label class="flex items-start gap-3 rounded-xl border border-sand-200 p-3 text-sm">
                            <input type="checkbox" wire:model="form.pages" value="{{ $key }}" class="mt-0.5 size-4">
                            <span><span class="font-semibold text-ink-800">{{ __($label) }}</span><br><span class="text-sand-700">{{ match ($key) {
                                'programme' => trans_choice(':count rencontre régulière, tirée du calendrier|:count rencontres régulières, tirées du calendrier', $counts['schedule']),
                                'evenements' => trans_choice(':count événement à venir, tiré du calendrier|:count événements à venir, tirés du calendrier', $counts['events']),
                                'annonces' => trans_choice(':count annonce publiée sur le site|:count annonces publiées sur le site', $counts['announcements']),
                                'predications' => trans_choice(':count prédication|:count prédications', $counts['sermons']),
                                'a-propos' => __('Votre histoire, ce que vous croyez, le mot du pasteur'),
                                'paroisses' => __('Les niveaux en dessous, avec un lien vers leur site'),
                                'don' => __('Les numéros mobile money et la déclaration du don'),
                                'contact' => __('Adresse, téléphone, WhatsApp, itinéraire'),
                            } }}</span></span>
                        </label>
                    @endforeach
                </div>
                <p class="mt-4 rounded-xl bg-ink-50 p-3 text-sm text-ink-800">{{ __('Chaque activité du calendrier et chaque annonce a une case « Sur le site vitrine » : seules celles qui sont cochées paraissent sur le site. Les activités de toute la communauté le sont d’office.') }}</p>
            </section>
        @elseif ($tab === 'textes')
            <section class="card space-y-4 p-5 sm:p-6">
                <div><label for="w-about" class="label">{{ __('Qui sommes-nous') }}</label><textarea wire:model="form.about_text" id="w-about" rows="6" class="input" placeholder="{{ __('Notre histoire, notre vision, nos ministères…') }}"></textarea><p class="mt-1 text-xs text-sand-700">{{ __('Une ligne vide commence un nouveau paragraphe.') }}</p></div>
                <div><label for="w-bel" class="label">{{ __('Ce que nous croyons') }}</label><textarea wire:model="form.beliefs_text" id="w-bel" rows="5" class="input"></textarea></div>
                <div class="grid gap-4 sm:grid-cols-[1fr_2fr]">
                    <div><label for="w-pn" class="label">{{ __('Nom du :t', ['t' => mb_strtolower($organization->term('pasteur'))]) }}</label><input wire:model="form.pastor_name" id="w-pn" class="input"></div>
                    <div><label for="w-pm" class="label">{{ __('Son mot') }}</label><textarea wire:model="form.pastor_message" id="w-pm" rows="4" class="input"></textarea></div>
                </div>
            </section>
        @elseif ($tab === 'dons')
            <section class="card space-y-4 p-5 sm:p-6">
                <div><label for="w-give" class="label">{{ __('Texte de la page des dons') }}</label><textarea wire:model="form.giving_text" id="w-give" rows="3" class="input" placeholder="{{ __('« Chacun donne comme il l’a résolu en son cœur » (2 Corinthiens 9.7).') }}"></textarea></div>
                <div>
                    <p class="label">{{ __('Comptes montrés') }}</p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        @forelse ($accounts->whereIn('kind', ['mobile', 'bank']) as $a)
                            <label class="flex items-start gap-3 rounded-xl border border-sand-200 p-3 text-sm"><input type="checkbox" wire:model="form.giving_accounts" value="{{ $a->id }}" class="mt-0.5 size-4"><span><span class="font-semibold text-ink-800">{{ $a->name }}</span><br><span class="font-mono text-sand-700">{{ collect([$a->provider, $a->account_number])->filter()->implode(' · ') ?: __('numéro non renseigné') }}</span></span></label>
                        @empty
                            <p class="text-sm text-sand-700">{{ __('Aucun compte mobile money ou bancaire : ajoutez-en un dans Finances › Comptes.') }}</p>
                        @endforelse
                    </div>
                </div>
                <div>
                    <p class="label">{{ __('Ce que le donateur peut choisir') }} <span class="font-normal text-sand-700">{{ __('(sinon : offrande)') }}</span></p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($categories as $c)
                            <label class="flex items-center gap-2 rounded-xl border border-sand-200 px-3 py-2 text-sm"><input type="checkbox" wire:model="form.giving_categories" value="{{ $c->id }}" class="size-4"> {{ $c->name }}</label>
                        @endforeach
                    </div>
                </div>
                <p class="rounded-xl bg-ink-50 p-3 text-sm text-ink-800">{{ __('Un don déclaré sur le site arrive dans Finances › Paiements déclarés : le trésorier le vérifie avec l’ID de la transaction, puis le valide ou le rejette.') }}</p>
            </section>
        @else
            <section class="card space-y-4 p-5 sm:p-6">
                <p class="text-sm text-sand-700">{{ __('L’adresse, le téléphone et l’e-mail viennent des Paramètres de la communauté.') }}</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label for="w-wa" class="label">{{ __('Numéro WhatsApp') }}</label><input wire:model="form.whatsapp" id="w-wa" type="tel" class="input" placeholder="+243 990 000 000"></div>
                    <div><label for="w-map" class="label">{{ __('Lien Google Maps') }}</label><input wire:model="form.map_url" id="w-map" type="url" class="input" placeholder="https://maps.app.goo.gl/…">@error('form.map_url') <p class="error">{{ $message }}</p> @enderror</div>
                    <div><label for="w-fb" class="label">{{ __('Page Facebook') }}</label><input wire:model="form.facebook_url" id="w-fb" type="url" class="input" placeholder="https://facebook.com/…">@error('form.facebook_url') <p class="error">{{ $message }}</p> @enderror</div>
                    <div><label for="w-yt" class="label">{{ __('Chaîne YouTube') }}</label><input wire:model="form.youtube_url" id="w-yt" type="url" class="input" placeholder="https://youtube.com/@…">@error('form.youtube_url') <p class="error">{{ $message }}</p> @enderror</div>
                </div>
            </section>
        @endif

        <div class="flex justify-end"><button class="btn-primary"><x-icon name="save" class="size-4" /> {{ __('Enregistrer') }}</button></div>
    </form>
</div>
