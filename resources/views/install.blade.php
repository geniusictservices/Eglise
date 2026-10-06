<x-layouts.simple :title="__('Installer Waumini')">
    <div x-data="{ tab: /iphone|ipad|ipod/i.test(navigator.userAgent) ? 'iphone' : (/android/i.test(navigator.userAgent) ? 'android' : 'windows') }">
        <p class="eyebrow">{{ __('Application') }}</p>
        <h1 class="page-title mt-1">{{ __('Installer Waumini') }}</h1>
        <p class="mt-2 max-w-2xl text-sand-700">{{ __('Waumini s’installe comme une application, sans passer par un magasin d’applications : une icône sur l’écran d’accueil ou dans le menu Démarrer, sa propre fenêtre, et les notifications. Une connexion Internet reste nécessaire.') }}</p>

        <div class="mt-5" x-data x-show="$store.pwa.canInstall" x-cloak>
            <button type="button" class="btn-accent !py-3 text-base" @click="$store.pwa.install()"><x-icon name="download" /> {{ __('Installer maintenant') }}</button>
        </div>
        <p class="mt-5 inline-flex items-center gap-2 rounded-xl bg-ink-50 px-3 py-2 text-sm font-bold text-ink-700" x-data x-show="$store.pwa.installed" x-cloak>
            <x-icon name="circle-check" class="size-4" /> {{ __('Waumini est déjà installé sur cet appareil.') }}
        </p>

        <div class="mt-8 flex gap-1 overflow-x-auto rounded-2xl border border-sand-200 bg-white p-1" role="tablist">
            <button type="button" role="tab" @click="tab = 'android'" :aria-selected="tab === 'android'" :class="tab === 'android' ? 'bg-ink-700 text-white' : 'text-ink-600 hover:bg-sand-50'" class="flex flex-1 items-center justify-center gap-2 whitespace-nowrap rounded-xl px-4 py-2.5 text-sm font-bold"><x-icon name="smartphone" class="size-4" /> Android</button>
            <button type="button" role="tab" @click="tab = 'iphone'" :aria-selected="tab === 'iphone'" :class="tab === 'iphone' ? 'bg-ink-700 text-white' : 'text-ink-600 hover:bg-sand-50'" class="flex flex-1 items-center justify-center gap-2 whitespace-nowrap rounded-xl px-4 py-2.5 text-sm font-bold"><x-icon name="smartphone" class="size-4" /> iPhone</button>
            <button type="button" role="tab" @click="tab = 'windows'" :aria-selected="tab === 'windows'" :class="tab === 'windows' ? 'bg-ink-700 text-white' : 'text-ink-600 hover:bg-sand-50'" class="flex flex-1 items-center justify-center gap-2 whitespace-nowrap rounded-xl px-4 py-2.5 text-sm font-bold"><x-icon name="monitor" class="size-4" /> {{ __('Windows et ordinateur') }}</button>
        </div>

        @php
            $guides = [
                'android' => [
                    __('Ouvrez Waumini dans Chrome.'),
                    __('Touchez le bouton « Installer » qui apparaît, ou le menu ⋮ en haut à droite.'),
                    __('Choisissez « Installer l’application » (ou « Ajouter à l’écran d’accueil »).'),
                    __('Confirmez : l’icône Waumini apparaît sur votre écran d’accueil.'),
                ],
                'iphone' => [
                    __('Ouvrez Waumini dans Safari (l’installation ne fonctionne qu’avec Safari).'),
                    __('Touchez le bouton Partager, le carré avec une flèche vers le haut, en bas de l’écran.'),
                    __('Faites défiler et choisissez « Sur l’écran d’accueil ».'),
                    __('Touchez « Ajouter ». Ouvrez ensuite Waumini depuis cette icône pour recevoir les notifications.'),
                ],
                'windows' => [
                    __('Ouvrez Waumini dans Microsoft Edge ou Google Chrome.'),
                    __('Cliquez sur l’icône d’installation à droite de la barre d’adresse (un écran avec une flèche), ou sur le bouton « Installer » de Waumini.'),
                    __('Confirmez « Installer ». Waumini s’ouvre dans sa propre fenêtre.'),
                    __('Clic droit sur l’icône dans la barre des tâches, puis « Épingler à la barre des tâches », pour la retrouver chaque jour.'),
                ],
            ];
        @endphp

        @foreach ($guides as $key => $steps)
            <ol x-show="tab === '{{ $key }}'" @if ($key !== 'windows') x-cloak @endif class="mt-6 space-y-3">
                @foreach ($steps as $i => $step)
                    <li class="card flex items-start gap-4 p-4">
                        <span class="grid size-8 shrink-0 place-items-center rounded-full bg-ochre-500 font-display font-bold text-ink-900">{{ $i + 1 }}</span>
                        <p class="pt-1">{{ $step }}</p>
                    </li>
                @endforeach
            </ol>
        @endforeach

        <div class="mt-8 rounded-2xl border border-dashed border-sand-300 p-5 text-sm text-sand-700" x-show="tab === 'windows'">
            <p class="font-bold text-ink-700">{{ __('Ordinateur de l’église') }}</p>
            <p class="mt-1">{{ __('Un fichier d’installation Windows (MSIX) sera aussi proposé au lancement, pour installer Waumini sans passer par le navigateur.') }}</p>
        </div>

        <p class="mt-10">
            @auth
                <a href="{{ route('dashboard') }}" class="btn-secondary"><x-icon name="chevron-left" class="size-4" /> {{ __('Retour au tableau de bord') }}</a>
            @else
                <a href="{{ route('login') }}" class="btn-secondary"><x-icon name="log-in" class="size-4" /> {{ __('Se connecter') }}</a>
            @endauth
        </p>
    </div>
</x-layouts.simple>
