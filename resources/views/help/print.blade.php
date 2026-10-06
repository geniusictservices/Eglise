<!DOCTYPE html>
<html lang="fr">
<head>
    @include('partials.head', ['title' => __('Manuel d’utilisation')])
    <style>
        @page { size: A4; margin: 16mm 14mm; }
        @media print {
            .no-print { display: none !important; }
            .chapitre, .apres-couverture { break-before: page; }
            .manuel a.manuel-capture { pointer-events: none; }
            .manuel table { break-inside: avoid; }
            body { background: #fff; }
        }
        .manuel img { max-height: 520px; width: auto; }
    </style>
</head>
<body class="bg-white text-ink-900">
    <div class="no-print sticky top-0 z-10 flex flex-wrap items-center gap-3 border-b border-sand-200 bg-sand-50 px-4 py-3 sm:px-8">
        <a href="{{ route('help.index') }}" class="inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Retour au manuel') }}</a>
        <p class="min-w-0 flex-1 text-sm text-sand-700">{{ __('Choisissez « Enregistrer au format PDF » comme imprimante pour obtenir le PDF.') }}</p>
        <button type="button" onclick="window.print()" class="btn-primary"><x-icon name="printer" class="size-4" /> {{ __('Imprimer ou enregistrer en PDF') }}</button>
    </div>

    <main class="mx-auto max-w-4xl px-4 py-10 sm:px-8">
        <section class="mb-10 flex min-h-[60vh] flex-col justify-center border-b border-sand-200 pb-10">
            <x-logo class="h-12 w-auto" />
            <h1 class="mt-8 text-4xl font-semibold text-ink-800">{{ __('Manuel d’utilisation de Waumini') }}</h1>
            <p class="mt-3 text-lg text-sand-700">{{ __('Pour les communautés de foi : églises indépendantes, dénominations, paroisses, et toute autre communauté.') }}</p>
            <p class="mt-8 text-sm text-sand-700">{{ __('Genius ICT, Goma · édition du :d · :n chapitres', ['d' => now()->translatedFormat('j F Y'), 'n' => $chapters->count()]) }}</p>
        </section>

        <nav class="apres-couverture mb-10" aria-label="{{ __('Sommaire') }}">
            <h2 class="mb-4 text-2xl font-semibold text-ink-800">{{ __('Sommaire') }}</h2>
            <ol class="columns-1 gap-8 text-sm sm:columns-2">
                @foreach ($chapters as $c)
                    <li class="mb-1.5 break-inside-avoid"><a href="#chapitre-{{ $c['slug'] }}" class="text-ink-700 hover:underline">{{ $c['title'] }}</a></li>
                @endforeach
            </ol>
        </nav>

        <article class="apres-couverture manuel">{!! preg_replace('/<h2 id="sommaire">.*?(?=<h2)/s', '', $intro) !!}</article>

        @foreach ($chapters as $c)
            <section id="chapitre-{{ $c['slug'] }}" class="chapitre manuel mt-12">{!! $c['html'] !!}</section>
        @endforeach
    </main>
</body>
</html>
