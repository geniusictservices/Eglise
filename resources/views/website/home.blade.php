@extends('website.layout')

@section('content')
    @php $style = $website->theme; $cover = $website->cover_path ? route('website.cover', $organization->slug) : null; @endphp
    {{-- L'accueil --}}
    <section @class(['relative isolate overflow-hidden',
        'wax text-white' => $style === 'chaleureux' && ! $cover,
        'bg-ink-900 text-white' => $style === 'solennel' || $cover,
        'bg-ink-50 text-ink-900' => $style === 'lumiere' && ! $cover])>
        @if ($cover)
            <img src="{{ $cover }}" alt="" class="absolute inset-0 -z-10 size-full object-cover">
            <div class="absolute inset-0 -z-10 bg-gradient-to-t from-ink-900/90 via-ink-900/55 to-ink-900/25"></div>
        @elseif ($style === 'chaleureux')
            <div class="absolute inset-0 -z-10 bg-gradient-to-b from-ink-700/80 to-ink-700/95"></div>
        @endif
        <div @class(['mx-auto max-w-6xl px-4 sm:px-6', 'py-20 sm:py-28' => $cover || $style !== 'lumiere', 'py-14 sm:py-20' => ! $cover && $style === 'lumiere'])>
            @if ($website->tagline)<p @class(['mb-3 text-sm font-semibold uppercase tracking-[0.2em]', 'text-ochre-300' => $cover || $style !== 'lumiere', 'text-ochre-600' => ! $cover && $style === 'lumiere'])>{{ $website->tagline }}</p>@endif
            <h1 @class(['max-w-3xl text-4xl font-bold leading-tight sm:text-5xl', 'font-serif font-semibold' => $style === 'solennel', 'text-white' => $cover || $style !== 'lumiere'])>{{ $website->welcome_title ?: $organization->name }}</h1>
            @if ($website->welcome_text)<x-website.text :text="$website->welcome_text" @class(['mt-5 max-w-2xl text-lg', 'text-white/85' => $cover || $style !== 'lumiere', 'text-ink-800' => ! $cover && $style === 'lumiere']) />@endif
            <div class="mt-8 flex flex-wrap gap-3">
                @if ($website->hasPage('programme'))<a href="{{ route('website.page', [$organization->slug, 'programme']) }}" class="btn-accent"><x-icon name="calendar" class="size-4" /> {{ __('Nos cultes') }}</a>@endif
                @if ($website->hasPage('don'))<a href="{{ route('website.page', [$organization->slug, 'don']) }}" @class(['btn', 'bg-white/15 text-white hover:bg-white/25' => $cover || $style !== 'lumiere', 'btn-secondary' => ! $cover && $style === 'lumiere'])><x-icon name="heart-handshake" class="size-4" /> {{ __('Faire un don') }}</a>@endif
                @if ($website->hasPage('contact'))<a href="{{ route('website.page', [$organization->slug, 'contact']) }}" @class(['btn', 'bg-white/15 text-white hover:bg-white/25' => $cover || $style !== 'lumiere', 'btn-secondary' => ! $cover && $style === 'lumiere'])><x-icon name="map-pin" class="size-4" /> {{ __('Nous trouver') }}</a>@endif
            </div>
        </div>
    </section>

    {{-- Le verset du moment --}}
    @if ($website->verse_text)
        <section class="border-b border-sand-200 bg-white">
            <figure class="mx-auto max-w-4xl px-4 py-10 text-center sm:px-6">
                <x-icon name="book-open" class="mx-auto size-7 text-ochre-500" />
                <blockquote @class(['mt-3 text-xl leading-relaxed text-ink-800 sm:text-2xl', 'font-serif italic' => $style === 'solennel'])>« {{ $website->verse_text }} »</blockquote>
                @if ($website->verse_reference)<figcaption class="mt-3 text-sm font-semibold uppercase tracking-[0.18em] text-ochre-600">{{ $website->verse_reference }}</figcaption>@endif
            </figure>
        </section>
    @endif

    <div class="mx-auto max-w-6xl space-y-16 px-4 pt-14 sm:px-6">
        {{-- Les cultes réguliers --}}
        @if ($schedule->isNotEmpty() && $website->hasPage('programme'))
            <section>
                <x-website.heading :website="$website" :eyebrow="__('Chaque semaine')">{{ __('Venez nous rejoindre') }}</x-website.heading>
                <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($schedule as $event)
                        <li class="rounded-2xl border border-sand-200 bg-white p-5">
                            <p class="text-sm font-semibold text-ochre-600">{{ $event->recurrenceLabel() }}</p>
                            <p class="mt-1 text-lg font-semibold text-ink-800">{{ $event->title }}</p>
                            <p class="mt-1 text-sm text-sand-700">{{ collect([$event->hours(), $event->place])->filter()->implode(' · ') }}</p>
                        </li>
                    @endforeach
                </ul>
                <a href="{{ route('website.page', [$organization->slug, 'programme']) }}" class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-ink-700 hover:underline">{{ __('Tout le programme') }} <x-icon name="arrow-right" class="size-4" /></a>
            </section>
        @endif

        {{-- Nouveau ? Un sujet de prière ? --}}
        @if ($website->hasPage('bienvenue') || $website->hasPage('priere'))
            <section class="grid gap-4 sm:grid-cols-2">
                @if ($website->hasPage('bienvenue'))
                    <a href="{{ route('website.page', [$organization->slug, 'bienvenue']) }}" class="group flex gap-4 rounded-3xl border border-sand-200 bg-white p-6 transition hover:border-ochre-300 hover:shadow-lg hover:shadow-ink-900/5">
                        <span class="grid size-12 shrink-0 place-items-center rounded-2xl bg-ochre-100 text-ochre-700"><x-icon name="user-plus" class="size-6" /></span>
                        <span><span class="block text-lg font-semibold text-ink-800">{{ __('Nouveau parmi nous ?') }}</span><span class="mt-1 block text-ink-900">{{ __('Laissez-nous vos coordonnées : nous serons heureux de faire votre connaissance.') }}</span>
                            <span class="mt-2 inline-flex items-center gap-1 text-sm font-semibold text-ink-700 group-hover:underline">{{ __('Faisons connaissance') }} <x-icon name="arrow-right" class="size-4" /></span></span>
                    </a>
                @endif
                @if ($website->hasPage('priere'))
                    <a href="{{ route('website.page', [$organization->slug, 'priere']) }}" class="group flex gap-4 rounded-3xl border border-sand-200 bg-white p-6 transition hover:border-ochre-300 hover:shadow-lg hover:shadow-ink-900/5">
                        <span class="grid size-12 shrink-0 place-items-center rounded-2xl bg-leaf-50 text-leaf-600"><x-icon name="heart-handshake" class="size-6" /></span>
                        <span><span class="block text-lg font-semibold text-ink-800">{{ __('Un sujet de prière ?') }}</span><span class="mt-1 block text-ink-900">{{ __('Confiez-le à l’équipe pastorale, en toute confidentialité.') }}</span>
                            <span class="mt-2 inline-flex items-center gap-1 text-sm font-semibold text-ink-700 group-hover:underline">{{ __('Demander la prière') }} <x-icon name="arrow-right" class="size-4" /></span></span>
                    </a>
                @endif
            </section>
        @endif

        @if ($website->pastor_message)
            <section class="grid gap-6 rounded-3xl bg-ink-50 p-6 sm:p-10 lg:grid-cols-[1fr_2fr]">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-ochre-600">{{ __('Le mot du :t', ['t' => mb_strtolower($organization->term('pasteur'))]) }}</p>
                    @if ($website->pastor_name)<p @class(['mt-2 text-2xl font-semibold text-ink-800', 'font-serif' => $style === 'solennel'])>{{ $website->pastor_name }}</p>@endif
                </div>
                <x-website.text :text="$website->pastor_message" class="text-lg text-ink-900" />
            </section>
        @endif

        <div class="grid gap-12 lg:grid-cols-2">
            @if ($events->isNotEmpty())
                <section class="min-w-0">
                    <x-website.heading :website="$website" :eyebrow="__('À venir')">{{ __('Événements') }}</x-website.heading>
                    <ul class="space-y-3">@foreach ($events as $o)<x-website.event :occurrence="$o" :organization="$organization" />@endforeach</ul>
                    <a href="{{ route('website.page', [$organization->slug, 'evenements']) }}" class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-ink-700 hover:underline">{{ __('Tous les événements') }} <x-icon name="arrow-right" class="size-4" /></a>
                </section>
            @endif
            @if ($announcements->isNotEmpty())
                <section class="min-w-0">
                    <x-website.heading :website="$website" :eyebrow="__('Nouvelles')">{{ __('Annonces') }}</x-website.heading>
                    <ul class="space-y-3">
                        @foreach ($announcements as $a)
                            <li class="rounded-2xl border border-sand-200 bg-white p-4"><p class="font-semibold text-ink-800">{{ $a->title }}</p><p class="mt-1 text-sm leading-relaxed text-ink-900">{{ \Illuminate\Support\Str::limit($a->body, 180) }}</p></li>
                        @endforeach
                    </ul>
                    <a href="{{ route('website.page', [$organization->slug, 'annonces']) }}" class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-ink-700 hover:underline">{{ __('Toutes les annonces') }} <x-icon name="arrow-right" class="size-4" /></a>
                </section>
            @endif
        </div>

        @if ($parishes->isNotEmpty())
            <section>
                <x-website.heading :website="$website" :eyebrow="trans_choice(':count communauté|:count communautés', $parishes->count())">{{ __('Nos paroisses') }}</x-website.heading>
                <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($parishes->sortByDesc(fn ($p) => (bool) $p['website'])->take(6) as $p)
                        <li class="min-w-0 rounded-2xl border border-sand-200 bg-white p-5">
                            <p class="text-xs font-semibold uppercase tracking-wider text-ochre-600">{{ $p['organization']->level_label }} · {{ $p['organization']->city }}</p>
                            <p class="mt-1 text-lg font-semibold text-ink-800">{{ $p['organization']->name }}</p>
                            @if ($p['website'])<a href="{{ route('website.home', $p['organization']->slug) }}" class="mt-2 inline-flex items-center gap-1 text-sm font-semibold text-ink-700 hover:underline">{{ __('Voir son site') }} <x-icon name="arrow-right" class="size-4" /></a>@endif
                        </li>
                    @endforeach
                </ul>
                <a href="{{ route('website.page', [$organization->slug, 'paroisses']) }}" class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-ink-700 hover:underline">{{ __('Toutes nos paroisses') }} <x-icon name="arrow-right" class="size-4" /></a>
            </section>
        @endif

        @if ($photos->isNotEmpty())
            <section>
                <x-website.heading :website="$website" :eyebrow="__('En images')">{{ __('La vie de la communauté') }}</x-website.heading>
                <ul class="grid grid-cols-3 gap-2 sm:gap-3 lg:grid-cols-6">
                    @foreach ($photos as $photo)
                        <li><a href="{{ route('website.page', [$organization->slug, 'galerie']) }}" class="block overflow-hidden rounded-2xl bg-sand-100"><img src="{{ route('website.photo', [$organization->slug, $photo->id, 'vignette']) }}" alt="{{ $photo->caption }}" loading="lazy" class="aspect-square w-full object-cover transition hover:scale-105"></a></li>
                    @endforeach
                </ul>
                <a href="{{ route('website.page', [$organization->slug, 'galerie']) }}" class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-ink-700 hover:underline">{{ __('Toute la galerie') }} <x-icon name="arrow-right" class="size-4" /></a>
            </section>
        @endif

        @if ($sermon)
            <section>
                <x-website.heading :website="$website" :eyebrow="__('Dernière prédication')">{{ __('Écouter la Parole') }}</x-website.heading>
                <div class="max-w-3xl"><x-website.sermon :sermon="$sermon" :organization="$organization" /></div>
                <a href="{{ route('website.page', [$organization->slug, 'predications']) }}" class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-ink-700 hover:underline">{{ __('Toutes les prédications') }} <x-icon name="arrow-right" class="size-4" /></a>
            </section>
        @endif
    </div>
@endsection
