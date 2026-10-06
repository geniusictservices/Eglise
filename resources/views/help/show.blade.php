<x-layouts.simple :title="$title">
    <div class="grid gap-8 lg:grid-cols-[240px_minmax(0,1fr)]">
        <nav class="lg:sticky lg:top-6 lg:self-start" aria-label="{{ __('Chapitres du manuel') }}" x-data="{ open: false }">
            <button type="button" class="btn-secondary w-full justify-between lg:hidden" @click="open = !open" :aria-expanded="open">
                <span class="flex items-center gap-2"><x-icon name="book-open" class="size-4" /> {{ __('Sommaire') }}</span>
                <x-icon name="chevron-down" class="size-4" />
            </button>
            <ul class="mt-2 space-y-0.5 lg:mt-0 lg:block" :class="open ? 'block' : 'hidden'">
                <li><a href="{{ route('help.index') }}" @class(['block rounded-lg px-3 py-2 text-sm', 'bg-ink-700 font-semibold text-white' => ! $chapter, 'text-ink-700 hover:bg-sand-100' => $chapter])>{{ __('Accueil du manuel') }}</a></li>
                @foreach ($chapters as $slug => $label)
                    <li><a href="{{ route('help.show', $slug) }}" @class(['block rounded-lg px-3 py-2 text-sm', 'bg-ink-700 font-semibold text-white' => $chapter === $slug, 'text-ink-700 hover:bg-sand-100' => $chapter !== $slug])>{{ $label }}</a></li>
                @endforeach
            </ul>
            <a href="{{ route('help.print') }}" class="mt-4 flex items-center gap-1 px-3 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="printer" class="size-4" /> {{ __('Manuel complet, à imprimer ou en PDF') }}</a>
            @auth
                <a href="{{ route('dashboard') }}" class="mt-4 hidden items-center gap-1 px-3 text-sm font-semibold text-ink-600 hover:underline lg:flex"><x-icon name="chevron-left" class="size-4" /> {{ __('Retour à Waumini') }}</a>
            @endauth
        </nav>

        <article class="manuel min-w-0">
            {!! $html !!}
        </article>
    </div>
</x-layouts.simple>
