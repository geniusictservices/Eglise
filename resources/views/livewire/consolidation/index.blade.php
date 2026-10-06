@php $usd = fn ($v) => \App\Support\Money::format($v, 'USD'); @endphp
<div>
    <x-page-header :title="__('Consolidation')" :description="__('Les chiffres de chaque niveau, additionnés de ses niveaux inférieurs, sans rien ressaisir. Touchez un niveau pour voir le détail en dessous.')">
        <x-slot:actions>
            @can('transfers.manage')<a href="{{ route('transfers.index') }}" class="btn-secondary"><x-icon name="arrow-left-right" class="size-4" /> {{ __('Transferts') }}</a>@endcan
            <a href="{{ route('quotas.index') }}" class="btn-secondary"><x-icon name="hand-coins" class="size-4" /> {{ __('Quotes-parts') }}</a>
        </x-slot:actions>
    </x-page-header>

    <nav class="mb-3 flex flex-wrap items-center gap-1 text-sm" aria-label="{{ __('Niveaux') }}">
        @foreach ($trail as $t)
            @if (! $loop->first)<x-icon name="chevron-right" class="size-4 text-sand-400" />@endif
            @if ($loop->last)<span class="font-semibold text-ink-800">{{ $t->name }}</span>@else<button type="button" wire:click="$set('unitId', {{ $t->id }})" class="text-ink-600 hover:underline">{{ $t->name }}</button>@endif
        @endforeach
    </nav>

    <div class="mb-5 flex flex-wrap items-center gap-2">
        <button type="button" wire:click="shift(-1)" class="btn-ghost !px-3" aria-label="{{ __('Période précédente') }}"><x-icon name="chevron-left" class="size-5" /></button>
        <h2 class="min-w-40 text-center text-lg">{{ $label }}</h2>
        <button type="button" wire:click="shift(1)" class="btn-ghost !px-3" aria-label="{{ __('Période suivante') }}"><x-icon name="chevron-right" class="size-5" /></button>
        <div class="ml-auto flex rounded-xl border border-sand-200 bg-white p-0.5 text-sm font-semibold">
            <button type="button" wire:click="$set('period', '{{ now()->format('Y-m') }}')" @class(['rounded-lg px-3 py-1.5', 'bg-ink-700 text-white' => ! $yearly, 'text-ink-600' => $yearly])>{{ __('Mois') }}</button>
            <button type="button" wire:click="$set('period', '{{ now()->year }}')" @class(['rounded-lg px-3 py-1.5', 'bg-ink-700 text-white' => $yearly, 'text-ink-600' => ! $yearly])>{{ __('Année') }}</button>
        </div>
    </div>

    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([[__('Membres'), number_format($totals['members'], 0, ',', ' '), $totals['new_members'] ? trans_choice(':count nouveau|:count nouveaux', $totals['new_members']) : null],
            [__('Recettes'), $usd($totals['income']), null], [__('Dépenses'), $usd($totals['expense']), null],
            [__('Présence au culte'), $totals['attendance'] !== null ? number_format($totals['attendance'], 0, ',', ' ') : '—', __('moyenne, tous niveaux')]] as [$l, $v, $h])
            <div class="card p-4"><p class="text-sm text-sand-700">{{ $l }}</p><p class="text-2xl font-semibold text-ink-800 tabular">{{ $v }}</p>@if ($h)<p class="text-xs text-sand-600">{{ $h }}</p>@endif</div>
        @endforeach
    </div>

    <div class="grid gap-5 xl:grid-cols-[1fr_20rem]">
        <section class="card min-w-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-sm">
                    <thead><tr class="border-b border-sand-200 text-left text-xs text-sand-700">
                        <th class="px-4 py-3 font-semibold">{{ __('Niveau') }}</th><th class="px-3 py-3 text-right font-semibold">{{ __('Membres') }}</th>
                        <th class="px-3 py-3 text-right font-semibold">{{ __('Recettes') }}</th><th class="px-3 py-3 text-right font-semibold">{{ __('Dépenses') }}</th>
                        <th class="px-3 py-3 text-right font-semibold">{{ __('Solde') }}</th><th class="px-3 py-3 text-right font-semibold">{{ __('Culte') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('Dernière saisie') }}</th>
                    </tr></thead>
                    <tbody class="divide-y divide-sand-100">
                        @foreach ($units as $u)
                            <tr wire:key="u-{{ $u['organization']->id }}-{{ $u['own'] ? 'own' : 'all' }}" @class(['bg-sand-50' => $u['own']])>
                                <td class="px-4 py-3">
                                    @if (! $u['own'] && $u['count'] > 1)
                                        <button type="button" wire:click="$set('unitId', {{ $u['organization']->id }})" class="text-left font-semibold text-ink-800 hover:underline">{{ $u['organization']->name }}</button>
                                        <span class="block text-xs text-sand-600">{{ trans_choice(':count niveau|:count niveaux', $u['count']) }}</span>
                                    @else
                                        <span class="font-semibold text-ink-800">{{ $u['own'] ? __(':n (lui-même)', ['n' => $u['organization']->name]) : $u['organization']->name }}</span>
                                        <span class="block text-xs text-sand-600">{{ $u['organization']->level_label }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-right tabular">{{ number_format($u['members'], 0, ',', ' ') }}@if ($u['new_members'])<span class="block text-xs text-leaf-600">+{{ $u['new_members'] }}</span>@endif</td>
                                <td class="px-3 py-3 text-right tabular">{{ $usd($u['income']) }}</td>
                                <td class="px-3 py-3 text-right tabular">{{ $usd($u['expense']) }}</td>
                                <td @class(['px-3 py-3 text-right font-semibold tabular', 'text-terra-700' => $u['result'] < 0, 'text-ink-800' => $u['result'] >= 0])>{{ $usd($u['result']) }}</td>
                                <td class="px-3 py-3 text-right tabular">{{ $u['attendance'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-xs">
                                    @if ($u['last_entry'])<span @class(['font-semibold text-terra-700' => $u['late'], 'text-sand-700' => ! $u['late']])>{{ $u['last_entry']->diffForHumans() }}</span>@else<span class="text-sand-600">{{ __('aucune') }}</span>@endif
                                    @if ($u['late'])<span class="badge ml-1 bg-terra-50 text-terra-700">{{ __('En retard') }}</span>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr class="border-t-2 border-sand-200 font-semibold">
                        <td class="px-4 py-3">{{ __('Total') }}</td><td class="px-3 py-3 text-right tabular">{{ number_format($totals['members'], 0, ',', ' ') }}</td>
                        <td class="px-3 py-3 text-right tabular">{{ $usd($totals['income']) }}</td><td class="px-3 py-3 text-right tabular">{{ $usd($totals['expense']) }}</td>
                        <td class="px-3 py-3 text-right tabular">{{ $usd($totals['result']) }}</td><td class="px-3 py-3 text-right tabular">{{ $totals['attendance'] ?? '—' }}</td><td></td>
                    </tr></tfoot>
                </table>
            </div>
            <p class="border-t border-sand-100 px-4 py-3 text-xs text-sand-700">{{ __('Montants en dollars, au taux du jour de chaque opération. Les quotes-parts entre niveaux sont retirées pour ne pas compter deux fois le même argent.') }}</p>
        </section>

        <aside>
            <section @class(['rounded-[18px] p-5', 'border border-terra-100 bg-terra-50' => $late->isNotEmpty(), 'card' => $late->isEmpty()])>
                <h2 class="mb-1 flex items-center gap-2 text-base"><x-icon name="clock" class="size-5 text-terra-600" /> {{ __('Saisies en retard') }}</h2>
                <p class="mb-3 text-sm text-sand-700">{{ __('Aucune opération enregistrée depuis plus de :n jours.', ['n' => \App\Services\Consolidation::LATE_DAYS]) }}</p>
                <ul class="space-y-2 text-sm">
                    @forelse ($late as $l)
                        <li><span class="font-semibold text-ink-800">{{ $l['organization']->name }}</span><span class="block text-xs text-sand-700">{{ $l['last'] ? __('dernière saisie :d', ['d' => $l['last']->diffForHumans()]) : __('aucune saisie') }}@if ($l['organization']->phone) · <a href="tel:{{ $l['organization']->phone }}" class="underline">{{ $l['organization']->phone }}</a>@endif</span></li>
                    @empty
                        <li class="text-leaf-600">{{ __('Tous les niveaux saisissent à jour.') }}</li>
                    @endforelse
                </ul>
            </section>
        </aside>
    </div>
</div>
