@php use App\Models\PayItem; use App\Models\PaySchedule; @endphp
<div>
    <a href="{{ route('payroll.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Paie') }}</a>
    <x-page-header :title="__('Réglages de la paie')" :description="__('Les éléments de paie de votre église (primes, logement, transport, retenues, cotisations) et ses rythmes de paie.')" />

    <div class="mb-6 flex gap-1 overflow-x-auto rounded-2xl border border-sand-200 bg-white p-1" role="tablist">
        @foreach (['elements' => __('Éléments de paie'), 'rythmes' => __('Rythmes de paie')] as $key => $label)
            <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    @class(['flex-1 whitespace-nowrap rounded-xl px-4 py-2 text-sm font-semibold', 'bg-ink-700 text-white' => $tab === $key, 'text-ink-600 hover:bg-sand-50' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'elements')
        @if ($canManage)<div class="mb-4 flex justify-end"><button type="button" wire:click="editItem" class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Nouvel élément') }}</button></div>@endif
        <ul class="card divide-y divide-sand-100">
            @forelse ($items as $i)
                <li wire:key="i-{{ $i->id }}">
                    <button type="button" @if ($canManage) wire:click="editItem({{ $i->id }})" @endif @class(['flex w-full items-center gap-3 px-5 py-3 text-left', 'hover:bg-sand-50' => $canManage, 'opacity-60' => ! $i->is_active])>
                        <span @class(['icon-tile size-9', 'bg-leaf-50 text-leaf-600' => $i->kind === 'earning', 'bg-terra-50 text-terra-600' => $i->kind === 'deduction'])><x-icon :name="$i->kind === 'earning' ? 'plus' : 'undo-2'" class="size-4" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-semibold text-ink-800">{{ $i->name }}</span>
                            <span class="block text-xs text-sand-700">{{ __(PayItem::KINDS[$i->kind]) }} · {{ $i->calculation === 'fixed' ? __('montant fixe propre à chaque personne') : $i->describe() }}@if ($i->applies_to_all) · {{ __('pour tous') }}@endif @if ($i->is_statutory) · {{ __('cotisation légale') }}@endif @unless ($i->is_active) · {{ __('désactivé') }}@endunless</span>
                        </span>
                    </button>
                </li>
            @empty
                <li class="px-5 py-6 text-sm text-sand-700">{{ __('Aucun élément : la paie ne comprend que le montant de base. Ajoutez par exemple une prime de transport, une indemnité de logement ou une cotisation.') }}</li>
            @endforelse
        </ul>
    @else
        @if ($canManage)<div class="mb-4 flex justify-end"><button type="button" wire:click="editSchedule" class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Nouveau rythme') }}</button></div>@endif
        <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($schedules as $s)
                <li wire:key="s-{{ $s->id }}">
                    <button type="button" @if ($canManage) wire:click="editSchedule({{ $s->id }})" @endif @class(['card flex h-full w-full flex-col p-4 text-left', 'transition hover:border-ochre-300' => $canManage, 'opacity-60' => ! $s->is_active])>
                        <span class="font-semibold text-ink-800">{{ $s->name }}</span>
                        <span class="text-sm text-sand-700">{{ $s->describe() }}</span>
                        <span class="mt-2 text-xs text-sand-700">{{ trans_choice(':count bénéficiaire|:count bénéficiaires', $s->payees_count) }}</span>
                    </button>
                </li>
            @endforeach
        </ul>
        <p class="hint mt-3">{{ __('À la prestation : le montant de chaque personne est multiplié par le nombre de prestations de la période (cultes, prédications, journées…), saisi au moment de la paie.') }}</p>
    @endif

    <x-modal name="item" :title="$itemId ? __('Modifier l’élément') : __('Nouvel élément de paie')">
        <form wire:submit="saveItem" class="space-y-4">
            <div><label for="it-name" class="label">{{ __('Nom') }}</label><input wire:model="item.name" id="it-name" class="input" placeholder="{{ __('Exemple : indemnité de logement') }}">@error('item.name') <p class="error">{{ $message }}</p> @enderror</div>
            <div class="grid grid-cols-2 gap-2" role="radiogroup">
                @foreach (PayItem::KINDS as $k => $l)
                    <label @class(['cursor-pointer rounded-xl border p-3 text-center text-sm font-semibold', 'border-ochre-400 bg-ochre-50 text-ink-800' => ($item['kind'] ?? '') === $k, 'border-sand-200 text-ink-600' => ($item['kind'] ?? '') !== $k])>
                        <input type="radio" wire:model.live="item.kind" value="{{ $k }}" class="sr-only">{{ __($l) }}</label>
                @endforeach
            </div>
            <div><label for="it-calc" class="label">{{ __('Calcul') }}</label><select wire:model.live="item.calculation" id="it-calc" class="input">@foreach (PayItem::CALCULATIONS as $k => $l)@if (! ($k === 'percent_gross' && ($item['kind'] ?? '') === 'earning'))<option value="{{ $k }}">{{ __($l) }}</option>@endif @endforeach</select>@error('item.calculation') <p class="error">{{ $message }}</p> @enderror</div>
            <div><label for="it-value" class="label">{{ ($item['calculation'] ?? 'fixed') === 'fixed' ? __('Montant par défaut') : __('Pourcentage') }}</label><input wire:model="item.default_value" id="it-value" type="number" step="0.01" min="0" class="input tabular">@error('item.default_value') <p class="error">{{ $message }}</p> @enderror
                @if (($item['calculation'] ?? 'fixed') === 'fixed')<p class="hint">{{ __('Dans la devise de chaque personne ; vous pouvez le changer pour chacune.') }}</p>@endif</div>
            <label class="flex items-start gap-3 text-sm"><input type="checkbox" wire:model="item.applies_to_all" class="mt-0.5 size-5"><span><span class="font-semibold text-ink-800">{{ __('Pour tous les bénéficiaires') }}</span><span class="block text-sand-700">{{ __('Sinon, cochez-le pour chaque personne concernée.') }}</span></span></label>
            @if (($item['kind'] ?? '') === 'deduction')
                <label class="flex items-start gap-3 text-sm"><input type="checkbox" wire:model="item.is_statutory" class="mt-0.5 size-5"><span><span class="font-semibold text-ink-800">{{ __('Cotisation légale') }}</span><span class="block text-sand-700">{{ __('Facultatif : pour les églises qui déclarent leurs salariés (sécurité sociale, impôt). Vérifiez les taux en vigueur.') }}</span></span></label>
            @endif
            <label class="flex items-center gap-3 text-sm"><input type="checkbox" wire:model="item.is_active" class="size-5"> {{ __('Élément utilisé') }}</label>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'item' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Enregistrer') }}</button></div>
        </form>
    </x-modal>

    <x-modal name="schedule" :title="$scheduleId ? __('Modifier le rythme') : __('Nouveau rythme de paie')">
        <form wire:submit="saveSchedule" class="space-y-4">
            <div><label for="sc-name" class="label">{{ __('Nom') }}</label><input wire:model="schedule.name" id="sc-name" class="input" placeholder="{{ __('Exemple : prédicateurs invités') }}">@error('schedule.name') <p class="error">{{ $message }}</p> @enderror</div>
            <div><label for="sc-unit" class="label">{{ __('Payé') }}</label>
                <select wire:model.live="schedule.unit" id="sc-unit" class="input">
                    <option value="month">{{ __('Par mois') }}</option><option value="week">{{ __('Par semaine') }}</option><option value="service">{{ __('À la prestation') }}</option>
                </select></div>
            @if (($schedule['unit'] ?? 'month') === 'service')
                <div><label for="sc-label" class="label">{{ __('Prestation') }}</label><input wire:model="schedule.service_label" id="sc-label" class="input" placeholder="{{ __('Exemple : prédication, culte, journée') }}"></div>
            @else
                <div><label for="sc-every" class="label">{{ ($schedule['unit'] ?? 'month') === 'month' ? __('Tous les … mois') : __('Toutes les … semaines') }}</label><input wire:model="schedule.every" id="sc-every" type="number" min="1" max="12" class="input max-w-28">@error('schedule.every') <p class="error">{{ $message }}</p> @enderror</div>
            @endif
            <label class="flex items-center gap-3 text-sm"><input type="checkbox" wire:model="schedule.is_active" class="size-5"> {{ __('Rythme utilisé') }}</label>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'schedule' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Enregistrer') }}</button></div>
        </form>
    </x-modal>
</div>
