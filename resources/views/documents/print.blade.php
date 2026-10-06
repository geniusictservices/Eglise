<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => $document->title.' · '.$document->number])
    <style>
        @page { size: A4; margin: 15mm 18mm; }
        @media print {
            body { background: #fff !important; }
            .no-print { display: none !important; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body class="min-h-dvh bg-sand-100">
    <div class="no-print sticky top-0 z-10 border-b border-sand-200 bg-white/95">
        <div class="mx-auto flex max-w-4xl flex-wrap items-center gap-3 px-4 py-3">
            <a href="{{ route('documents.index') }}" class="inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Documents délivrés') }}</a>
            <span class="flex-1"></span>
            @if ($document->isCancelled())<span class="badge bg-terra-50 text-terra-700">{{ __('Annulé le :d', ['d' => $document->cancelled_at->translatedFormat('j F Y')]) }}</span>@endif
            <button type="button" onclick="window.print()" class="btn-primary"><x-icon name="printer" class="size-4" /> {{ __('Imprimer') }}</button>
        </div>
    </div>
    <main class="px-3 py-6 print:p-0">
        @include('documents.sheet', ['organization' => $document->organization, 'identity' => $identity, 'title' => $document->title, 'number' => $document->number,
            'body' => $document->body, 'date' => $document->issued_on->translatedFormat('j F Y'), 'signatory' => $document->signatory, 'signatoryTitle' => $document->signatory_title,
            'qr' => $qr, 'cancelled' => $document->isCancelled()])
        <p class="no-print mx-auto mt-4 max-w-[210mm] text-center text-sm text-sand-700">{{ __('Imprimez, faites signer à la main et apposez le cachet. Le QR code permet à quiconque de vérifier le document.') }}</p>
    </main>
</body>
</html>
