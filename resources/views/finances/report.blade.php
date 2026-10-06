<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => $title])
    <style>
        @page { size: A4; margin: 12mm; }
        @media print { body { background: #fff !important; } .no-print { display: none !important; } .sheet { box-shadow: none !important; padding: 0 !important; margin: 0 !important; } * { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body class="min-h-dvh bg-sand-100">
    <div class="no-print sticky top-0 z-10 border-b border-sand-200 bg-white/95">
        <div class="mx-auto flex max-w-4xl flex-wrap items-center gap-3 px-4 py-3">
            <a href="{{ route('finances.reports', $query) }}" class="inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Rapports') }}</a>
            <span class="flex-1"></span>
            <span class="hidden text-xs text-sand-700 sm:inline">{{ __('Pour un PDF, choisissez « Enregistrer au format PDF » comme imprimante.') }}</span>
            <button type="button" onclick="window.print()" class="btn-primary"><x-icon name="printer" class="size-4" /> {{ __('Imprimer ou PDF') }}</button>
        </div>
    </div>
    <main class="sheet mx-auto my-8 max-w-4xl rounded-2xl bg-white p-8 shadow-lg">
        <x-documents.header :identity="$identity" :organization="$organization" />

        <h1 class="mt-6 text-center text-xl font-semibold uppercase tracking-wide text-ink-800">{{ $title }}</h1>
        <p class="mb-6 text-center text-sm text-sand-700">
            {{ __('Du :a au :b', ['a' => $r['from']->translatedFormat('j F Y'), 'b' => $r['to']->translatedFormat('j F Y')]) }} ·
            @if ($closing?->isClosed()) {{ __('période clôturée le :d par :n', ['d' => $closing->closed_at->translatedFormat('j F Y'), 'n' => $closing->closer?->name]) }}
            @else <span class="font-semibold text-terra-600">{{ __('provisoire : période non clôturée') }}</span>
            @endif
        </p>

        @include('finances.partials.report', ['r' => $r])

        <div class="mt-10 grid grid-cols-2 gap-10 text-sm break-inside-avoid">
            @foreach ([__('Le trésorier'), __('Le pasteur responsable')] as $who)
                <div><p class="font-semibold text-ink-800">{{ $who }}</p><div class="mt-12 border-t border-sand-300 pt-1 text-xs text-sand-700">{{ __('Nom et signature') }}</div></div>
            @endforeach
        </div>
        <p class="mt-8 text-center text-xs text-sand-500">{{ __('Établi le :d avec Waumini', ['d' => now()->translatedFormat('j F Y à H:i')]) }}</p>
    </main>
</body>
</html>
