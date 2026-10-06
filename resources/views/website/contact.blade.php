@extends('website.layout', ['title' => __('Nous trouver')])

@section('content')
    @php $whatsapp = $website->whatsapp ? preg_replace('/\D/', '', $website->whatsapp) : null; @endphp
    @include('website.partials.title', ['title' => __('Nous trouver')])
    <div class="mx-auto grid max-w-6xl gap-5 px-4 pt-10 sm:px-6 md:grid-cols-2">
        <section class="min-w-0 rounded-2xl border border-sand-200 bg-white p-6">
            <h2 class="mb-4 text-lg font-semibold text-ink-800">{{ $organization->name }}</h2>
            <ul class="space-y-3 text-ink-900">
                @if ($organization->address)<li class="flex gap-3"><x-icon name="map-pin" class="mt-0.5 size-5 shrink-0 text-ochre-600" /> <span>{{ $organization->address }}@if ($organization->city), {{ $organization->city }}@endif</span></li>@endif
                @if ($organization->phone)<li class="flex gap-3"><x-icon name="phone" class="mt-0.5 size-5 shrink-0 text-ochre-600" /> <a href="tel:{{ $organization->phone }}" class="hover:underline">{{ $organization->phone }}</a></li>@endif
                @if ($organization->email)<li class="flex gap-3"><x-icon name="mail" class="mt-0.5 size-5 shrink-0 text-ochre-600" /> <a href="mailto:{{ $organization->email }}" class="break-all hover:underline">{{ $organization->email }}</a></li>@endif
            </ul>
            <div class="mt-6 flex flex-wrap gap-2">
                @if ($whatsapp)<a href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener" class="btn-primary"><x-icon name="message-circle" class="size-4" /> {{ __('Écrire sur WhatsApp') }}</a>@endif
                @if ($website->map_url)<a href="{{ $website->map_url }}" target="_blank" rel="noopener" class="btn-secondary"><x-icon name="navigation" class="size-4" /> {{ __('Itinéraire') }}</a>@endif
            </div>
        </section>
        @if ($website->hasPage('programme'))
            @php $schedule = app(\App\Services\Websites::class)->schedule($organization); @endphp
            @if ($schedule->isNotEmpty())
                <section class="min-w-0 rounded-2xl border border-sand-200 bg-white p-6">
                    <h2 class="mb-4 text-lg font-semibold text-ink-800">{{ __('Quand venir') }}</h2>
                    <ul class="space-y-2 text-ink-900">@foreach ($schedule as $e)<li><span class="font-semibold">{{ $e->title }}</span> · {{ $e->recurrenceLabel() }}@if ($e->hours()), {{ $e->hours() }}@endif</li>@endforeach</ul>
                </section>
            @endif
        @endif
    </div>
@endsection
