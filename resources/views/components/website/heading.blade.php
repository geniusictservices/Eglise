@props(['website', 'eyebrow' => null])
<div {{ $attributes->merge(['class' => 'mb-6']) }}>
    @if ($eyebrow)<p class="mb-1 text-xs font-semibold uppercase tracking-[0.18em] text-ochre-600">{{ $eyebrow }}</p>@endif
    <h2 @class(['text-2xl font-semibold text-ink-800 sm:text-3xl', 'font-serif' => $website->theme === 'solennel'])>{{ $slot }}</h2>
</div>
