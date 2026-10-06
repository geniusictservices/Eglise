@php use App\Support\Money; @endphp
<div>
    <a href="{{ route('payroll.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Paie') }}</a>
    <x-page-header :title="__('Bénéficiaires')" :description="__('Les personnes payées par la communauté : membres ou personnes extérieures, chacune avec son rythme, son montant et ses éléments de paie.')">
        <x-slot:actions>
            @if ($canManage)<button type="button" wire:click="edit" class="btn-primary"><x-icon name="user-plus" class="size-4" /> {{ __('Nouveau bénéficiaire') }}</button>@endif
        </x-slot:actions>
    </x-page-header>

    <label class="mb-3 inline-flex items-center gap-2 text-sm text-sand-700"><input type="checkbox" wire:model.live="showInactive" class="size-4"> {{ __('Montrer aussi les personnes qui ne sont plus payées') }}</label>

    <ul class="grid gap-3 md:grid-cols-2">
        @forelse ($payees as $p)
            @php $c = $nets[$p->id]; @endphp
            <li wire:key="p-{{ $p->id }}">
                <button type="button" @if ($canManage) wire:click="edit({{ $p->id }})" @endif @class(['card flex h-full w-full flex-col p-4 text-left', 'transition hover:border-ochre-300' => $canManage, 'opacity-60' => ! $p->is_active])>
                    <span class="flex items-start gap-3">
                        <span class="min-w-0 flex-1">
                            <span class="block font-semibold text-ink-800">{{ $p->displayName() }}</span>
                            <span class="block text-sm text-sand-700">{{ collect([$p->position, $p->department?->name])->filter()->implode(' · ') }}</span>
                        </span>
                        @if (! $p->member_id)<span class="badge bg-sand-100 text-sand-700">{{ __('extérieur') }}</span>@endif
                    </span>
                    <span class="mt-3 flex flex-wrap items-baseline gap-x-3 text-sm">
                        <span class="text-sand-700">{{ $p->schedule?->describe() }}</span>
                        <span class="ml-auto font-semibold tabular text-ink-800">{{ Money::format($p->base_amount, $p->currency) }}@if ($p->schedule?->isPerService()) <span class="font-normal text-sand-700">/ {{ $p->schedule->service_label ?: __('prestation') }}</span>@endif</span>
                    </span>
                    @if (! $p->schedule?->isPerService() && ($c['lines'] || $c['net'] != $c['base']))
                        <span class="mt-1 block text-right text-xs text-sand-700">{{ __('Net habituel : :m', ['m' => Money::format($c['net'], $p->currency)]) }}</span>
                    @endif
                </button>
            </li>
        @empty
            <li class="card p-8 text-center text-sand-700 md:col-span-2">{{ __('Aucun bénéficiaire pour le moment.') }}</li>
        @endforelse
    </ul>

    <x-modal name="payee" :title="$payeeId ? __('Modifier le bénéficiaire') : __('Nouveau bénéficiaire')">
        <form wire:submit="save" class="space-y-4">
            <div>
                <p class="label">{{ __('Personne') }}</p>
                @if ($member)
                    <div class="flex items-center gap-3 rounded-xl bg-ochre-50 px-3 py-2"><span class="flex-1 text-sm font-semibold text-ink-800">{{ $member->officialName() }}</span>
                        <button type="button" wire:click="$set('form.member_id', null)" class="rounded-lg p-1.5 text-sand-500 hover:bg-white" aria-label="{{ __('Changer') }}"><x-icon name="x" class="size-4" /></button></div>
                @else
                    <input wire:model.live.debounce.300ms="memberSearch" type="search" class="input" placeholder="{{ __('Membre : nom ou numéro') }}" aria-label="{{ __('Rechercher le membre') }}">
                    <ul class="mt-1 space-y-1">@foreach ($candidates as $c)<li><button type="button" wire:click="chooseMember({{ $c->id }})" class="w-full rounded-xl px-3 py-2 text-left text-sm hover:bg-sand-100"><span class="font-semibold text-ink-700">{{ $c->officialName() }}</span></button></li>@endforeach</ul>
                    <input wire:model="form.name" class="input mt-2" placeholder="{{ __('… ou le nom d’une personne extérieure') }}" aria-label="{{ __('Nom') }}">
                    @error('form.name') <p class="error">{{ $message }}</p> @enderror
                @endif
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label for="py-position" class="label">{{ __('Fonction') }}</label><input wire:model="form.position" id="py-position" class="input" placeholder="{{ __('Exemple : sentinelle') }}"></div>
                <div><label for="py-dept" class="label">{{ __('Département (pour le budget)') }}</label><select wire:model="form.department_id" id="py-dept" class="input">@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div>
            </div>
            <div><label for="py-schedule" class="label">{{ __('Rythme de paie') }}</label><select wire:model.live="form.pay_schedule_id" id="py-schedule" class="input">@foreach ($schedules->where('is_active', true) as $s)<option value="{{ $s->id }}">{{ $s->name }} · {{ $s->describe() }}</option>@endforeach</select></div>
            <div class="grid gap-4 sm:grid-cols-[1fr_7rem]">
                <div><label for="py-amount" class="label">{{ $perService ? __('Montant par prestation') : __('Montant de base par période') }}</label><input wire:model="form.base_amount" id="py-amount" type="number" step="0.01" min="0" class="input tabular">@error('form.base_amount') <p class="error">{{ $message }}</p> @enderror</div>
                <div><label for="py-cur" class="label">{{ __('Devise') }}</label><select wire:model="form.currency" id="py-cur" class="input">@foreach ($currencies as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select></div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label for="py-method" class="label">{{ __('Payé par') }}</label><select wire:model.live="form.payment_method" id="py-method" class="input">@foreach (\App\Models\FinanceTransaction::PAYMENT_METHODS as $k => $l)<option value="{{ $k }}">{{ __($l) }}</option>@endforeach</select></div>
                @if (($form['payment_method'] ?? 'cash') !== 'cash')<div><label for="py-number" class="label">{{ __('Numéro (mobile money ou compte)') }}</label><input wire:model="form.payment_number" id="py-number" class="input font-mono"></div>@endif
            </div>
            @if ($catalog->isNotEmpty())
                <fieldset>
                    <legend class="label">{{ __('Éléments de paie') }}</legend>
                    <ul class="space-y-2">
                        @foreach ($catalog as $i)
                            <li class="flex items-center gap-3 rounded-xl border border-sand-200 px-3 py-2 text-sm">
                                <input type="checkbox" wire:model.live="items.{{ $i->id }}.on" class="size-4" aria-label="{{ $i->name }}">
                                <span class="min-w-0 flex-1"><span class="font-semibold text-ink-800">{{ $i->name }}</span> <span @class(['text-xs', 'text-leaf-600' => $i->kind === 'earning', 'text-terra-600' => $i->kind === 'deduction'])>{{ __(\App\Models\PayItem::KINDS[$i->kind]) }}</span><span class="block text-xs text-sand-700">{{ $i->describe() }}@if ($i->applies_to_all) · {{ __('pour tous') }}@endif</span></span>
                                @if ($items[$i->id]['on'] ?? false)
                                    <input wire:model="items.{{ $i->id }}.value" type="number" step="0.01" min="0" class="input !w-28 !py-1.5 text-right tabular" placeholder="{{ (float) $i->default_value }}" aria-label="{{ __('Valeur pour cette personne') }}">
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <p class="hint">{{ __('Laissez la valeur vide pour garder celle de l’élément.') }}</p>
                </fieldset>
            @endif
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label for="py-start" class="label">{{ __('Payé depuis le') }}</label><input wire:model="form.starts_on" id="py-start" type="date" class="input"></div>
                <div class="flex items-end"><label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.is_active" class="size-4"> {{ __('Toujours payé') }}</label></div>
            </div>
            <div><label for="py-notes" class="label">{{ __('Remarques') }}</label><textarea wire:model="form.notes" id="py-notes" rows="2" class="input"></textarea></div>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'payee' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Enregistrer') }}</button></div>
        </form>
    </x-modal>
</div>
