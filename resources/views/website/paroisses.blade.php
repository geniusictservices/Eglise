@extends('website.layout', ['title' => __('Nos paroisses')])

@section('content')
    @include('website.partials.title', ['title' => __('Nos paroisses'), 'intro' => __('Retrouvez la communauté la plus proche de chez vous.')])
    <div class="mx-auto grid max-w-6xl gap-4 px-4 pt-10 sm:grid-cols-2 sm:px-6 lg:grid-cols-3">
        @foreach ($parishes as $p)
            @php $o = $p['organization']; @endphp
            <div class="min-w-0 rounded-2xl border border-sand-200 bg-white p-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-ochre-600">{{ $o->level_label }}</p>
                <p class="mt-1 text-lg font-semibold text-ink-800">{{ $o->name }}</p>
                <p class="mt-1 text-sm text-sand-700">{{ collect([$o->address, $o->city])->filter()->implode(', ') }}</p>
                @if ($o->phone)<p class="text-sm text-sand-700">{{ $o->phone }}</p>@endif
                @if ($p['website'])<a href="{{ route('website.home', $o->slug) }}" class="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-700 hover:underline">{{ __('Voir son site') }} <x-icon name="arrow-right" class="size-4" /></a>@endif
            </div>
        @endforeach
    </div>
@endsection
