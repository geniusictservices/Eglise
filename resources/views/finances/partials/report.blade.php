{{-- Corps du rapport financier : à l'écran et à l'impression. --}}
@php
    use App\Support\Money;
    $cols = collect(array_merge(...array_map(fn ($row) => array_keys($row['amounts']), array_merge($r['income'], $r['expense']))))->unique()
        ->sortBy(fn ($c) => $c === config('waumini.base_currency') ? '' : $c)->values();
    $th = 'whitespace-nowrap px-2 py-1.5 text-right font-semibold';
    $td = 'whitespace-nowrap px-2 py-1.5 text-right tabular';
@endphp
<div class="space-y-6 text-sm">
    <div class="grid grid-cols-3 gap-3">
        @foreach ([[__('Recettes'), $r['totals']['income'], 'text-leaf-600'], [__('Dépenses'), $r['totals']['expense'], 'text-terra-600'], [__('Résultat'), $r['totals']['result'], $r['totals']['result'] < 0 ? 'text-terra-600' : 'text-ink-800']] as [$label, $value, $tone])
            <div class="rounded-xl border border-sand-200 p-3"><p class="text-xs text-sand-700">{{ $label }}</p><p class="text-lg font-semibold tabular {{ $tone }}">{{ Money::format($value, 'USD') }}</p></div>
        @endforeach
    </div>

    <section class="break-inside-avoid">
        <h3 class="mb-2 text-base font-semibold text-ink-800">{{ __('Comptes') }}</h3>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[40rem] border-collapse">
                <thead class="border-b-2 border-ink-700 text-xs text-ink-700">
                    <tr><th class="px-2 py-1.5 text-left font-semibold">{{ __('Compte') }}</th><th class="{{ $th }}">{{ __('Début') }}</th><th class="{{ $th }}">{{ __('Recettes') }}</th><th class="{{ $th }}">{{ __('Dépenses') }}</th><th class="{{ $th }}">{{ __('Virements et change') }}</th><th class="{{ $th }}">{{ __('Fin') }}</th></tr>
                </thead>
                <tbody>
                    @foreach ($r['accounts'] as $a)
                        <tr class="border-b border-sand-100">
                            <td class="min-w-[10rem] px-2 py-1.5">{{ $a['account']->name }} <span class="text-xs text-sand-700">{{ $a['currency'] }}</span></td>
                            <td class="{{ $td }}">{{ Money::format($a['opening'], $a['currency']) }}</td>
                            <td class="{{ $td }} text-leaf-600">{{ $a['income']->isZero() ? '·' : Money::format($a['income'], $a['currency']) }}</td>
                            <td class="{{ $td }} text-terra-600">{{ $a['expense']->isZero() ? '·' : Money::format($a['expense'], $a['currency']) }}</td>
                            <td class="{{ $td }}">{{ $a['transfers']->isZero() ? '·' : ($a['transfers']->isPositive() ? '+' : '').Money::format($a['transfers'], $a['currency']) }}</td>
                            <td class="{{ $td }} font-semibold">{{ Money::format($a['closing'], $a['currency']) }}</td>
                        </tr>
                    @endforeach
                    @foreach ($r['currencies'] as $code => $t)
                        <tr class="bg-sand-50 font-semibold">
                            <td class="px-2 py-1.5">{{ __('Total :c', ['c' => $code]) }}</td>
                            <td class="{{ $td }}">{{ Money::format($t['opening'], $code) }}</td>
                            <td class="{{ $td }}">{{ Money::format($t['income'], $code) }}</td>
                            <td class="{{ $td }}">{{ Money::format($t['expense'], $code) }}</td>
                            <td class="{{ $td }}">{{ $t['transfers']->isZero() ? '·' : Money::format($t['transfers'], $code) }}</td>
                            <td class="{{ $td }}">{{ Money::format($t['closing'], $code) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    @foreach (['income' => __('Recettes par catégorie'), 'expense' => __('Dépenses par catégorie')] as $type => $title)
        <section class="break-inside-avoid">
            <h3 class="mb-2 text-base font-semibold text-ink-800">{{ $title }}</h3>
            @if ($r[$type])
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[30rem] border-collapse">
                        <thead class="border-b-2 border-ink-700 text-xs text-ink-700">
                            <tr><th class="px-2 py-1.5 text-left font-semibold">{{ __('Catégorie') }}</th>@foreach ($cols as $c)<th class="{{ $th }}">{{ $c }}</th>@endforeach<th class="{{ $th }}">{{ __('Équiv. USD') }}</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($r[$type] as $row)
                                <tr class="border-b border-sand-100"><td class="px-2 py-1.5">{{ $row['name'] }}</td>
                                    @foreach ($cols as $c)<td class="{{ $td }}">{{ isset($row['amounts'][$c]) ? Money::format($row['amounts'][$c], $c) : '·' }}</td>@endforeach
                                    <td class="{{ $td }} font-semibold">{{ Money::format($row['usd'], 'USD') }}</td></tr>
                            @endforeach
                            <tr class="bg-sand-50 font-semibold"><td class="px-2 py-1.5">{{ __('Total') }}</td>
                                @foreach ($cols as $c)<td class="{{ $td }}">{{ Money::format(array_sum(array_map(fn ($row) => $row['amounts'][$c] ?? 0, $r[$type])), $c) }}</td>@endforeach
                                <td class="{{ $td }}">{{ Money::format($r['totals'][$type], 'USD') }}</td></tr>
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sand-700">{{ $type === 'income' ? __('Aucune recette sur la période.') : __('Aucune dépense sur la période.') }}</p>
            @endif
        </section>
    @endforeach

    @if ($r['departments'])
        <section class="break-inside-avoid">
            <h3 class="mb-2 text-base font-semibold text-ink-800">{{ __('Dépenses par département') }}</h3>
            <table class="w-full border-collapse">
                <tbody>
                    @foreach ($r['departments'] as $d)
                        <tr class="border-b border-sand-100"><td class="px-2 py-1.5">{{ $d['name'] }}</td><td class="{{ $td }} font-semibold">{{ Money::format($d['usd'], 'USD') }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    @if ($r['months'])
        <section class="break-inside-avoid">
            <h3 class="mb-2 text-base font-semibold text-ink-800">{{ __('Mois par mois') }}</h3>
            <table class="w-full border-collapse">
                <thead class="border-b-2 border-ink-700 text-xs text-ink-700"><tr><th class="px-2 py-1.5 text-left font-semibold">{{ __('Mois') }}</th><th class="{{ $th }}">{{ __('Recettes') }}</th><th class="{{ $th }}">{{ __('Dépenses') }}</th><th class="{{ $th }}">{{ __('Résultat') }}</th></tr></thead>
                <tbody>
                    @foreach ($r['months'] as $m)
                        <tr class="border-b border-sand-100"><td class="px-2 py-1.5">{{ ucfirst($m['month']->translatedFormat('F')) }}</td><td class="{{ $td }}">{{ Money::format($m['income'], 'USD') }}</td><td class="{{ $td }}">{{ Money::format($m['expense'], 'USD') }}</td><td class="{{ $td }} font-semibold">{{ Money::format($m['income'] - $m['expense'], 'USD') }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    <p class="text-xs text-sand-700">{{ __('Montants dans la devise de chaque compte. L’équivalent en dollars est calculé au taux du jour de chaque opération. Les opérations annulées ne sont pas comptées.') }}</p>
</div>
