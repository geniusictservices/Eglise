@php use App\Support\Money; @endphp
<div>
    <a href="{{ route('finances.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Finances') }}</a>
    <x-page-header :title="__('Comptes et catégories')" :description="__('Les comptes de la communauté (caisses physiques, mobile money, banques), chacun dans les devises de votre choix. Les catégories classent les recettes et les dépenses dans les rapports.')" />

    <div class="mb-6 flex gap-1 overflow-x-auto rounded-2xl border border-sand-200 bg-white p-1" role="tablist" x-init="$el.querySelector('[aria-selected=true]')?.scrollIntoView({ inline: 'center', block: 'nearest' })">
        @foreach (['caisses' => __('Comptes'), 'recettes' => __('Catégories de recettes'), 'depenses' => __('Catégories de dépenses'), 'circuit' => __('Circuit des dépenses'), 'exercice' => __('Exercice')] as $key => $label)
            <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    @class(['flex-1 whitespace-nowrap rounded-xl px-4 py-2 text-sm font-semibold', 'bg-ink-700 text-white' => $tab === $key, 'text-ink-600 hover:bg-sand-50' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'caisses')
        <div class="mb-4 flex justify-end"><button type="button" wire:click="editAccount" class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Nouveau compte') }}</button></div>
        @foreach (\App\Models\CashAccount::KINDS as $kind => $kindLabel)
            @php $group = $accounts->where('kind', $kind); @endphp
            <h2 class="mb-2 mt-5 flex items-center gap-2 text-base first:mt-0"><x-icon :name="['cash' => 'banknote', 'mobile' => 'smartphone', 'bank' => 'landmark'][$kind]" class="size-5 text-ochre-600" /> {{ __($kindLabel) }}</h2>
            <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @forelse ($group as $a)
                    <li wire:key="a-{{ $a->id }}">
                        <button type="button" wire:click="editAccount({{ $a->id }})" @class(['card flex h-full w-full flex-col p-4 text-left transition hover:border-ochre-300', 'opacity-60' => ! $a->is_active])>
                            <span class="block font-semibold text-ink-700">{{ $a->name }}</span>
                            <span class="block text-xs text-sand-700">{{ collect([$a->provider, $a->account_number, $a->is_active ? null : __('fermé')])->filter()->implode(' · ') ?: __($kindLabel) }}</span>
                            <span class="mt-3 space-y-1">
                                @foreach ($balances[$a->id] ?? [] as $b)
                                    <span class="flex items-baseline justify-between gap-3"><span class="text-xs font-semibold text-sand-700">{{ $b['currency'] }}</span><span class="font-semibold tabular text-ink-800">{{ Money::format($b['balance'], $b['currency']) }}</span></span>
                                @endforeach
                            </span>
                        </button>
                    </li>
                @empty
                    <li class="rounded-[18px] border border-dashed border-sand-300 p-4 text-sm text-sand-700 sm:col-span-2 lg:col-span-3">{{ ['cash' => __('Aucune caisse physique.'), 'mobile' => __('Aucun compte mobile money.'), 'bank' => __('Aucun compte bancaire.')][$kind] }}</li>
                @endforelse
            </ul>
        @endforeach
    @elseif ($tab === 'exercice')
        <form wire:submit="saveFiscalYear" class="card max-w-2xl space-y-5 p-5 sm:p-6">
            <div>
                <label for="fiscalStart" class="label">{{ __('L’exercice commence en') }}</label>
                <select wire:model="fiscalStart" id="fiscalStart" class="input max-w-60">
                    @foreach (range(1, 12) as $m)<option value="{{ $m }}">{{ ucfirst(\Illuminate\Support\Carbon::create(2000, $m)->translatedFormat('F')) }}</option>@endforeach
                </select>
                @error('fiscalStart') <p class="error">{{ $message }}</p> @enderror
                <p class="hint">{{ __('Le budget, le plan d’action, la clôture de l’exercice et le rapport annuel suivent ce choix. Les mois se clôturent toujours un par un.') }}</p>
            </div>
            <p class="rounded-xl bg-sand-50 p-3 text-sm text-ink-800">{{ __('Exercice en cours : :l, du :a au :b.', ['l' => $fiscalLabel, 'a' => $fiscalBounds[0]->translatedFormat('j F Y'), 'b' => $fiscalBounds[1]->translatedFormat('j F Y')]) }}</p>
            <p class="text-sm text-sand-700">{{ __('Sans choix, la communauté suit son niveau supérieur. Une fois un exercice clôturé, le mois de début ne change plus.') }}</p>
            <div class="flex justify-end"><button class="btn-primary"><x-icon name="save" class="size-4" /> {{ __('Enregistrer') }}</button></div>
        </form>
    @elseif ($tab === 'circuit')
        <form wire:submit="saveCircuit" class="card max-w-2xl space-y-6 p-5 sm:p-6">
            <div>
                <p class="label">{{ __('Signatures pour approuver une dépense') }}</p>
                <div class="grid grid-cols-3 gap-2">
                    @foreach ([1 => __('Une'), 2 => __('Deux'), 3 => __('Trois')] as $n => $label)
                        <label @class(['flex cursor-pointer flex-col items-center rounded-xl border p-3 text-center', 'border-ochre-400 bg-ochre-50' => $approvalsRequired === $n, 'border-sand-200' => $approvalsRequired !== $n])>
                            <input type="radio" wire:model.live="approvalsRequired" value="{{ $n }}" class="sr-only">
                            <span class="text-2xl font-semibold text-ink-800">{{ $n }}</span><span class="text-xs text-sand-700">{{ $label }}@if ($n === 2) · {{ __('conseillé') }}@endif</span>
                        </label>
                    @endforeach
                </div>
                <p class="hint">{{ __('Après le contrôle de la finance, la dépense doit être signée par ce nombre de personnes ayant la permission « Approuver les dépenses ». Le demandeur ne signe jamais sa propre demande.') }}</p>
            </div>
            <div>
                <label for="advanceDays" class="label">{{ __('Délai pour justifier une avance (jours)') }}</label>
                <input wire:model="advanceDays" id="advanceDays" type="number" min="1" max="180" class="input max-w-32">
                @error('advanceDays') <p class="error">{{ $message }}</p> @enderror
            </div>
            <label class="flex items-start gap-3 text-sm"><input type="checkbox" wire:model="blockAdvances" class="mt-0.5 size-5">
                <span><span class="font-semibold text-ink-800">{{ __('Bloquer les nouvelles avances') }}</span><span class="block text-sand-700">{{ __('Une personne qui n’a pas justifié une avance dans le délai ne peut pas en recevoir une autre.') }}</span></span></label>
            <div class="flex justify-end"><button class="btn-primary"><x-icon name="save" class="size-4" /> {{ __('Enregistrer') }}</button></div>
        </form>
    @else
        @php $type = $tab === 'recettes' ? 'income' : 'expense'; $list = $type === 'income' ? $income : $expense; @endphp
        <div class="mb-4 flex justify-end"><button type="button" wire:click="editCategory(null, '{{ $type }}')" class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Nouvelle catégorie') }}</button></div>
        <ul class="card divide-y divide-sand-100">
            @foreach ($list as $c)
                <li wire:key="c-{{ $c->id }}">
                    <button type="button" wire:click="editCategory({{ $c->id }})" @class(['flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-sand-50', 'opacity-60' => ! $c->is_active])>
                        <span class="min-w-0 flex-1">
                            <span class="block font-semibold text-ink-700">{{ $c->name }}</span>
                            @if ($c->description)<span class="block text-sm text-sand-700">{{ $c->description }}</span>@endif
                        </span>
                        @if ($c->nature)
                            <span @class(['badge', 'bg-ink-50 text-ink-700' => $c->nature === 'collective', 'bg-ochre-100 text-ochre-700' => $c->nature === 'personal', 'bg-leaf-50 text-leaf-600' => $c->nature === 'group'])>{{ ['collective' => __('Collective'), 'personal' => __('Personnelle'), 'group' => __('De groupe')][$c->nature] }}</span>
                        @endif
                        @unless ($c->is_active)<span class="badge bg-sand-100 text-sand-700">{{ __('masquée') }}</span>@endunless
                    </button>
                </li>
            @endforeach
        </ul>
        @if ($type === 'income')
            <p class="mt-3 text-sm text-sand-700">{{ __('Collective : la boîte du culte, seul le total compte. Personnelle : au nom du membre (dîme…), visible seulement des personnes autorisées. De groupe : versée par un département.') }}</p>
        @endif
    @endif

    <x-modal name="account" :title="$accountId ? __('Modifier le compte') : __('Nouveau compte')" max-width="max-w-xl">
        <form wire:submit="saveAccount" class="space-y-4">
            <fieldset>
                <legend class="label">{{ __('Type de compte') }}</legend>
                <div class="grid grid-cols-3 gap-2">
                    @foreach (\App\Models\CashAccount::KINDS as $k => $l)
                        <label @class(['flex cursor-pointer flex-col items-center gap-1 rounded-xl border-[1.5px] px-2 py-3 text-center text-sm font-semibold', 'border-ink-700 bg-ink-50 text-ink-800' => ($account['kind'] ?? '') === $k, 'border-sand-300 text-ink-600' => ($account['kind'] ?? '') !== $k])>
                            <input type="radio" wire:model.live="account.kind" value="{{ $k }}" class="sr-only">
                            <x-icon :name="['cash' => 'banknote', 'mobile' => 'smartphone', 'bank' => 'landmark'][$k]" class="size-5" /> {{ __($l) }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <div><label for="ac-name" class="label">{{ __('Nom du compte') }}</label><input wire:model="account.name" id="ac-name" class="input" placeholder="{{ ['cash' => __('Exemple : Caisse principale'), 'mobile' => __('Exemple : M-Pesa de la paroisse'), 'bank' => __('Exemple : Compte Rawbank')][$account['kind'] ?? 'cash'] }}">@error('account.name') <p class="error">{{ $message }}</p> @enderror</div>
            @if (($account['kind'] ?? '') !== 'cash')
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label for="ac-provider" class="label">{{ ($account['kind'] ?? '') === 'mobile' ? __('Opérateur') : __('Banque') }}</label>
                        <input wire:model="account.provider" id="ac-provider" class="input" list="providers-{{ $account['kind'] ?? '' }}">
                        <datalist id="providers-{{ $account['kind'] ?? '' }}">@foreach (\App\Models\CashAccount::PROVIDERS[$account['kind'] ?? 'mobile'] ?? [] as $p)<option value="{{ $p }}">@endforeach</datalist></div>
                    <div><label for="ac-num" class="label">{{ ($account['kind'] ?? '') === 'mobile' ? __('Numéro de téléphone du compte') : __('Numéro de compte') }}</label><input wire:model="account.account_number" id="ac-num" class="input font-mono"></div>
                </div>
            @endif
            <div><label for="ac-holder" class="label">{{ ($account['kind'] ?? '') === 'cash' ? __('Responsable de la caisse') : __('Titulaire ou signataires') }}</label><input wire:model="account.holder" id="ac-holder" class="input"></div>

            <fieldset>
                <legend class="label">{{ __('Devises tenues dans ce compte') }}</legend>
                <ul class="space-y-2">
                    @foreach ($account['currencies'] ?? [] as $code => $c)
                        <li class="rounded-xl border border-sand-200 p-3" wire:key="cur-{{ $code }}">
                            <label class="flex items-center gap-3 text-sm font-semibold text-ink-800">
                                <input type="checkbox" wire:model.live="account.currencies.{{ $code }}.enabled" class="size-5" @disabled($c['locked'])>
                                {{ $code }} <span class="font-normal text-sand-700">· {{ config("waumini.currencies.$code.name") }}</span>
                                @if ($c['locked'])<span class="badge ml-auto bg-sand-100 text-sand-700">{{ __('déjà utilisée') }}</span>@endif
                            </label>
                            @if ($c['enabled'] && ! $c['locked'])
                                <div class="mt-2 grid grid-cols-2 gap-2 pl-8">
                                    <div><label for="op-{{ $code }}" class="text-xs text-sand-700">{{ __('Solde de départ') }}</label><input wire:model="account.currencies.{{ $code }}.opening" id="op-{{ $code }}" type="number" step="0.01" min="0" class="input !min-h-0 !py-2"></div>
                                    <div><label for="od-{{ $code }}" class="text-xs text-sand-700">{{ __('Au') }}</label><input wire:model="account.currencies.{{ $code }}.opened_on" id="od-{{ $code }}" type="date" class="input !min-h-0 !py-2"></div>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @error('account.currencies') <p class="error">{{ $message }}</p> @enderror
                <p class="hint">{{ __('Les devises proposées sont celles de votre communauté (écran Devises et taux).') }}</p>
            </fieldset>
            <div><label for="ac-desc" class="label">{{ __('Description') }}</label><input wire:model="account.description" id="ac-desc" class="input"></div>
            <label class="flex items-center gap-3 text-sm"><input type="checkbox" wire:model="account.is_active" class="size-5"> {{ __('Compte ouvert') }}</label>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'account' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Enregistrer') }}</button></div>
        </form>
    </x-modal>

    <x-modal name="category" :title="$categoryId ? __('Modifier la catégorie') : __('Nouvelle catégorie')">
        <form wire:submit="saveCategory" class="space-y-4">
            <div><label for="ca-name" class="label">{{ __('Nom') }}</label><input wire:model="category.name" id="ca-name" class="input">@error('category.name') <p class="error">{{ $message }}</p> @enderror</div>
            @if (($category['type'] ?? '') === 'income')
                <fieldset><legend class="label">{{ __('Nature') }}</legend>
                    <div class="space-y-2">
                        @foreach (\App\Models\FinanceCategory::NATURES as $k => $l)
                            <label class="flex items-center gap-3 text-sm"><input type="radio" wire:model="category.nature" value="{{ $k }}" class="size-5"> {{ __($l) }}</label>
                        @endforeach
                    </div>
                    @error('category.nature') <p class="error">{{ $message }}</p> @enderror
                </fieldset>
            @endif
            <div><label for="ca-desc" class="label">{{ __('Description') }}</label><input wire:model="category.description" id="ca-desc" class="input"></div>
            <label class="flex items-center gap-3 text-sm"><input type="checkbox" wire:model="category.is_active" class="size-5"> {{ __('Proposée à la saisie') }}</label>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'category' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Enregistrer') }}</button></div>
        </form>
    </x-modal>
</div>
