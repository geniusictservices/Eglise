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

    @if ($drift)
        <p class="mb-5 rounded-2xl border border-ochre-200 bg-ochre-50 p-4 text-sm text-ink-800"><x-icon name="triangle-alert" class="mr-1 inline size-4 text-ochre-700" />
            {{ __('Ces chiffres ne sont plus exactement ceux de la clôture du :d : un compte, un solde de départ ou un libellé a changé depuis. À la clôture : recettes :i, dépenses :e. Rouvrez puis clôturez à nouveau la période si la différence est voulue.', ['d' => $closing->closed_at->translatedFormat('j M Y'), 'i' => \App\Support\Money::format($drift['income'], 'USD'), 'e' => \App\Support\Money::format($drift['expense'], 'USD')]) }}</p>
    @endif

    <section class="card p-5 sm:p-6" wire:loading.class="opacity-60">
        <h2 class="mb-4 text-lg">{{ $title }}</h2>
        @include('finances.partials.report', ['r' => $r])
    </section>
</div>
