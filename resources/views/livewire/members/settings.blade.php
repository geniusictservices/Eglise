<div>
    <a href="{{ route('members.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Membres') }}</a>
    <x-page-header :title="__('Réglages du registre')"
                   :description="$isRoot ? __('Ces réglages s’appliquent à :name et à tous ses niveaux inférieurs.', ['name' => $organization->name]) : __('Le numéro, les statuts et les champs communs sont fixés par :root. Vous pouvez ajouter vos propres champs et fonctions.', ['root' => $root->name])" />

    <div class="mb-6 flex gap-1 overflow-x-auto rounded-2xl border border-sand-200 bg-white p-1" role="tablist">
        @foreach (['numerotation' => __('Numérotation'), 'statuts' => __('Statuts'), 'fonctions' => __('Fonctions'), 'champs' => __('Champs de la fiche')] as $key => $label)
            <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    @class(['flex-1 whitespace-nowrap rounded-xl px-4 py-2 text-sm font-semibold', 'bg-ink-700 text-white' => $tab === $key, 'text-ink-600 hover:bg-sand-50' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'numerotation')
        <form wire:submit="saveNumbering" class="grid gap-6 lg:grid-cols-[1.2fr_1fr]">
            <section class="card space-y-5 p-5 sm:p-6">
                <div>
                    <label for="numberFormat" class="label">{{ __('Format du numéro de membre') }}</label>
                    <input wire:model.live.debounce.300ms="numberFormat" id="numberFormat" class="input font-mono" @disabled(! $isRoot)>
                    <p class="hint">{{ __('Éléments disponibles :') }} <code class="text-ink-700">{SIGLE}</code> {{ __('sigle du niveau') }}, <code class="text-ink-700">{SIEGE}</code> {{ __('sigle du siège') }}, <code class="text-ink-700">{ANNEE}</code>, <code class="text-ink-700">{AN}</code> {{ __('(2 chiffres)') }}, <code class="text-ink-700">{NUMERO}</code>.</p>
                    @error('numberFormat') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="numberPadding" class="label">{{ __('Chiffres du numéro') }}</label>
                        <input wire:model.live="numberPadding" id="numberPadding" type="number" min="1" max="8" class="input" @disabled(! $isRoot)>
                        <p class="hint">{{ __('4 donne 0045, 5 donne 00045.') }}</p>
                    </div>
                    <div>
                        <label for="startNumber" class="label">{{ __('Numéro de départ') }}</label>
                        <input wire:model.live="startNumber" id="startNumber" type="number" min="1" class="input" @disabled(! $isRoot)>
                        <p class="hint">{{ __('Pour continuer la numérotation de votre ancien registre.') }}</p>
                        @error('startNumber') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <label class="flex items-center gap-3 text-sm">
                    <input type="checkbox" wire:model.live="yearlyReset" class="size-5" @disabled(! $isRoot)>
                    {{ __('Recommencer la numérotation à chaque nouvelle année') }}
                </label>
                <div>
                    <label for="code" class="label">{{ __('Sigle de :name', ['name' => $organization->displayName()]) }}</label>
                    <input wire:model.live.debounce.300ms="code" id="code" class="input w-40 font-mono uppercase" maxlength="12">
                    <p class="hint">{{ __('Chaque niveau choisit son sigle, utilisé par {SIGLE}.') }}</p>
                    @error('code') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end"><button type="submit" class="btn-primary"><x-icon name="save" class="size-4" /> {{ __('Enregistrer') }}</button></div>
            </section>
            <section class="wax wax-veil wax-veil-strong self-start rounded-[22px] p-6 text-white">
                <p class="text-sm text-ink-100">{{ __('Exemple du prochain numéro') }}</p>
                <p class="mt-2 break-all font-mono text-3xl font-semibold text-white">{{ $preview }}</p>
                <p class="mt-3 text-sm text-ink-100">{{ __('Les numéros déjà attribués ne changent jamais.') }}</p>
            </section>
        </form>

    @elseif ($tab === 'statuts')
        <section class="card p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <p class="max-w-2xl text-sm text-sand-700">{{ __('Le statut indique où en est chaque personne. Ceux qui « comptent dans l’effectif » sont additionnés dans les rapports.') }}</p>
                @if ($isRoot)<button type="button" class="btn-secondary" wire:click="editStatus"><x-icon name="plus" class="size-4" /> {{ __('Ajouter un statut') }}</button>@endif
            </div>
            <ul class="mt-4 divide-y divide-sand-100 rounded-2xl border border-sand-200">
                @foreach ($statuses as $status)
                    <li class="flex flex-wrap items-center gap-3 px-4 py-3" wire:key="st-{{ $status->id }}">
                        <x-status-badge :status="$status" />
                        @if ($status->is_default)<span class="text-xs font-semibold text-leaf-600">{{ __('par défaut') }}</span>@endif
                        @if ($status->needs_harmonization)<span class="badge bg-terra-50 text-terra-600">{{ __('à harmoniser') }}</span>@endif
                        <span class="text-xs text-sand-700">{{ $status->counts_as_member ? __('compte dans l’effectif') : __('ne compte pas') }} · {{ trans_choice(':count personne|:count personnes', $status->members_count) }}</span>
                        <span class="ml-auto flex gap-1">
                            @if ($isRoot && ! $status->needs_harmonization)
                                @unless ($status->is_default)<button type="button" class="rounded-lg px-2 py-1 text-xs font-semibold text-ink-600 hover:bg-ink-50" wire:click="makeDefaultStatus({{ $status->id }})">{{ __('Par défaut') }}</button>@endunless
                                <button type="button" class="rounded-lg p-2 text-sand-500 hover:bg-ink-50 hover:text-ink-700" wire:click="editStatus({{ $status->id }})" aria-label="{{ __('Modifier') }}"><x-icon name="pencil" class="size-4" /></button>
                            @endif
                            @if ($status->organization_id === $organization->id)
                                <button type="button" class="rounded-lg p-2 text-sand-500 hover:bg-terra-50 hover:text-terra-600" wire:click="deleteStatus({{ $status->id }})" wire:confirm="{{ __('Supprimer ce statut ?') }}" aria-label="{{ __('Supprimer') }}"><x-icon name="trash-2" class="size-4" /></button>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
            @if ($statuses->contains('needs_harmonization', true))
                <p class="mt-3 rounded-xl bg-terra-50 p-3 text-sm text-terra-700">{{ __('Les statuts « à harmoniser » viennent de votre ancien registre. Changez le statut des personnes concernées vers un statut de :root, puis supprimez-les.', ['root' => $root->name]) }}</p>
            @endif
        </section>

    @elseif ($tab === 'fonctions')
        <section class="card p-5 sm:p-6">
            <p class="max-w-2xl text-sm text-sand-700">{{ __('Les fonctions et titres que peuvent exercer les membres, avec leurs dates de mandat.') }}</p>
            <form wire:submit="addFunction" class="mt-4 flex flex-wrap gap-2">
                <input wire:model="functionName" class="input max-w-sm" placeholder="{{ __('Ex. : Trésorière des mamans') }}" aria-label="{{ __('Nouvelle fonction') }}">
                <button type="submit" class="btn-secondary"><x-icon name="plus" class="size-4" /> {{ __('Ajouter') }}</button>
            </form>
            @error('functionName') <p class="error">{{ $message }}</p> @enderror
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach ($functions as $function)
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-sand-300 bg-white py-1.5 pl-3.5 pr-1.5 text-sm" wire:key="fn-{{ $function->id }}">
                        {{ $function->name }}
                        @if ($function->organization_id === $organization->id)
                            <button type="button" class="grid size-6 place-items-center rounded-full text-sand-500 hover:bg-terra-50 hover:text-terra-600" wire:click="deleteFunction({{ $function->id }})" aria-label="{{ __('Supprimer :name', ['name' => $function->name]) }}"><x-icon name="x" class="size-3.5" /></button>
                        @else
                            <span class="grid size-6 place-items-center text-sand-300" title="{{ __('Fixée par :name', ['name' => $function->organization->name]) }}"><x-icon name="lock" class="size-3.5" /></span>
                        @endif
                    </span>
                @endforeach
            </div>
        </section>

    @else
        <div class="grid gap-6 lg:grid-cols-2">
            <section class="card self-start p-5 sm:p-6">
                <h2 class="text-lg">{{ __('Champs courants') }}</h2>
                <p class="mt-1 text-sm text-sand-700">{{ $isRoot ? __('Décochez les champs dont vos églises n’ont pas besoin : ils disparaissent de la fiche.') : __('Choisis par :root.', ['root' => $root->name]) }}</p>
                <p class="mt-3 text-sm text-sand-700">{{ __('Toujours présents : nom, post-nom, prénom, sexe, date de naissance, téléphone, adresse, ménage, statut.') }}</p>
                <div class="mt-3 grid gap-1 sm:grid-cols-2">
                    @foreach ($optionalFields as $key => $label)
                        <label class="flex items-center gap-3 rounded-lg px-2 py-2 text-sm hover:bg-sand-50">
                            <input type="checkbox" class="size-5" @disabled(! $isRoot)
                                   @checked(! in_array($key, $hiddenFields)) wire:click="$set('hiddenFields', {{ json_encode(in_array($key, $hiddenFields) ? array_values(array_diff($hiddenFields, [$key])) : array_values(array_merge($hiddenFields, [$key]))) }})">
                            {{ __($label) }}
                        </label>
                    @endforeach
                </div>
                @if ($isRoot)
                    <div class="mt-4 flex justify-end"><button type="button" wire:click="saveHiddenFields" class="btn-primary">{{ __('Enregistrer') }}</button></div>
                @endif
            </section>

            <section class="card self-start p-5 sm:p-6">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg">{{ __('Champs ajoutés') }}</h2>
                        <p class="mt-1 text-sm text-sand-700">{{ __('Vos propres informations : tribu, carte d’électeur, langue maternelle…') }}</p>
                    </div>
                    <button type="button" class="btn-secondary shrink-0" wire:click="editField"><x-icon name="plus" class="size-4" /> {{ __('Ajouter') }}</button>
                </div>
                <ul class="mt-4 divide-y divide-sand-100 rounded-2xl border border-sand-200">
                    @forelse ($fields as $field)
                        <li class="flex items-center gap-3 px-4 py-3" wire:key="fd-{{ $field->id }}">
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-ink-800">{{ $field->label }} @if ($field->required)<span class="text-terra-600">*</span>@endif</p>
                                <p class="text-xs text-sand-700">{{ __($types[$field->type]) }}@if ($field->sensitive) · {{ __('sensible') }}@endif @if ($field->organization_id !== $organization->id) · {{ __('fixé par :name', ['name' => $field->organization->name]) }}@endif</p>
                            </div>
                            @if ($field->organization_id === $organization->id)
                                <button type="button" class="rounded-lg p-2 text-sand-500 hover:bg-ink-50 hover:text-ink-700" wire:click="editField({{ $field->id }})" aria-label="{{ __('Modifier') }}"><x-icon name="pencil" class="size-4" /></button>
                                <button type="button" class="rounded-lg p-2 text-sand-500 hover:bg-terra-50 hover:text-terra-600" wire:click="deleteField({{ $field->id }})" wire:confirm="{{ __('Supprimer ce champ ?') }}" aria-label="{{ __('Supprimer') }}"><x-icon name="trash-2" class="size-4" /></button>
                            @else
                                <x-icon name="lock" class="size-4 text-sand-300" />
                            @endif
                        </li>
                    @empty
                        <li class="px-4 py-3 text-sm text-sand-700">{{ __('Aucun champ ajouté pour le moment.') }}</li>
                    @endforelse
                </ul>
            </section>
        </div>
    @endif

    <x-modal name="status" :title="$statusId ? __('Modifier le statut') : __('Ajouter un statut')">
        <form wire:submit="saveStatus" class="space-y-4">
            <div>
                <label for="statusName" class="label">{{ __('Nom') }}</label>
                <input wire:model="statusName" id="statusName" class="input" placeholder="{{ __('Ex. : Serviteur') }}">
                @error('statusName') <p class="error">{{ $message }}</p> @enderror
            </div>
            <fieldset>
                <legend class="label">{{ __('Couleur') }}</legend>
                <div class="flex flex-wrap gap-2">
                    @foreach ($colors as $key => $label)
                        <label class="cursor-pointer"><input type="radio" wire:model="statusColor" value="{{ $key }}" class="peer sr-only"><x-status-badge :status="(object) ['name' => __($label), 'color' => $key]" class="peer-checked:ring-2 peer-checked:ring-ochre-500" /></label>
                    @endforeach
                </div>
            </fieldset>
            <label class="flex items-center gap-3 text-sm"><input type="checkbox" wire:model="statusCounts" class="size-5"> {{ __('Compte dans l’effectif') }}</label>
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'status' })">{{ __('Annuler') }}</button>
                <button type="submit" class="btn-primary">{{ __('Enregistrer') }}</button>
            </div>
        </form>
    </x-modal>

    <x-modal name="field" :title="$fieldId ? __('Modifier le champ') : __('Ajouter un champ')">
        <form wire:submit="saveField" class="space-y-4">
            <div>
                <label for="fieldLabel" class="label">{{ __('Nom du champ') }}</label>
                <input wire:model="fieldLabel" id="fieldLabel" class="input" placeholder="{{ __('Ex. : Langue maternelle') }}">
                @error('fieldLabel') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="fieldType" class="label">{{ __('Type de réponse') }}</label>
                <select wire:model.live="fieldType" id="fieldType" class="input">
                    @foreach ($types as $key => $label)<option value="{{ $key }}">{{ __($label) }}</option>@endforeach
                </select>
            </div>
            @if ($fieldType === 'select')
                <div>
                    <label for="fieldOptions" class="label">{{ __('Choix possibles (un par ligne)') }}</label>
                    <textarea wire:model="fieldOptions" id="fieldOptions" rows="4" class="input" placeholder="{{ "Kiswahili\nKinande\nKihunde" }}"></textarea>
                    @error('fieldOptions') <p class="error">{{ $message }}</p> @enderror
                </div>
            @endif
            <label class="flex items-center gap-3 text-sm"><input type="checkbox" wire:model="fieldRequired" class="size-5"> {{ __('Obligatoire') }}</label>
            <label class="flex items-start gap-3 text-sm"><input type="checkbox" wire:model="fieldSensitive" class="mt-0.5 size-5"> <span>{{ __('Information sensible : visible seulement par les rôles qui ont la permission « informations sensibles »') }}</span></label>
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'field' })">{{ __('Annuler') }}</button>
                <button type="submit" class="btn-primary">{{ __('Enregistrer') }}</button>
            </div>
        </form>
    </x-modal>
</div>
