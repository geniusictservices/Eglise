@php use App\Models\DocumentType; use App\Models\LifeEvent; @endphp
<div>
    <a href="{{ route('documents.templates') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Modèles de documents') }}</a>
    <x-page-header :title="$type ? $type->name : __('Nouveau modèle')" :description="__('Écrivez le texte une fois : les variables entre accolades se remplacent par les informations de la personne au moment de délivrer le document.')" />

    <div class="grid gap-6 xl:grid-cols-[1fr_1.05fr]">
        <form wire:submit="save" class="min-w-0 space-y-5">
            <section class="card space-y-4 p-5 sm:p-6">
                <div class="grid gap-4 sm:grid-cols-[2fr_1fr]">
                    <div><label for="t-name" class="label">{{ __('Nom du modèle') }}</label><input wire:model="form.name" id="t-name" class="input" placeholder="{{ __('Exemple : Attestation de baptême') }}">@error('form.name') <p class="error">{{ $message }}</p> @enderror</div>
                    <div><label for="t-code" class="label">{{ __('Code') }}</label><input wire:model.live.debounce.500ms="form.code" id="t-code" class="input font-mono uppercase" maxlength="12" placeholder="ABA">@error('form.code') <p class="error">{{ $message }}</p> @enderror</div>
                </div>
                <div><label for="t-title" class="label">{{ __('Titre imprimé') }}</label><input wire:model.live.debounce.500ms="form.title" id="t-title" class="input">@error('form.title') <p class="error">{{ $message }}</p> @enderror</div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label for="t-subject" class="label">{{ __('Délivré à') }}</label>
                        <select wire:model.live="form.subject" id="t-subject" class="input">@foreach (DocumentType::SUBJECTS as $k => $l)<option value="{{ $k }}">{{ __($l) }}</option>@endforeach</select></div>
                    @if ($form['subject'] !== 'free')
                        <div><label for="t-event" class="label">{{ __('Étape de vie reprise') }}</label>
                            <select wire:model.live="form.life_event_type" id="t-event" class="input"><option value="">{{ __('Aucune') }}</option>@foreach (LifeEvent::TYPES as $k => $l)<option value="{{ $k }}">{{ __($l) }}</option>@endforeach</select></div>
                    @endif
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label for="t-sign" class="label">{{ __('Qualité du signataire') }}</label><input wire:model.live.debounce.500ms="form.signatory_title" id="t-sign" class="input" placeholder="{{ __('Pasteur, Secrétaire…') }}"></div>
                    <div><label for="t-number" class="label">{{ __('Format du numéro') }}</label><input wire:model.live.debounce.500ms="form.number_format" id="t-number" class="input font-mono">
                        @error('form.number_format') <p class="error">{{ $message }}</p> @else <p class="mt-1 text-xs text-sand-700">{{ __('Exemple : :n', ['n' => $values['numero_document']]) }} · {CODE} {SIGLE} {SIEGE} {ANNEE} {AN} {NUMERO}</p> @enderror</div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label for="t-orientation" class="label">{{ __('Présentation') }}</label><select wire:model.live="form.orientation" id="t-orientation" class="input">
                        <option value="landscape">{{ __('Paysage : certificat, attestation') }}</option><option value="portrait">{{ __('Portrait : lettre, ordre de mission') }}</option></select></div>
                    <div><label for="t-style" class="label">{{ __('Style') }}</label><select wire:model.live="form.style" id="t-style" class="input">
                        <option value="">{{ __('Celui de l’église (:s)', ['s' => __(\App\Support\DocumentStyles::STYLES[\App\Support\DocumentStyles::forOrganization($organization)]['name'])]) }}</option>
                        @foreach (\App\Support\DocumentStyles::STYLES as $key => $s)<option value="{{ $key }}">{{ __($s['name']) }}</option>@endforeach</select></div>
                </div>
                @if ($form['subject'] !== 'free')
                    <label class="flex items-start gap-3 rounded-xl border border-sand-200 p-3 text-sm">
                        <input type="checkbox" wire:model.live="form.show_photo" class="mt-0.5 size-4">
                        <span><span class="font-semibold text-ink-800">{{ __('Mettre la photo du membre') }}</span><br><span class="text-sand-700">{{ __('Au format identité, en haut à droite, prise dans sa fiche au moment de la délivrance. Elle paraît aussi sur la page de vérification du QR code.') }}</span></span>
                    </label>
                @endif
            </section>

            <section class="card space-y-3 p-5 sm:p-6">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-lg">{{ __('Champs à remplir') }}</h2>
                    <button type="button" wire:click="addField" class="btn-ghost !min-h-0 !py-1.5 text-sm"><x-icon name="plus" class="size-4" /> {{ __('Ajouter un champ') }}</button>
                </div>
                <p class="text-sm text-sand-700">{{ __('Ce que la personne qui délivre le document doit indiquer : la destination d’une mission, la communauté d’accueil… Chaque champ devient une variable.') }}</p>
                @forelse ($form['fields'] as $i => $f)
                    <div class="flex flex-wrap items-center gap-2" wire:key="f-{{ $i }}">
                        <input wire:model.live.debounce.500ms="form.fields.{{ $i }}.label" class="input min-w-0 flex-1 basis-40" placeholder="{{ __('Libellé, ex. Destination') }}" aria-label="{{ __('Libellé du champ') }}">
                        <select wire:model="form.fields.{{ $i }}.type" class="input w-auto" aria-label="{{ __('Type') }}">@foreach (DocumentType::FIELD_TYPES as $k => $l)<option value="{{ $k }}">{{ __($l) }}</option>@endforeach</select>
                        <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" wire:model="form.fields.{{ $i }}.required" class="size-4"> {{ __('Obligatoire') }}</label>
                        @if (trim($f['label'] ?? '') !== '')<code class="rounded bg-sand-100 px-1.5 py-0.5 text-xs">{{ '{'.($f['key'] ?? \App\Support\DocumentTemplate::fieldKey($f['label'])).'}' }}</code>@endif
                        <button type="button" wire:click="removeField({{ $i }})" class="rounded-lg p-2 text-sand-500 hover:bg-terra-50 hover:text-terra-600" aria-label="{{ __('Retirer') }}"><x-icon name="x" class="size-4" /></button>
                    </div>
                @empty
                    <p class="text-sm text-sand-600">{{ __('Aucun champ : tout vient de la fiche de la personne.') }}</p>
                @endforelse
                @error('form.fields') <p class="error">{{ $message }}</p> @enderror
            </section>

            <section class="card space-y-3 p-5 sm:p-6" x-data="{ insert(v) { const t = $refs.body; const s = t.selectionStart ?? t.value.length, e = t.selectionEnd ?? s; t.value = t.value.slice(0, s) + v + t.value.slice(e); t.selectionStart = t.selectionEnd = s + v.length; t.dispatchEvent(new Event('input')); t.focus(); } }">
                <h2 class="text-lg">{{ __('Texte') }}</h2>
                <p class="text-sm text-sand-700">{{ __('Touchez une variable pour l’insérer à l’endroit du curseur. **Deux astérisques** de chaque côté mettent en gras ; une ligne vide commence un nouveau paragraphe.') }}</p>
                <textarea x-ref="body" wire:model.live.debounce.600ms="form.body" rows="12" class="input font-mono text-sm leading-relaxed" aria-label="{{ __('Texte du document') }}"></textarea>
                @error('form.body') <p class="error">{{ $message }}</p> @enderror
                @if ($unknown)<p class="rounded-xl bg-terra-50 px-3 py-2 text-sm text-terra-700">{{ __('Variables inconnues : :v. Vérifiez l’orthographe, ou ajoutez un champ de ce nom.', ['v' => collect($unknown)->map(fn ($u) => '{'.$u.'}')->implode(', ')]) }}</p>@endif
                <div class="space-y-3">
                    @if ($customKeys)
                        <div><p class="mb-1 text-xs font-semibold uppercase tracking-wide text-sand-700">{{ __('Champs de ce modèle') }}</p>
                            <div class="flex flex-wrap gap-1.5">@foreach ($customKeys as $key => $label)<button type="button" @click="insert('{{ '{'.$key.'}' }}')" class="rounded-lg bg-ochre-50 px-2 py-1 text-xs text-ink-800 hover:bg-ochre-100" title="{{ $label }}">{{ $label }}</button>@endforeach</div></div>
                    @endif
                    @foreach ($variables as $group => $items)
                        <div><p class="mb-1 text-xs font-semibold uppercase tracking-wide text-sand-700">{{ $group }}</p>
                            <div class="flex flex-wrap gap-1.5">@foreach ($items as $key => $label)<button type="button" @click="insert('{{ '{'.$key.'}' }}')" class="rounded-lg bg-sand-100 px-2 py-1 text-xs text-ink-800 hover:bg-sand-200" title="{{ '{'.$key.'}' }}">{{ $label }}</button>@endforeach</div></div>
                    @endforeach
                </div>
            </section>

            <div class="flex flex-wrap justify-end gap-2">
                @if ($type)<button type="button" wire:click="delete" wire:confirm="{{ __('Supprimer ce modèle ? Les documents déjà délivrés restent valables.') }}" class="btn-ghost mr-auto text-terra-600">{{ __('Supprimer') }}</button>@endif
                <a href="{{ route('documents.templates') }}" class="btn-ghost">{{ __('Annuler') }}</a>
                <button class="btn-primary">{{ __('Enregistrer le modèle') }}</button>
            </div>
        </form>

        <aside class="min-w-0">
            <div class="xl:sticky xl:top-24">
                <p class="mb-2 text-sm font-semibold text-sand-700">{{ __('Aperçu, avec une fiche de la communauté') }}</p>
                <div class="overflow-hidden rounded-xl bg-sand-100 p-3 sm:p-5">
                    <div class="origin-top-left">
                        @include('documents.sheet', ['organization' => $organization, 'identity' => $identity, 'title' => $draft->title, 'number' => $values['numero_document'],
                            'body' => $preview, 'date' => $values['date'], 'signatory' => $values['signataire'], 'signatoryTitle' => $values['qualite_signataire'], 'qr' => \App\Support\QrCode::svg(url('/verifier/document/exemple')),
                            'photoFrame' => $draft->show_photo, 'orientation' => $draft->orientation,
                            'style' => \App\Support\DocumentStyles::resolve($draft, $organization), 'headline' => \App\Support\DocumentStyles::headline($draft, $values, null)])
                    </div>
                </div>
            </div>
        </aside>
    </div>
</div>
