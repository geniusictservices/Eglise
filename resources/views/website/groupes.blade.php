@extends('website.layout', ['title' => __('Nos groupes')])

@section('content')
    @php $whatsapp = $website->whatsapp ? preg_replace('/\D/', '', $website->whatsapp) : null; @endphp
    @include('website.partials.title', ['title' => __('Nos groupes'), 'intro' => __('Chorales, cellules de quartier, jeunesse, mamans… Il y a une place pour chacun : venez, vous serez bien accueilli.')])
    <div class="mx-auto max-w-6xl px-4 pt-10 sm:px-6">
        @if ($groups->isEmpty())
            <p class="text-sand-700">{{ __('La liste des groupes arrive bientôt.') }}</p>
        @else
            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($groups as $group)
                    <li class="flex min-w-0 flex-col rounded-2xl border border-sand-200 bg-white p-5">
                        <p class="text-xs font-semibold uppercase tracking-wider text-ochre-600">{{ __(\App\Models\Group::KINDS[$group->kind] ?? 'Autre') }}</p>
                        <p @class(['mt-1 text-xl font-semibold text-ink-800', 'font-serif' => $website->theme === 'solennel'])>{{ $group->name }}</p>
                        @if ($group->description)<p class="mt-2 text-ink-900">{{ $group->description }}</p>@endif
                        @if ($group->meetingTimes())
                            <ul class="mt-3 space-y-1">
                                @foreach ($group->meetingTimes() as $when)<li class="flex items-center gap-2 text-sm font-semibold text-ink-700"><x-icon name="calendar" class="size-4 text-ochre-600" /> {{ $when }}</li>@endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
            <p class="mt-8 rounded-2xl bg-ink-50 p-5 text-ink-800">
                {{ __('Vous voulez rejoindre un groupe ? Dites-le nous, nous vous mettrons en contact avec son responsable.') }}
                @if ($website->hasPage('bienvenue'))<a href="{{ route('website.page', [$organization->slug, 'bienvenue']) }}" class="font-semibold underline underline-offset-4">{{ __('Faisons connaissance') }}</a>@elseif ($whatsapp)<a href="https://wa.me/{{ $whatsapp }}" class="font-semibold underline underline-offset-4">WhatsApp</a>@endif
            </p>
        @endif
    </div>
@endsection
