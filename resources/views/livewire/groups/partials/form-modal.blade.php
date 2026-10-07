@php use App\Models\Group; @endphp
<x-modal name="group" :title="$title">
    <form wire:submit="save" class="space-y-4">
        <div>
            <label for="g-name" class="label">{{ __('Nom') }}</label>
            <input wire:model="form.name" id="g-name" class="input" placeholder="{{ __('Exemple : Cellule de Mabanga Sud') }}">
            @error('form.name') <p class="error">{{ $message }}</p> @enderror
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div><label for="g-kind" class="label">{{ __('Type') }}</label>
                <select wire:model="form.kind" id="g-kind" class="input">@foreach (Group::KINDS as $k => $l)<option value="{{ $k }}">{{ __($l) }}</option>@endforeach</select></div>
            <div><label for="g-dept" class="label">{{ __('Département') }}</label>
                <select wire:model="form.department_id" id="g-dept" class="input">
                    @if ($full)<option value="">{{ __('Aucun : toute la communauté') }}</option>@else<option value="">{{ __('Choisir…') }}</option>@endif
                    @foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
                </select>
                @error('form.department_id') <p class="error">{{ $message }}</p> @enderror</div>
        </div>
        @if ($withLeader)
            <div>
                <p class="label">{{ __('Responsable') }} <span class="font-normal text-sand-700">{{ __('(obligatoire)') }}</span></p>
                @if ($leader)
                    <div class="flex items-center gap-3 rounded-xl bg-ochre-50 px-3 py-2">
                        <x-icon name="badge-check" class="size-4 text-ochre-600" />
                        <span class="flex-1 text-sm font-semibold text-ink-800">{{ $leader->officialName() }}</span>
                        <button type="button" wire:click="$set('form.leader_member_id', null)" class="rounded-lg p-1.5 text-sand-500 hover:bg-white" aria-label="{{ __('Changer') }}"><x-icon name="x" class="size-4" /></button>
                    </div>
                @else
                    <input wire:model.live.debounce.300ms="leaderSearch" type="search" class="input" placeholder="{{ __('Membre : nom, numéro ou téléphone') }}" aria-label="{{ __('Rechercher le responsable') }}">
                    <ul class="mt-1 space-y-1">@foreach ($candidates as $c)<li><button type="button" wire:click="chooseLeader({{ $c->id }})" class="w-full rounded-xl px-3 py-2 text-left text-sm hover:bg-sand-100"><span class="font-semibold text-ink-700">{{ $c->officialName() }}</span> <span class="font-mono text-xs text-sand-700">{{ $c->number }}</span></button></li>@endforeach</ul>
                @endif
                @error('form.leader_member_id') <p class="error">{{ $message }}</p> @enderror
            </div>
        @endif
        <div>
            <p class="label">{{ __('Rencontres de la semaine') }} <span class="font-normal text-sand-700">{{ __('(une chorale peut répéter plusieurs jours)') }}</span></p>
            <ul class="space-y-2">
                @foreach ($form['schedule'] ?? [] as $i => $m)
                    <li wire:key="meeting-{{ $i }}-{{ count($form['schedule']) }}" class="grid grid-cols-[1fr_6.5rem_auto] gap-2 sm:grid-cols-[1fr_7rem_1.2fr_auto]">
                        <select wire:model="form.schedule.{{ $i }}.day" class="input" aria-label="{{ __('Jour') }}"><option value="">{{ __('Jour…') }}</option>@foreach (Group::DAYS as $k => $l)<option value="{{ $k }}">{{ __($l) }}</option>@endforeach</select>
                        <input wire:model="form.schedule.{{ $i }}.time" type="time" class="input" aria-label="{{ __('Heure') }}">
                        <input wire:model="form.schedule.{{ $i }}.label" maxlength="40" class="input col-span-2 row-start-2 sm:col-span-1 sm:row-start-auto" placeholder="{{ __('Répétition, prière… (facultatif)') }}" aria-label="{{ __('Intitulé') }}">
                        <button type="button" wire:click="removeMeeting({{ $i }})" class="rounded-lg p-2 text-sand-500 hover:bg-terra-50 hover:text-terra-600 sm:row-start-auto" aria-label="{{ __('Retirer') }}"><x-icon name="trash-2" class="size-4" /></button>
                    </li>
                @endforeach
            </ul>
            @error('form.schedule.*.time') <p class="error">{{ $message }}</p> @enderror
            @if (count($form['schedule'] ?? []) < Group::MAX_MEETINGS)
                <button type="button" wire:click="addMeeting" class="btn-ghost mt-1 !min-h-0 !px-2 !py-1.5 text-sm"><x-icon name="plus" class="size-4" /> {{ __('Ajouter un jour de rencontre') }}</button>
            @endif
            <p class="hint">{{ __('Sans jour : rencontres à des dates variables.') }}</p>
        </div>
        <div><label for="g-place" class="label">{{ __('Lieu') }}</label><input wire:model="form.place" id="g-place" class="input" placeholder="{{ __('Chez…, salle…') }}"></div>
        <div class="grid grid-cols-[2fr_1fr] gap-4">
            <div><label for="g-dues" class="label">{{ __('Cotisation mensuelle') }} <span class="font-normal text-sand-700">{{ __('(facultatif)') }}</span></label><input wire:model="form.dues_amount" id="g-dues" type="number" step="0.01" min="0" class="input tabular" placeholder="0"></div>
            <div><label for="g-cur" class="label">{{ __('Devise') }}</label><select wire:model="form.dues_currency" id="g-cur" class="input"><option value="USD">USD</option><option value="CDF">CDF</option></select></div>
        </div>
        <div><label for="g-desc" class="label">{{ __('Description') }}</label><textarea wire:model="form.description" id="g-desc" rows="2" class="input"></textarea></div>
        <div class="flex flex-wrap justify-end gap-2">
            <button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'group' })">{{ __('Annuler') }}</button>
            <button class="btn-primary">{{ __('Enregistrer') }}</button>
        </div>
    </form>
</x-modal>
