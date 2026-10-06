{{-- En-tête de document (A4) : logo, nom, identité juridique et coordonnées choisis par la communauté. --}}
@props(['identity', 'organization'])
@php
    $logo = $identity->logoUrl();
    $lines = $identity->headerLines();
    $contacts = $identity->contactLines();
@endphp
<div class="flex items-start gap-4 border-b-2 border-ink-700 pb-4">
    @if ($logo)
        <img src="{{ $logo }}" alt="" class="h-20 w-20 shrink-0 object-contain">
    @endif
    <div class="min-w-0 flex-1">
        @isset($lines['parent'])<p class="text-xs font-semibold uppercase tracking-wide text-sand-700">{{ $lines['parent'] }}</p>@endisset
        <p class="text-xl font-semibold leading-tight text-ink-800">{{ $organization->name }}</p>
        @isset($lines['legal_name'])<p class="text-sm font-semibold text-ink-700">{{ $lines['legal_name'] }}</p>@endisset
        @foreach (array_intersect_key($lines, array_flip(['legal_form', 'legal_registration', 'ids'])) as $line)
            <p class="text-xs text-sand-700">{{ $line }}</p>
        @endforeach
        @if ($contacts)<p class="mt-1 text-xs text-ink-800">{{ implode(' · ', $contacts) }}</p>@endif
        @if ($identity->motto())<p class="mt-1 text-xs italic text-ink-600">{{ $identity->motto() }}</p>@endif
    </div>
    {{ $slot ?? '' }}
</div>
