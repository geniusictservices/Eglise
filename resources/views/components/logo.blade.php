@props(['light' => false])
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2.5']) }}>
    <x-logo-mark :light="$light" class="size-9" />
    <span @class(['font-display text-2xl font-bold tracking-tight', 'text-white' => $light, 'text-ink-700' => ! $light])>waum<span class="relative">ı<span class="absolute left-1/2 top-[0.12em] size-[0.2em] -translate-x-1/2 rounded-full bg-ochre-500"></span></span>n<span class="relative">ı<span class="absolute left-1/2 top-[0.12em] size-[0.2em] -translate-x-1/2 rounded-full bg-ochre-500"></span></span></span>
</span>
