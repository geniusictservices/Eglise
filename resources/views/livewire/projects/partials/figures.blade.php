{{-- Les chiffres d'un projet, en dollars : objectif, promis, reçu, dépensé, disponible. --}}
@php use App\Support\Money; $t = $totals; @endphp
<div class="grid grid-cols-2 gap-2 sm:grid-cols-5">
    @foreach ([[__('Objectif'), $t['goal'], 'text-ink-800'], [__('Promis'), $t['promised'], 'text-ink-800'], [__('Reçu'), $t['received'] + $t['in_kind'], 'text-leaf-600'], [($relay ?? false) ? __('Versé au siège') : __('Dépensé'), $t['spent'], 'text-terra-600'], [__('Disponible'), $t['available'], $t['available'] < 0 ? 'text-terra-600' : 'text-ink-800']] as [$label, $value, $tone])
        <div class="rounded-xl bg-sand-50 px-3 py-2 {{ $loop->last ? 'col-span-2 sm:col-span-1' : '' }}">
            <p class="text-xs text-sand-700">{{ $label }}</p>
            <p class="font-semibold tabular {{ $tone }}">{{ $value === null ? '—' : Money::format($value, 'USD') }}</p>
        </div>
    @endforeach
</div>
@if ($t['budgeted_planned'] > 0 || $t['committed'] > 0)
    <p class="mt-2 text-xs text-sand-700">
        @if ($t['budgeted_planned'] > 0){{ __('Le budget adopté lui accorde :p sur les recettes ordinaires (dîmes, offrandes…) ; :m sont déjà débloqués, au rythme des recettes rentrées, et comptent dans le disponible.', ['p' => Money::format($t['budgeted_planned'], 'USD'), 'm' => Money::format($t['budgeted'], 'USD')]) }}@endif
        @if ($t['committed'] > 0){{ __('Dépenses approuvées pas encore payées : :m.', ['m' => Money::format($t['committed'], 'USD')]) }}@endif
    </p>
@endif
