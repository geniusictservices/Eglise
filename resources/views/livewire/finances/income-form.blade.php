@php use App\Support\Money; @endphp
<div>
    <a href="{{ route('finances.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Finances') }}</a>
    <x-page-header :title="__('Nouvelle recette')" :description="__('Pour la collecte complète du culte (billets comptés, plusieurs offrandes), utilisez plutôt la feuille de collecte.')" />

    @if ($saved)
        <div class="mb-5 flex flex-wrap items-center gap-3 rounded-2xl border border-leaf-100 bg-leaf-50 p-4 text-sm">
            <x-icon name="circle-check" class="size-5 text-leaf-600" />
            <span class="flex-1 text-ink-800">{{ __(':amount enregistrés sur :account (:category).', ['amount' => Money::format($saved->amount, $saved->currency), 'account' => $saved->account->name, 'category' => $saved->category?->name]) }}</span>
            <a href="{{ route('finances.receipt', $saved) }}" target="_blank" class="btn-secondary !min-h-0 !py-2"><x-icon name="printer" class="size-4" /> {{ __('Reçu :n', ['n' => $saved->receipt_number]) }}</a>
        </div>
    @endif

    @if ($accounts->isEmpty())
        <div class="card p-6 text-center">
            <p class="text-sand-700">{{ __('Créez d’abord un compte (caisse, mobile money ou banque) pour recevoir l’argent.') }}</p>
            @can('finance.settings')<a href="{{ route('finances.settings') }}" class="btn-primary mt-4">{{ __('Créer un compte') }}</a>@endcan
        </div>
    @else
        <form wire:submit="save" class="grid gap-5 lg:grid-cols-[1.3fr_1fr]">
            <section class="card space-y-4 p-5 sm:p-6">
                <div>
                    <label for="categoryId" class="label">{{ __('Catégorie') }}</label>
                    <select wire:model.live="categoryId" id="categoryId" class="input">
                        @foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                    </select>
                    @if ($category)<p class="hint">{{ __(\App\Models\FinanceCategory::NATURES[$category->nature] ?? '') }}</p>@endif
                </div>

                @if ($category?->nature === 'personal')
                    <div>
                        <p class="label">{{ __('Membre') }}</p>
                        @if ($member)
                            <div class="flex items-center gap-3 rounded-xl bg-ochre-50 px-3 py-2">
                                @include('livewire.members.partials.avatar', ['member' => $member, 'size' => 'size-9 text-xs'])
                                <span class="flex-1"><span class="block font-semibold text-ink-800">{{ $member->officialName() }}</span><span class="font-mono text-xs text-sand-700">{{ $member->number }}</span></span>
                                <button type="button" wire:click="$set('memberId', null)" class="rounded-lg p-1.5 text-sand-500 hover:bg-white" aria-label="{{ __('Changer de membre') }}"><x-icon name="x" class="size-4" /></button>
                            </div>
                        @else
                            <input wire:model.live.debounce.300ms="memberSearch" type="search" class="input" placeholder="{{ __('Nom, numéro ou téléphone') }}" aria-label="{{ __('Rechercher le membre') }}">
                            <ul class="mt-1 space-y-1">
                                @foreach ($candidates as $c)
                                    <li><button type="button" wire:click="chooseMember({{ $c->id }})" class="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left hover:bg-sand-100">
                                        @include('livewire.members.partials.avatar', ['member' => $c, 'size' => 'size-8 text-[11px]'])
                                        <span class="text-sm"><span class="font-semibold text-ink-700">{{ $c->officialName() }}</span> <span class="font-mono text-xs text-sand-700">{{ $c->number }}</span></span>
                                    </button></li>
                                @endforeach
                            </ul>
                            <label for="payerName" class="mt-3 block text-sm text-sand-700">{{ __('Ou, pour un donateur qui n’est pas inscrit :') }}</label>
                            <input wire:model="payerName" id="payerName" class="input mt-1" placeholder="{{ __('Nom du donateur') }}">
                        @endif
                        @error('memberId') <p class="error">{{ $message }}</p> @enderror
                    </div>
                @elseif ($category?->nature === 'group')
                    <div>
                        <label for="departmentId" class="label">{{ __('Département') }}</label>
                        <select wire:model="departmentId" id="departmentId" class="input">
                            <option value="">—</option>
                            @foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
                        </select>
                        @error('departmentId') <p class="error">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div>
                    <label for="accountId" class="label">{{ __('Compte') }}</label>
                    <select wire:model.live="accountId" id="accountId" class="input">
                        @foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}@if ($a->provider) · {{ $a->provider }}@endif</option>@endforeach
                    </select>
                </div>
                <div class="grid gap-4 sm:grid-cols-[9rem_1fr]">
                    <div>
                        <label for="currency" class="label">{{ __('Devise') }}</label>
                        <select wire:model.live="currency" id="currency" class="input">
                            @foreach ($currencies as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach
                        </select>
                        @error('currency') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="amount" class="label">{{ __('Montant') }}</label>
                        <div class="relative">
                            <input wire:model="amount" id="amount" type="number" step="0.01" min="0" inputmode="decimal" class="input pr-14 text-lg font-semibold tabular">
                            <span class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-sm font-semibold text-sand-500">{{ config("waumini.currencies.$currency.symbol", $currency) }}</span>
                        </div>
                        @error('amount') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div><label for="description" class="label">{{ __('Libellé (facultatif)') }}</label><input wire:model="description" id="description" class="input" placeholder="{{ __('Exemple : culte du dimanche 4 octobre') }}"></div>
            </section>

            <aside class="space-y-5">
                <section class="card space-y-4 p-5">
                    <div><label for="occurredOn" class="label">{{ __('Date') }}</label><input wire:model="occurredOn" id="occurredOn" type="date" max="{{ today()->toDateString() }}" class="input">@error('occurredOn') <p class="error">{{ $message }}</p> @enderror</div>
                    <div>
                        <label for="paymentMethod" class="label">{{ __('Moyen de paiement') }}</label>
                        <select wire:model.live="paymentMethod" id="paymentMethod" class="input">
                            @foreach (\App\Models\FinanceTransaction::PAYMENT_METHODS as $k => $l)<option value="{{ $k }}">{{ __($l) }}</option>@endforeach
                        </select>
                    </div>
                    @if ($paymentMethod !== 'cash')
                        <div><label for="externalReference" class="label">{{ $paymentMethod === 'mobile' ? __('ID de la transaction') : __('Référence') }}</label><input wire:model="externalReference" id="externalReference" class="input font-mono">@error('externalReference') <p class="error">{{ $message }}</p> @enderror</div>
                    @endif
                </section>
                <button class="btn-primary w-full"><x-icon name="save" class="size-4" /> {{ __('Enregistrer la recette') }}</button>
            </aside>
        </form>
    @endif
</div>
