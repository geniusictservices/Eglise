<x-layouts.site :title="$title">
    <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6">
        <article class="manuel">
            <h1>{{ $title }}</h1>
            <p class="!mt-0 text-sm text-sand-700">{{ __('Version :v, en vigueur depuis le :date.', ['v' => $document->version, 'date' => $document->published_at->translatedFormat('j F Y')]) }}</p>
            {!! $html !!}
        </article>
    </div>
</x-layouts.site>
