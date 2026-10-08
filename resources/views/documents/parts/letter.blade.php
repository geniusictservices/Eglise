{{-- La lettre, en portrait. --}}
<header class="wd-lhead">
    @if ($logo)<img src="{{ $logo }}" alt="" class="wd-logo">@endif
    @include('documents.parts.identity', ['full' => true])
</header>

<div class="wd-meta">
    <p class="wd-number">{{ __('N° :n', ['n' => $number]) }}</p>
    @include('documents.parts.photo')
</div>

<h1 class="wd-ltitle">{{ $title }}</h1>
<div class="wd-lbody document-body">{!! $body !!}</div>

<footer class="wd-lfoot">
    @include('documents.parts.qr')
    @include('documents.parts.signature')
</footer>
@if ($identity->documentFooter())<p class="wd-footer-note">{{ $identity->documentFooter() }}</p>@endif
