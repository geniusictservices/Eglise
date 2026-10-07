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
        @if (! empty($website->leaders))
            <section>
                <x-website.heading :website="$website" :eyebrow="__('Ils vous accueillent')">{{ __('Nos responsables') }}</x-website.heading>
                <ul class="grid grid-cols-2 gap-x-4 gap-y-6 sm:grid-cols-3">
                    @foreach ($website->leaders as $i => $leader)
                        <li class="text-center">
                            @if ($leader['photo_path'] ?? null)
                                <img src="{{ route('website.leader', [$organization->slug, $i, 'v' => substr(md5($leader['photo_path']), 0, 8)]) }}" alt="" loading="lazy" class="mx-auto size-28 rounded-full object-cover ring-4 ring-white shadow-md sm:size-32">
                            @else
                                <span class="mx-auto grid size-28 place-items-center rounded-full bg-ink-50 text-3xl font-semibold text-ink-700 ring-4 ring-white sm:size-32">{{ collect(preg_split('/\s+/', $leader['name']))->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('') }}</span>
                            @endif
                            <p @class(['mt-3 font-semibold text-ink-800', 'font-serif text-lg' => $website->theme === 'solennel'])>{{ $leader['name'] }}</p>
                            @if ($leader['role'] ?? null)<p class="text-sm text-sand-700">{{ $leader['role'] }}</p>@endif
                        </li>
                    @endforeach
                </ul>
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
