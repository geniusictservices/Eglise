@props(['light' => false])
{{-- Symbole Waumini : deux mains en coupe portant trois graines. --}}
<svg {{ $attributes->merge(['class' => 'size-9']) }} viewBox="6 11 108 108" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <path d="M16,30 C16,74 29,100 45,100 C55,100 60,91 60,78 C60,91 65,100 75,100 C91,100 104,74 104,30" fill="none" stroke="{{ $light ? '#FFFFFF' : '#173F4E' }}" stroke-width="15" stroke-linecap="round" stroke-linejoin="round"/>
    <circle cx="40" cy="56" r="9" fill="#B5532F"/>
    <circle cx="60" cy="44" r="10.5" fill="#E09A2D"/>
    <circle cx="80" cy="56" r="9" fill="#B5532F"/>
</svg>
