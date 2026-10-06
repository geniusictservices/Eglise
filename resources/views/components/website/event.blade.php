@props(['occurrence', 'organization'])
@php $event = $occurrence['event']; $date = $occurrence['date']; @endphp
<li {{ $attributes->merge(['class' => 'flex gap-4 rounded-2xl border border-sand-200 bg-white p-4']) }}>
    <div class="grid w-16 shrink-0 place-items-center self-start rounded-xl bg-ink-50 py-2 text-center text-ink-800">
        <span class="text-xs font-semibold uppercase">{{ $date->translatedFormat('M') }}</span>
        <span class="text-2xl font-bold leading-none">{{ $date->format('j') }}</span>
        <span class="text-xs">{{ $date->translatedFormat('D') }}</span>
    </div>
    <div class="min-w-0 flex-1">
        <p class="font-semibold text-ink-800">{{ $event->title }}</p>
        <p class="mt-0.5 text-sm text-sand-700">{{ collect([$event->ends_on && ! $event->ends_on->isSameDay($event->starts_on) ? __('jusqu’au :d', ['d' => $event->ends_on->translatedFormat('j F')]) : null, $event->hours(), $event->place])->filter()->implode(' · ') }}</p>
        @if ($event->description)<p class="mt-2 text-sm leading-relaxed text-ink-900">{{ \Illuminate\Support\Str::limit($event->description, 220) }}</p>@endif
        <a href="https://wa.me/?text={{ rawurlencode($event->shareText($date, $organization)) }}" target="_blank" rel="noopener" class="mt-2 inline-flex items-center gap-1.5 text-sm font-semibold text-leaf-600"><x-icon name="share" class="size-4" /> {{ __('Partager sur WhatsApp') }}</a>
    </div>
</li>
