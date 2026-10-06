<div>
    <a href="{{ route('finances.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Finances') }}</a>
    <x-page-header :title="__('Rapports financiers')" :description="__('Le rapport d’un mois ou d’un exercice, pour le conseil, l’assemblée ou le niveau supérieur.')">
        <x-slot:actions>
            <a href="{{ route('finances.reports.print', $query) }}" target="_blank" class="btn-secondary"><x-icon name="printer" class="size-4" /> {{ __('Imprimer ou PDF') }}</a>
            <a href="{{ route('finances.reports.excel', $query) }}" class="btn-primary"><x-icon name="file-spreadsheet" class="size-4" /> {{ __('Excel') }}</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-5 flex flex-wrap items-center gap-2">
        <select wire:model.live="month" class="input !w-auto" aria-label="{{ __('Période') }}">
            <option value="0">{{ __('Exercice entier') }}</option>
            @foreach ($months as $m => $label)<option value="{{ $m }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="year" class="input !w-auto" aria-label="{{ __('Exercice') }}">@foreach ($years as $y => $label)<option value="{{ $y }}">{{ $label }}</option>@endforeach</select>
        @if ($closing?->isClosed())
            <span class="badge bg-leaf-50 text-leaf-600"><x-icon name="lock" class="size-3.5" /> {{ __('Clôturé le :d', ['d' => $closing->closed_at->translatedFormat('j M Y')]) }}</span>
        @else
            <span class="badge bg-ochre-100 text-ochre-700">{{ __('Provisoire : période non clôturée') }}</span>
        @endif
    </div>

    <section class="card p-5 sm:p-6" wire:loading.class="opacity-60">
        <h2 class="mb-4 text-lg">{{ $title }}</h2>
        @include('finances.partials.report', ['r' => $r])
    </section>
</div>
