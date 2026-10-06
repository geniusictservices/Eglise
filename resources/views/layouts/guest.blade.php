<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => $title ?? null])
</head>
<body class="min-h-dvh">
    <div class="grid min-h-dvh lg:grid-cols-[1fr_minmax(0,560px)]">
        {{-- Panneau de marque (ordinateur) --}}
        <div class="relative hidden overflow-hidden bg-ink-700 p-12 text-ink-50 lg:flex lg:flex-col">
            <x-logo light />
            <div class="mt-auto max-w-lg">
                <p class="eyebrow !text-ochre-300">{{ __('La mémoire de votre communauté') }}</p>
                <p class="mt-4 font-display text-4xl font-semibold leading-tight text-white">{{ __('Le registre de vos fidèles, vos finances en toute transparence, votre année préparée ensemble.') }}</p>
                <p class="mt-6 text-ink-200">{{ __('Waumini signifie « les fidèles » en swahili.') }}</p>
            </div>
            <svg class="pointer-events-none absolute -right-24 -top-16 size-[460px] opacity-[0.07]" viewBox="6 11 108 108" aria-hidden="true">
                <path d="M16,30 C16,74 29,100 45,100 C55,100 60,91 60,78 C60,91 65,100 75,100 C91,100 104,74 104,30" fill="none" stroke="#fff" stroke-width="15" stroke-linecap="round"/>
                <circle cx="40" cy="56" r="9" fill="#fff"/><circle cx="60" cy="44" r="10.5" fill="#fff"/><circle cx="80" cy="56" r="9" fill="#fff"/>
            </svg>
        </div>

        <main class="flex flex-col px-5 py-8 sm:px-10" style="padding-top: max(2rem, env(safe-area-inset-top))">
            <div class="lg:hidden"><x-logo /></div>
            <div class="my-auto w-full max-w-md self-center py-10">
                {{ $slot }}
            </div>
            <p class="text-center text-xs text-sand-500">Waumini · Genius ICT · Goma</p>
        </main>
    </div>
    <x-toasts />
    @livewireScripts
    @include('partials.service-worker')
</body>
</html>
