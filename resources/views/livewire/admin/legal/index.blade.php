<div>
    <x-page-header :title="__('Textes juridiques')" :description="__('Les conditions d’utilisation et la politique de confidentialité publiées sur waumini.com et dans l’application. Chaque publication crée une nouvelle version datée.')" />

    <p class="mb-5 rounded-2xl border border-ochre-300 bg-ochre-50 p-4 text-sm text-ink-800">
        <x-icon name="info" class="mr-1 inline size-4 text-ochre-600" />
        {{ __('Les textes de départ citent le droit congolais (Constitution, Code du numérique de 2023, loi sur la protection du consommateur, OHADA). Faites-les vérifier par un juriste, puis adaptez-les ici.') }}
    </p>

    <div class="grid gap-5 lg:grid-cols-2">
        @foreach ($documents as $doc)
            <section class="card p-5 sm:p-6">
                <div class="flex items-start gap-3">
                    <span class="icon-tile bg-ink-50 text-ink-700"><x-icon name="scroll-text" class="size-5" /></span>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-lg">{{ __($doc['label']) }}</h2>
                        <p class="text-sm text-sand-700">{{ __('Version :v, publiée le :date', ['v' => $doc['current']->version, 'date' => $doc['current']->published_at->translatedFormat('j F Y')]) }}</p>
                    </div>
                </div>
                @if ($doc['draft'])
                    <p class="mt-3 rounded-xl bg-sand-100 px-3 py-2 text-sm text-ink-800">{{ __('Brouillon de la version :v en cours : :s', ['v' => $doc['draft']->version, 's' => $doc['draft']->summary]) }}</p>
                @endif
                <div class="mt-4 flex flex-wrap gap-2">
                    <a href="{{ route('admin.legal.edit', $doc['key']) }}" class="btn-primary"><x-icon name="pencil" class="size-4" /> {{ $doc['draft'] ? __('Reprendre le brouillon') : __('Modifier') }}</a>
                    <a href="{{ $doc['key'] === 'terms' ? route('legal.terms') : route('legal.privacy') }}" target="_blank" class="btn-secondary"><x-icon name="eye" class="size-4" /> {{ __('Voir la page publique') }}</a>
                </div>
                <h3 class="mb-2 mt-5 text-sm font-semibold text-ink-700">{{ __('Historique') }}</h3>
                <ol class="space-y-1.5 text-sm">
                    @foreach ($doc['versions'] as $v)
                        <li class="flex gap-3"><span class="w-10 shrink-0 font-mono text-sand-700">v{{ $v->version }}</span>
                            <span class="flex-1">{{ $v->summary }} <span class="text-sand-700">· {{ $v->published_at->translatedFormat('j M Y') }}{{ $v->author ? ' · '.$v->author->name : '' }}</span></span></li>
                    @endforeach
                </ol>
            </section>
        @endforeach
    </div>
</div>
