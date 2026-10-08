{{-- Le sceau : le logo au centre, le nom de l'église tout autour. --}}
@php $ring = \Illuminate\Support\Str::upper($organization->short_name ?: $organization->name); @endphp
<div class="wd-seal" aria-hidden="true">
    <svg viewBox="0 0 100 100">
        <defs><path id="{{ $sealId }}" d="M50,50 m-37,0 a37,37 0 1,1 74,0 a37,37 0 1,1 -74,0" /></defs>
        <circle cx="50" cy="50" r="47" fill="none" stroke="currentColor" stroke-width="1.6" />
        <circle cx="50" cy="50" r="29" fill="none" stroke="currentColor" stroke-width="0.8" />
        <text><textPath href="#{{ $sealId }}" startOffset="0">★ {{ $ring }} ★ {{ $ring }}</textPath></text>
        @if ($logo)
            <image href="{{ $logo }}" x="27" y="27" width="46" height="46" preserveAspectRatio="xMidYMid meet" />
        @else
            <text x="50" y="55" text-anchor="middle" style="font-size: 14px; letter-spacing: 0">✝</text>
        @endif
    </svg>
</div>
