@if ($qr)
    <div class="wd-qr">
        <div class="wd-qr-code">{!! $qr !!}</div>
        <p>{{ __('Vérifiez ce document en scannant ce code, ou sur :u', ['u' => $verifyHost]) }}</p>
    </div>
@endif
