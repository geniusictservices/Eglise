@php
    $theme = $organization->theme();
    $style = $website->theme;
    $logo = $identity->logoUrl();
    $nav = collect(\App\Models\Website::NAV)->filter(fn ($l, $k) => $website->hasPage($k) && ($k !== 'paroisses' || $organization->children()->exists()));
    $dark = $style !== 'lumiere';
    $whatsapp = $website->whatsapp ? preg_replace('/\D/', '', $website->whatsapp) : null;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $organization->locale ?: app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ $organization->name }}</title>
    <meta name="description" content="{{ \Illuminate\Support\Str::limit(strip_tags($description ?? $website->welcome_text ?? $website->tagline ?? $organization->name), 160) }}">
    <meta property="og:title" content="{{ isset($title) ? $title.' · ' : '' }}{{ $organization->name }}">
    <meta property="og:type" content="website">
    @if ($website->cover_path)<meta property="og:image" content="{{ route('website.cover', $organization->slug) }}">@endif
    @if (! $website->is_published || $organization->is_demo)<meta name="robots" content="noindex">@endif
    <meta name="theme-color" content="{{ $theme->primary }}">
    @if ($logo)<link rel="icon" href="{{ $logo }}">@else<link rel="icon" href="{{ asset('icons/favicon.svg') }}" type="image/svg+xml">@endif
    @vite(['resources/css/app.css'])
    <style>{!! $theme->css() !!}</style>
