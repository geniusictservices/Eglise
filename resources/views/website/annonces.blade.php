@extends('website.layout', ['title' => __('Annonces')])

@section('content')
    @include('website.partials.title', ['title' => __('Annonces')])
    <div class="mx-auto max-w-3xl space-y-4 px-4 pt-10 sm:px-6">
        @forelse ($announcements as $a)
            <article class="rounded-2xl border border-sand-200 bg-white p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-ochre-600">{{ $a->published_at?->translatedFormat('j F Y') }}</p>
                <h2 class="mt-1 text-xl font-semibold text-ink-800">{{ $a->title }}</h2>
                <x-website.text :text="$a->body" class="mt-2 text-ink-900" />
            </article>
        @empty
            <p class="text-sand-700">{{ __('Aucune annonce pour le moment.') }}</p>
        @endforelse
    </div>
@endsection
