@php
    use App\Support\AdminNavigation;
    $items = AdminNavigation::items();
    $user = auth()->user();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => isset($title) ? $title.' · '.__('Espace Genius ICT') : __('Espace Genius ICT')])
</head>
<body class="min-h-dvh" x-data="{ drawer: false }">
    <div class="lg:flex">
        @foreach (['desktop', 'mobile'] as $variant)
            @if ($variant === 'mobile')
                <div x-cloak x-show="drawer" class="fixed inset-0 z-40 lg:hidden" role="dialog" aria-modal="true">
                    <div x-show="drawer" x-transition.opacity class="absolute inset-0 bg-ink-900/60" @click="drawer = false"></div>
            @endif
            <aside @if ($variant === 'mobile') x-show="drawer" x-transition:enter="transition duration-200" x-transition:enter-start="-translate-x-full" @endif
                   @class([
                       'wax wax-veil wax-veil-strong flex-col text-ink-50',
                       'hidden lg:fixed lg:inset-y-0 lg:flex lg:w-72' => $variant === 'desktop',
                       'absolute inset-y-0 left-0 flex w-[85%] max-w-xs' => $variant === 'mobile',
                   ])>
                <div class="px-5 pb-2 pt-5">
                    <a href="{{ route('admin.dashboard') }}"><x-logo light /></a>
                    <p class="mt-3 inline-flex items-center gap-1.5 rounded-full bg-ochre-500 px-3 py-1 text-xs font-semibold text-on-accent"><x-icon name="shield-check" class="size-3.5" /> {{ __('Espace Genius ICT') }}</p>
                </div>
                <nav class="mt-4 flex-1 space-y-0.5 overflow-y-auto px-3 pb-6" aria-label="{{ __('Menu') }}">
                    @foreach ($items as $item)
                        @php $active = AdminNavigation::isActive($item['route']); @endphp
                        <a href="{{ route($item['route']) }}" @class([
                            'flex items-center gap-3 rounded-xl px-3 py-2.5 text-[15px] transition',
                            'bg-ochre-500 font-semibold text-on-accent shadow-sm' => $active,
                            'text-ink-50/90 hover:bg-white/10 hover:text-white' => ! $active,
                        ]) @if ($active) aria-current="page" @endif>
                            <x-icon :name="$item['icon']" @class(['size-5', 'text-on-accent' => $active, 'text-ink-200' => ! $active]) /> {{ $item['label'] }}
                        </a>
                    @endforeach
                </nav>
                <div class="border-t border-white/10 px-5 py-4 text-xs text-ink-200">
                    @if ($user->directOrganizations()->isNotEmpty())
                        <a href="{{ route('dashboard') }}" class="flex items-center gap-2 hover:text-white"><x-icon name="arrow-left-right" class="size-4" /> {{ __('Revenir à ma communauté') }}</a>
                    @endif
                    <p class="mt-2">{{ config("waumini.platform_roles.{$user->platform_role}.name") }} · {{ $user->name }}</p>
                </div>
            </aside>
            @if ($variant === 'mobile')
                </div>
            @endif
        @endforeach

        <div class="flex min-h-dvh flex-1 flex-col lg:pl-72">
            <header class="wax wax-veil sticky top-0 z-30 text-white lg:hidden" style="padding-top: env(safe-area-inset-top)">
                <div class="flex h-14 items-center gap-2 px-3">
                    <button type="button" class="rounded-xl p-2 hover:bg-white/10" @click="drawer = true" aria-label="{{ __('Ouvrir le menu') }}"><x-icon name="menu" class="size-6" /></button>
                    <span class="min-w-0 flex-1 truncate font-semibold">{{ __('Espace Genius ICT') }}</span>
                    @include('partials.user-menu', ['onDark' => true])
                </div>
            </header>
            <header class="sticky top-0 z-30 hidden border-b border-sand-200 bg-sand-50/95 backdrop-blur lg:block">
                <div class="flex h-16 items-center gap-3 px-8">
                    <p class="min-w-0 flex-1 text-sm text-sand-700">{{ __('Administration de la plateforme') }} · <span class="font-semibold text-ink-800">{{ config('waumini.domain') }}</span></p>
                    @include('partials.user-menu', ['onDark' => false])
                </div>
            </header>
            <main class="flex-1 px-4 pb-16 pt-5 sm:px-6 lg:px-8 lg:pt-8">
                <div class="mx-auto max-w-6xl">{{ $slot }}</div>
            </main>
        </div>
    </div>
    <x-toasts />
    @livewireScripts
</body>
</html>
