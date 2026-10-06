@php
    use App\Support\Money;
    $donor = $t->member?->officialName() ?? $t->payer_name ?? $t->department?->name ?? __('Offrande collective');
    $thermal = $format !== 'a4';
    $logo = $identity->logoUrl();
    $lines = $identity->headerLines();
    $contacts = $identity->contactLines();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => __('Reçu :n', ['n' => $t->receipt_number])])
    <style>
        @if ($thermal)
            @page { size: {{ $format }}mm auto; margin: 0; }
            .ticket { width: {{ $format }}mm; padding: {{ $format === '58' ? '2mm' : '3mm' }}; font-size: {{ $format === '58' ? '9pt' : '10pt' }}; line-height: 1.35; color: #000; }
            .ticket .rule { border-top: 1px dashed #000; margin: 2mm 0; }
            .ticket img { filter: grayscale(1) contrast(1.4); }
        @else
            @page { size: A4; margin: 12mm; }
        @endif
        @media print {
            body { background: #fff !important; }
            .no-print { display: none !important; }
            .receipt, .ticket { box-shadow: none !important; break-inside: avoid; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body class="min-h-dvh bg-sand-100">
    <div class="no-print sticky top-0 z-10 border-b border-sand-200 bg-white/95">
        <div class="mx-auto flex max-w-3xl flex-wrap items-center gap-3 px-4 py-3">
            @can('finance.view')
                <a href="{{ route('finances.journal') }}" class="inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Opérations') }}</a>
            @else
                <a href="{{ route('member.space') }}" class="inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Mon espace') }}</a>
            @endcan
            <span class="flex-1"></span>
            <div class="flex rounded-xl border border-sand-200 bg-sand-50 p-0.5 text-sm font-semibold" role="group" aria-label="{{ __('Format') }}">
                @foreach (\App\Support\DocumentIdentity::RECEIPT_FORMATS as $key => $label)
                    <a href="{{ route('finances.receipt', [$t, 'format' => $key]) }}" @class(['rounded-lg px-3 py-1.5', 'bg-ink-700 text-white' => $format === $key, 'text-ink-600' => $format !== $key])>{{ $key === 'a4' ? 'A4' : $key.' mm' }}</a>
                @endforeach
            </div>
            <button type="button" onclick="window.print()" class="btn-primary"><x-icon name="printer" class="size-4" /> {{ __('Imprimer') }}</button>
        </div>
    </div>

    @if ($thermal)
        {{-- Ticket pour imprimante thermique --}}
        <main class="flex justify-center py-8">
            <section class="ticket bg-white font-sans shadow-lg">
                <div class="text-center">
                    @if ($logo)<img src="{{ $logo }}" alt="" class="mx-auto mb-1 object-contain" style="max-height: {{ $format === '58' ? '14mm' : '18mm' }}; max-width: 60%">@endif
                    @isset($lines['parent'])<p style="font-size: .85em">{{ $lines['parent'] }}</p>@endisset
                    <p class="font-bold" style="font-size: 1.1em">{{ $organization->name }}</p>
                    @foreach (array_intersect_key($lines, array_flip(['legal_name', 'legal_form', 'legal_registration', 'ids'])) as $line)
                        <p style="font-size: .8em">{{ $line }}</p>
                    @endforeach
                    @foreach ($contacts as $line)<p style="font-size: .8em">{{ $line }}</p>@endforeach
                </div>
                <div class="rule"></div>
                <p class="text-center font-bold">{{ __('REÇU N° :n', ['n' => $t->receipt_number]) }}</p>
                <p class="text-center" style="font-size: .85em">{{ $t->occurred_on->translatedFormat('j F Y') }}</p>
                <div class="rule"></div>
                <p>{{ __('Reçu de') }} : <b>{{ $donor }}</b></p>
                @if ($t->member)<p>{{ __('N° membre') }} : {{ $t->member->number }}</p>@endif
                <p>{{ __('Pour') }} : {{ $t->category?->name }}</p>
                @if ($t->description)<p>{{ $t->description }}</p>@endif
                <p>{{ __('Payé par') }} : {{ __(\App\Models\FinanceTransaction::PAYMENT_METHODS[$t->payment_method] ?? '') }}</p>
                @if ($t->external_reference)<p>{{ __('ID') }} : {{ $t->external_reference }}</p>@endif
                <div class="rule"></div>
                <p class="text-center font-bold" style="font-size: 1.5em">{{ Money::format($t->amount, $t->currency) }}</p>
                @if ($t->currency !== 'USD')<p class="text-center" style="font-size: .8em">≈ {{ Money::format($t->usd_amount, 'USD') }}</p>@endif
                <div class="rule"></div>
                @if ($t->cancelled_at)<p class="text-center font-bold">{{ __('ANNULÉ') }} : {{ $t->cancel_reason }}</p>@endif
                <p style="font-size: .8em">{{ __('Caissier') }} : {{ $t->author?->name ?? '—' }}</p>
                @if ($identity->representative())<p style="font-size: .8em">{{ __('Représentant légal') }} : {{ $identity->representative() }}</p>@endif
                @if ($identity->footer())<p class="mt-2 text-center" style="font-size: .85em">{{ $identity->footer() }}</p>@endif
                @if ($identity->motto())<p class="mt-1 text-center italic" style="font-size: .8em">{{ $identity->motto() }}</p>@endif
                <p class="mt-2 text-center" style="font-size: .7em">Waumini · {{ config('waumini.domain') }}</p>
            </section>
        </main>
    @else
        <main class="mx-auto max-w-3xl space-y-6 px-4 py-8">
            @foreach ([__('Exemplaire du donateur'), __('Souche de la communauté')] as $copy)
                <section class="receipt overflow-hidden rounded-2xl bg-white p-6 shadow-lg">
                    <x-documents.header :identity="$identity" :organization="$organization">
                        <div class="shrink-0 text-right">
                            <p class="text-xs uppercase tracking-wide text-sand-700">{{ __('Reçu n°') }}</p>
                            <p class="font-mono text-lg font-semibold text-ink-800">{{ $t->receipt_number }}</p>
                        </div>
                    </x-documents.header>
                    <div class="grid gap-4 py-5 sm:grid-cols-[1fr_auto]">
                        <dl class="space-y-2 text-sm">
                            <div class="flex gap-3"><dt class="w-32 shrink-0 text-sand-700">{{ __('Reçu de') }}</dt><dd class="font-semibold text-ink-800">{{ $donor }}</dd></div>
                            @if ($t->member)<div class="flex gap-3"><dt class="w-32 shrink-0 text-sand-700">{{ __('N° de membre') }}</dt><dd class="font-mono">{{ $t->member->number }}</dd></div>@endif
                            <div class="flex gap-3"><dt class="w-32 shrink-0 text-sand-700">{{ __('Pour') }}</dt><dd class="text-ink-800">{{ $t->category?->name }}@if ($t->description) · {{ $t->description }}@endif</dd></div>
                            <div class="flex gap-3"><dt class="w-32 shrink-0 text-sand-700">{{ __('Date') }}</dt><dd>{{ $t->occurred_on->translatedFormat('j F Y') }}</dd></div>
                            <div class="flex gap-3"><dt class="w-32 shrink-0 text-sand-700">{{ __('Payé par') }}</dt><dd>{{ __(\App\Models\FinanceTransaction::PAYMENT_METHODS[$t->payment_method] ?? '') }}@if ($t->external_reference) · {{ __('ID :r', ['r' => $t->external_reference]) }}@endif · {{ $t->account->name }}</dd></div>
                        </dl>
                        <div class="rounded-2xl bg-sand-50 px-5 py-4 text-center sm:min-w-44">
                            <p class="text-xs text-sand-700">{{ __('Montant reçu') }}</p>
                            <p class="text-2xl font-semibold tabular text-ink-800">{{ Money::format($t->amount, $t->currency) }}</p>
                            @if ($t->currency !== 'USD')<p class="text-xs tabular text-sand-700">≈ {{ Money::format($t->usd_amount, 'USD') }}</p>@endif
                        </div>
                    </div>
                    @if ($identity->footer())<p class="text-center text-sm italic text-ink-700">{{ $identity->footer() }}</p>@endif
                    <div class="mt-3 flex items-end justify-between gap-4 border-t border-dashed border-sand-300 pt-4 text-xs text-sand-700">
                        <div>
                            <p>{{ __('Enregistré par :n', ['n' => $t->author?->name ?? '—']) }}</p>
                            @if ($t->cancelled_at)<p class="mt-1 text-sm font-semibold text-terra-600">{{ __('ANNULÉ : :r', ['r' => $t->cancel_reason]) }}</p>@endif
                            <p class="mt-1 italic">{{ $copy }}</p>
                        </div>
                        <div class="w-52 border-t border-ink-300 pt-1 text-center">{{ $identity->representative() ? __('Pour :n', ['n' => $identity->representative()]) : __('Signature et cachet') }}</div>
                    </div>
                </section>
            @endforeach
        </main>
    @endif
</body>
</html>
