@extends('website.layout', ['title' => $sermon->title, 'description' => $sermon->summary])

@section('content')
    <div class="mx-auto max-w-3xl px-4 pt-10 sm:px-6">
        <a href="{{ route('website.page', [$organization->slug, 'predications']) }}" class="mb-4 inline-flex items-center gap-1 text-sm font-semibold text-ink-700 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Toutes les prédications') }}</a>
        <x-website.sermon :sermon="$sermon" :organization="$organization" :full="true" />
    </div>
@endsection
