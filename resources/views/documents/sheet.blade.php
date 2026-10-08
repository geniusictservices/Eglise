{{--
    Une feuille A4 : en-tête de la communauté, numéro, titre, texte, lieu et date,
    signature, QR code de vérification. Sert à l'aperçu et à l'impression.
--}}
@php $place = $organization->city ?: $organization->name; @endphp
<article class="document-sheet relative mx-auto flex aspect-[210/297] w-full max-w-[210mm] flex-col bg-white p-[8%] text-[11pt] leading-relaxed text-ink-900 shadow-lg shadow-ink-900/10 print:aspect-auto print:min-h-[273mm] print:max-w-none print:p-0 print:shadow-none">
    <x-documents.header :identity="$identity" :organization="$organization" />

    <div class="mt-4 flex items-start justify-end gap-4">
        <p class="text-sm text-ink-700">{{ __('N° :n', ['n' => $number]) }}</p>
        {{-- La photo du membre, au format identité (30 × 38 mm) ; dans l'aperçu, un cadre vide si le membre n'en a pas. --}}
        @if ($photo ?? null)
            <img src="{{ $photo }}" alt="" class="h-[38mm] w-[30mm] shrink-0 border border-sand-300 object-cover p-[1mm]">
        @elseif ($photoFrame ?? false)
            <span class="grid h-[38mm] w-[30mm] shrink-0 place-items-center border border-dashed border-sand-300 text-center text-[8pt] text-sand-500 print:hidden">{{ __('Photo du membre') }}</span>
        @endif
    </div>
    <h1 class="mb-8 mt-6 text-center text-xl font-semibold uppercase tracking-wide text-ink-800 underline decoration-ochre-500 decoration-2 underline-offset-8">{{ $title }}</h1>

    <div class="document-body flex-1 text-justify">{!! $body !!}</div>

    <div class="mt-8 flex flex-wrap-reverse items-end justify-between gap-6">
        <div class="flex shrink-0 items-end gap-3">
            @if ($qr ?? null)
                <div class="size-24 shrink-0 [&_svg]:h-full [&_svg]:w-full">{!! $qr !!}</div>
                <p class="w-32 text-[8pt] leading-snug text-sand-700">{{ __('Vérifiez ce document en scannant ce code, ou sur :u', ['u' => $verifyHost ?? parse_url(config('app.url'), PHP_URL_HOST)]) }}</p>
            @endif
        </div>
        <div class="ml-auto min-w-48 text-center">
            <p>{{ __('Fait à :p, le :d', ['p' => $place, 'd' => $date]) }}</p>
            @if ($signatoryTitle)<p class="mt-2 font-semibold">{{ $signatoryTitle }}</p>@endif
            <div class="h-20"></div>
            <p class="font-semibold">{{ $signatory ?: \App\Support\DocumentTemplate::BLANK }}</p>
        </div>
    </div>

    @if ($identity->documentFooter())<p class="mt-6 border-t border-sand-200 pt-2 text-center text-[8pt] text-sand-700">{{ $identity->documentFooter() }}</p>@endif

    @if ($cancelled ?? false)
        <div class="pointer-events-none absolute inset-0 grid place-items-center">
            <span class="-rotate-12 rounded-xl border-4 border-terra-600 px-6 py-2 text-5xl font-bold uppercase tracking-widest text-terra-600/80">{{ __('Annulé') }}</span>
        </div>
    @endif
</article>
