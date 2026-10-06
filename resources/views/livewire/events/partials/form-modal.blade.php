@php use App\Models\Event; @endphp
<x-modal name="event" :title="$editingEventId ? __('Modifier l’activité') : __('Nouvelle activité')">
    <form wire:submit="saveEvent" class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-[2fr_1fr]">
            <div><label for="e-title" class="label">{{ __('Titre') }}</label><input wire:model="eventForm.title" id="e-title" class="input" placeholder="{{ __('Exemple : Culte du dimanche') }}">@error('eventForm.title') <p class="error">{{ $message }}</p> @enderror</div>
            <div><label for="e-kind" class="label">{{ __('Type') }}</label><select wire:model="eventForm.kind" id="e-kind" class="input">@foreach (Event::KINDS as $k => $l)<option value="{{ $k }}">{{ __($l) }}</option>@endforeach</select></div>
        </div>
        <div class="grid gap-4 sm:grid-cols-4">
            <div class="sm:col-span-2"><label for="e-start" class="label">{{ __('Date') }}</label><input wire:model.live="eventForm.starts_on" id="e-start" type="date" class="input">@error('eventForm.starts_on') <p class="error">{{ $message }}</p> @enderror</div>
            <div><label for="e-from" class="label">{{ __('De') }}</label><input wire:model="eventForm.start_time" id="e-from" type="time" class="input"></div>
            <div><label for="e-to" class="label">{{ __('À') }}</label><input wire:model="eventForm.end_time" id="e-to" type="time" class="input"></div>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div><label for="e-repeats" class="label">{{ __('Répétition') }}</label>
                <select wire:model.live="eventForm.repeats" id="e-repeats" class="input">
                    @foreach (Event::REPEATS as $k => $l)
                        @php $preview = $k !== 'none' && ($eventForm['starts_on'] ?? null) ? (new Event(['starts_on' => $eventForm['starts_on'], 'repeats' => $k]))->recurrenceLabel() : null; @endphp
                        <option value="{{ $k }}">{{ $preview ? $preview : __($l) }}</option>
                    @endforeach
                </select></div>
            @if (($eventForm['repeats'] ?? 'none') !== 'none')
                <div><label for="e-until" class="label">{{ __('Jusqu’au') }} <span class="font-normal text-sand-700">{{ __('(facultatif)') }}</span></label><input wire:model="eventForm.repeat_until" id="e-until" type="date" class="input">@error('eventForm.repeat_until') <p class="error">{{ $message }}</p> @enderror</div>
            @else
                <div><label for="e-end" class="label">{{ __('Dernier jour') }} <span class="font-normal text-sand-700">{{ __('(sur plusieurs jours)') }}</span></label><input wire:model="eventForm.ends_on" id="e-end" type="date" class="input">@error('eventForm.ends_on') <p class="error">{{ $message }}</p> @enderror</div>
            @endif
        </div>
        <div><label for="e-place" class="label">{{ __('Lieu') }}</label><input wire:model="eventForm.place" id="e-place" class="input" placeholder="{{ __('Temple, salle paroissiale…') }}"></div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div><label for="e-aud" class="label">{{ __('Pour qui') }}</label>
                <select wire:model.live="eventForm.audience" id="e-aud" class="input">@foreach ($formAudiences as $k => $l)<option value="{{ $k }}">{{ __($l) }}</option>@endforeach</select>
                @error('eventForm.audience') <p class="error">{{ $message }}</p> @enderror</div>
            @if (($eventForm['audience'] ?? 'all') === 'department')
                <div><label for="e-dept" class="label">{{ __('Département') }}</label><select wire:model="eventForm.department_id" id="e-dept" class="input"><option value="">{{ __('Choisir…') }}</option>@foreach ($formDepartments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>@error('eventForm.department_id') <p class="error">{{ $message }}</p> @enderror</div>
            @elseif (($eventForm['audience'] ?? 'all') === 'group')
                <div><label for="e-group" class="label">{{ __('Groupe') }}</label><select wire:model="eventForm.group_id" id="e-group" class="input"><option value="">{{ __('Choisir…') }}</option>@foreach ($formGroups as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach</select>@error('eventForm.group_id') <p class="error">{{ $message }}</p> @enderror</div>
            @endif
        </div>
        <div class="space-y-2 rounded-xl border border-sand-200 p-3">
            <label class="flex items-start gap-3 text-sm"><input type="checkbox" wire:model="eventForm.is_public" class="mt-0.5 size-4"><span><span class="font-semibold text-ink-800">{{ __('Sur le site vitrine') }}</span><br><span class="text-sand-700">{{ __('Visible par tous sur le site de la communauté, s’il est publié.') }}</span></span></label>
            <label class="flex items-start gap-3 text-sm"><input type="checkbox" wire:model="eventForm.tracks_attendance" class="mt-0.5 size-4"><span><span class="font-semibold text-ink-800">{{ __('Noter les présences') }}</span><br><span class="text-sand-700">{{ __('Effectifs, pointage des membres ou visiteurs : chacun facultatif.') }}</span></span></label>
            <label class="flex items-start gap-3 text-sm"><input type="checkbox" wire:model.live="eventForm.registration" class="mt-0.5 size-4"><span><span class="font-semibold text-ink-800">{{ __('Sur inscription') }}</span><br><span class="text-sand-700">{{ __('Pour une convention, une retraite, un séminaire…') }}</span></span></label>
            @if ($eventForm['registration'] ?? false)
                <div class="ml-7 max-w-48"><label for="e-cap" class="label">{{ __('Places') }} <span class="font-normal text-sand-700">{{ __('(facultatif)') }}</span></label><input wire:model="eventForm.capacity" id="e-cap" type="number" min="1" class="input tabular">@error('eventForm.capacity') <p class="error">{{ $message }}</p> @enderror</div>
            @endif
        </div>
        <div><label for="e-desc" class="label">{{ __('Description') }}</label><textarea wire:model="eventForm.description" id="e-desc" rows="2" class="input"></textarea></div>
        <div class="flex flex-wrap justify-end gap-2">
            <button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'event' })">{{ __('Annuler') }}</button>
            <button class="btn-primary">{{ __('Enregistrer') }}</button>
        </div>
    </form>
</x-modal>
