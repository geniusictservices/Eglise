@php
    use App\Models\Register;
    $kind = $register->kind;
    $dateLabel = ['baptism' => __('Date du baptême'), 'marriage' => __('Date du mariage'), 'child_presentation' => __('Date de la présentation'), 'confirmation' => __('Date de la confirmation'), 'consecration' => __('Date de la consécration'), 'death' => __('Date du décès')][$kind] ?? __('Date');
    $witnessLabel = $kind === 'baptism' ? __('Parrain, marraine ou témoins') : __('Témoins');
@endphp
<div>
    <a href="{{ route('registers.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Registres') }}</a>
    <x-page-header :title="$register->name" :description="collect([__(Register::KINDS[$kind]), $register->period(), $register->notes])->filter()->implode(' · ')" />

    <div @class(['grid gap-6', 'lg:grid-cols-[minmax(0,1fr)_minmax(0,1.25fr)]' => $canManage])>
        @if ($canManage)
            <section class="card self-start p-5 sm:p-6 lg:sticky lg:top-24" x-data x-on:entry-saved.window="$nextTick(() => $refs.lastName.focus())">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h2 class="text-lg">{{ $editingId ? __('Corriger l’acte n° :n', ['n' => $entry['entry_number']]) : __('Saisie rapide') }}</h2>
                    @if ($editingId)<button type="button" wire:click="cancelEdit" class="btn-ghost !min-h-0 !py-1 text-sm">{{ __('Nouvel acte') }}</button>@endif
                </div>
                @unless ($editingId)<p class="mb-3 text-sm text-sand-700">{{ __('Recopiez le cahier acte par acte. Après chaque acte, le numéro suit ; la date, le lieu, l’officiant et la page restent, souvent communs à plusieurs actes.') }}</p>@endunless
                <form wire:submit="save" class="space-y-3">
                    <div class="grid grid-cols-[1fr_1fr_1.6fr] gap-3">
                        <div><label for="e-num" class="label">{{ __('N° acte') }}</label><input wire:model="entry.entry_number" id="e-num" class="input font-mono"></div>
                        <div><label for="e-page" class="label">{{ __('Page') }}</label><input wire:model="entry.page" id="e-page" class="input font-mono"></div>
                        <div><label for="e-date" class="label">{{ $dateLabel }}</label><input wire:model="entry.event_date" id="e-date" type="date" class="input"></div>
                    </div>
                    @error('entry.entry_number') <p class="error">{{ $message }}</p> @enderror
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div><label for="e-last" class="label">{{ __('Nom') }}</label><input wire:model="entry.last_name" x-ref="lastName" id="e-last" class="input uppercase">@error('entry.last_name') <p class="error">{{ $message }}</p> @enderror</div>
                        <div><label for="e-middle" class="label">{{ __('Post-nom') }}</label><input wire:model="entry.middle_name" id="e-middle" class="input"></div>
                        <div><label for="e-first" class="label">{{ __('Prénom') }}</label><input wire:model="entry.first_name" id="e-first" class="input"></div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-[auto_1fr_1fr]">
                        <div><label for="e-gender" class="label">{{ __('Sexe') }}</label><select wire:model="entry.gender" id="e-gender" class="input"><option value="">—</option><option value="M">{{ __('M') }}</option><option value="F">{{ __('F') }}</option></select></div>
                        <div><label for="e-bdate" class="label">{{ __('Né(e) le') }}</label><input wire:model="entry.birth_date" id="e-bdate" type="date" class="input"></div>
                        <div><label for="e-bplace" class="label">{{ __('à') }}</label><input wire:model="entry.birth_place" id="e-bplace" class="input"></div>
                    </div>
                    @if ($kind === 'marriage')
                        <div><label for="e-partner" class="label">{{ __('Conjoint(e) : nom complet') }}</label><input wire:model="entry.partner_name" id="e-partner" class="input"></div>
                    @else
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div><label for="e-father" class="label">{{ __('Père') }}</label><input wire:model="entry.father" id="e-father" class="input"></div>
                            <div><label for="e-mother" class="label">{{ __('Mère') }}</label><input wire:model="entry.mother" id="e-mother" class="input"></div>
                        </div>
                    @endif
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div><label for="e-place" class="label">{{ __('Lieu') }}</label><input wire:model="entry.place" id="e-place" class="input"></div>
                        <div><label for="e-off" class="label">{{ __('Officiant') }}</label><input wire:model="entry.officiant" id="e-off" class="input"></div>
                    </div>
                    <div><label for="e-wit" class="label">{{ $witnessLabel }}</label><input wire:model="entry.witnesses" id="e-wit" class="input"></div>
                    <div><label for="e-notes" class="label">{{ __('Remarques') }}</label><input wire:model="entry.notes" id="e-notes" class="input" placeholder="{{ __('Mention en marge, rature, page abîmée…') }}"></div>
                    <div class="flex flex-wrap justify-end gap-2">
                        @if ($editingId)<button type="button" wire:click="delete({{ $editingId }})" wire:confirm="{{ __('Supprimer cet acte ?') }}" class="btn-ghost mr-auto text-terra-600">{{ __('Supprimer') }}</button>@endif
                        <button class="btn-primary">{{ $editingId ? __('Enregistrer') : __('Enregistrer et suivant') }}</button>
                    </div>
                </form>
            </section>
        @endif

        <section class="card min-w-0 overflow-hidden">
            <div class="flex flex-wrap items-center gap-2 px-5 pb-3 pt-5">
                <h2 class="flex-1 text-lg">{{ trans_choice(':count acte|:count actes', $entries->total()) }}</h2>
                <input wire:model.live.debounce.300ms="search" type="search" class="input w-full sm:w-56" placeholder="{{ __('Nom ou n° d’acte') }}" aria-label="{{ __('Rechercher dans ce registre') }}">
            </div>
            <ul class="divide-y divide-sand-100">
                @forelse ($entries as $e)
                    <li wire:key="en-{{ $e->id }}" @class(['flex flex-wrap items-center gap-x-3 gap-y-2 px-5 py-3', 'bg-ochre-50' => $editingId === $e->id])>
                        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-ink-50 font-mono text-xs font-semibold text-ink-700">{{ $e->entry_number }}</span>
                        <span class="min-w-0 flex-1 basis-48">
                            <span class="block font-semibold text-ink-800">{{ $e->officialName() }}@if ($e->partner_name) <span class="font-normal text-sand-700">&amp;</span> {{ $e->partner_name }}@endif</span>
                            <span class="block text-xs text-sand-700">{{ collect([$e->event_date?->translatedFormat('j M Y'), $e->page ? __('page :p', ['p' => $e->page]) : null, $e->officiant])->filter()->implode(' · ') }}</span>
                            @if ($e->member)
                                <a href="{{ route('members.show', $e->member_id) }}" class="mt-1 inline-flex items-center gap-1 rounded-full bg-leaf-50 px-2 py-0.5 text-xs font-semibold text-leaf-600"><x-icon name="link" class="size-3" /> {{ $e->member->number ?: __('Fiche') }}</a>
                            @endif
                        </span>
                        <span class="ml-[52px] flex flex-wrap gap-1 sm:ml-0">
                            @if ($canIssue && $reissue)<a href="{{ route('documents.issue', ['modele' => $reissue->id, 'acte' => $e->id]) }}" class="btn-ghost !min-h-0 !px-2.5 !py-1.5 text-sm" title="{{ __('Rééditer : :n', ['n' => $reissue->name]) }}"><x-icon name="file-text" class="size-4" /> {{ __('Rééditer') }}</a>@endif
                            @if ($canManage)
                                @if ($e->member_id)
                                    <button type="button" wire:click="unlink({{ $e->id }})" wire:confirm="{{ __('Délier cet acte de la fiche du membre ?') }}" class="rounded-lg p-2 text-sand-500 hover:bg-sand-100" aria-label="{{ __('Délier de la fiche') }}"><x-icon name="link" class="size-4" /></button>
                                @else
                                    <button type="button" wire:click="askLink({{ $e->id }})" class="btn-ghost !min-h-0 !px-2.5 !py-1.5 text-sm"><x-icon name="link" class="size-4" /> {{ __('Relier') }}</button>
                                @endif
                                <button type="button" wire:click="edit({{ $e->id }})" class="rounded-lg p-2 text-ink-500 hover:bg-sand-100" aria-label="{{ __('Corriger') }}"><x-icon name="pencil" class="size-4" /></button>
                            @endif
                        </span>
                    </li>
                @empty
                    <li class="px-5 pb-5 text-sm text-sand-700">{{ trim($search) !== '' ? __('Aucun acte ne correspond.') : __('Aucun acte saisi.') }}</li>
                @endforelse
            </ul>
            <div class="p-4">{{ $entries->links() }}</div>
        </section>
    </div>

    @if ($canManage)
        <x-modal name="link" :title="__('Relier à la fiche d’un membre')">
            <p class="mb-3 text-sm text-sand-700">{{ __('La fiche du membre recevra l’étape de vie avec la référence de ce registre, si elle ne l’a pas déjà.') }}</p>
            <input wire:model.live.debounce.300ms="memberSearch" type="search" class="input" placeholder="{{ __('Nom, numéro ou téléphone') }}" aria-label="{{ __('Rechercher le membre') }}">
            <ul class="mt-2 space-y-1">
                @forelse ($candidates as $c)
                    <li><button type="button" wire:click="link({{ $c->id }})" class="w-full rounded-xl px-3 py-2 text-left text-sm hover:bg-sand-100"><span class="font-semibold text-ink-700">{{ $c->officialName() }}</span> <span class="font-mono text-xs text-sand-700">{{ $c->number }}</span>@if ($c->birth_date)<span class="block text-xs text-sand-700">{{ __('né(e) le :d', ['d' => $c->birth_date->translatedFormat('j M Y')]) }}</span>@endif</button></li>
                @empty
                    <li class="text-sm text-sand-700">{{ __('Aucun membre ne correspond.') }}</li>
                @endforelse
            </ul>
        </x-modal>
    @endif
</div>
