@extends('website.layout', ['title' => __('Qui sommes-nous')])

@section('content')
    @include('website.partials.title', ['title' => __('Qui sommes-nous')])
    <div class="mx-auto max-w-3xl space-y-12 px-4 pt-10 sm:px-6">
        @if ($website->about_text)
            <section><x-website.text :text="$website->about_text" class="text-lg text-ink-900" /></section>
        @endif
        @if ($organization->parent_id)
            <p class="rounded-2xl bg-ink-50 p-4 text-ink-800">{{ __(':n fait partie de :r.', ['n' => $organization->name, 'r' => $organization->root()->name]) }}</p>
        @endif
        @if ($website->beliefs_text)
            <section>
                <x-website.heading :website="$website">{{ __('Ce que nous croyons') }}</x-website.heading>
                <x-website.text :text="$website->beliefs_text" class="text-ink-900" />
            </section>
        @endif
        @if ($website->pastor_message)
            <section class="rounded-3xl border border-sand-200 bg-white p-6">
                <x-website.heading :website="$website" :eyebrow="__('Le mot du :t', ['t' => mb_strtolower($organization->term('pasteur'))])">{{ $website->pastor_name ?: $organization->term('pasteur') }}</x-website.heading>
                <x-website.text :text="$website->pastor_message" class="text-ink-900" />
            </section>
        @endif
    </div>
@endsection
