@php
    $show = fn (string $field) => ! in_array($field, $hidden, true);
    $err = fn (string $key) => $errors->has($key) ? 'input input-error' : 'input';
@endphp
<div>
    <a href="{{ $member ? route('members.show', $member) : route('members.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ $member ? $member->fullName() : __('Membres') }}</a>
    <x-page-header :title="$member ? __('Modifier la fiche') : __('Ajouter un membre')"
                   :description="$member ? null : __('Seul le nom est obligatoire. Vous pourrez compléter la fiche plus tard.')" />

    <form wire:submit="save" class="grid gap-5 lg:grid-cols-[1fr_20rem] lg:items-start lg:gap-6">
        <div class="space-y-5">
            {{-- Doublons possibles --}}
            @error('duplicates')
                <div class="rounded-2xl border border-ochre-300 bg-ochre-50 p-4 sm:p-5" role="alert">
                    <p class="flex items-start gap-2 font-semibold text-ink-800"><x-icon name="triangle-alert" class="mt-0.5 size-5 shrink-0 text-ochre-600" /> {{ $message }}</p>
                    <ul class="mt-3 space-y-2">
                        @foreach ($duplicates as $d)
                            <li class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl bg-white px-3 py-2 text-sm">
                                <span class="font-semibold text-ink-700">{{ $d->officialName() }}</span>
                                <span class="font-mono text-xs">{{ $d->number }}</span>
                                <span class="tabular text-sand-700">{{ \App\Support\Phone::format($d->phone) }}</span>
                                <span class="text-sand-700">{{ $d->organization->displayName() }}</span>
                                <a href="{{ route('members.show', $d) }}" target="_blank" class="ml-auto font-semibold text-ink-600 underline decoration-ochre-300 underline-offset-4">{{ __('Voir la fiche') }}</a>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-3 text-sm text-ink-800">{{ __('S’il s’agit d’une autre personne, cliquez à nouveau sur « Enregistrer ».') }}</p>
                </div>
            @enderror

            {{-- Identité --}}
            <section class="card space-y-4 p-5 sm:p-6">
                <h2 class="flex items-center gap-2 text-lg"><x-icon name="id-card" class="size-5 text-ochre-600" /> {{ __('Identité') }}</h2>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="last_name" class="label">{{ __('Nom') }} <span class="text-terra-500">*</span></label>
                        <input wire:model.blur="data.last_name" id="last_name" class="{{ $err('data.last_name') }} uppercase" autocomplete="family-name" required>
                        @error('data.last_name') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="middle_name" class="label">{{ __('Post-nom') }}</label>
                        <input wire:model.blur="data.middle_name" id="middle_name" class="input">
                    </div>
                    <div>
                        <label for="first_name" class="label">{{ __('Prénom') }}</label>
                        <input wire:model.blur="data.first_name" id="first_name" class="input" autocomplete="given-name">
                    </div>
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <fieldset>
                        <legend class="label">{{ __('Sexe') }}</legend>
                        <div class="flex gap-2">
                            @foreach (['F' => __('Femme'), 'M' => __('Homme')] as $value => $label)
                                <label @class(['flex flex-1 cursor-pointer items-center justify-center gap-2 rounded-xl border-[1.5px] px-3 py-2.5 text-sm font-semibold', 'border-ink-700 bg-ink-50 text-ink-800' => $data['gender'] === $value, 'border-sand-300 text-ink-600' => $data['gender'] !== $value])>
                                    <input type="radio" wire:model.live="data.gender" value="{{ $value }}" class="sr-only"> {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                    <div>
                        <label for="birth_date" class="label">{{ __('Date de naissance') }}</label>
                        <input wire:model.blur="data.birth_date" id="birth_date" type="date" class="{{ $err('data.birth_date') }}" max="{{ now()->format('Y-m-d') }}">
                        @error('data.birth_date') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    @if ($show('birth_place'))
                        <div>
                            <label for="birth_place" class="label">{{ __('Lieu de naissance') }}</label>
                            <input wire:model.blur="data.birth_place" id="birth_place" class="input">
                        </div>
                    @endif
                </div>
                @if ($show('marital_status') || $show('profession') || $show('education_level'))
                    <div class="grid gap-4 sm:grid-cols-3">
                        @if ($show('marital_status'))
                            <div>
                                <label for="marital_status" class="label">{{ __('État civil') }}</label>
                                <select wire:model="data.marital_status" id="marital_status" class="input">
                                    <option value="">—</option>
                                    @foreach (\App\Models\Member::MARITAL_STATUSES as $key => $label)
                                        <option value="{{ $key }}">{{ __($label) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        @if ($show('profession'))
                            <div>
                                <label for="profession" class="label">{{ __('Profession') }}</label>
                                <input wire:model.blur="data.profession" id="profession" class="input">
                            </div>
                        @endif
                        @if ($show('education_level'))
                            <div>
                                <label for="education_level" class="label">{{ __('Niveau d’études') }}</label>
                                <input wire:model.blur="data.education_level" id="education_level" class="input" list="education-levels">
                                <datalist id="education-levels">
                                    @foreach ([__('Primaire'), __('Secondaire'), __('Diplômé d’État'), __('Graduat'), __('Licence'), __('Master'), __('Doctorat')] as $level)<option value="{{ $level }}">@endforeach
                                </datalist>
                            </div>
                        @endif
                    </div>
                @endif
            </section>

            {{-- Contact et adresse --}}
            <section class="card space-y-4 p-5 sm:p-6">
                <h2 class="flex items-center gap-2 text-lg"><x-icon name="phone" class="size-5 text-ochre-600" /> {{ __('Contact et adresse') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="phone" class="label">{{ __('Téléphone') }}</label>
                        <input wire:model.blur="data.phone" id="phone" type="tel" inputmode="tel" class="{{ $err('data.phone') }}" placeholder="0812 345 678" autocomplete="tel">
                        @error('data.phone') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    @if ($show('phone2'))
                        <div>
                            <label for="phone2" class="label">{{ __('Second téléphone') }}</label>
                            <input wire:model.blur="data.phone2" id="phone2" type="tel" inputmode="tel" class="{{ $err('data.phone2') }}">
                            @error('data.phone2') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                    @if ($show('email'))
                        <div>
                            <label for="email" class="label">{{ __('E-mail') }}</label>
                            <input wire:model.blur="data.email" id="email" type="email" class="{{ $err('data.email') }}" autocomplete="email">
                            @error('data.email') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                    @if ($show('preferred_language'))
                        <div>
                            <label for="preferred_language" class="label">{{ __('Langue préférée') }}</label>
                            <select wire:model="data.preferred_language" id="preferred_language" class="input">
                                <option value="">—</option>
                                @foreach (config('waumini.locales') as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </div>
                <div class="grid gap-4 sm:grid-cols-[1fr_1fr_8rem]">
                    <div>
                        <label for="district" class="label">{{ __('Quartier') }}</label>
                        <input wire:model.blur="data.district" id="district" class="input">
                    </div>
                    <div>
                        <label for="street" class="label">{{ __('Avenue') }}</label>
                        <input wire:model.blur="data.street" id="street" class="input">
                    </div>
                    <div>
                        <label for="house_number" class="label">{{ __('Numéro') }}</label>
                        <input wire:model.blur="data.house_number" id="house_number" class="input">
                    </div>
                </div>
                <div class="sm:w-1/2">
                    <label for="city" class="label">{{ __('Ville') }}</label>
                    <input wire:model.blur="data.city" id="city" class="input">
                </div>
                @if ($show('emergency_contact'))
                    <div class="grid gap-4 border-t border-sand-100 pt-4 sm:grid-cols-2">
                        <div>
                            <label for="emergency_contact_name" class="label">{{ __('Personne à prévenir') }}</label>
                            <input wire:model.blur="data.emergency_contact_name" id="emergency_contact_name" class="input">
                        </div>
                        <div>
                            <label for="emergency_contact_phone" class="label">{{ __('Son téléphone') }}</label>
                            <input wire:model.blur="data.emergency_contact_phone" id="emergency_contact_phone" type="tel" inputmode="tel" class="{{ $err('data.emergency_contact_phone') }}">
                            @error('data.emergency_contact_phone') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                @endif
            </section>

            {{-- Vie dans l'église --}}
            <section class="card space-y-4 p-5 sm:p-6">
                <h2 class="flex items-center gap-2 text-lg"><x-icon name="heart-handshake" class="size-5 text-ochre-600" /> {{ __('Vie dans la communauté') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="status_id" class="label">{{ __('Statut') }}</label>
                        <select wire:model.live="data.status_id" id="status_id" class="input">
                            <option value="">{{ __('Sans statut') }}</option>
                            @foreach ($statuses as $s)
                                <option value="{{ $s->id }}">{{ $s->name }}{{ $s->needs_harmonization ? ' ('.__('à harmoniser').')' : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($show('joined_on'))
                        <div>
                            <label for="joined_on" class="label">{{ __('Date d’adhésion') }}</label>
                            <input wire:model.blur="data.joined_on" id="joined_on" type="date" class="{{ $err('data.joined_on') }}">
                            @error('data.joined_on') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>
                @if ($statusChanged)
                    <div class="rounded-xl bg-sand-100 p-4">
                        <label for="statusReason" class="label">{{ __('Motif du changement de statut') }}</label>
                        <input wire:model="statusReason" id="statusReason" class="input" placeholder="{{ __('Exemple : baptisé le 12 avril, transféré à Himbi…') }}">
                        <p class="hint">{{ __('Le changement est gardé dans l’historique de la fiche.') }}</p>
                    </div>
                @endif
                @if ($show('origin_church'))
                    <div>
                        <label for="origin_church" class="label">{{ __('Église d’origine') }}</label>
                        <input wire:model.blur="data.origin_church" id="origin_church" class="input">
                    </div>
                @endif
            </section>

            {{-- Champs ajoutés par la communauté --}}
            @if ($fields->isNotEmpty())
                <section class="card space-y-4 p-5 sm:p-6">
                    <h2 class="flex items-center gap-2 text-lg"><x-icon name="square-plus" class="size-5 text-ochre-600" /> {{ __('Informations propres à la communauté') }}</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach ($fields as $field)
                            @php $key = 'custom.'.$field->key; $id = 'custom-'.$field->key; @endphp
                            <div wire:key="field-{{ $field->id }}">
                                @if ($field->type === 'boolean')
                                    <label class="mt-7 flex items-center gap-3 text-sm font-semibold text-ink-700">
                                        <input type="checkbox" wire:model="{{ $key }}" class="size-5"> {{ $field->label }}
                                        @if ($field->sensitive)<x-icon name="lock" class="size-3.5 text-sand-500" />@endif
                                    </label>
                                @else
                                    <label for="{{ $id }}" class="label">{{ $field->label }} @if ($field->required)<span class="text-terra-500">*</span>@endif
                                        @if ($field->sensitive)<x-icon name="lock" class="inline size-3.5 text-sand-500" />@endif</label>
                                    @if ($field->type === 'select')
                                        <select wire:model="{{ $key }}" id="{{ $id }}" class="{{ $err($key) }}">
                                            <option value="">—</option>
                                            @foreach ($field->options ?? [] as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
                                        </select>
                                    @else
                                        <input wire:model.blur="{{ $key }}" id="{{ $id }}" class="{{ $err($key) }}"
                                               type="{{ ['number' => 'number', 'date' => 'date', 'phone' => 'tel'][$field->type] ?? 'text' }}" @if ($field->type === 'number') step="any" @endif>
                                    @endif
                                @endif
                                @error($key) <p class="error">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($canSensitive)
                <section class="card space-y-2 p-5 sm:p-6">
                    <label for="notes" class="flex items-center gap-2 text-lg font-semibold text-ink-800"><x-icon name="lock" class="size-5 text-ochre-600" /> {{ __('Notes confidentielles') }}</label>
                    <textarea wire:model.blur="data.notes" id="notes" rows="3" class="input"></textarea>
                    <p class="hint">{{ __('Visibles seulement par les personnes autorisées à voir les informations sensibles.') }}</p>
                </section>
            @endif
        </div>

        {{-- Colonne de droite : photo, numéro, ménage --}}
        <aside class="space-y-5 lg:sticky lg:top-24">
            <section class="card p-5" x-data="{ preview: null }">
                <p class="label">{{ __('Photo') }}</p>
                <div class="flex items-center gap-4">
                    <div class="size-24 shrink-0 overflow-hidden rounded-2xl bg-sand-100">
                        <template x-if="preview"><img :src="preview" alt="" class="size-full object-cover"></template>
                        @if ($member?->photo_path && ! $removePhoto)
                            <img x-show="! preview" src="{{ route('members.photo', $member) }}" alt="" class="size-full object-cover">
                        @else
                            <span x-show="! preview" class="grid size-full place-items-center text-sand-500"><x-icon name="camera" class="size-8" /></span>
                        @endif
                    </div>
                    <div class="space-y-2">
                        <label class="btn-secondary cursor-pointer !min-h-0 !py-2">
                            <x-icon name="upload" class="size-4" /> {{ __('Choisir') }}
                            <input type="file" wire:model="photo" accept="image/*" capture="user" class="sr-only"
                                   @change="const f = $event.target.files[0]; preview = f ? URL.createObjectURL(f) : null">
                        </label>
                        @if ($member?->photo_path && ! $removePhoto)
                            <button type="button" wire:click="$set('removePhoto', true)" class="block text-sm font-semibold text-terra-600 hover:underline">{{ __('Retirer la photo') }}</button>
                        @endif
                    </div>
                </div>
                <div wire:loading wire:target="photo" class="mt-2 text-sm text-sand-700">{{ __('Envoi de la photo…') }}</div>
                @error('photo') <p class="error">{{ $message }}</p> @enderror
            </section>

            <section class="card p-5">
                <p class="label">{{ __('Numéro de membre') }}</p>
                @if ($member)
                    <p class="font-mono text-lg font-semibold text-ink-700">{{ $member->number ?? '—' }}</p>
                    <p class="hint">{{ __('Un numéro attribué ne change jamais.') }}</p>
                @else
                    <p class="font-mono text-lg font-semibold text-ink-700">{{ trim($existingNumber) !== '' ? $existingNumber : $nextNumber }}</p>
                    <details class="mt-2" @if ($existingNumber !== '' || $errors->has('existingNumber')) open @endif>
                        <summary class="cursor-pointer text-sm font-semibold text-ink-600">{{ __('Reprendre un numéro de l’ancien registre') }}</summary>
                        <input wire:model.live.debounce.400ms="existingNumber" class="{{ $err('existingNumber') }} mt-2 font-mono" aria-label="{{ __('Numéro de l’ancien registre') }}">
                        @error('existingNumber') <p class="error">{{ $message }}</p> @enderror
                    </details>
                @endif
            </section>

            @unless ($member)
                <section class="card space-y-3 p-5">
                    <p class="label !mb-0">{{ __('Ménage') }}</p>
                    @foreach (['none' => __('Pas maintenant'), 'new' => __('Créer un nouveau ménage'), 'existing' => __('Ajouter à un ménage existant')] as $mode => $label)
                        <label class="flex items-center gap-3 text-sm">
                            <input type="radio" wire:model.live="householdMode" value="{{ $mode }}" class="size-5"> {{ $label }}
                        </label>
                    @endforeach

                    @if ($householdMode === 'existing')
                        @if ($chosenHousehold)
                            <div class="flex items-center gap-2 rounded-xl bg-leaf-50 px-3 py-2 text-sm">
                                <x-icon name="house" class="size-4 text-leaf-600" />
                                <span class="flex-1 font-semibold text-ink-800">{{ $chosenHousehold->name }}</span>
                                <button type="button" wire:click="$set('householdId', null)" class="text-sand-700 hover:text-terra-600" aria-label="{{ __('Changer de ménage') }}"><x-icon name="x" class="size-4" /></button>
                            </div>
                        @else
                            <input wire:model.live.debounce.300ms="householdSearch" type="search" class="{{ $err('householdId') }}" placeholder="{{ __('Nom du ménage ou quartier') }}" aria-label="{{ __('Rechercher un ménage') }}">
                            @error('householdId') <p class="error">{{ __('Choisissez le ménage.') }}</p> @enderror
                            <ul class="space-y-1">
                                @foreach ($households as $h)
                                    <li><button type="button" wire:click="chooseHousehold({{ $h->id }})" class="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left text-sm hover:bg-sand-100">
                                        <span class="flex-1"><span class="font-semibold text-ink-700">{{ $h->name }}</span> <span class="text-sand-700">· {{ $h->district }}</span></span>
                                        <span class="text-xs text-sand-700">{{ trans_choice(':count pers.|:count pers.', $h->members_count) }}</span>
                                    </button></li>
                                @endforeach
                            </ul>
                        @endif
                    @endif

                    @if ($householdMode !== 'none')
                        <div>
                            <label for="householdRole" class="label">{{ __('Place dans le ménage') }}</label>
                            <select wire:model="householdRole" id="householdRole" class="input">
                                @foreach (\App\Models\Household::ROLES as $key => $label)<option value="{{ $key }}">{{ __($label) }}</option>@endforeach
                            </select>
                        </div>
                    @endif
                </section>
            @endunless

            <div class="flex gap-2 lg:flex-col">
                <button type="submit" class="btn-primary flex-1" wire:loading.attr="disabled" wire:target="save,photo">
                    <x-icon name="save" class="size-4" /> {{ __('Enregistrer') }}
                </button>
                <a href="{{ $member ? route('members.show', $member) : route('members.index') }}" class="btn-ghost flex-1">{{ __('Annuler') }}</a>
            </div>
        </aside>
    </form>
</div>
