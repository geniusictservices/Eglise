<x-layouts.site :title="__('Essayer la démo')" :description="__('Essayez Waumini tout de suite, sans inscription : une communauté de démonstration déjà remplie, rien que pour vous, pendant :d jours.', ['d' => $days])">
    <section class="wax wax-veil wax-veil-strong text-white">
        <div class="mx-auto grid max-w-6xl items-center gap-10 px-4 py-14 sm:px-6 lg:grid-cols-[1.1fr_1fr] lg:py-20">
            <div class="space-y-5">
                <p class="text-sm font-semibold uppercase tracking-[0.14em] text-ochre-300">{{ __('Démo en direct') }}</p>
                <h1 class="text-[2rem] font-semibold leading-tight text-white sm:text-5xl">{{ __('Essayez Waumini maintenant, sans inscription.') }}</h1>
                <p class="max-w-xl text-lg text-ink-100">{{ __('Vous recevez votre propre communauté de démonstration, déjà remplie : un siège, ses régions et ses paroisses, des membres, des finances, un budget, une paie, des groupes, des documents, un site vitrine. Tout est utilisable. Elle est effacée au bout de :d jours.', ['d' => $days]) }}</p>
                @if (session('status'))<p class="rounded-xl bg-terra-500/90 px-4 py-3 text-sm">{{ session('status') }}</p>@endif
                <form method="POST" action="{{ route('demo.start') }}" x-data="{ busy: false }" @submit="busy = true">
                    @csrf
                    <div class="hidden" aria-hidden="true"><label>{{ __('Site web') }} <input name="site_web" tabindex="-1" autocomplete="off"></label></div>
                    <button class="btn-accent !px-6 !py-3 text-base" :disabled="busy">
                        <span x-show="!busy" class="inline-flex items-center gap-2"><x-icon name="play" class="size-5" /> {{ __('Lancer ma démo') }}</span>
                        <span x-show="busy" x-cloak>{{ __('Préparation de votre communauté… (10 secondes)') }}</span>
                    </button>
                </form>
                <p class="text-sm text-ink-200">{{ __('Aucune donnée personnelle demandée · Les noms et les numéros sont fictifs · Rien n’est envoyé à personne') }}</p>
            </div>
            <img src="{{ asset('images/site/bureau-03-tableau-de-bord.png') }}" alt="{{ __('Tableau de bord de Waumini') }}" class="w-full rounded-2xl border-4 border-white/20 shadow-2xl shadow-black/40">
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <p class="eyebrow">{{ __('Un compte par rôle') }}</p>
        <h2 class="mt-2 max-w-3xl text-3xl">{{ __('Voyez Waumini avec les yeux de chacun') }}</h2>
        <p class="mt-2 max-w-2xl text-sand-700">{{ __('Après le lancement, Waumini vous donne les numéros de ces comptes et leur mot de passe.') }}</p>
        <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($accounts as [$name, $role, $hint])
                <div class="card p-5"><p class="font-semibold text-ink-800">{{ $role }}</p><p class="text-sm text-sand-700">{{ $name }}</p><p class="mt-2 text-sm text-ink-900">{{ $hint }}</p></div>
            @endforeach
        </div>
        <p class="mt-10 text-center"><a href="{{ route('register') }}" class="btn-primary">{{ __('Convaincu ? Créez le vrai compte de votre église') }}</a></p>
    </section>
</x-layouts.site>
