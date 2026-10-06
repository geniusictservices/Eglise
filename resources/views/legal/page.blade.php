<x-layouts.site :title="$title">
    <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6">
        <p class="rounded-2xl bg-ochre-50 p-4 text-sm text-ochre-700 ring-1 ring-ochre-100">{{ __('Version provisoire, à faire valider par un juriste avant le lancement.') }}</p>
        <article class="manuel mt-6">
            {!! $html !!}
        </article>
    </div>
</x-layouts.site>
