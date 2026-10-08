@php use App\Models\Project; @endphp
<x-modal name="project" :title="$projectId ? __('Modifier le projet') : __('Nouveau projet')">
    <form wire:submit="saveProject" class="space-y-4">
        <div><label for="pj-name" class="label">{{ __('Nom') }}</label><input wire:model="project.name" id="pj-name" class="input" placeholder="{{ __('Exemple : achat de la parcelle de Kibati') }}">@error('project.name') <p class="error">{{ $message }}</p> @enderror</div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div><label for="pj-kind" class="label">{{ __('Genre') }}</label><select wire:model="project.kind" id="pj-kind" class="input">@foreach (Project::KINDS as $k => $l)<option value="{{ $k }}">{{ __($l) }}</option>@endforeach</select></div>
            <div><label for="pj-status" class="label">{{ __('État') }}</label><select wire:model="project.status" id="pj-status" class="input">@foreach (Project::STATUSES as $k => $l)<option value="{{ $k }}">{{ __($l) }}</option>@endforeach</select></div>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div><label for="pj-dept" class="label">{{ __('Département') }} <span class="font-normal text-sand-700">{{ __('(facultatif)') }}</span></label><select wire:model="project.department_id" id="pj-dept" class="input"><option value="">{{ __('Toute la communauté') }}</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div>
            <div><label for="pj-theme" class="label">{{ __('Axe de la vision') }} <span class="font-normal text-sand-700">{{ __('(facultatif)') }}</span></label><input wire:model="project.theme" id="pj-theme" class="input" placeholder="{{ __('Infrastructures, Jeunesse…') }}"></div>
        </div>
        <div>
            <p class="label">{{ __('Responsable') }}</p>
            @if ($responsible)
                <div class="flex items-center gap-3 rounded-xl bg-ochre-50 px-3 py-2"><span class="flex-1 text-sm font-semibold text-ink-800">{{ $responsible->officialName() }}</span>
                    <button type="button" wire:click="$set('project.responsible_member_id', null)" class="rounded-lg p-1.5 text-sand-500 hover:bg-white" aria-label="{{ __('Changer') }}"><x-icon name="x" class="size-4" /></button></div>
            @else
                <input wire:model.live.debounce.300ms="responsibleSearch" type="search" class="input" placeholder="{{ __('Membre : nom ou numéro') }}" aria-label="{{ __('Rechercher le responsable') }}">
                <ul class="mt-1 space-y-1">@foreach ($candidates as $c)<li><button type="button" wire:click="chooseResponsible({{ $c->id }})" class="w-full rounded-xl px-3 py-2 text-left text-sm hover:bg-sand-100"><span class="font-semibold text-ink-700">{{ $c->officialName() }}</span></button></li>@endforeach</ul>
                <input wire:model="project.responsible_name" class="input mt-2" placeholder="{{ __('… ou nom du responsable, du comité') }}" aria-label="{{ __('Nom du responsable') }}">
            @endif
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div><label for="pj-start" class="label">{{ __('Début') }}</label><input wire:model="project.starts_on" id="pj-start" type="date" class="input"></div>
            <div><label for="pj-end" class="label">{{ __('Fin prévue') }}</label><input wire:model="project.ends_on" id="pj-end" type="date" class="input">@error('project.ends_on') <p class="error">{{ $message }}</p> @enderror</div>
        </div>
        <div class="grid gap-4 sm:grid-cols-[1fr_7rem]">
            <div><label for="pj-goal" class="label">{{ __('Objectif total à collecter') }} <span class="font-normal text-sand-700">{{ __('(sinon : la somme des années)') }}</span></label><input wire:model="project.goal_amount" id="pj-goal" type="number" step="0.01" min="0" class="input tabular"></div>
            <div><label for="pj-cur" class="label">{{ __('Devise') }}</label><select wire:model="project.goal_currency" id="pj-cur" class="input">@foreach ($currencies as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select></div>
        </div>
        <div>
            <label for="pj-account" class="label">{{ __('Compte du projet') }} <span class="font-normal text-sand-700">{{ __('(facultatif)') }}</span></label>
            <select wire:model="project.cash_account_id" id="pj-account" class="input"><option value="">{{ __('Aucun : l’argent reste dans les caisses, réservé au projet') }}</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>
            <p class="hint">{{ __('Une caisse, un compte bancaire ou un numéro mobile money réservé au projet : il est proposé d’office pour ses dons et ses dépenses.') }}</p>
        </div>

        {{-- Les années : un projet d'un an a une ligne ; un projet sur plusieurs années, une par exercice. --}}
        <div>
            <p class="label">{{ __('Tranches annuelles (en dollars)') }}</p>
            <p class="mb-2 text-sm text-sand-700">{{ __('Ce que le projet prévoit de collecter et de dépenser chaque exercice. Chaque budget annuel reprend la tranche de son année.') }}</p>
            <ul class="space-y-2">
                @foreach ($tranches as $i => $t)
                    <li wire:key="tr-{{ $i }}-{{ count($tranches) }}" class="grid grid-cols-[5.5rem_1fr_1fr_auto] items-center gap-2">
                        <input wire:model="tranches.{{ $i }}.fiscal_year" type="number" min="2000" max="2100" class="input tabular" aria-label="{{ __('Année') }}">
                        <input wire:model="tranches.{{ $i }}.income_planned" type="number" step="0.01" min="0" class="input tabular" placeholder="{{ __('Collecte') }}" aria-label="{{ __('Collecte prévue') }}">
                        <input wire:model="tranches.{{ $i }}.expense_planned" type="number" step="0.01" min="0" class="input tabular" placeholder="{{ __('Dépenses') }}" aria-label="{{ __('Dépenses prévues') }}">
                        <button type="button" wire:click="removeTranche({{ $i }})" class="rounded-lg p-2 text-sand-500 hover:bg-terra-50 hover:text-terra-600" aria-label="{{ __('Retirer') }}"><x-icon name="trash-2" class="size-4" /></button>
                        <input wire:model="tranches.{{ $i }}.note" maxlength="255" class="input col-span-3 col-start-2 !py-1.5 text-sm" placeholder="{{ __('Ce qui sera fait cette année (facultatif)') }}" aria-label="{{ __('Note') }}">
                    </li>
                @endforeach
            </ul>
            @error('tranches.*.fiscal_year') <p class="error">{{ $message }}</p> @enderror
            <button type="button" wire:click="addTranche" class="btn-ghost mt-1 !min-h-0 !px-2 !py-1.5 text-sm"><x-icon name="plus" class="size-4" /> {{ __('Ajouter une année') }}</button>
        </div>
        <div><label for="pj-desc" class="label">{{ __('Description') }}</label><textarea wire:model="project.description" id="pj-desc" rows="3" class="input"></textarea></div>
        <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'project' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Enregistrer') }}</button></div>
    </form>
</x-modal>
