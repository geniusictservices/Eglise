@php use App\Support\Money; @endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => __('Procès-verbal de collecte')])
    <style>
        @page { size: A4; margin: 14mm; }
        @media print { body { background: #fff !important; } .no-print { display: none !important; } .sheet { box-shadow: none !important; padding: 0 !important; } * { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body class="min-h-dvh bg-sand-100">
    <div class="no-print sticky top-0 z-10 border-b border-sand-200 bg-white/95">
        <div class="mx-auto flex max-w-3xl items-center gap-3 px-4 py-3">
            <a href="{{ route('finances.collections.show', $sheet) }}" class="inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Feuille de collecte') }}</a>
            <span class="flex-1"></span>
            <button type="button" onclick="window.print()" class="btn-primary"><x-icon name="printer" class="size-4" /> {{ __('Imprimer') }}</button>
        </div>
    </div>
    <main class="sheet mx-auto my-8 max-w-3xl rounded-2xl bg-white p-8 shadow-lg">
        <x-documents.header :identity="$identity" :organization="$organization" />

        <h1 class="mt-6 text-center text-xl font-semibold uppercase tracking-wide text-ink-800">{{ __('Procès-verbal de collecte') }}</h1>
        <p class="text-center text-sm text-sand-700">{{ $sheet->service_label }} · {{ $sheet->service_date->translatedFormat('l j F Y') }}</p>
        @if ($sheet->status !== 'validated')<p class="mt-2 text-center text-sm font-semibold text-terra-600">{{ __(\App\Models\CollectionSheet::STATUSES[$sheet->status]) }}@if ($sheet->cancel_reason) : {{ $sheet->cancel_reason }}@endif</p>@endif

        <table class="mt-6 w-full text-sm">
            <thead><tr class="border-b-2 border-ink-700"><th class="py-2 text-left">{{ __('Offrandes') }}</th>@foreach (array_keys($summary) as $c)<th class="py-2 text-right">{{ $c }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach ($sheet->lines->groupBy('category_id') as $lines)
                    <tr class="border-b border-sand-200"><td class="py-1.5">{{ $lines->first()->category->name }}</td>
                        @foreach (array_keys($summary) as $c)<td class="py-1.5 text-right tabular">{{ ($l = $lines->firstWhere('currency', $c)) ? Money::format($l->amount, $c) : '—' }}</td>@endforeach</tr>
                @endforeach
                <tr class="border-b border-sand-200"><td class="py-1.5">{{ trans_choice('Enveloppes nominatives (:count)|Enveloppes nominatives (:count)', $sheet->envelopes->count()) }}</td>
                    @foreach ($summary as $c => $s)<td class="py-1.5 text-right tabular">{{ Money::format($s['envelopes'], $c) }}</td>@endforeach</tr>
                <tr class="font-semibold"><td class="py-2">{{ __('Total') }}</td>@foreach ($summary as $c => $s)<td class="py-2 text-right tabular">{{ Money::format($s['declared'], $c) }}</td>@endforeach</tr>
            </tbody>
        </table>

        @if (collect($summary)->contains(fn ($s) => $s['counted']))
            <h2 class="mt-6 text-base">{{ __('Comptage des billets') }}</h2>
            <div class="mt-2 grid grid-cols-2 gap-6 text-sm">
                @foreach ($sheet->counts ?? [] as $currency => $rows)
                    <table class="w-full">
                        @foreach ($rows as $value => $qty)
                            <tr class="border-b border-sand-100"><td class="py-1 tabular">{{ Money::format($value, $currency) }}</td><td class="py-1 text-center tabular">× {{ $qty }}</td><td class="py-1 text-right tabular">{{ Money::format($value * $qty, $currency) }}</td></tr>
                        @endforeach
                        <tr class="font-semibold"><td class="py-1" colspan="2">{{ __('Total compté') }}</td><td class="py-1 text-right tabular">{{ Money::format($summary[$currency]['counted'] ?? 0, $currency) }}</td></tr>
                    </table>
                @endforeach
            </div>
        @endif

        @if ($canSeeNames && $sheet->envelopes->isNotEmpty())
            <h2 class="mt-6 text-base">{{ __('Enveloppes nominatives') }}</h2>
            <table class="mt-2 w-full text-sm">
                @foreach ($sheet->envelopes as $e)
                    <tr class="border-b border-sand-100"><td class="py-1">{{ $e->member?->officialName() ?? $e->payer_name }}</td><td class="py-1 text-sand-700">{{ $e->category->name }}</td><td class="py-1 text-right tabular">{{ Money::format($e->amount, $e->currency) }}</td></tr>
                @endforeach
            </table>
        @endif

        <p class="mt-6 text-sm">{{ __('Montants déposés dans : :account.', ['account' => $sheet->account->name]) }}@if ($sheet->notes) {{ $sheet->notes }}@endif</p>

        <div class="mt-10 grid grid-cols-3 gap-6 text-center text-xs text-sand-700">
            @foreach (array_pad(array_filter($sheet->counters ?? []), 2, '') as $name)
                <div class="border-t border-ink-300 pt-1"><p class="font-semibold text-ink-800">{{ $name ?: '……………………' }}</p><p>{{ __('Compteur') }}</p></div>
            @endforeach
            <div class="border-t border-ink-300 pt-1"><p class="font-semibold text-ink-800">{{ $sheet->validator?->name ?? '……………………' }}</p><p>{{ __('Trésorier') }}</p></div>
        </div>
    </main>
</body>
</html>
