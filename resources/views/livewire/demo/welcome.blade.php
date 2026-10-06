<div class="mx-auto max-w-3xl">
    <section class="wax wax-veil wax-veil-strong mb-6 overflow-hidden rounded-[22px] p-6 text-white sm:p-8">
        <p class="text-sm font-semibold uppercase tracking-[0.14em] text-ochre-300">{{ __('Votre démonstration est prête') }}</p>
        <h1 class="mt-2 text-3xl font-semibold text-white">{{ __('Bienvenue dans la :n', ['n' => $organization->name]) }}</h1>
        <p class="mt-3 max-w-2xl text-ink-100">{{ __('Une communauté fictive, déjà remplie : un siège, deux régions, des paroisses, des membres, des finances, un budget, une paie, des groupes, des documents. Tout est à vous : ajoutez, modifiez, supprimez. Personne d’autre ne la voit.') }}</p>
        <p class="mt-4 rounded-xl bg-white/10 px-4 py-3 text-sm">{{ __('Elle sera effacée le :d, avec tout ce que vous y aurez saisi.', ['d' => $organization->demo_expires_at?->translatedFormat('l j F à H:i')]) }}</p>
    </section>

    <section class="card mb-6 p-5 sm:p-6">
        <h2 class="mb-1 text-lg">{{ __('Essayer chaque rôle') }}</h2>
        <p class="mb-4 text-sm text-sand-700">{{ __('Chacun voit Waumini selon son rôle. Déconnectez-vous, puis connectez-vous avec un autre numéro : le mot de passe est le même pour tous.') }}</p>
        <p class="mb-4 text-sm">{{ __('Mot de passe') }} : <span class="rounded-lg bg-ink-50 px-2 py-1 font-mono text-base font-semibold text-ink-800 select-all">{{ $password }}</span></p>
        <ul class="divide-y divide-sand-100">
            @foreach ($accounts as $a)
                <li class="flex flex-wrap items-center gap-x-4 gap-y-1 py-3">
                    <span class="w-36 shrink-0 font-mono font-semibold text-ink-800 select-all">{{ $a['phone'] }}</span>
                    <span class="min-w-0 flex-1 basis-56"><span class="font-semibold text-ink-800">{{ $a['role'] }}</span> · {{ $a['name'] }}<span class="block text-sm text-sand-700">{{ $a['hint'] }}</span></span>
                </li>
            @endforeach
        </ul>
        <p class="mt-4 text-sm text-sand-700">{{ __('Notez ces identifiants pour revenir depuis un autre appareil pendant la durée de la démo.') }}</p>
    </section>

    <div class="flex flex-wrap gap-3">
        <a href="{{ route('dashboard') }}" class="btn-primary"><x-icon name="layout-dashboard" class="size-4" /> {{ __('Ouvrir le tableau de bord') }}</a>
        <a href="{{ route('help.index') }}" class="btn-secondary"><x-icon name="book-open" class="size-4" /> {{ __('Le manuel') }}</a>
    </div>
</div>
