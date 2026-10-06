@extends('website.layout', ['title' => __('Événements')])

@section('content')
    @include('website.partials.title', ['title' => __('Événements'), 'intro' => __('Conventions, concerts, retraites, journées d’évangélisation : ce qui se prépare.')])
    <div class="mx-auto max-w-3xl px-4 pt-10 sm:px-6">
        <ul class="space-y-3">
            @forelse ($events as $o)
                <x-website.event :occurrence="$o" :organization="$organization" />
            @empty
                <li class="text-sand-700">{{ __('Aucun événement annoncé pour le moment.') }}</li>
            @endforelse
        </ul>
    </div>
@endsection
