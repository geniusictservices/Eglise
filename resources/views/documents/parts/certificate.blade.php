{{-- Le certificat, en paysage. Un titre ou un nom long prend une taille plus petite, pour tenir sur la page. --}}
@php
    $longTitle = mb_strlen($title) > 26;
    $name = $headline && $style === 'prestige' ? mb_convert_case(mb_strtolower($headline), MB_CASE_TITLE) : $headline;
    $longName = $headline && mb_strlen($headline) > 30;
    $noteWithQr = in_array($style, ['prestige', 'solennel'], true);
@endphp
@if ($style === 'prestige')
    <span class="wd-corner tl"></span><span class="wd-corner tr"></span><span class="wd-corner bl"></span><span class="wd-corner br"></span>
@endif
@if ($style === 'solennel' && $logo)
    <div class="wd-watermark"><img src="{{ $logo }}" alt=""></div>
@endif

@if ($style === 'moderne')
    <aside class="wd-band">
        @if ($logo)<img src="{{ $logo }}" alt="" class="wd-logo">@endif
        @include('documents.parts.identity', ['full' => false])
        <p class="wd-number">{{ __('N° :n', ['n' => $number]) }}</p>
        @include('documents.parts.photo')
        @include('documents.parts.qr')
    </aside>
@endif

<div class="wd-main">
    @if ($style === 'moderne')
        <p class="wd-eyebrow">{{ $identity->motto() ?: $organization->name }}</p>
    @else
        <header class="wd-head">
            @if ($logo)<img src="{{ $logo }}" alt="" class="wd-logo">@endif
            @include('documents.parts.identity', ['full' => $style === 'classique'])
        </header>
        <p class="wd-number">{{ __('N° :n', ['n' => $number]) }}</p>
        @if ($photo || $photoFrame)<div class="wd-photo-slot">@include('documents.parts.photo')</div>@endif
    @endif

    <h1 @class(['wd-title', 'is-long' => $longTitle])>{{ $title }}</h1>
    <div class="wd-divider"><span></span></div>
    @if ($headline)<p @class(['wd-name', 'is-long' => $longName])>{{ $name }}</p>@endif

    <div class="wd-body document-body">{!! $body !!}</div>

    <footer class="wd-foot">
        @if ($style === 'moderne')<span></span>@else<div>@include('documents.parts.qr')
            @if ($noteWithQr && $identity->documentFooter())<p class="wd-footer-note">{{ $identity->documentFooter() }}</p>@endif</div>@endif
        @if (in_array($style, ['prestige', 'solennel', 'classique'], true))<span></span>@endif
        @include('documents.parts.signature')
    </footer>
    @if (! $noteWithQr && $identity->documentFooter())<p class="wd-footer-note">{{ $identity->documentFooter() }}</p>@endif

    @if ($style === 'prestige')
        <div class="wd-seal-slot">@include('documents.parts.seal')</div>
    @elseif ($style === 'solennel')
        <div class="wd-ribbon">@include('documents.parts.seal')</div>
    @endif
</div>
