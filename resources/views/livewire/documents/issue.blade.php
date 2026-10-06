@php use App\Models\DocumentType; @endphp
<div>
    <a href="{{ route('documents.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Documents délivrés') }}</a>
    <x-page-header :title="$type ? $type->name : __('Délivrer un document')" :description="$type ? null : __('Choisissez le document à délivrer.')" />

    @if (! $type)
        <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($types as $t)
                <li><button type="button" wire:click="chooseType({{ $t->id }})" class="card flex h-full w-full items-start gap-3 p-4 text-left transition hover:border-ochre-300">
                    <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-ink-50 font-mono text-xs font-semibold text-ink-700">{{ $t->code }}</span>
                    <span class="min-w-0"><span class="block font-semibold text-ink-800">{{ $t->name }}</span><span class="block text-sm text-sand-700">{{ __(DocumentType::SUBJECTS[$t->subject]) }}</span></span>
                </button></li>
            @endforeach
        </ul>
    @else
        <div class="grid gap-6 xl:grid-cols-[1fr_1.05fr]">
            <form wire:submit="issue" class="min-w-0 space-y-5">
                <section class="card space-y-4 p-5 sm:p-6">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm text-sand-700">{{ __('Modèle : :n', ['n' => $type->name]) }}</p>
                        <button type="button" wire:click="changeType" class="btn-ghost !min-h-0 !py-1 text-sm">{{ __('Changer') }}</button>
                    </div>
                    @if ($type->subject === 'free')
                        <div><label for="d-benef" class="label">{{ __('Destinataire') }}</label><input wire:model.live.debounce.500ms="beneficiary" id="d-benef" class="input" placeholder="{{ __('Nom de la personne ou de l’institution') }}"></div>
                    @else
                        <div>
                            <p class="label">{{ __('Membre') }}</p>
                            @if ($member)
                                <div class="flex items-center gap-3 rounded-xl bg-ochre-50 px-3 py-2">
                                    @include('livewire.members.partials.avatar', ['member' => $member, 'size' => 'size-9 text-xs'])
                                    <span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold text-ink-800">{{ $member->officialName() }}</span><span class="block font-mono text-xs text-sand-700">{{ $member->number }}</span></span>
                                    <button type="button" wire:click="$set('memberId', null)" class="rounded-lg p-1.5 text-sand-500 hover:bg-white" aria-label="{{ __('Changer') }}"><x-icon name="x" class="size-4" /></button>
                                </div>
                            @else
                                <input wire:model.live.debounce.300ms="memberSearch" type="search" class="input" placeholder="{{ __('Nom, numéro ou téléphone') }}" aria-label="{{ __('Rechercher le membre') }}">
                                <ul class="mt-1 space-y-1">@foreach ($candidates as $c)<li><button type="button" wire:click="chooseMember({{ $c->id }})" class="w-full rounded-xl px-3 py-2 text-left text-sm hover:bg-sand-100"><span class="font-semibold text-ink-700">{{ $c->officialName() }}</span> <span class="font-mono text-xs text-sand-700">{{ $c->number }}</span></button></li>@endforeach</ul>
                            @endif
                        </div>
                    @endif

                    @foreach ($type->customFields() as $f)
                        <div>
                            <label for="d-{{ $f['key'] }}" class="label">{{ $f['label'] }}@unless ($f['required']) <span class="font-normal text-sand-700">{{ __('(facultatif)') }}</span>@endunless</label>
                            @if ($f['type'] === 'long')
                                <textarea wire:model.live.debounce.600ms="fields.{{ $f['key'] }}" id="d-{{ $f['key'] }}" rows="5" class="input"></textarea>
                            @else
                                <input wire:model.live.debounce.500ms="fields.{{ $f['key'] }}" id="d-{{ $f['key'] }}" type="{{ $f['type'] === 'date' ? 'date' : 'text' }}" class="input">
                            @endif
                        </div>
                    @endforeach

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="sm:col-span-2"><label for="d-sign" class="label">{{ __('Signataire') }}</label><input wire:model.live.debounce.500ms="signatory" id="d-sign" class="input" placeholder="{{ __('Nom de la personne qui signe') }}"></div>
                        <div><label for="d-quality" class="label">{{ __('Qualité') }}</label><input wire:model.live.debounce.500ms="signatoryTitle" id="d-quality" class="input"></div>
                    </div>
                    <div class="max-w-48"><label for="d-date" class="label">{{ __('Date du document') }}</label><input wire:model.live="issuedOn" id="d-date" type="date" max="{{ today()->toDateString() }}" class="input">@error('issuedOn') <p class="error">{{ $message }}</p> @enderror</div>
                </section>

                @if ($missing)
                    <div class="rounded-2xl border border-ochre-300 bg-ochre-50 p-4 text-sm text-ink-800">
                        <p class="font-semibold">{{ __('Informations absentes :') }} {{ implode(', ', $missing) }}.</p>
                        <p class="mt-1 text-sand-700">{{ __('Elles resteront en pointillés, à compléter à la main. Mieux : complétez la fiche, puis revenez.') }}@if ($member) <a href="{{ route('members.edit', $member->id) }}" class="font-semibold text-ink-700 underline">{{ __('Compléter la fiche') }}</a>@endif</p>
                    </div>
                @endif
                @error('issue') <p class="rounded-xl bg-terra-50 px-4 py-3 text-sm font-semibold text-terra-700">{{ $message }}</p> @enderror

                <div class="flex flex-wrap justify-end gap-2">
                    <a href="{{ route('documents.index') }}" class="btn-ghost">{{ __('Annuler') }}</a>
                    <button class="btn-primary"><x-icon name="file-text" class="size-4" /> {{ __('Délivrer et imprimer') }}</button>
                </div>
                <p class="text-right text-xs text-sand-700">{{ __('Le document reçoit son numéro et son QR code ; son texte est alors figé. Une erreur se corrige en l’annulant et en le délivrant de nouveau.') }}</p>
            </form>

            <aside class="min-w-0">
                <div class="xl:sticky xl:top-24">
                    <p class="mb-2 text-sm font-semibold text-sand-700">{{ __('Aperçu') }}</p>
                    <div class="overflow-hidden rounded-xl bg-sand-100 p-3 sm:p-5">
                        @include('documents.sheet', ['organization' => $organization, 'identity' => $identity, 'title' => $type->title, 'number' => $values['numero_document'],
                            'body' => $preview, 'date' => $values['date'], 'signatory' => $values['signataire'], 'signatoryTitle' => $values['qualite_signataire'], 'qr' => \App\Support\QrCode::svg(url('/verifier/document/exemple'))])
                    </div>
                </div>
            </aside>
        </div>
    @endif
</div>
