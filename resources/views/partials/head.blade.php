<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="app-url" content="{{ url('/') }}">
<title>{{ $title ? $title.' · ' : '' }}Waumini</title>
<meta name="description" content="Waumini, la plateforme de gestion des communautés de foi.">
@php $theme = current_organization()?->theme() ?? \App\Support\Theme::default(); @endphp
<meta name="theme-color" content="{{ $theme->primary }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Waumini">
<meta name="application-name" content="Waumini">
<meta name="msapplication-TileColor" content="#2C2F6B">
<meta name="msapplication-TileImage" content="{{ asset('icons/windows/Square150x150Logo.png') }}">
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="icon" href="{{ asset('icons/favicon.svg') }}" type="image/svg+xml">
<link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
@vite(['resources/css/app.css', 'resources/js/app.js'])
@unless ($theme->isDefault() && $theme->pattern)
    {{-- Couleurs choisies par la communauté (Paramètres > Apparence) --}}
    <style>{!! $theme->css() !!}@unless ($theme->pattern) .wax{background-image:none}@endunless</style>
@endunless
