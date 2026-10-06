@php use App\Support\Phone; @endphp
<div>
    <x-page-header :title="__('Présences')" :description="__('La fréquentation des cultes et des activités, les visiteurs à revoir et les fidèles qu’on ne voit plus.')">
        <x-slot:actions>
            <a href="{{ route('events.index') }}" class="btn-secondary"><x-icon name="calendar-days" class="size-4" /> {{ __('Calendrier') }}</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-5 flex flex-wrap gap-2">
        <select wire:model.live="eventId" class="input w-auto min-w-0 flex-1 basis-48 sm:flex-none" aria-label="{{ __('Activité') }}">
            <option value="">{{ __('Toutes les activités') }}</option>
            @foreach ($events as $e)<option value="{{ $e->id }}">{{ $e->title }}</option>@endforeach
        </select>
        <select wire:model.live="weeks" class="input w-auto" aria-label="{{ __('Période') }}">
            @foreach ([4 => __('4 dernières semaines'), 12 => __('3 derniers mois'), 26 => __('6 derniers mois'), 52 => __('12 derniers mois')] as $w => $l)<option value="{{ $w }}">{{ $l }}</option>@endforeach
        </select>
    </div>

    @if ($averages->isNotEmpty())
        <div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($averages as $a)
                <div class="card p-4">
                    <p class="truncate text-sm text-sand-700">{{ $a['event']?->title }}</p>
                    <p class="text-3xl font-semibold text-ink-800 tabular">{{ $a['average'] }}</p>
                    <p class="text-xs text-sand-700">{{ __('en moyenne sur :n dates · record :m · :v visiteurs', ['n' => $a['dates'], 'm' => $a['max'], 'v' => $a['visitors']]) }}</p>
                </div>
            @endforeach
        </div>
    @endif

    @if ($chart->count() > 1)
        <section class="card mb-5 p-5 sm:p-6">
            <h2 class="mb-4 text-lg">{{ __('Fréquentation') }} <span class="font-normal text-sand-700">· {{ $chartTitle }}</span></h2>
            <div class="flex h-40 items-end gap-1 overflow-x-auto" role="img" aria-label="{{ __('Présents à chaque date') }}">
                @foreach ($chart as $r)
                    <div class="flex min-w-6 flex-1 flex-col items-center justify-end gap-1" title="{{ $r->occurs_on->translatedFormat('j M') }} · {{ $r->event?->title }} : {{ $r->headcount }}">
                        <span class="text-[10px] text-sand-700 tabular">{{ $r->headcount }}</span>
                        <span class="w-full rounded-t-md bg-ink-600" style="height: {{ max(4, round($r->headcount / $peak * 120)) }}px"></span>
                        <span class="text-[10px] text-sand-600">{{ $r->occurs_on->format('d/m') }}</span>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <div class="grid gap-5 lg:grid-cols-[1.5fr_1fr]">
        <section class="card overflow-hidden">
            <h2 class="px-5 pb-2 pt-5 text-lg sm:px-6">{{ __('Dates notées') }}</h2>
            <ul class="divide-y divide-sand-100">
                @forelse ($records as $r)
                    <li wire:key="ar-{{ $r->id }}">
                        <a href="{{ route('events.show', ['event' => $r->event_id, 'date' => $r->occurs_on->toDateString()]) }}" class="flex items-center gap-3 px-5 py-3 hover:bg-sand-50 sm:px-6">
                            <span class="w-14 shrink-0 text-sm text-sand-700">{{ $r->occurs_on->translatedFormat('j M') }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-semibold text-ink-800">{{ $r->event?->title }}</span>
                                <span class="block truncate text-xs text-sand-700">{{ collect([
                                    ...collect(['men' => __('H'), 'women' => __('F'), 'children' => __('E')])->filter(fn ($l, $k) => $r->$k !== null)->map(fn ($l, $k) => $l.' '.$r->$k)->values(),
                                    $r->checkins_count ? trans_choice(':count pointé|:count pointés', $r->checkins_count) : null,
                                    ($r->visitors ?? $r->named_visitors_count) ? trans_choice(':count visiteur|:count visiteurs', $r->visitors ?? $r->named_visitors_count) : null,
                                ])->filter()->implode(' · ') }}</span>
                            </span>
                            <span class="text-lg font-semibold text-ink-800 tabular">{{ $r->headcount ?? '—' }}</span>
                        </a>
                    </li>
                @empty
                    <li class="px-5 pb-5 text-sm text-sand-700 sm:px-6">{{ __('Aucune présence notée sur cette période. Ouvrez une date du calendrier pour noter les effectifs.') }}</li>
                @endforelse
            </ul>
        </section>

        <div class="space-y-5">
            <section class="card p-5 sm:p-6">
                <h2 class="mb-1 text-lg">{{ __('Visiteurs à revoir') }}</h2>
                <p class="mb-3 text-sm text-sand-700">{{ __('Venus ces 30 derniers jours, pas encore revus.') }}</p>
                <ul class="space-y-2">
                    @forelse ($toFollow as $v)
                        <li class="flex items-center gap-3">
                            <a href="{{ route('events.show', ['event' => $v->record->event_id, 'date' => $v->record->occurs_on->toDateString()]) }}" class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-ink-800">{{ $v->name }}</span>
                                <span class="block truncate text-xs text-sand-700">{{ $v->record->occurs_on->translatedFormat('j M') }} · {{ $v->record->event?->title }}@if ($v->invited_by) · {{ __('invité par :n', ['n' => $v->invited_by]) }}@endif</span>
                            </a>
                            @if ($v->phone)<a href="tel:{{ $v->phone }}" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-sm text-ink-700 hover:bg-sand-100 tabular"><x-icon name="phone" class="size-4" /> <span class="hidden sm:inline">{{ Phone::format($v->phone) }}</span></a>@endif
                        </li>
                    @empty
                        <li class="text-sm text-sand-700">{{ __('Tous les visiteurs ont été revus.') }}</li>
                    @endforelse
                </ul>
            </section>
            @if ($missing->isNotEmpty())
                <section class="rounded-[18px] border border-ochre-300 bg-ochre-50 p-5">
                    <h2 class="mb-1 flex items-center gap-2 text-base"><x-icon name="heart-handshake" class="size-5 text-ochre-600" /> {{ __('On ne les voit plus') }}</h2>
                    <p class="mb-3 text-sm text-sand-700">{{ __('Pointés régulièrement avant, absents des quatre dernières dates pointées.') }}</p>
                    <ul class="space-y-2">
                        @foreach ($missing as $m)
                            <li class="flex items-center gap-3">
                                <a href="{{ route('members.show', $m) }}" class="min-w-0 flex-1 text-sm font-semibold text-ink-800 hover:underline">{{ $m->officialName() }}</a>
                                @if ($m->phone)<a href="tel:{{ $m->phone }}" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-sm text-ink-700 hover:bg-white tabular"><x-icon name="phone" class="size-4" /> {{ Phone::format($m->phone) }}</a>@endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>
</div>
