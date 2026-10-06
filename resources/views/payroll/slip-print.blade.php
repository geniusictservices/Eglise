@php use App\Support\Money; $p = $s->payee; @endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => __('Bulletin de paie : :n', ['n' => $p->displayName()])])
    <style>@page { size: A4; margin: 14mm; } @media print { body { background: #fff !important; } .no-print { display: none !important; } .sheet { box-shadow: none !important; padding: 0 !important; margin: 0 !important; } * { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }</style>
</head>
<body class="min-h-dvh bg-sand-100">
    <div class="no-print sticky top-0 z-10 border-b border-sand-200 bg-white/95"><div class="mx-auto flex max-w-2xl items-center gap-3 px-4 py-3">
        <a href="{{ route('payroll.run', $r) }}" class="inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Paie') }}</a><span class="flex-1"></span>
        <button type="button" onclick="window.print()" class="btn-primary"><x-icon name="printer" class="size-4" /> {{ __('Imprimer ou PDF') }}</button></div></div>
    <main class="sheet mx-auto my-8 max-w-2xl rounded-2xl bg-white p-8 text-sm shadow-lg">
        <x-documents.header :identity="$identity" :organization="$organization" />
        <h1 class="mt-6 text-center text-xl font-semibold uppercase tracking-wide text-ink-800">{{ __('Bulletin de paie') }}</h1>
        <p class="mb-5 text-center text-sand-700">{{ $r->label() }} · {{ __('du :a au :b', ['a' => $r->period_start->translatedFormat('j F Y'), 'b' => $r->period_end->translatedFormat('j F Y')]) }}</p>
        <div class="mb-5 grid grid-cols-2 gap-3 rounded-xl border border-sand-200 p-3">
            <div><p class="text-xs text-sand-700">{{ __('Bénéficiaire') }}</p><p class="font-semibold text-ink-800">{{ $p->member?->officialName() ?? $p->name }}</p></div>
            <div><p class="text-xs text-sand-700">{{ __('Fonction') }}</p><p class="font-semibold text-ink-800">{{ $p->position ?: '—' }}</p></div>
            <div><p class="text-xs text-sand-700">{{ __('Département') }}</p><p class="text-ink-800">{{ $p->department?->name ?? '—' }}</p></div>
            <div><p class="text-xs text-sand-700">{{ __('Rythme') }}</p><p class="text-ink-800">{{ $r->schedule?->describe() }}</p></div>
        </div>
        <table class="w-full border-collapse">
            <tbody>
                <tr class="border-b border-sand-200"><td class="py-1.5">{{ $r->schedule?->isPerService() ? __('Base : :q × :m', ['q' => (float) $s->quantity, 'm' => Money::format($p->base_amount, $s->currency)]) : __('Montant de base') }}</td><td class="py-1.5 text-right tabular">{{ Money::format($s->base, $s->currency) }}</td></tr>
                @foreach (collect($s->details['lines'] ?? [])->where('kind', 'earning') as $l)<tr class="border-b border-sand-100"><td class="py-1.5">{{ $l['label'] }}</td><td class="py-1.5 text-right tabular">{{ Money::format($l['amount'], $s->currency) }}</td></tr>@endforeach
                <tr class="font-semibold"><td class="py-2">{{ __('Brut') }}</td><td class="py-2 text-right tabular">{{ Money::format($s->gross, $s->currency) }}</td></tr>
                @foreach (collect($s->details['lines'] ?? [])->where('kind', 'deduction') as $l)<tr class="border-b border-sand-100"><td class="py-1.5">{{ $l['label'] }}@if ($l['statutory'] ?? false) <span class="text-xs text-sand-700">({{ __('cotisation légale') }})</span>@endif</td><td class="py-1.5 text-right tabular">− {{ Money::format($l['amount'], $s->currency) }}</td></tr>@endforeach
                @foreach ($s->details['advances'] ?? [] as $a)<tr class="border-b border-sand-100"><td class="py-1.5">{{ __('Retenue :l', ['l' => mb_strtolower($a['label'])]) }}</td><td class="py-1.5 text-right tabular">− {{ Money::format($a['amount'], $s->currency) }}</td></tr>@endforeach
                <tr class="border-t-2 border-ink-700 text-base font-semibold"><td class="py-2">{{ __('Net à payer') }}</td><td class="py-2 text-right tabular">{{ Money::format($s->net, $s->currency) }}</td></tr>
            </tbody>
        </table>
        @if ($s->paid_at)
            <p class="mt-3 text-sand-700">{{ __('Payé le :d, :a', ['d' => $s->paid_at->translatedFormat('j F Y'), 'a' => $s->account?->name]) }}@if ($s->paid_currency !== $s->currency) {{ __('en :m (équivalent au taux du jour)', ['m' => Money::format($s->paid_amount, $s->paid_currency)]) }}@endif.</p>
        @endif
        <div class="mt-12 grid grid-cols-2 gap-10">
            <div><p class="font-semibold text-ink-800">{{ __('Pour l’église') }}</p><div class="mt-10 border-t border-sand-300 pt-1 text-xs text-sand-700">{{ __('Signature') }}</div></div>
            <div><p class="font-semibold text-ink-800">{{ __('Reçu par le bénéficiaire') }}</p><div class="mt-10 border-t border-sand-300 pt-1 text-xs text-sand-700">{{ __('Signature') }}</div></div>
        </div>
    </main>
</body>
</html>
