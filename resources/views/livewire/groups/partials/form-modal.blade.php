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
        <div class="grid gap-4 sm:grid-cols-3">
            <div><label for="g-day" class="label">{{ __('Jour de rencontre') }}</label>
                <select wire:model="form.meeting_day" id="g-day" class="input"><option value="">{{ __('Variable') }}</option>@foreach (Group::DAYS as $k => $l)<option value="{{ $k }}">{{ __($l) }}</option>@endforeach</select></div>
            <div><label for="g-time" class="label">{{ __('Heure') }}</label><input wire:model="form.meeting_time" id="g-time" type="time" class="input">@error('form.meeting_time') <p class="error">{{ $message }}</p> @enderror</div>
            <div><label for="g-place" class="label">{{ __('Lieu') }}</label><input wire:model="form.place" id="g-place" class="input" placeholder="{{ __('Chez…, salle…') }}"></div>
        </div>
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