</head>
<body @class(['min-h-dvh', 'bg-sand-50' => $style === 'chaleureux', 'bg-white' => $style === 'lumiere', 'bg-[#FBF8F2]' => $style === 'solennel'])>
    @unless ($website->is_published)
        <div class="bg-ochre-500 px-4 py-2 text-center text-sm font-semibold text-[var(--color-on-accent)]">{{ __('Aperçu : ce site n’est pas encore publié. Seuls ceux qui le gèrent le voient.') }}</div>
    @endunless

    <header @class(['relative z-20', 'bg-ink-800 text-white' => $style === 'chaleureux', 'border-b border-sand-200 bg-white text-ink-800' => $style === 'lumiere', 'bg-ink-900 text-white' => $style === 'solennel'])>
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
            <a href="{{ route('website.home', $organization->slug) }}" class="flex min-w-0 items-center gap-3">
                @if ($logo)
                    <img src="{{ $logo }}" alt="" class="size-11 shrink-0 rounded-xl bg-white object-contain p-1">
                @else
                    <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-ochre-500 font-bold text-[var(--color-on-accent)]">{{ $organization->initials() }}</span>
                @endif
                <span class="min-w-0">
                    <span @class(['block truncate text-lg font-semibold leading-tight', 'font-serif' => $style === 'solennel'])>{{ $organization->name }}</span>
                    @if ($website->tagline)<span @class(['block truncate text-xs', 'text-white/75' => $dark, 'text-sand-700' => ! $dark])>{{ $website->tagline }}</span>@endif
                </span>
            </a>
            <nav class="hidden items-center gap-0.5 xl:flex" aria-label="{{ __('Pages du site') }}">
                @foreach ($nav as $key => $label)
                    <a href="{{ route('website.page', [$organization->slug, $key]) }}" @class(['whitespace-nowrap rounded-xl px-3 py-2 text-sm font-medium transition',
                        'bg-white/15' => $dark && $page === $key, 'hover:bg-white/10' => $dark,
                        'bg-ink-50 text-ink-800' => ! $dark && $page === $key, 'hover:bg-ink-50' => ! $dark])>{{ __($label) }}</a>
                @endforeach
            </nav>
            <details class="group relative xl:hidden">
                <summary @class(['flex cursor-pointer list-none items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold [&::-webkit-details-marker]:hidden', 'bg-white/15' => $dark, 'bg-ink-50' => ! $dark])>
                    <x-icon name="menu" class="size-5" /> {{ __('Menu') }}
                </summary>
                <div class="absolute right-0 mt-2 w-64 rounded-2xl border border-sand-200 bg-white p-2 text-ink-800 shadow-xl">
                    <a href="{{ route('website.home', $organization->slug) }}" class="block rounded-xl px-3 py-2.5 font-medium hover:bg-sand-100">{{ __('Accueil') }}</a>
                    @foreach ($nav as $key => $label)
                        <a href="{{ route('website.page', [$organization->slug, $key]) }}" @class(['block rounded-xl px-3 py-2.5 font-medium hover:bg-sand-100', 'bg-ink-50' => $page === $key])>{{ __($label) }}</a>
                    @endforeach
                </div>
            </details>
        </div>
    </header>

    <main>
        @yield('content')
    </main>

    <footer @class(['mt-16', 'wax wax-veil text-white' => $style === 'chaleureux', 'border-t border-sand-200 bg-sand-50 text-ink-800' => $style === 'lumiere', 'bg-ink-900 text-white' => $style === 'solennel'])>
        <div class="mx-auto grid max-w-6xl gap-8 px-4 py-10 sm:grid-cols-3 sm:px-6">
            <div class="min-w-0">
                <p @class(['text-lg font-semibold', 'font-serif' => $style === 'solennel', 'text-white' => $dark])>{{ $organization->name }}</p>
                @if ($organization->parent_id)<p @class(['mt-1 text-sm', 'text-white/75' => $dark, 'text-sand-700' => ! $dark])>{{ $organization->root()->name }}</p>@endif
            </div>
            <ul class="min-w-0 space-y-2 text-sm">
                @if ($organization->address)<li class="flex gap-2"><x-icon name="map-pin" class="mt-0.5 size-4 shrink-0" /> <span>{{ $organization->address }}@if ($organization->city), {{ $organization->city }}@endif</span></li>@endif
                @if ($organization->phone)<li class="flex gap-2"><x-icon name="phone" class="mt-0.5 size-4 shrink-0" /> <a href="tel:{{ $organization->phone }}" class="underline-offset-4 hover:underline">{{ $organization->phone }}</a></li>@endif
                @if ($whatsapp)<li class="flex gap-2"><x-icon name="message-circle" class="mt-0.5 size-4 shrink-0" /> <a href="https://wa.me/{{ $whatsapp }}" class="underline-offset-4 hover:underline">WhatsApp</a></li>@endif
                @if ($organization->email)<li class="flex gap-2"><x-icon name="mail" class="mt-0.5 size-4 shrink-0" /> <a href="mailto:{{ $organization->email }}" class="break-all underline-offset-4 hover:underline">{{ $organization->email }}</a></li>@endif
            </ul>
            <div class="min-w-0 space-y-2 text-sm">
                @if ($website->facebook_url)<a href="{{ $website->facebook_url }}" rel="noopener" target="_blank" class="flex items-center gap-2 underline-offset-4 hover:underline"><x-icon name="external-link" class="size-4" /> Facebook</a>@endif
                @if ($website->youtube_url)<a href="{{ $website->youtube_url }}" rel="noopener" target="_blank" class="flex items-center gap-2 underline-offset-4 hover:underline"><x-icon name="play" class="size-4" /> YouTube</a>@endif
                <p @class(['pt-2 text-xs', 'text-white/60' => $dark, 'text-sand-700' => ! $dark])>{{ __('Site tenu avec') }} <a href="{{ route('home') }}" class="font-semibold underline-offset-4 hover:underline">Waumini</a></p>
            </div>
        </div>
    </footer>
    <script>
        document.addEventListener('click', (e) => {
            const button = e.target.closest('[data-embed]');
            if (!button) return;
            const frame = document.createElement('iframe');
            frame.src = button.dataset.embed;
            frame.title = button.dataset.title;
            frame.className = 'size-full';
            frame.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
            frame.allowFullscreen = true;
            button.replaceWith(frame);
        });
    </script>
</body>
</html>
