@extends('website.layout', ['title' => __('Programme des cultes')])

@section('content')
    @include('website.partials.title', ['title' => __('Programme des cultes'), 'intro' => __('Nos rencontres régulières : vous êtes le bienvenu, sans inscription.')])
    <div class="mx-auto grid max-w-6xl gap-12 px-4 pt-10 sm:px-6 lg:grid-cols-[3fr_2fr]">
        <section class="min-w-0">
            @forelse ($schedule as $event)
                <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-sand-200 py-4">
                    <div class="min-w-0">
                        <p class="text-lg font-semibold text-ink-800">{{ $event->title }}</p>
                        <p class="text-sm text-sand-700">{{ $event->recurrenceLabel() }}@if ($event->place) · {{ $event->place }}@endif</p>
                        @if ($event->description)<p class="mt-1 text-sm text-ink-900">{{ $event->description }}</p>@endif
                    </div>
                    <p class="shrink-0 text-lg font-semibold tabular text-ochre-600">{{ $event->hours() }}</p>
                </div>
            @empty
                <p class="text-sand-700">{{ __('Le programme sera bientôt publié.') }}</p>
            @endforelse
        </section>
        <section class="min-w-0">
            <x-website.heading :website="$website" :eyebrow="__('Les deux prochaines semaines')">{{ __('Au calendrier') }}</x-website.heading>
            <ul class="divide-y divide-sand-200 rounded-2xl border border-sand-200 bg-white">
                @forelse ($agenda as $o)
                    <li class="flex gap-3 px-4 py-3">
                        <span class="w-24 shrink-0 text-sm font-semibold text-ink-800">{{ ucfirst($o['date']->translatedFormat('D j M')) }}</span>
                        <span class="min-w-0 text-sm"><span class="font-medium text-ink-900">{{ $o['event']->title }}</span>@if ($o['event']->hours())<span class="block text-sand-700">{{ $o['event']->hours() }}</span>@endif</span>
                    </li>
                @empty
                    <li class="px-4 py-3 text-sm text-sand-700">{{ __('Rien de prévu pour l’instant.') }}</li>
                @endforelse
            </ul>
        </section>
    </div>
@endsection
