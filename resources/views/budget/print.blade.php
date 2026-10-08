@php use App\Support\Money; $income = $b->total('income'); $expense = $b->total('expense'); @endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => __('Budget :y', ['y' => $yearLabel])])
    <style>
        @page { size: A4; margin: 12mm; }
        @media print { body { background: #fff !important; } .no-print { display: none !important; } .sheet { box-shadow: none !important; padding: 0 !important; margin: 0 !important; } * { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body class="min-h-dvh bg-sand-100">
    <div class="no-print sticky top-0 z-10 border-b border-sand-200 bg-white/95">
        <div class="mx-auto flex max-w-4xl items-center gap-3 px-4 py-3">
            <a href="{{ route('budget.version', $b) }}" class="inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Budget') }}</a>
            <span class="flex-1"></span>
            <button type="button" onclick="window.print()" class="btn-primary"><x-icon name="printer" class="size-4" /> {{ __('Imprimer ou PDF') }}</button>
        </div>
    </div>
    <main class="sheet mx-auto my-8 max-w-4xl rounded-2xl bg-white p-8 shadow-lg">
        <x-documents.header :identity="$identity" :organization="$organization" />
        <h1 class="mt-6 text-center text-xl font-semibold uppercase tracking-wide text-ink-800">{{ __('Budget de l’exercice :y', ['y' => $yearLabel]) }}</h1>
        <p class="mb-6 text-center text-sm text-sand-700">{{ __('Du :a au :b · version :v', ['a' => $bounds[0]->translatedFormat('j F Y'), 'b' => $bounds[1]->translatedFormat('j F Y'), 'v' => $b->version]) }}@if ($b->reason) · {{ $b->reason }}@endif</p>

        <div class="mb-6 grid grid-cols-3 gap-3 text-sm">
            @foreach ([[__('Recettes prévues'), $income], [__('Dépenses prévues'), $expense], [$income - $expense < 0 ? __('Déficit') : __('Excédent'), $income - $expense]] as [$label, $value])
                <div class="rounded-xl border border-sand-200 p-3"><p class="text-xs text-sand-700">{{ $label }}</p><p class="text-lg font-semibold tabular text-ink-800">{{ Money::format($value, 'USD') }}</p></div>
            @endforeach
        </div>

        @foreach (['income' => __('Recettes prévues'), 'expense' => __('Dépenses prévues')] as $type => $title)
            <h2 class="mb-2 mt-6 text-base font-semibold text-ink-800">{{ $title }}</h2>
            <table class="w-full border-collapse text-sm">
                <thead class="border-b-2 border-ink-700 text-xs text-ink-700"><tr><th class="px-2 py-1.5 text-left font-semibold">{{ __('Objet') }}</th><th class="px-2 py-1.5 text-left font-semibold">{{ __('Catégorie') }}</th><th class="px-2 py-1.5 text-right font-semibold">{{ __('Montant') }}</th></tr></thead>
                <tbody>
                    @foreach ($groups[$type] as $group => $lines)
                        <tr class="bg-sand-50 font-semibold break-inside-avoid"><td class="px-2 py-1.5" colspan="2">{{ $group }}</td><td class="whitespace-nowrap px-2 py-1.5 text-right tabular">{{ Money::format($lines->sum('amount'), 'USD') }}</td></tr>
                        @foreach ($lines as $l)
                            <tr class="border-b border-sand-100"><td class="px-2 py-1.5 pl-5">{{ $l->label }}@if ($type === 'expense' && $state['expense'][$l->id]['sources']->isNotEmpty())<span class="block text-xs text-sand-700">{{ __('Financée par') }} {{ $state['expense'][$l->id]['sources']->map(fn ($src) => $src['line']->label.' '.Money::format($src['amount'], 'USD'))->implode(' · ') }}</span>@endif</td><td class="px-2 py-1.5 text-sand-700">{{ $l->category?->name }}</td><td class="whitespace-nowrap px-2 py-1.5 text-right tabular">{{ Money::format($l->amount, 'USD') }}</td></tr>
                        @endforeach
                    @endforeach
                    <tr class="font-semibold"><td class="px-2 py-2" colspan="2">{{ __('Total') }}</td><td class="whitespace-nowrap px-2 py-2 text-right tabular">{{ Money::format($b->total($type), 'USD') }}</td></tr>
                </tbody>
            </table>
        @endforeach

        <div class="mt-10 grid grid-cols-2 gap-10 text-sm break-inside-avoid">
            <div><p class="font-semibold text-ink-800">{{ __('Présenté par la finance') }}</p><p class="text-sand-700">{{ $b->submitter?->name }}@if ($b->submitted_at), {{ $b->submitted_at->translatedFormat('j F Y') }}@endif</p><div class="mt-10 border-t border-sand-300 pt-1 text-xs text-sand-700">{{ __('Signature') }}</div></div>
            <div><p class="font-semibold text-ink-800">{{ __('Approuvé par le pasteur') }}</p><p class="text-sand-700">{{ $b->approver?->name }}@if ($b->approved_at), {{ $b->approved_at->translatedFormat('j F Y') }}@endif</p><div class="mt-10 border-t border-sand-300 pt-1 text-xs text-sand-700">{{ __('Signature') }}</div></div>
        </div>
        <p class="mt-8 text-center text-xs text-sand-500">{{ __('Montants en dollars. Établi le :d avec Waumini', ['d' => now()->translatedFormat('j F Y à H:i')]) }}</p>
    </main>
</body>
</html>
