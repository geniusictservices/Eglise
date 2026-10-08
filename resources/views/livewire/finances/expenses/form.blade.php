<div>
    <a href="{{ route('finances.expenses') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Dépenses') }}</a>
    <x-page-header :title="__('Demander une dépense')" :description="trans_choice('La demande sera contrôlée par la finance, puis approuvée par :count signature.|La demande sera contrôlée par la finance, puis approuvée par :count signatures.', $settings['approvals_required'])" />

    <form wire:submit="save" class="grid gap-5 lg:grid-cols-[1.3fr_1fr] lg:items-start">
        <section class="card space-y-4 p-5 sm:p-6">
            <div><label for="title" class="label">{{ __('Objet') }}</label><input wire:model="title" id="title" class="input" placeholder="{{ __('Exemple : achat de 50 chaises pour la salle') }}">@error('title') <p class="error">{{ $message }}</p> @enderror</div>
            @if ($adopted)
                {{-- Une dépense se rattache au budget adopté ; un imprévu dit pourquoi il n'était pas prévu. --}}
                <div class="grid grid-cols-2 gap-2" role="radiogroup" aria-label="{{ __('Rattachement au budget') }}">
                    @foreach (['budget' => [__('Prévue au budget'), 'milestone'], 'imprevu' => [__('Imprévu'), 'triangle-alert']] as $mode => [$label, $icon])
                        <label @class(['flex cursor-pointer items-center justify-center gap-2 rounded-xl border p-3 text-sm font-semibold', 'border-ochre-400 bg-ochre-50 text-ink-800' => $budgetMode === $mode, 'border-sand-200 text-ink-600' => $budgetMode !== $mode])>
                            <input type="radio" wire:model.live="budgetMode" value="{{ $mode }}" class="sr-only"><x-icon :name="$icon" class="size-4" /> {{ $label }}</label>
                    @endforeach
                </div>
            @endif
            @if ($adopted && $budgetMode === 'budget')
                <div>
                    <label for="budgetLineId" class="label">{{ __('Ligne du budget :y', ['y' => \App\Support\FiscalYear::label($this->organization(), $adopted->fiscal_year)]) }}</label>
                    <select wire:model.live="budgetLineId" id="budgetLineId" class="input">
                        <option value="">{{ __('Choisir la ligne…') }}</option>
                        @foreach ($choices as $department => $lines)
                            <optgroup label="{{ $department }}">
                                @foreach ($lines as $c)<option value="{{ $c['line']->id }}">{{ $c['line']->label }} · {{ $c['line']->category?->name }} · {{ __('reste :m', ['m' => \App\Support\Money::format($c['available'], 'USD')]) }}</option>@endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @error('budgetLineId') <p class="error">{{ $message }}</p> @enderror
                </div>
            @else
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label for="departmentId" class="label">{{ __('Département') }}</label><select wire:model.live="departmentId" id="departmentId" class="input">@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div>
                    <div><label for="categoryId" class="label">{{ __('Catégorie') }}</label><select wire:model.live="categoryId" id="categoryId" class="input">@foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
                </div>
                @if ($adopted)
                    <div>
                        <label for="unforeseenReason" class="label">{{ __('Pourquoi n’était-ce pas prévu ?') }}</label>
                        <textarea wire:model="unforeseenReason" id="unforeseenReason" rows="2" maxlength="255" class="input" placeholder="{{ __('Exemple : la toiture a cédé pendant l’orage du 3 octobre') }}"></textarea>
                        @error('unforeseenReason') <p class="error">{{ $message }}</p> @enderror
                    </div>
                @else
                    <p class="rounded-xl bg-sand-50 p-3 text-sm text-ink-800"><x-icon name="info" class="mr-1 inline size-4 text-sand-500" /> {{ __('Pas encore de budget adopté pour cet exercice : la dépense est classée par département et par catégorie.') }}</p>
                @endif
            @endif
            @if ($budgetLine && ! ($adopted && $budgetMode === 'budget' && ! $budgetLineId))
                @if ($budgetLine['available'] === null)
                    <p class="rounded-xl bg-ochre-50 p-3 text-sm text-ink-800"><x-icon name="info" class="mr-1 inline size-4 text-ochre-600" /> {{ __('Rien n’est prévu au budget pour ce département et cette catégorie : la dépense demandera une autorisation de dépassement au pasteur.') }}</p>
                @else
                    <p class="rounded-xl bg-sand-50 p-3 text-sm text-ink-800">{{ __('Budget de cette ligne : :a disponibles.', ['a' => \App\Support\Money::format($budgetLine['available'], 'USD')]) }}</p>
                @endif
            @endif
            @if ($projects->isNotEmpty())
                <div>
                    <label for="projectId" class="label">{{ __('Projet') }} <span class="font-normal text-sand-700">{{ __('(facultatif)') }}</span></label>
                    <select wire:model.live="projectId" id="projectId" class="input"><option value="">{{ __('Aucun : dépense ordinaire') }}</option>@foreach ($projects as $pj)<option value="{{ $pj->id }}">{{ $pj->name }}</option>@endforeach</select>
                    @error('projectId') <p class="error">{{ $message }}</p> @enderror
                    @if ($projectAvailable !== null)<p class="hint">{{ __('Ce projet a :m disponibles : la dépense est payée avec son argent.', ['m' => \App\Support\Money::format($projectAvailable, 'USD')]) }}</p>@endif
                </div>
            @endif
            <div class="grid gap-4 sm:grid-cols-[1fr_7rem]">
                <div><label for="amount" class="label">{{ __('Montant') }}</label><input wire:model="amount" id="amount" type="number" step="0.01" min="0" class="input text-lg font-semibold tabular">@error('amount') <p class="error">{{ $message }}</p> @enderror</div>
                <div><label for="currency" class="label">{{ __('Devise') }}</label><select wire:model="currency" id="currency" class="input">@foreach ($currencies as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select></div>
            </div>
            <div><label for="description" class="label">{{ __('Détails') }}</label><textarea wire:model="description" id="description" rows="3" class="input" placeholder="{{ __('À quoi sert la dépense, fournisseur, détail des articles…') }}"></textarea></div>
        </section>

        <aside class="space-y-5">
            <section class="card space-y-4 p-5">
                <label class="flex items-start gap-3 text-sm"><input type="checkbox" wire:model.live="isAdvance" class="mt-0.5 size-5">
                    <span><span class="font-semibold text-ink-800">{{ __('Avance à justifier') }}</span><span class="block text-sand-700">{{ __('L’argent est remis avant l’achat ; la personne rapporte les factures et le reste dans les :n jours.', ['n' => $settings['advance_days']]) }}</span></span></label>
                @if ($isAdvance)
                    <div>
                        <p class="label">{{ __('Remise à') }}</p>
                        @if ($beneficiary)
                            <div class="flex items-center gap-3 rounded-xl bg-ochre-50 px-3 py-2"><span class="flex-1 text-sm font-semibold text-ink-800">{{ $beneficiary->officialName() }}</span>
                                <button type="button" wire:click="$set('beneficiaryId', null)" class="rounded-lg p-1.5 text-sand-500 hover:bg-white" aria-label="{{ __('Changer') }}"><x-icon name="x" class="size-4" /></button></div>
                        @else
                            <input wire:model.live.debounce.300ms="beneficiarySearch" type="search" class="input" placeholder="{{ __('Membre : nom ou numéro') }}" aria-label="{{ __('Rechercher') }}">
                            <ul class="mt-1 space-y-1">@foreach ($candidates as $c)<li><button type="button" wire:click="chooseBeneficiary({{ $c->id }})" class="w-full rounded-xl px-3 py-2 text-left text-sm hover:bg-sand-100"><span class="font-semibold text-ink-700">{{ $c->officialName() }}</span></button></li>@endforeach</ul>
                            <input wire:model="beneficiaryName" class="input mt-2" placeholder="{{ __('… ou nom de la personne') }}" aria-label="{{ __('Nom') }}">
                        @endif
                        @error('beneficiaryId') <p class="error">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div><label for="neededOn" class="label">{{ __('Pour le') }}</label><input wire:model="neededOn" id="neededOn" type="date" class="input"></div>
                <div>
                    <p class="label">{{ __('Devis ou pièces (facultatif)') }}</p>
                    <label class="btn-secondary cursor-pointer !min-h-0 !py-2"><x-icon name="upload" class="size-4" /> {{ count($files) ? trans_choice(':count fichier choisi|:count fichiers choisis', count($files)) : __('Ajouter des fichiers') }}
                        <input type="file" wire:model="files" multiple accept="image/*,application/pdf" class="sr-only"></label>
                    @error('files.*') <p class="error">{{ $message }}</p> @enderror
                </div>
            </section>
            @error('submit') <p class="error">{{ $message }}</p> @enderror
            <button class="btn-primary w-full" wire:loading.attr="disabled" wire:target="files,save"><x-icon name="upload" class="size-4" /> {{ __('Envoyer la demande') }}</button>
        </aside>
    </form>
</div>
