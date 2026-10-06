@php
    use App\Support\Navigation;
    $sections = Navigation::sections();
    $mobileItems = Navigation::mobile();
    $user = auth()->user();
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
        <aside class="hidden lg:fixed lg:inset-y-0 lg:flex lg:w-72 lg:flex-col bg-ink-700 text-ink-50">
            @include('partials.sidebar')
        </aside>

        {{-- Tiroir (téléphone) --}}
        <div x-cloak x-show="drawer" class="fixed inset-0 z-40 lg:hidden" role="dialog" aria-modal="true">
            <div x-show="drawer" x-transition.opacity class="absolute inset-0 bg-ink-900/60" @click="drawer = false"></div>
            <aside x-show="drawer" x-transition:enter="transition duration-200" x-transition:enter-start="-translate-x-full" x-transition:leave="transition duration-150" x-transition:leave-end="-translate-x-full"
                   class="absolute inset-y-0 left-0 flex w-[85%] max-w-xs flex-col bg-ink-700 text-ink-50" style="padding-top: env(safe-area-inset-top)">
                <button type="button" class="absolute right-3 top-3 rounded-lg p-2 text-ink-100 hover:bg-ink-600" @click="drawer = false" aria-label="{{ __('Fermer le menu') }}">
                    <x-icon name="x" />
                </button>
                @include('partials.sidebar')
            </aside>
        </div>

        <div class="flex min-h-dvh flex-1 flex-col lg:pl-72">
            {{-- En-tête --}}
            <header class="sticky top-0 z-30 border-b border-sand-200 bg-sand-50/95 backdrop-blur" style="padding-top: env(safe-area-inset-top)">
                <div class="flex h-14 items-center gap-3 px-4 sm:px-6 lg:h-16 lg:px-8">
                    <button type="button" class="-ml-2 rounded-lg p-2 text-ink-700 hover:bg-sand-100 lg:hidden" @click="drawer = true" aria-label="{{ __('Ouvrir le menu') }}">
                        <x-icon name="menu" class="size-6" />
                    </button>
                    <a href="{{ route('dashboard') }}" class="lg:hidden" aria-label="Waumini">
                        <x-logo-mark class="size-8" />
                    </a>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-display text-base font-semibold text-ink-700 lg:hidden">{{ $organization?->displayName() }}</p>
                        <div class="hidden lg:block">
                            @isset($breadcrumb)
                                {{ $breadcrumb }}
                            @else
                                <p class="text-sm text-sand-700">{{ $organization?->level_label }} · <span class="font-bold text-ink-700">{{ $organization?->name }}</span></p>
                            @endisset
                        </div>
                    </div>

                    <x-install-button class="hidden sm:inline-flex" />

                    <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
                        <button type="button" @click="open = !open" class="flex items-center gap-2 rounded-full p-1 pr-1 hover:bg-sand-100 sm:pr-3" aria-haspopup="menu" :aria-expanded="open">
                            <span class="grid size-9 place-items-center rounded-full bg-ochre-100 font-display text-sm font-bold text-ochre-700">{{ $user->initials() }}</span>
                            <span class="hidden text-left sm:block">
                                <span class="block max-w-40 truncate text-sm font-bold text-ink-700">{{ $user->name }}</span>
                            </span>
                            <x-icon name="chevron-down" class="hidden size-4 text-sand-500 sm:block" />
                        </button>
                        <div x-cloak x-show="open" x-transition.origin.top.right class="absolute right-0 z-40 mt-2 w-64 overflow-hidden rounded-2xl border border-sand-200 bg-white shadow-xl shadow-ink-900/10" role="menu">
                            <div class="border-b border-sand-100 px-4 py-3">
                                <p class="truncate font-bold text-ink-700">{{ $user->name }}</p>
                                <p class="text-sm text-sand-700 tabular">{{ $user->formattedPhone() }}</p>
                            </div>
                            <a href="{{ route('profile.edit') }}" class="flex items-center gap-3 px-4 py-3 text-sm hover:bg-sand-50" role="menuitem">
                                <x-icon name="user-round" class="size-4 text-sand-500" /> {{ __('Mon profil') }}
                            </a>
                            <a href="{{ route('help.index') }}" class="flex items-center gap-3 px-4 py-3 text-sm hover:bg-sand-50" role="menuitem">
                                <x-icon name="circle-help" class="size-4 text-sand-500" /> {{ __('Aide et manuel') }}
                            </a>
                            <a href="{{ route('install') }}" class="flex items-center gap-3 px-4 py-3 text-sm hover:bg-sand-50" role="menuitem">
                                <x-icon name="download" class="size-4 text-sand-500" /> {{ __('Installer l’application') }}
                            </a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="flex w-full items-center gap-3 border-t border-sand-100 px-4 py-3 text-left text-sm text-terra-600 hover:bg-terra-50" role="menuitem">
                                    <x-icon name="log-out" class="size-4" /> {{ __('Se déconnecter') }}
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            @if ($organization?->isReadOnly())
                <div class="border-b border-terra-100 bg-terra-50 px-4 py-2.5 text-sm text-terra-700 sm:px-6 lg:px-8">
                    <x-icon name="lock" class="mr-1 inline size-4" />
                    {{ __('Cette communauté est en lecture seule. Vos données restent consultables et exportables.') }}
                </div>
            @endif

            <main class="flex-1 px-4 pb-28 pt-5 sm:px-6 lg:px-8 lg:pb-12 lg:pt-8">
                <div class="mx-auto max-w-6xl">
                    {{ $slot }}
                </div>
            </main>
        </div>
    </div>

    {{-- Barre d'onglets (téléphone) --}}
    <nav class="fixed inset-x-0 bottom-0 z-30 border-t border-sand-200 bg-white/95 backdrop-blur lg:hidden" style="padding-bottom: env(safe-area-inset-bottom)" aria-label="{{ __('Navigation principale') }}">
        <div class="mx-auto grid max-w-md grid-cols-4">
            @foreach ($mobileItems as $item)
                @php $active = Navigation::isActive($item['route']); @endphp
                <a href="{{ route($item['route']) }}" @class(['flex flex-col items-center gap-1 py-2.5 text-[11px] font-bold', 'text-ink-700' => $active, 'text-sand-500' => ! $active]) @if($active) aria-current="page" @endif>
                    <span @class(['grid h-7 w-12 place-items-center rounded-full', 'bg-ochre-100 text-ink-700' => $active])><x-icon :name="$item['icon']" class="size-5" /></span>
                    {{ $item['short'] ?? $item['label'] }}
                </a>
            @endforeach
            <button type="button" @click="drawer = true" class="flex flex-col items-center gap-1 py-2.5 text-[11px] font-bold text-sand-500">
                <span class="grid h-7 w-12 place-items-center rounded-full"><x-icon name="ellipsis" class="size-5" /></span>
                {{ __('Plus') }}
            </button>
        </div>
    </nav>

    <x-toasts />
    @livewireScripts
    @include('partials.service-worker')
</body>
</html>
