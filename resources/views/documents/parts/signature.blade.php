<div class="wd-sign">
    <p class="wd-sign-date">{{ __('Fait à :p, le :d', ['p' => $place, 'd' => $date]) }}</p>
    @if ($signatoryTitle)<p class="wd-sign-role">{{ $signatoryTitle }}</p>@endif
    <div class="wd-sign-space"></div>
    <p class="wd-sign-name">{{ $signatory ?: \App\Support\DocumentTemplate::BLANK }}</p>
</div>
