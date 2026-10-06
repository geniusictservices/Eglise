@php use App\Support\Money; $t = $x['totals']; @endphp
<div>
    <a href="{{ route('budget.index', ['exercice' => $year]) }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Budget') }}</a>
    <x-page-header :title="__('Suivi du budget')" :description="__('Le budget adopté face aux opérations : ce qui est dépensé, ce qui est engagé (dépenses contrôlées, pas encore payées) et ce qui reste.')">
        <x-slot:actions>
            <select wire:model.live="year" class="input !w-auto" aria-label="{{ __('Exercice') }}">@foreach ($years as $y => $label)<option value="{{ $y }}">{{ __('Exercice :y', ['y' => $label]) }}</option>@endforeach</select>
        </x-slot:actions>
    </x-page-header>

    @if (! $x['budget'])
        <div class="card p-8 text-center text-sand-700">{{ __('Pas de budget adopté pour l’exercice :y.', ['y' => $yearLabel]) }}</div>
    @else
        <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ([[__('Dépenses prévues'), $t['expense_budgeted'], 'text-ink-800'], [__('Dépenses réalisées'), $t['expense_actual'], 'text-terra-600'], [__('Recettes prévues'), $t['income_budgeted'], 'text-ink-800'], [__('Recettes réalisées'), $t['income_actual'], 'text-leaf-600']] as [$label, $value, $tone])
                <div class="card p-4"><p class="text-sm text-sand-700">{{ $label }}</p><p class="text-xl font-semibold tabular {{ $tone }}">{{ Money::format($value, 'USD') }}</p></div>
            @endforeach
        </div>
        <p class="mb-5 text-sm text-sand-700">{{ __(':p % de l’exercice est écoulé : une ligne bien au-delà de ce rythme mérite un regard.', ['p' => $elapsed]) }} {{ __('Version :v du budget.', ['v' => $x['budget']->version]) }}</p>

        <section class="card mb-5 p-5 sm:p-6">
            <h2 class="mb-3 text-lg">{{ __('Dépenses') }}</h2>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[42rem] border-collapse text-sm">
                    <thead class="border-b-2 border-ink-700 text-xs text-ink-700">
                        <tr>
                            <th class="px-2 py-1.5 text-left font-semibold">{{ __('Ligne') }}</th>
                            @foreach ([__('Prévu'), __('Dépensé'), __('Engagé'), __('Disponible')] as $h)<th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold">{{ $h }}</th>@endforeach
                            <th class="w-32 px-2 py-1.5"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (collect($x['expense'])->sortBy(fn ($r) => $r['department'].$r['category']) as $r)
                            @php $planned = $r['budgeted'] + $r['overruns'] - $r['transfers']; $used = $planned > 0 ? min(100, round(($r['actual'] + $r['committed']) / $planned * 100)) : 100; @endphp
                            <tr class="border-b border-sand-100">
                                <td class="px-2 py-2"><span class="font-semibold text-ink-800">{{ $r['department'] }}</span> <span class="text-sand-700">· {{ $r['category'] }}</span>
                                    @if ($r['overruns'] || $r['transfers'])<span class="block text-xs text-ochre-700">{{ collect([$r['overruns'] ? __('+ :m autorisés', ['m' => Money::format($r['overruns'], 'USD')]) : null, $r['transfers'] ? __('− :m cédés', ['m' => Money::format($r['transfers'], 'USD')]) : null])->filter()->implode(' · ') }}</span>@endif</td>
                                <td class="whitespace-nowrap px-2 py-2 text-right tabular">{{ Money::format($planned, 'USD') }}</td>
                                <td class="whitespace-nowrap px-2 py-2 text-right tabular">{{ Money::format($r['actual'], 'USD') }}</td>
                                <td class="whitespace-nowrap px-2 py-2 text-right tabular">{{ $r['committed'] ? Money::format($r['committed'], 'USD') : '·' }}</td>
                                <td @class(['whitespace-nowrap px-2 py-2 text-right font-semibold tabular', 'text-terra-600' => $r['available'] < 0, 'text-ink-800' => $r['available'] >= 0])>{{ Money::format($r['available'], 'USD') }}</td>
                                <td class="px-2 py-2"><span class="block h-2 overflow-hidden rounded-full bg-sand-100"><span @class(['block h-full rounded-full', 'bg-terra-500' => $used >= 100, 'bg-ochre-500' => $used < 100 && $used > $elapsed + 15, 'bg-leaf-500' => $used <= $elapsed + 15]) style="width: {{ $used }}%"></span></span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if (! empty($x['unbudgeted']['expense']))
                <h3 class="mb-1 mt-5 text-sm font-semibold text-terra-600">{{ __('Dépensé hors budget') }}</h3>
                <ul class="text-sm">
                    @foreach ($x['unbudgeted']['expense'] as $key => $value)
                        @php [$d, $c] = array_map('intval', explode('-', $key)); @endphp
                        <li class="flex justify-between border-b border-sand-100 py-1.5"><span>{{ $names['departments'][$d] ?? __('Sans département') }} · {{ $names['categories'][$c] ?? '' }}</span><span class="tabular">{{ Money::format($value, 'USD') }}</span></li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="card mb-5 p-5 sm:p-6">
            <h2 class="mb-3 text-lg">{{ __('Recettes') }}</h2>
            <ul class="divide-y divide-sand-100 text-sm">
                @foreach ($x['income'] as $r)
                    @php $p = $r['budgeted'] > 0 ? round($r['actual'] / $r['budgeted'] * 100) : 0; @endphp
                    <li class="flex flex-wrap items-center gap-x-4 gap-y-1 py-2">
                        <span class="min-w-0 flex-1"><span class="font-semibold text-ink-800">{{ $r['category'] }}</span> <span class="text-sand-700">· {{ $r['department'] ?? __('Recettes générales') }}</span></span>
                        <span class="tabular">{{ Money::format($r['actual'], 'USD') }} <span class="text-sand-700">/ {{ Money::format($r['budgeted'], 'USD') }}</span></span>
                        <span class="w-12 text-right font-semibold tabular text-ink-800">{{ $p }} %</span>
                    </li>
                @endforeach
            </ul>
            @if (! empty($x['unbudgeted']['income']))
                <p class="mt-3 text-sm text-sand-700">{{ __('Recettes reçues sans ligne au budget : :m.', ['m' => Money::format(array_sum($x['unbudgeted']['income']), 'USD')]) }}</p>
            @endif
        </section>

        <section class="card p-5 sm:p-6">
            <h2 class="mb-3 text-lg">{{ __('Dépassements') }}</h2>
            <ul class="divide-y divide-sand-100 text-sm">
                @forelse ($overruns as $o)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5">
                        <span class="min-w-0 flex-1">
                            <span class="block font-semibold text-ink-800">{{ Money::format($o->amount, 'USD') }} · {{ $o->department?->name }} · {{ $o->category?->name }}</span>
                            <span class="block text-xs text-sand-700">{{ $o->sourceLabel() }}@if ($o->expense) · <a href="{{ route('finances.expenses.show', $o->expense) }}" class="font-semibold hover:underline">{{ $o->expense->number }}</a>@endif@if ($o->payRun) · <a href="{{ route('payroll.run', $o->payRun) }}" class="font-semibold hover:underline">{{ __('paie :p', ['p' => $o->payRun->label()]) }}</a>@endif</span>
                        </span>
                        <span @class(['badge', 'bg-ochre-100 text-ochre-700' => $o->status === 'pending', 'bg-leaf-50 text-leaf-600' => $o->status === 'authorized', 'bg-terra-50 text-terra-600' => $o->status === 'refused'])>{{ __(\App\Models\BudgetOverrun::STATUSES[$o->status]) }}</span>
                    </li>
                @empty
                    <li class="py-2 text-sand-700">{{ __('Aucun dépassement demandé.') }}</li>
                @endforelse
            </ul>
        </section>
    @endif
</div>
