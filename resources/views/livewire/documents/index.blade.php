<div>
    <x-page-header :title="__('Documents délivrés')" :description="__('Attestations, certificats, lettres et ordres de mission : chacun garde son numéro et son QR code de vérification, même annulé.')">
        <x-slot:actions>
            @can('documents.templates')<a href="{{ route('documents.templates') }}" class="btn-secondary"><x-icon name="pencil" class="size-4" /> {{ __('Modèles') }}</a>@endcan
            @if ($canIssue)<a href="{{ route('documents.issue') }}" class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Délivrer un document') }}</a>@endif
        </x-slot:actions>
    </x-page-header>

    @if ($pending->isNotEmpty())
        <section class="mb-5 rounded-[18px] border border-ochre-300 bg-ochre-50 p-5">
            <h2 class="mb-3 flex items-center gap-2 text-base"><x-icon name="file-text" class="size-5 text-ochre-600" /> {{ trans_choice(':count demande d’un membre|:count demandes de membres', $pending->count()) }}</h2>
            <ul class="space-y-2">
                @foreach ($pending as $r)
                    <li class="flex flex-wrap items-center gap-2">
                        <span class="min-w-0 flex-1 basis-56 text-sm"><span class="font-semibold text-ink-800">{{ $r->member?->officialName() }}</span> · {{ $r->type?->name }}@if ($r->message) <span class="text-sand-700">« {{ $r->message }} »</span>@endif <span class="text-xs text-sand-600">· {{ $r->created_at->diffForHumans() }}</span></span>
                        @if ($canIssue)
                            <a href="{{ route('documents.issue', ['modele' => $r->document_type_id, 'membre' => $r->member_id, 'demande' => $r->id]) }}" class="btn-primary !min-h-0 !py-1.5 text-sm">{{ __('Délivrer') }}</a>
                            <button type="button" wire:click="askRefuse({{ $r->id }})" class="btn-ghost !min-h-0 !py-1.5 text-sm text-terra-600">{{ __('Refuser') }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="mb-4 flex flex-wrap gap-2">
        <input wire:model.live.debounce.300ms="search" type="search" class="input min-w-0 flex-1 basis-56" placeholder="{{ __('Numéro ou nom de la personne') }}" aria-label="{{ __('Rechercher') }}">
        <select wire:model.live="typeCode" class="input w-auto min-w-0" aria-label="{{ __('Modèle') }}">
            <option value="">{{ __('Tous les documents') }}</option>
            @foreach ($types->unique('code') as $t)<option value="{{ $t->code }}">{{ $t->name }}</option>@endforeach
        </select>
    </div>
    <p class="mb-3 text-sm text-sand-700">{{ trans_choice(':count document délivré cette année.|:count documents délivrés cette année.', $thisYear) }}</p>

    <ul class="space-y-2">
        @forelse ($documents as $d)
            <li wire:key="doc-{{ $d->id }}" @class(['card flex flex-wrap items-center gap-x-4 gap-y-2 p-4', 'opacity-70' => $d->isCancelled()])>
                <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-ink-50 font-mono text-xs font-semibold text-ink-700">{{ $d->type?->code }}</span>
                <span class="min-w-0 flex-1 basis-48">
                    <span class="block truncate font-semibold text-ink-800">{{ $d->beneficiary }}</span>
                    <span class="block truncate text-sm text-sand-700">{{ $d->title }} · <span class="font-mono">{{ $d->number }}</span></span>
                    <span class="block text-xs text-sand-600">{{ $d->issued_on->translatedFormat('j M Y') }}@if ($d->issuer) · {{ $d->issuer->name }}@endif
                        @if ($d->isCancelled()) · <span class="font-semibold text-terra-700">{{ __('Annulé : :r', ['r' => $d->cancel_reason]) }}</span>@endif</span>
                </span>
                <span class="flex gap-1">
                    <a href="{{ route('documents.print', $d) }}" class="btn-ghost !min-h-0 !px-3 !py-1.5 text-sm"><x-icon name="printer" class="size-4" /> <span class="hidden sm:inline">{{ __('Imprimer') }}</span></a>
                    @if ($canIssue && ! $d->isCancelled())
                        <button type="button" wire:click="askCancel({{ $d->id }})" class="rounded-lg p-2 text-sand-500 hover:bg-terra-50 hover:text-terra-600" aria-label="{{ __('Annuler le document') }}"><x-icon name="x" class="size-4" /></button>
                    @endif
                </span>
            </li>
        @empty
            <li class="card p-8 text-center text-sm text-sand-700">{{ trim($search) !== '' ? __('Aucun document ne correspond.') : __('Aucun document délivré pour l’instant.') }}</li>
        @endforelse
    </ul>
    <div class="mt-4">{{ $documents->links() }}</div>

    <x-modal name="refuse-request" :title="__('Refuser la demande')">
        <form wire:submit="refuse" class="space-y-4">
            <p class="text-sm text-sand-700">{{ __('La personne verra ce motif dans son espace.') }}</p>
            <input wire:model="refusal" class="input" placeholder="{{ __('Exemple : votre baptême n’est pas encore inscrit ; passez au secrétariat.') }}" aria-label="{{ __('Motif') }}">
            @error('refusal') <p class="error">{{ $message }}</p> @enderror
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'refuse-request' })">{{ __('Retour') }}</button><button class="btn-danger">{{ __('Refuser') }}</button></div>
        </form>
    </x-modal>

    <x-modal name="cancel-document" :title="__('Annuler le document')">
        <form wire:submit="cancel" class="space-y-4">
            <p class="text-sm text-sand-700">{{ __('Le document reste dans le registre avec son numéro, mais son QR code indiquera qu’il est annulé. Pour corriger une erreur, délivrez-en ensuite un nouveau.') }}</p>
            <div><label for="c-reason" class="label">{{ __('Motif') }}</label><input wire:model="reason" id="c-reason" class="input" placeholder="{{ __('Exemple : erreur sur la date de naissance') }}">@error('reason') <p class="error">{{ $message }}</p> @enderror</div>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'cancel-document' })">{{ __('Retour') }}</button><button class="btn-danger">{{ __('Annuler le document') }}</button></div>
        </form>
    </x-modal>
</div>
