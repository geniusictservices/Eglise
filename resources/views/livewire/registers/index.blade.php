@php use App\Models\Register; @endphp
<div>
    <x-page-header :title="__('Registres')" :description="__('Les registres officiels de baptêmes, de mariages, de présentations… y compris les anciens cahiers papier, recopiés acte par acte. Un acte retrouvé se réédite avec un QR code.')">
        <x-slot:actions>
            @if ($canManage)<button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Nouveau registre') }}</button>@endif
        </x-slot:actions>
    </x-page-header>

    <input wire:model.live.debounce.300ms="search" type="search" class="input mb-4" placeholder="{{ __('Chercher une personne dans tous les registres : nom, parents, conjoint, numéro d’acte') }}" aria-label="{{ __('Rechercher dans les registres') }}">

    @if (trim($search) !== '')
        <section class="card mb-6 overflow-hidden">
            <h2 class="px-5 pb-2 pt-5 text-lg">{{ trans_choice(':count acte trouvé|:count actes trouvés', $results->count()) }}</h2>
            <ul class="divide-y divide-sand-100">
                @forelse ($results as $e)
                    <li><a href="{{ route('registers.show', ['register' => $e->register_id, 'acte' => $e->id]) }}" class="flex items-center gap-3 px-5 py-3 hover:bg-sand-50">
                        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-ink-50 font-mono text-xs font-semibold text-ink-700">{{ $e->entry_number }}</span>
                        <span class="min-w-0 flex-1"><span class="block truncate font-semibold text-ink-800">{{ $e->officialName() }}@if ($e->partner_name) &amp; {{ $e->partner_name }}@endif</span>
                            <span class="block truncate text-sm text-sand-700">{{ $e->register?->name }}@if ($e->event_date) · {{ $e->event_date->translatedFormat('j M Y') }}@endif</span></span>
                        @if ($e->member_id)<span class="badge bg-leaf-50 text-leaf-600">{{ __('Membre') }}</span>@endif
                    </a></li>
                @empty
                    <li class="px-5 pb-5 text-sm text-sand-700">{{ __('Aucun acte ne correspond.') }}</li>
                @endforelse
            </ul>
        </section>
    @endif

    <ul class="grid gap-3 md:grid-cols-2">
        @forelse ($registers as $r)
            <li><a href="{{ route('registers.show', $r) }}" class="card flex h-full items-start gap-3 p-4 transition hover:border-ochre-300">
                <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-ochre-50 text-ochre-600"><x-icon name="book-open" class="size-5" /></span>
                <span class="min-w-0 flex-1">
                    <span class="block font-semibold text-ink-800">{{ $r->name }}</span>
                    <span class="block text-sm text-sand-700">{{ __(Register::KINDS[$r->kind]) }}@if ($r->period()) · {{ $r->period() }}@endif</span>
                    <span class="mt-1 block text-xs text-sand-600">{{ trans_choice(':count acte saisi|:count actes saisis', $r->entries_count) }}@if ($r->linked_count) · {{ trans_choice(':count relié à une fiche|:count reliés à une fiche', $r->linked_count) }}@endif</span>
                </span>
            </a></li>
        @empty
            <li class="card p-8 text-center text-sm text-sand-700 md:col-span-2">{{ __('Aucun registre. Créez-en un par cahier : « Registre des baptêmes n° 1 (1985-2002) », par exemple.') }}</li>
        @endforelse
    </ul>

    @if ($canManage)
        <x-modal name="register" :title="__('Nouveau registre')">
            <form wire:submit="save" class="space-y-4">
                <div><label for="r-kind" class="label">{{ __('Registre des') }}</label><select wire:model="form.kind" id="r-kind" class="input">@foreach (Register::KINDS as $k => $l)<option value="{{ $k }}">{{ __($l) }}</option>@endforeach</select></div>
                <div><label for="r-name" class="label">{{ __('Nom') }}</label><input wire:model="form.name" id="r-name" class="input" placeholder="{{ __('Exemple : Registre des baptêmes n° 2') }}">@error('form.name') <p class="error">{{ $message }}</p> @enderror</div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label for="r-from" class="label">{{ __('De l’année') }}</label><input wire:model="form.from_year" id="r-from" type="number" class="input tabular" placeholder="1985"></div>
                    <div><label for="r-to" class="label">{{ __('À l’année') }}</label><input wire:model="form.to_year" id="r-to" type="number" class="input tabular" placeholder="2002">@error('form.to_year') <p class="error">{{ $message }}</p> @enderror</div>
                </div>
                <div><label for="r-notes" class="label">{{ __('Remarques') }}</label><textarea wire:model="form.notes" id="r-notes" rows="2" class="input" placeholder="{{ __('État du cahier, où il est rangé…') }}"></textarea></div>
                <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'register' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Créer') }}</button></div>
            </form>
        </x-modal>
    @endif
</div>
