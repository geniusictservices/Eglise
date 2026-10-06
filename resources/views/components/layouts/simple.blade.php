@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => $title])
</head>
<body class="min-h-dvh">
    <header class="border-b border-sand-200 bg-white" style="padding-top: env(safe-area-inset-top)">
        <div class="mx-auto flex h-16 max-w-4xl items-center px-4 sm:px-6">
            <a href="{{ url('/') }}"><x-logo /></a>
        </div>
    </header>
    <main class="mx-auto max-w-4xl px-4 py-8 sm:px-6 sm:py-12">
        {{ $slot }}
    </main>
    @livewireScripts
    @include('partials.service-worker')
</body>
</html>
