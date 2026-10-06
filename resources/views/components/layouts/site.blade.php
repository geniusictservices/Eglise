@props(['title' => null, 'description' => null])
@php $contact = \App\Support\Platform::contact(); @endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => $title])
    @if ($description)<meta name="description" content="{{ $description }}">@endif
    <meta property="og:title" content="{{ $title ? $title.' · ' : '' }}Waumini">
    <meta property="og:description" content="{{ $description ?? __('La plateforme de gestion des communautés de foi en RDC.') }}">
    <meta property="og:image" content="{{ asset('icons/icon-512.png') }}">
</head>
<body class="min-h-dvh" x-data="{ menu: false }">
    {{-- En-tête du site --}}
    <header class="wax wax-veil wax-veil-strong sticky top-0 z-40 text-white" style="padding-top: env(safe-area-inset-top)">
        <div class="mx-auto flex h-16 max-w-6xl items-center gap-6 px-4 sm:px-6">
            <a href="{{ route('home') }}" aria-label="{{ __('Accueil de Waumini') }}"><x-logo light /></a>
            <nav class="ml-auto hidden items-center gap-6 text-sm md:flex" aria-label="{{ __('Navigation du site') }}">
                <a href="{{ route('home') }}#fonctionnalites" class="text-ink-100 hover:text-white">{{ __('Fonctionnalités') }}</a>
                <a href="{{ route('home') }}#denominations" class="text-ink-100 hover:text-white">{{ __('Dénominations') }}</a>
                <a href="{{ route('home') }}#securite" class="text-ink-100 hover:text-white">{{ __('Sécurité') }}</a>
                <a href="{{ route('help.index') }}" class="text-ink-100 hover:text-white">{{ __('Aide') }}</a>
                @guest<a href="{{ route('demo.show') }}" class="text-ink-100 hover:text-white">{{ __('Démo') }}</a>@endguest
                @auth
                    <a href="{{ route('dashboard') }}" class="btn-accent !min-h-0 !py-2">{{ __('Ouvrir Waumini') }}</a>
                @else
                    <a href="{{ route('login') }}" class="font-semibold text-white hover:underline">{{ __('Se connecter') }}</a>
                    <a href="{{ route('register') }}" class="btn-accent !min-h-0 !py-2">{{ __('Essai gratuit') }}</a>
                @endauth
            </nav>
            <button type="button" class="ml-auto rounded-xl p-2 hover:bg-white/10 md:hidden" @click="menu = !menu" :aria-expanded="menu" aria-label="{{ __('Menu') }}">
                <x-icon name="menu" class="size-6" x-show="!menu" />
                <x-icon name="x" class="size-6" x-show="menu" x-cloak />
            </button>
        </div>
        <nav x-cloak x-show="menu" x-transition class="border-t border-white/10 px-4 pb-4 md:hidden" aria-label="{{ __('Navigation du site') }}">
            <div class="grid gap-1 pt-2 text-base">
                <a href="{{ route('home') }}#fonctionnalites" @click="menu = false" class="rounded-xl px-3 py-2.5 hover:bg-white/10">{{ __('Fonctionnalités') }}</a>
                <a href="{{ route('home') }}#denominations" @click="menu = false" class="rounded-xl px-3 py-2.5 hover:bg-white/10">{{ __('Dénominations') }}</a>
                <a href="{{ route('home') }}#securite" @click="menu = false" class="rounded-xl px-3 py-2.5 hover:bg-white/10">{{ __('Sécurité') }}</a>
                <a href="{{ route('help.index') }}" class="rounded-xl px-3 py-2.5 hover:bg-white/10">{{ __('Aide') }}</a>
                @guest<a href="{{ route('demo.show') }}" class="rounded-xl px-3 py-2.5 hover:bg-white/10">{{ __('Essayer la démo') }}</a>@endguest
                @auth
                    <a href="{{ route('dashboard') }}" class="btn-accent mt-2">{{ __('Ouvrir Waumini') }}</a>
                @else
                    <div class="mt-2 grid grid-cols-2 gap-2">
                        <a href="{{ route('login') }}" class="btn border-[1.5px] border-white/40 text-white">{{ __('Se connecter') }}</a>
                        <a href="{{ route('register') }}" class="btn-accent">{{ __('Essai gratuit') }}</a>
                    </div>
                @endauth
            </div>
        </nav>
    </header>

    <main>
        {{ $slot }}
    </main>

    {{-- Pied de page --}}
    <footer class="bg-ink-900 text-ink-100">
        <div class="mx-auto grid max-w-6xl gap-8 px-4 py-12 sm:px-6 md:grid-cols-[1.4fr_1fr_1fr]">
            <div class="space-y-3">
                <x-logo light />
                <p class="max-w-sm text-sm">{{ __('Waumini signifie « les fidèles » en swahili. La plateforme de gestion des églises et des communautés de foi, conçue à Goma pour la RDC.') }}</p>
            </div>
            <div class="space-y-2 text-sm">
                <p class="font-semibold text-white">{{ $contact['company'] }}</p>
                <p>{{ $contact['city'] }}</p>
                <p class="select-all">{{ $contact['email'] }}</p>
                @if ($contact['phone'])<p class="select-all">{{ \App\Support\Phone::format($contact['phone']) }}</p>@endif
            </div>
            <div class="grid content-start gap-2 text-sm">
                <a href="{{ route('register') }}" class="hover:text-white">{{ __('Créer le compte de mon église') }}</a>
                <a href="{{ route('login') }}" class="hover:text-white">{{ __('Se connecter') }}</a>
                <a href="{{ route('install') }}" class="hover:text-white">{{ __('Installer l’application') }}</a>
                <a href="{{ route('help.index') }}" class="hover:text-white">{{ __('Manuel d’utilisation') }}</a>
                <a href="{{ route('legal.terms') }}" class="hover:text-white">{{ __('Conditions d’utilisation') }}</a>
                <a href="{{ route('legal.privacy') }}" class="hover:text-white">{{ __('Confidentialité') }}</a>
            </div>
        </div>
        <p class="border-t border-white/10 px-4 py-4 text-center text-xs text-ink-200">© {{ now()->year }} {{ $contact['company'] }} · Waumini</p>
    </footer>

    <x-toasts />
    @livewireScripts
    @include('partials.service-worker')
</body>
</html>
