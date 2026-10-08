{{-- Dépenses prévues et recettes prévues : deux onglets séparés, chacun avec son total ; le budget ajoute ses projets. --}}
@php use App\Support\Money; $projectsTab ??= null; @endphp
<div @class(['mb-5 grid grid-cols-2 gap-2 rounded-2xl border border-sand-200 bg-white p-1.5', 'sm:grid-cols-3' => $projectsTab]) role="tablist">
    @foreach (['depenses' => [__('Dépenses prévues'), $totals['expense'], 'terra'], 'recettes' => [__('Recettes prévues'), $totals['income'], 'leaf']] as $key => [$label, $total, $tone])
        <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                @class(['rounded-xl px-3 py-2.5 text-left transition', 'bg-ink-700 text-white' => $tab === $key, 'hover:bg-sand-50' => $tab !== $key])>
            <span @class(['flex items-center gap-1.5 text-sm font-semibold', 'text-white' => $tab === $key, 'text-ink-700' => $tab !== $key])>
                <span @class(['size-2 rounded-full', 'bg-terra-500' => $tone === 'terra', 'bg-leaf-500' => $tone === 'leaf'])></span>{{ $label }}
            </span>
            <span @class(['block text-lg font-semibold tabular', 'text-white' => $tab === $key, 'text-terra-600' => $tab !== $key && $tone === 'terra', 'text-leaf-600' => $tab !== $key && $tone === 'leaf'])>{{ Money::format($total, 'USD') }}</span>
            @isset($notes[$key])<span @class(['block text-xs', 'text-ink-100' => $tab === $key, 'text-sand-700' => $tab !== $key])>{{ $notes[$key] }}</span>@endisset
        </button>
    @endforeach
    @if ($projectsTab)
        <button type="button" role="tab" wire:click="$set('tab', 'projets')" aria-selected="{{ $tab === 'projets' ? 'true' : 'false' }}"
                @class(['col-span-2 rounded-xl px-3 py-2.5 text-left transition sm:col-span-1', 'bg-ink-700 text-white' => $tab === 'projets', 'hover:bg-sand-50' => $tab !== 'projets'])>
            <span @class(['flex items-center gap-1.5 text-sm font-semibold', 'text-white' => $tab === 'projets', 'text-ink-700' => $tab !== 'projets'])>
                <span class="size-2 rounded-full bg-ochre-500"></span>{{ __('Projets de l’exercice') }}
            </span>
            <span @class(['block text-lg font-semibold', 'text-white' => $tab === 'projets', 'text-ink-800' => $tab !== 'projets'])>{{ $projectsTab }}</span>
        </button>
    @endif
</div>
