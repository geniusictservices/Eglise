@props(['sermon', 'organization', 'full' => false])
<article {{ $attributes->merge(['class' => 'overflow-hidden rounded-2xl border border-sand-200 bg-white']) }}>
    @if ($sermon->embedUrl())
        {{-- La vidéo ne se charge que si l'on touche Lecture : rien n'est téléchargé pour rien. --}}
        <div class="aspect-video bg-ink-900">
            <button type="button" data-embed="{{ $sermon->embedUrl(autoplay: true) }}" data-title="{{ $sermon->title }}" class="wax wax-veil group grid size-full place-items-center text-white">
                <span class="flex flex-col items-center gap-2">
                    <span class="grid size-16 place-items-center rounded-full bg-ochre-500 text-[var(--color-on-accent)] shadow-lg transition group-hover:scale-105"><x-icon name="play" class="ml-1 size-7" /></span>
                    <span class="text-sm font-semibold">{{ __('Regarder sur :p', ['p' => $sermon->platform()]) }}</span>
                </span>
            </button>
        </div>
    @endif
    <div class="p-4 sm:p-5">
        <p class="text-xs font-semibold uppercase tracking-wider text-ochre-600">{{ $sermon->preached_on->translatedFormat('j F Y') }}@if ($sermon->passage) · {{ $sermon->passage }}@endif</p>
        <h3 class="mt-1 text-lg font-semibold text-ink-800">@if (! $full)<a href="{{ route('website.sermon', [$organization->slug, $sermon->id]) }}" class="hover:underline">{{ $sermon->title }}</a>@else{{ $sermon->title }}@endif</h3>
        @if ($sermon->preacher)<p class="text-sm text-sand-700">{{ $sermon->preacher }}</p>@endif
        @if ($sermon->audio_path)
            <audio controls preload="none" class="mt-3 w-full" src="{{ route('website.audio', [$organization->slug, $sermon->id]) }}"></audio>
        @endif
        @if ($sermon->video_url && ! $sermon->embedUrl())
            <a href="{{ $sermon->video_url }}" target="_blank" rel="noopener" class="btn-secondary mt-3"><x-icon name="play" class="size-4" /> {{ __('Regarder') }}</a>
        @endif
        @if ($sermon->summary)
            @if ($full)<x-website.text :text="$sermon->summary" class="mt-3 text-ink-900" />@else<p class="mt-2 text-sm leading-relaxed text-ink-900">{{ \Illuminate\Support\Str::limit($sermon->summary, 200) }}</p>@endif
        @endif
    </div>
</article>
