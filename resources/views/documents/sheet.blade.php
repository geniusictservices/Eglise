{{--
    Une feuille A4 : le certificat en paysage (attestations, baptême, mariage), la lettre en
    portrait (recommandation, ordre de mission, convocation), dans le style choisi par l'église
    et à ses couleurs. Sert à l'aperçu et à l'impression.
--}}
@php
    $orientation = ($orientation ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait';
    $style = isset(\App\Support\DocumentStyles::STYLES[$style ?? '']) ? $style : \App\Support\DocumentStyles::forOrganization($organization);
    $theme = $organization->theme();
    $place = $organization->city ?: $organization->name;
    $lines = $identity->headerLines();
    $logo = $identity->logoUrl();
    $sealId = 'seal-'.\Illuminate\Support\Str::random(6);
    $shared = compact('organization', 'identity', 'lines', 'logo', 'place', 'style', 'sealId') + [
        'title' => $title, 'number' => $number, 'body' => $body, 'date' => $date, 'signatory' => $signatory ?? null, 'signatoryTitle' => $signatoryTitle ?? null,
        'qr' => $qr ?? null, 'photo' => $photo ?? null, 'photoFrame' => $photoFrame ?? false, 'headline' => $headline ?? null,
        'verifyHost' => $verifyHost ?? parse_url(config('app.url'), PHP_URL_HOST),
    ];
@endphp
<div class="wd-frame is-{{ $orientation }}">
    <article class="document-sheet wd wd-{{ $orientation === 'landscape' ? 'cert' : 'letter' }} wd-s-{{ $style }}" style="--p: {{ $theme->primary }}; --a: {{ $theme->accent }};">
        @include($orientation === 'landscape' ? 'documents.parts.certificate' : 'documents.parts.letter', $shared)
        @if ($cancelled ?? false)
            <div class="wd-cancelled"><span>{{ __('Annulé') }}</span></div>
        @endif
    </article>
</div>
