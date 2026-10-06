@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => $title])
</head>
<body class="min-h-dvh">
    <header class="wax wax-veil wax-veil-strong" style="padding-top: env(safe-area-inset-top)">
        <div class="mx-auto flex h-16 max-w-5xl items-center px-4 sm:px-6">
            <a href="{{ url('/') }}"><x-logo light /></a>
        </div>
    </header>
    <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6 sm:py-12">
        {{ $slot }}
    </main>
    @livewireScripts
    @include('partials.service-worker')
</body>
</html>
