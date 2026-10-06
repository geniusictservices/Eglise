{{-- D'où viendra l'argent d'un dépassement : champs liés à « overrun.* », lignes cédantes dans $sourceLines. --}}
@php use App\Support\Money; @endphp
<fieldset>
    <legend class="label">{{ __('D’où viendra l’argent ?') }}</legend>
    <div class="space-y-2">
        @foreach (\App\Models\BudgetOverrun::SOURCES as $k => $label)
            <label @class(['flex cursor-pointer items-start gap-3 rounded-xl border p-3 text-sm', 'border-ochre-400 bg-ochre-50' => ($overrun['source'] ?? '') === $k, 'border-sand-200' => ($overrun['source'] ?? '') !== $k])>
                <input type="radio" wire:model.live="overrun.source" value="{{ $k }}" class="mt-0.5 size-4"><span class="text-ink-800">{{ __($label) }}</span></label>
        @endforeach
    </div>
</fieldset>
@if (($overrun['source'] ?? '') === 'transfer')
    <div><label for="ov-line" class="label">{{ __('Ligne du budget qui cède l’argent') }}</label>
        <select wire:model="overrun.source_key" id="ov-line" class="input"><option value="">{{ __('Choisir…') }}</option>
            @foreach ($sourceLines as $key => $l)<option value="{{ $key }}">{{ $l['department'] }} · {{ $l['category'] }} ({{ __('disponible :m', ['m' => Money::format($l['available'], 'USD')]) }})</option>@endforeach
        </select>@error('overrun.source_key') <p class="error">{{ $message }}</p> @enderror</div>
@else
    <div><label for="ov-detail" class="label">{{ __('Précision') }}</label><input wire:model="overrun.source_detail" id="ov-detail" class="input" placeholder="{{ ($overrun['source'] ?? '') === 'reserves' ? __('Exemple : excédent de l’exercice 2025') : __('Exemple : don de la famille Mbuyi, reçu le 3 octobre') }}">@error('overrun.source_detail') <p class="error">{{ $message }}</p> @enderror</div>
@endif
