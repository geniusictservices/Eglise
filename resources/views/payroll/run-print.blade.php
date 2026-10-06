@php use App\Support\Money; @endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => __('État de paie : :p', ['p' => $r->label()])])
    <style>@page { size: A4 landscape; margin: 10mm; } @media print { body { background: #fff !important; } .no-print { display: none !important; } .sheet { box-shadow: none !important; padding: 0 !important; margin: 0 !important; max-width: none !important; } * { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }</style>
</head>
<body class="min-h-dvh bg-sand-100">
    <div class="no-print sticky top-0 z-10 border-b border-sand-200 bg-white/95"><div class="mx-auto flex max-w-5xl items-center gap-3 px-4 py-3">
        <a href="{{ route('payroll.run', $r) }}" class="inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Paie') }}</a><span class="flex-1"></span>
        <button type="button" onclick="window.print()" class="btn-primary"><x-icon name="printer" class="size-4" /> {{ __('Imprimer ou PDF') }}</button></div></div>
    <main class="sheet mx-auto my-8 max-w-5xl rounded-2xl bg-white p-8 text-sm shadow-lg">
        <x-documents.header :identity="$identity" :organization="$organization" />
        <h1 class="mt-6 text-center text-xl font-semibold uppercase tracking-wide text-ink-800">{{ __('État de paie') }}</h1>
        <p class="mb-5 text-center text-sand-700">{{ $r->schedule?->name }} · {{ $r->label() }} · {{ __('du :a au :b', ['a' => $r->period_start->translatedFormat('j F Y'), 'b' => $r->period_end->translatedFormat('j F Y')]) }}</p>
        <table class="w-full border-collapse">
            <thead class="border-b-2 border-ink-700 text-xs text-ink-700"><tr>
                <th class="px-2 py-1.5 text-left">{{ __('Bénéficiaire') }}</th><th class="px-2 py-1.5 text-left">{{ __('Fonction') }}</th>
                @foreach ([__('Base'), __('Gains'), __('Brut'), __('Retenues'), __('Avances'), __('Net'), __('Signature')] as $h)<th class="px-2 py-1.5 text-right">{{ $h }}</th>@endforeach
            </tr></thead>
            <tbody>
                @foreach ($r->slips->where('net', '>', 0) as $s)
                    <tr class="border-b border-sand-200">
                        <td class="px-2 py-2 font-semibold">{{ $s->payee->displayName() }}</td><td class="px-2 py-2">{{ $s->payee->position }}</td>
                        <td class="whitespace-nowrap px-2 py-2 text-right tabular">{{ Money::format($s->base, $s->currency) }}</td>
                        <td class="whitespace-nowrap px-2 py-2 text-right tabular">{{ Money::format((float) $s->gross - (float) $s->base, $s->currency) }}</td>
                        <td class="whitespace-nowrap px-2 py-2 text-right tabular">{{ Money::format($s->gross, $s->currency) }}</td>
                        <td class="whitespace-nowrap px-2 py-2 text-right tabular">{{ Money::format($s->deductions, $s->currency) }}</td>
                        <td class="whitespace-nowrap px-2 py-2 text-right tabular">{{ Money::format($s->advance_total, $s->currency) }}</td>
                        <td class="whitespace-nowrap px-2 py-2 text-right font-semibold tabular">{{ Money::format($s->net, $s->currency) }}</td>
                        <td class="w-32 px-2 py-2"></td>
                    </tr>
                @endforeach
                @foreach ($r->totals() as $currency => $t)
                    <tr class="bg-sand-50 font-semibold"><td class="px-2 py-2" colspan="4">{{ __('Total :c', ['c' => $currency]) }}</td>
                        <td class="whitespace-nowrap px-2 py-2 text-right tabular">{{ Money::format($t['gross'], $currency) }}</td><td class="whitespace-nowrap px-2 py-2 text-right tabular">{{ Money::format($t['deductions'], $currency) }}</td>
                        <td class="whitespace-nowrap px-2 py-2 text-right tabular">{{ Money::format($t['advances'], $currency) }}</td><td class="whitespace-nowrap px-2 py-2 text-right tabular">{{ Money::format($t['net'], $currency) }}</td><td></td></tr>
                @endforeach
            </tbody>
        </table>
        <div class="mt-10 grid grid-cols-2 gap-10 break-inside-avoid">
            <div><p class="font-semibold text-ink-800">{{ __('Préparée et présentée par la finance') }}</p><p class="text-sand-700">{{ $r->submitter?->name }}</p><div class="mt-10 border-t border-sand-300 pt-1 text-xs text-sand-700">{{ __('Signature') }}</div></div>
            <div><p class="font-semibold text-ink-800">{{ __('Approuvée par le pasteur') }}</p><p class="text-sand-700">{{ $r->approver?->name }}@if ($r->approved_at), {{ $r->approved_at->translatedFormat('j F Y') }}@endif</p><div class="mt-10 border-t border-sand-300 pt-1 text-xs text-sand-700">{{ __('Signature') }}</div></div>
        </div>
    </main>
</body>
</html>
