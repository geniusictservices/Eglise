@php use App\Support\Money; @endphp
<div>
    <a href="{{ route('finances.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Finances') }}</a>
    <x-page-header :title="__('Collecte du culte')" :description="__('Une feuille par culte : les billets comptés, les offrandes de la boîte et les enveloppes nominatives. À la validation, tout devient recette.')">
        <x-slot:actions>
            @can('finance.income')<button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Nouvelle feuille') }}</button>@endcan
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex items-center">
        <div class="flex items-center rounded-2xl border border-sand-200 bg-white p-1">
            <button type="button" wire:click="shiftMonth(-1)" class="rounded-xl p-2 hover:bg-sand-50" aria-label="{{ __('Mois précédent') }}"><x-icon name="chevron-left" class="size-4" /></button>
            <span class="min-w-32 px-2 text-center text-sm font-semibold capitalize text-ink-800">{{ $monthLabel }}</span>
            <button type="button" wire:click="shiftMonth(1)" class="rounded-xl p-2 hover:bg-sand-50" aria-label="{{ __('Mois suivant') }}"><x-icon name="chevron-right" class="size-4" /></button>
        </div>
    </div>

    <ul class="space-y-3">
        @forelse ($sheets as $row)
            @php $s = $row['sheet']; @endphp
            <li wire:key="s-{{ $s->id }}">
                <a href="{{ route('finances.collections.show', $s) }}" class="card flex flex-wrap items-center gap-x-4 gap-y-2 p-4 transition hover:border-ochre-300">
                    <span class="grid size-12 shrink-0 place-items-center rounded-xl bg-ochre-100 text-center leading-none text-ochre-700">
                        <span><span class="block text-lg font-semibold">{{ $s->service_date->format('d') }}</span><span class="text-[10px] uppercase">{{ $s->service_date->translatedFormat('M') }}</span></span>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-semibold text-ink-700">{{ $s->service_label }}</span>
                        <span class="block text-sm text-sand-700">{{ $s->account->name }} · {{ trans_choice(':count enveloppe|:count enveloppes', $s->envelopes->count()) }}</span>
                    </span>
                    <span class="text-right">
                        @foreach ($row['summary'] as $currency => $sum)
                            <span class="block font-semibold tabular text-ink-800">{{ Money::format($sum['declared'], $currency) }}</span>
                        @endforeach
                        <span @class(['badge mt-1', 'bg-sand-100 text-sand-700' => $s->status === 'draft', 'bg-leaf-50 text-leaf-600' => $s->status === 'validated', 'bg-terra-50 text-terra-600' => $s->status === 'cancelled'])>{{ __(\App\Models\CollectionSheet::STATUSES[$s->status]) }}</span>
                    </span>
                </a>
            </li>
        @empty
            <li class="card p-8 text-center text-sand-700">{{ __('Aucune feuille de collecte ce mois-ci.') }}</li>
        @endforelse
    </ul>
</div>
