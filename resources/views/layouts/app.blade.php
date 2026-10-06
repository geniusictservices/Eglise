@php
    use App\Support\Navigation;
    $sections = Navigation::sections();
    $mobileItems = Navigation::mobile();
    $organization = current_organization();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => $title ?? null])
</head>
<body class="min-h-dvh" x-data="{ drawer: false }">
    <div class="lg:flex">
        {{-- Barre latérale (ordinateur) --}}
        <aside class="wax wax-veil wax-veil-strong hidden text-ink-50 lg:fixed lg:inset-y-0 lg:flex lg:w-72 lg:flex-col">
            @include('partials.sidebar')
        </aside>

        {{-- Tiroir (téléphone) --}}
        <div x-cloak x-show="drawer" class="fixed inset-0 z-40 lg:hidden" role="dialog" aria-modal="true">
            <div x-show="drawer" x-transition.opacity class="absolute inset-0 bg-ink-900/60" @click="drawer = false"></div>
            <aside x-show="drawer" x-transition:enter="transition duration-200" x-transition:enter-start="-translate-x-full" x-transition:leave="transition duration-150" x-transition:leave-end="-translate-x-full"
                   class="wax wax-veil wax-veil-strong absolute inset-y-0 left-0 flex w-[85%] max-w-xs flex-col text-ink-50" style="padding-top: env(safe-area-inset-top)">
                <button type="button" class="absolute right-3 top-3 z-10 rounded-lg p-2 text-ink-100 hover:bg-white/10" @click="drawer = false" aria-label="{{ __('Fermer le menu') }}">
                    <x-icon name="x" />
                </button>
                @include('partials.sidebar')
            </aside>
        </div>

        <div class="flex min-h-dvh flex-1 flex-col lg:pl-72">
            {{-- En-tête téléphone : bandeau wax --}}
            <header class="wax wax-veil sticky top-0 z-30 text-white lg:hidden" style="padding-top: env(safe-area-inset-top)">
                <div class="flex h-14 items-center gap-2 px-3">
                    <button type="button" class="rounded-xl p-2 hover:bg-white/10" @click="drawer = true" aria-label="{{ __('Ouvrir le menu') }}">
                        <x-icon name="menu" class="size-6" />
                    </button>
                    <a href="{{ route('dashboard') }}" class="min-w-0 flex-1" aria-label="{{ __('Tableau de bord') }}">
                        <span class="block text-[11px] leading-tight text-ink-100">{{ $organization?->level_label }}</span>
                        <span class="block truncate font-semibold leading-tight">{{ $organization?->displayName() }}</span>
                    </a>
                    <livewire:notifications.bell :on-dark="true" />
                    @include('partials.user-menu', ['onDark' => true])
                </div>
            </header>

            {{-- En-tête ordinateur --}}
            <header class="sticky top-0 z-30 hidden border-b border-sand-200 bg-sand-50/95 backdrop-blur lg:block">
                <div class="flex h-16 items-center gap-3 px-8">
                    <div class="min-w-0 flex-1">
                        @isset($breadcrumb)
                            {{ $breadcrumb }}
                        @else
                            <p class="text-sm text-sand-700">{{ $organization?->level_label }} · <span class="font-semibold text-ink-800">{{ $organization?->name }}</span></p>
                        @endisset
                    </div>
                    <x-install-button />
                    <livewire:notifications.bell :on-dark="false" />
                    @include('partials.user-menu', ['onDark' => false])
                </div>
            </header>

            @if ($support = app(\App\Support\SupportAccess::class)->organization())
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-ink-800 bg-ink-800 px-4 py-2.5 text-sm text-white sm:px-6 lg:px-8">
                    <x-icon name="shield-check" class="size-4 shrink-0 text-ochre-300" />
                    <span class="min-w-0 flex-1">{{ __('Support Genius ICT : vous voyez :n en lecture seule, avec son accord, jusqu’au :d.', ['n' => $support->name, 'd' => $support->support_access_until->translatedFormat('j F à H:i')]) }}</span>
                    <form method="POST" action="{{ route('support.leave') }}">@csrf<button class="font-semibold underline underline-offset-4">{{ __('Quitter') }}</button></form>
                </div>
            @endif
            @if ($organization?->status === 'grace')
                <div class="border-b border-ochre-300 bg-ochre-50 px-4 py-2.5 text-sm text-ink-800 sm:px-6 lg:px-8">
                    <x-icon name="clock" class="mr-1 inline size-4 text-ochre-600" />
                    {{ __('L’abonnement est à renouveler : la communauté passera bientôt en lecture seule.') }}
                    @can('organization.settings')<a href="{{ route('subscription') }}" class="font-semibold underline decoration-ochre-300 underline-offset-4">{{ __('Voir l’abonnement') }}</a>@endcan
                </div>
            @endif
            @if ($organization?->isReadOnly())
                <div class="border-b border-terra-100 bg-terra-50 px-4 py-2.5 text-sm text-terra-700 sm:px-6 lg:px-8">
                    <x-icon name="lock" class="mr-1 inline size-4" />
                    {{ __('Cette communauté est en lecture seule. Vos données restent consultables et exportables.') }}
                </div>
            @endif

            <main class="flex-1 px-4 pb-32 pt-5 sm:px-6 lg:px-8 lg:pb-12 lg:pt-8">
                <div class="mx-auto max-w-6xl">
                    {{ $slot }}
                </div>
            </main>
        </div>
    </div>

    {{-- Barre d'onglets flottante (téléphone) --}}
    <nav class="fixed inset-x-3 z-30 rounded-[22px] bg-ink-700 text-ink-200 shadow-lg shadow-ink-900/25 lg:hidden" style="bottom: calc(env(safe-area-inset-bottom) + 0.75rem)" aria-label="{{ __('Navigation principale') }}">
        <div class="mx-auto grid max-w-md grid-cols-4">
            @foreach ($mobileItems as $item)
                @php $active = Navigation::isActive($item['route']); @endphp
                <a href="{{ route($item['route']) }}" @class(['flex flex-col items-center gap-0.5 py-2.5 text-[11px]', 'font-semibold text-ochre-500' => $active, 'hover:text-white' => ! $active]) @if($active) aria-current="page" @endif>
                    <x-icon :name="$item['icon']" class="size-[22px]" />
                    {{ $item['short'] ?? $item['label'] }}
                </a>
            @endforeach
            <button type="button" @click="drawer = true" class="flex flex-col items-center gap-0.5 py-2.5 text-[11px] hover:text-white">
                <x-icon name="ellipsis" class="size-[22px]" />
                {{ __('Plus') }}
            </button>
        </div>
    </nav>

    <x-toasts />
    @livewireScripts
    @include('partials.service-worker')
</body>
</html>
