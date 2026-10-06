@php use App\Models\Event; @endphp
<div>
    <x-page-header :title="__('Calendrier')" :description="__('Cultes, prières et événements de la communauté. Ouvrez une date pour ses inscriptions et ses présences.')">
        <x-slot:actions>
            @can('attendance.record')<a href="{{ route('attendance.index') }}" class="btn-secondary"><x-icon name="clipboard-check" class="size-4" /> {{ __('Présences') }}</a>@endcan
            @if ($canCreate)<button type="button" wire:click="openEventForm" class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Nouvelle activité') }}</button>@endif
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex items-center gap-2">
        <button type="button" wire:click="shift(-1)" class="btn-ghost !px-3" aria-label="{{ __('Mois précédent') }}"><x-icon name="chevron-left" class="size-5" /></button>
        <h2 class="min-w-0 flex-1 text-center text-lg sm:flex-none sm:text-left">{{ ucfirst($start->translatedFormat('F Y')) }}</h2>
        <button type="button" wire:click="shift(1)" class="btn-ghost !px-3" aria-label="{{ __('Mois suivant') }}"><x-icon name="chevron-right" class="size-5" /></button>
        @unless ($start->isSameMonth(today()))<button type="button" wire:click="$set('month', '{{ today()->format('Y-m') }}')" class="btn-ghost text-sm sm:ml-2">{{ __('Aujourd’hui') }}</button>@endunless
    </div>

    <div class="space-y-4">
        @forelse ($days as $day => $items)
            @php $date = \Illuminate\Support\Carbon::parse($day); @endphp
            <section wire:key="d-{{ $day }}" @class(['-mx-2 rounded-[20px] bg-ochre-50 p-2' => $date->isToday()])>
                <h3 @class(['mb-2 flex items-center gap-2 text-sm font-semibold', 'text-ochre-700' => $date->isToday(), 'text-sand-700' => $date->isPast() && ! $date->isToday(), 'text-ink-700' => $date->isFuture()])>
                    {{ ucfirst($date->translatedFormat('l j F')) }}
                    @if ($date->isToday())<span class="badge bg-ochre-100 text-ochre-700">{{ __('Aujourd’hui') }}</span>@endif
                </h3>
                <ul class="space-y-2">
                    @foreach ($items as $o)
                        @php $e = $o['event']; $key = $e->id.'|'.$day; $record = $records[$key] ?? null; @endphp
                        <li>
                            <a href="{{ route('events.show', ['event' => $e, 'date' => $day]) }}" class="card flex items-center gap-3 p-3.5 transition hover:border-ochre-300 sm:p-4">
                                <span class="w-14 shrink-0 text-center text-sm font-semibold text-ink-700 tabular">{{ $e->start_time ? substr($e->start_time, 0, 5) : __('Journée') }}</span>
                                <span class="min-w-0 flex-1 border-l border-sand-200 pl-3">
                                    <span class="block truncate font-semibold text-ink-800">{{ $e->title }}</span>
                                    <span class="block truncate text-sm text-sand-700">
                                        {{ __(Event::KINDS[$e->kind]) }}@if ($e->place) · {{ $e->place }}@endif @if ($e->audience !== 'all') · {{ $e->audienceLabel() }}@endif
                                    </span>
                                    @if ($record || isset($registrations[$key]))
                                        <span class="mt-1 flex flex-wrap gap-1.5">
                                            @if ($record && $record->total !== null)<span class="badge bg-leaf-50 text-leaf-600 tabular">{{ trans_choice(':count présent|:count présents', $record->total) }}</span>
                                            @elseif ($record && $record->checkins_count)<span class="badge bg-leaf-50 text-leaf-600 tabular">{{ trans_choice(':count pointé|:count pointés', $record->checkins_count) }}</span>@endif
                                            @isset($registrations[$key])<span class="badge bg-ink-50 text-ink-700 tabular">{{ trans_choice(':count inscrit|:count inscrits', $registrations[$key]) }}@if ($e->capacity) / {{ $e->capacity }}@endif</span>@endisset
                                        </span>
                                    @endif
                                </span>
                                @if ($e->repeats !== 'none')<x-icon name="refresh-cw" class="size-4 shrink-0 text-sand-400" />@endif
                                <x-icon name="chevron-right" class="size-4 shrink-0 text-sand-400" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <p class="card p-8 text-center text-sm text-sand-700">{{ __('Aucune activité ce mois-ci.') }}</p>
        @endforelse
    </div>

    @if ($canCreate)
        @include('livewire.events.partials.form-modal')
    @endif
</div>
