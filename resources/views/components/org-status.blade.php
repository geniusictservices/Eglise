@props(['status'])
@php
    [$label, $color] = \App\Models\Organization::STATUSES[$status] ?? [$status, 'sand'];
    $classes = ['ink' => 'bg-ink-50 text-ink-700', 'ochre' => 'bg-ochre-100 text-ochre-700', 'terra' => 'bg-terra-50 text-terra-600', 'leaf' => 'bg-leaf-50 text-leaf-600', 'sand' => 'bg-sand-100 text-sand-700'][$color];
@endphp
<span {{ $attributes->merge(['class' => 'badge '.$classes]) }}>{{ __($label) }}</span>
