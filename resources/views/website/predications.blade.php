@extends('website.layout', ['title' => __('Prédications')])

@section('content')
    @include('website.partials.title', ['title' => __('Prédications'), 'intro' => __('Écoutez ou regardez les messages prêchés dans notre communauté.')])
    <div class="mx-auto grid max-w-6xl gap-5 px-4 pt-10 sm:px-6 md:grid-cols-2">
        @forelse ($sermons as $sermon)
            <x-website.sermon :sermon="$sermon" :organization="$organization" class="min-w-0" />
        @empty
            <p class="text-sand-700">{{ __('Aucune prédication publiée pour le moment.') }}</p>
        @endforelse
    </div>
@endsection
