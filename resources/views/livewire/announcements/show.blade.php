<div class="max-w-3xl">
    <a href="{{ route('announcements.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Annonces') }}</a>
    <article class="card p-5 sm:p-7">
        <p class="text-sm text-sand-700">{{ $announcement->published_at->translatedFormat('j F Y') }} · {{ $announcement->audienceLabel() }}</p>
        <h1 class="mt-1 text-2xl font-semibold text-ink-800">{{ $announcement->title }}</h1>
        @if ($announcement->event && $announcement->event_date)
            <a href="{{ route('events.show', ['event' => $announcement->event_id, 'date' => $announcement->event_date->toDateString()]) }}" class="mt-3 inline-flex items-center gap-2 rounded-xl bg-ochre-50 px-3 py-2 text-sm font-semibold text-ink-800 hover:bg-ochre-100">
                <x-icon name="calendar-days" class="size-4 text-ochre-600" />
                {{ ucfirst($announcement->event_date->translatedFormat('l j F')) }}@if ($announcement->event->hours()) · {{ $announcement->event->hours() }}@endif @if ($announcement->event->place) · {{ $announcement->event->place }}@endif
            </a>
        @endif
        <div class="mt-4 whitespace-pre-line text-ink-700">{{ $announcement->body }}</div>
        <div class="mt-6 border-t border-sand-100 pt-4">@include('livewire.announcements.partials.share')</div>
    </article>
</div>
