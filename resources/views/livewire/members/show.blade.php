@php
    use App\Support\Phone;
    $show = fn (string $field) => ! in_array($field, $hidden, true);
    $wa = $member->phone ? 'https://wa.me/'.ltrim($member->phone, '+') : null;
    $dash = '—';
    $date = fn ($d) => $d?->translatedFormat('j F Y');
@endphp
<div>
    <a href="{{ route('members.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Membres') }}</a>

    {{-- En-tête de la fiche --}}
    <section class="wax wax-veil wax-veil-strong mb-5 overflow-hidden rounded-[22px] p-5 text-white sm:p-6">
        <div class="flex flex-wrap items-center gap-4 sm:gap-5">
            @if ($member->photo_path)
                <img src="{{ route('members.photo', $member) }}" alt="" class="size-16 shrink-0 self-start rounded-2xl object-cover ring-4 ring-white/20 sm:size-24 sm:self-center">
            @else
                <span class="grid size-16 shrink-0 place-items-center self-start rounded-2xl bg-white/15 font-display text-xl font-semibold ring-4 ring-white/10 sm:size-24 sm:self-center sm:text-2xl">{{ $member->initials() }}</span>
            @endif
            <div class="min-w-0 flex-1">
                <p class="font-mono text-sm text-ochre-300">{{ $member->number }}</p>
                <h1 class="text-xl leading-tight font-semibold text-white sm:text-[1.75rem]">{{ $member->officialName() }}</h1>
                <p class="mt-1 text-sm text-ink-100">
                    {{ collect([
                        $member->gender === 'F' ? __('Femme') : ($member->gender === 'M' ? __('Homme') : null),
                        $member->age() !== null ? trans_choice(':count an|:count ans', $member->age()) : null,
                        $organization->id !== current_organization()->id ? $organization->displayName() : null,
                    ])->filter()->implode(' · ') }}
                </p>
                <x-status-badge :status="$member->status" class="mt-2 !bg-white !text-ink-800" />
            </div>
        </div>
        <div class="mt-5 flex flex-wrap gap-2">
            @if ($member->phone)
                <a href="tel:{{ $member->phone }}" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="phone" class="size-4" /> {{ __('Appeler') }}</a>
                <a href="{{ $wa }}" target="_blank" rel="noopener" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="message-circle" class="size-4" /> WhatsApp</a>
            @endif
            @if ($canManage)
                <a href="{{ route('members.edit', $member) }}" class="btn-accent !min-h-0 !py-2 sm:ml-auto"><x-icon name="pencil" class="size-4" /> {{ __('Modifier') }}</a>
                <button type="button" wire:click="openStatus" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="refresh-cw" class="size-4" /> {{ __('Statut') }}</button>
                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button type="button" @click="open = ! open" class="btn !min-h-0 bg-white/15 !px-3 !py-2 text-white hover:bg-white/25" aria-label="{{ __('Plus d’actions') }}"><x-icon name="ellipsis" class="size-4" /></button>
                    <div x-cloak x-show="open" x-transition class="absolute right-0 z-20 mt-2 w-60 rounded-2xl border border-sand-200 bg-white p-1.5 text-ink-800 shadow-xl">
                        <button type="button" class="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left text-sm text-terra-600 hover:bg-terra-50"
                                wire:click="archive" wire:confirm="{{ __('Supprimer la fiche de :name ? Elle restera dans le journal d’audit.', ['name' => $member->fullName()]) }}">
                            <x-icon name="trash-2" class="size-4" /> {{ __('Supprimer la fiche') }}
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </section>

    <div class="mb-5 flex gap-1 overflow-x-auto rounded-2xl border border-sand-200 bg-white p-1" role="tablist">
        @foreach (['profil' => __('Profil'), 'parcours' => __('Parcours'), 'fonctions' => __('Fonctions'), 'famille' => __('Ménage')] as $key => $label)
            <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    @class(['flex-1 whitespace-nowrap rounded-xl px-4 py-2 text-sm font-semibold', 'bg-ink-700 text-white' => $tab === $key, 'text-ink-600 hover:bg-sand-50' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'profil')
        <div class="grid gap-5 lg:grid-cols-2">
            @php
                $blocks = [
                    __('Identité') => array_filter([
                        __('Nom') => $member->last_name,
                        __('Post-nom') => $member->middle_name,
                        __('Prénom') => $member->first_name,
                        __('Date de naissance') => $date($member->birth_date),
                        __('Lieu de naissance') => $show('birth_place') ? ($member->birth_place ?: $dash) : null,
                        __('État civil') => $show('marital_status') ? ($member->marital_status ? __(\App\Models\Member::MARITAL_STATUSES[$member->marital_status]) : $dash) : null,
                        __('Profession') => $show('profession') ? ($member->profession ?: $dash) : null,
                        __('Niveau d’études') => $show('education_level') ? ($member->education_level ?: $dash) : null,
                    ], fn ($v) => $v !== null),
                    __('Contact') => array_filter([
                        __('Téléphone') => Phone::format($member->phone) ?: $dash,
                        __('Second téléphone') => $show('phone2') ? (Phone::format($member->phone2) ?: $dash) : null,
                        __('E-mail') => $show('email') ? ($member->email ?: $dash) : null,
                        __('Adresse') => $member->address() ?: $dash,
                        __('Langue préférée') => $show('preferred_language') ? (config('waumini.locales')[$member->preferred_language] ?? $dash) : null,
                        __('Personne à prévenir') => $show('emergency_contact') ? (trim($member->emergency_contact_name.' '.Phone::format($member->emergency_contact_phone)) ?: $dash) : null,
                    ], fn ($v) => $v !== null),
                    __('Vie dans la communauté') => array_filter([
                        __('Inscrit(e) à') => $organization->name,
                        __('Date d’adhésion') => $show('joined_on') ? ($date($member->joined_on) ?: $dash) : null,
                        __('Église d’origine') => $show('origin_church') ? ($member->origin_church ?: $dash) : null,
                        __('Ménage') => $household ? $household->name.' · '.__(\App\Models\Household::ROLES[$member->household_role] ?? '') : $dash,
                    ], fn ($v) => $v !== null),
                ];
                if ($fields->isNotEmpty()) {
                    $blocks[__('Informations propres à la communauté')] = $fields->mapWithKeys(function ($f) use ($member, $dash) {
                        $value = $member->custom[$f->key] ?? null;
                        $value = match (true) {
                            $value === null || $value === '' => $dash,
                            $f->type === 'boolean' => $value ? __('Oui') : __('Non'),
                            $f->type === 'date' => \Illuminate\Support\Carbon::parse($value)->translatedFormat('j F Y'),
                            default => $value,
                        };

                        return [$f->label => $value];
                    })->all();
                }
            @endphp
            @foreach ($blocks as $title => $rows)
                <section class="card p-5 sm:p-6">
                    <h2 class="mb-3 text-lg">{{ $title }}</h2>
                    <dl class="divide-y divide-sand-100">
                        @foreach ($rows as $label => $value)
                            <div class="grid grid-cols-[9rem_1fr] gap-3 py-2 text-sm sm:grid-cols-[11rem_1fr]">
                                <dt class="text-sand-700">{{ $label }}</dt>
                                <dd @class(['font-medium text-ink-800 break-words', 'tabular' => str_contains($label, __('Téléphone'))])>{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            @endforeach
            @if ($canSensitive && $member->notes)
                <section class="card border-ochre-300 bg-ochre-50 p-5 sm:p-6 lg:col-span-2">
                    <h2 class="mb-2 flex items-center gap-2 text-lg"><x-icon name="lock" class="size-5 text-ochre-600" /> {{ __('Notes confidentielles') }}</h2>
                    <p class="whitespace-pre-line text-sm text-ink-800">{{ $member->notes }}</p>
                </section>
            @endif
        </div>

    @elseif ($tab === 'parcours')
        <div class="grid gap-5 lg:grid-cols-[1.3fr_1fr]">
            <section class="card p-5 sm:p-6">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h2 class="text-lg">{{ __('Étapes de vie') }}</h2>
                    @if ($canManage)<button type="button" wire:click="openEvent" class="btn-secondary !min-h-0 !py-2"><x-icon name="plus" class="size-4" /> {{ __('Ajouter') }}</button>@endif
                </div>
                @forelse ($events as $e)
                    <div class="relative flex gap-4 pb-5 last:pb-0" wire:key="ev-{{ $e->id }}">
                        @unless ($loop->last)<span class="absolute left-[19px] top-10 bottom-0 w-px bg-sand-200"></span>@endunless
                        <span class="icon-tile bg-ochre-100 text-ochre-700"><x-icon :name="['baptism' => 'droplets', 'marriage' => 'heart', 'consecration' => 'award', 'child_presentation' => 'baby'][$e->type] ?? 'milestone'" class="size-5" /></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <p class="font-semibold text-ink-800">{{ $e->title() }}</p>
                                <p class="text-sm text-sand-700">{{ $date($e->occurred_on) ?? __('Date inconnue') }}</p>
                            </div>
                            <p class="text-sm text-sand-700">
                                {{ collect([$e->place, $e->officiant ? __('par :name', ['name' => $e->officiant]) : null, $e->register_number ? __('registre n° :n', ['n' => $e->register_number]) : null])->filter()->implode(' · ') }}
                            </p>
                            @if ($e->witnesses)<p class="text-sm text-sand-700">{{ __('Témoins : :names', ['names' => $e->witnesses]) }}</p>@endif
                            @if ($canManage)
                                <div class="mt-1 flex gap-3 text-sm">
                                    <button type="button" wire:click="openEvent({{ $e->id }})" class="font-semibold text-ink-600 hover:underline">{{ __('Modifier') }}</button>
                                    <button type="button" wire:click="deleteEvent({{ $e->id }})" wire:confirm="{{ __('Supprimer cette étape ?') }}" class="font-semibold text-terra-600 hover:underline">{{ __('Supprimer') }}</button>
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-sand-700">{{ __('Aucune étape enregistrée : baptême, mariage religieux, consécration…') }}</p>
                @endforelse
            </section>

            <section class="card p-5 sm:p-6">
                <h2 class="mb-4 text-lg">{{ __('Historique du statut') }}</h2>
                <ol class="space-y-3">
                    @forelse ($statusChanges as $c)
                        <li class="text-sm">
                            <p class="flex flex-wrap items-center gap-1.5">
                                @if ($c->from)<x-status-badge :status="$c->from" /> <x-icon name="arrow-right" class="size-3.5 text-sand-500" />@endif
                                <x-status-badge :status="$c->to" />
                                <span class="text-sand-700">{{ $date($c->changed_on) }}</span>
                            </p>
                            @if ($c->reason)<p class="mt-0.5 text-ink-800">{{ $c->reason }}</p>@endif
                            @if ($c->user)<p class="text-xs text-sand-700">{{ __('par :name', ['name' => $c->user->name]) }}</p>@endif
                        </li>
                    @empty
                        <li class="text-sm text-sand-700">{{ __('Pas encore de changement.') }}</li>
                    @endforelse
                </ol>
            </section>
        </div>

    @elseif ($tab === 'fonctions')
        <section class="card p-5 sm:p-6">
            <div class="mb-4 flex items-center justify-between gap-3">
                <h2 class="text-lg">{{ __('Fonctions et mandats') }}</h2>
                @if ($canManage)<button type="button" wire:click="openTerm" class="btn-secondary !min-h-0 !py-2"><x-icon name="plus" class="size-4" /> {{ __('Ajouter') }}</button>@endif
            </div>
            <ul class="divide-y divide-sand-100">
                @forelse ($terms as $t)
                    <li class="flex flex-wrap items-center gap-3 py-3" wire:key="t-{{ $t->id }}">
                        <span @class(['icon-tile', 'bg-leaf-50 text-leaf-600' => $t->isCurrent(), 'bg-sand-100 text-sand-700' => ! $t->isCurrent()])><x-icon name="award" class="size-5" /></span>
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-ink-800">{{ $t->function->name }}
                                @if ($t->isCurrent())<span class="badge ml-1 bg-leaf-50 text-leaf-600">{{ __('En cours') }}</span>@endif</p>
                            <p class="text-sm text-sand-700">
                                @if ($t->started_on && $t->ended_on) {{ __('Du :from au :to', ['from' => $date($t->started_on), 'to' => $date($t->ended_on)]) }}
                                @elseif ($t->started_on) {{ __('Depuis le :date', ['date' => $date($t->started_on)]) }}
                                @elseif ($t->ended_on) {{ __('Jusqu’au :date', ['date' => $date($t->ended_on)]) }}
                                @endif
                                @if ($t->note) · {{ $t->note }} @endif
                            </p>
                        </div>
                        @if ($canManage)
                            <div class="flex gap-3 text-sm">
                                <button type="button" wire:click="openTerm({{ $t->id }})" class="font-semibold text-ink-600 hover:underline">{{ __('Modifier') }}</button>
                                <button type="button" wire:click="deleteTerm({{ $t->id }})" wire:confirm="{{ __('Retirer cette fonction de la fiche ?') }}" class="font-semibold text-terra-600 hover:underline">{{ __('Retirer') }}</button>
                            </div>
                        @endif
                    </li>
                @empty
                    <li class="py-2 text-sm text-sand-700">{{ __('Aucune fonction. Ajoutez par exemple « Diacre depuis 2021 ».') }}</li>
                @endforelse
            </ul>
        </section>

    @else
        <div class="grid gap-5 lg:grid-cols-2">
            <section class="card p-5 sm:p-6">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h2 class="text-lg">{{ __('Ménage') }}</h2>
                    @if ($household)<a href="{{ route('households.show', $household) }}" class="text-sm font-semibold text-ink-600 hover:underline">{{ __('Ouvrir le ménage') }}</a>@endif
                </div>
                @if ($household)
                    <p class="font-semibold text-ink-800">{{ $household->name }}</p>
                    <p class="mb-3 text-sm text-sand-700">{{ $household->address() }}</p>
                    <ul class="space-y-2">
                        @foreach ($household->members as $m)
                            <li>
                                <a href="{{ route('members.show', $m) }}" @class(['flex items-center gap-3 rounded-xl px-2 py-1.5 hover:bg-sand-50', 'bg-ochre-50' => $m->is($member)])>
                                    @include('livewire.members.partials.avatar', ['member' => $m, 'size' => 'size-9 text-xs'])
                                    <span class="flex-1"><span class="block text-sm font-semibold text-ink-700">{{ $m->fullName() }}</span>
                                        <span class="block text-xs text-sand-700">{{ __(\App\Models\Household::ROLES[$m->household_role] ?? '') }}@if ($m->age() !== null) · {{ trans_choice(':count an|:count ans', $m->age()) }}@endif</span></span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-sand-700">{{ __('Cette personne n’est rattachée à aucun ménage.') }}</p>
                    @if ($canManage)
                        <div class="mt-4 flex flex-wrap gap-2">
                            <button type="button" wire:click="createHousehold" class="btn-secondary"><x-icon name="house-plus" class="size-4" /> {{ __('Créer son ménage') }}</button>
                            <button type="button" wire:click="openJoinHousehold" class="btn-ghost"><x-icon name="house" class="size-4" /> {{ __('Rejoindre un ménage') }}</button>
                        </div>
                    @endif
                @endif
            </section>
            <section class="card p-5 sm:p-6">
                <h2 class="mb-4 text-lg">{{ __('Départements') }}</h2>
                <ul class="space-y-2">
                    @forelse ($departments as $d)
                        <li class="flex items-center gap-3 text-sm">
                            <span class="icon-tile size-9 bg-ink-50 text-ink-700"><x-icon name="users-round" class="size-4" /></span>
                            <span class="flex-1 font-semibold text-ink-800">{{ $d->name }}</span>
                            <span class="badge bg-sand-100 text-sand-700">{{ __(\App\Models\Department::ROLES[$d->pivot->role] ?? '') }}</span>
                        </li>
                    @empty
                        <li class="text-sm text-sand-700">{{ __('Membre d’aucun département pour l’instant.') }}</li>
                    @endforelse
                </ul>
            </section>
        </div>
    @endif

    {{-- Fenêtres --}}
    <x-modal name="status" :title="__('Changer le statut')">
        <form wire:submit="changeStatus" class="space-y-4">
            <div>
                <label for="newStatusId" class="label">{{ __('Nouveau statut') }}</label>
                <select wire:model="newStatusId" id="newStatusId" class="input">
                    @foreach ($statuses as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                </select>
                @error('newStatusId') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="statusDate" class="label">{{ __('Date') }}</label>
                <input wire:model="statusDate" id="statusDate" type="date" class="input" max="{{ now()->format('Y-m-d') }}">
                @error('statusDate') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="statusReasonModal" class="label">{{ __('Motif') }}</label>
                <input wire:model="statusReason" id="statusReasonModal" class="input" placeholder="{{ __('Exemple : transféré à la paroisse de Himbi') }}">
            </div>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'status' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Enregistrer') }}</button></div>
        </form>
    </x-modal>

    <x-modal name="term" :title="$termId ? __('Modifier la fonction') : __('Ajouter une fonction')">
        <form wire:submit="saveTerm" class="space-y-4">
            <div>
                <label for="termFunctionId" class="label">{{ __('Fonction') }}</label>
                <select wire:model="termFunctionId" id="termFunctionId" class="input">
                    <option value="">—</option>
                    @foreach ($functions as $f)<option value="{{ $f->id }}">{{ $f->name }}</option>@endforeach
                </select>
                @error('termFunctionId') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label for="termStart" class="label">{{ __('Depuis le') }}</label><input wire:model="termStart" id="termStart" type="date" class="input"></div>
                <div><label for="termEnd" class="label">{{ __('Jusqu’au') }}</label><input wire:model="termEnd" id="termEnd" type="date" class="input">
                    @error('termEnd') <p class="error">{{ $message }}</p> @enderror</div>
            </div>
            <p class="hint">{{ __('Laissez la date de fin vide si le mandat est en cours.') }}</p>
            <div><label for="termNote" class="label">{{ __('Remarque') }}</label><input wire:model="termNote" id="termNote" class="input"></div>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'term' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Enregistrer') }}</button></div>
        </form>
    </x-modal>

    <x-modal name="event" :title="$eventId ? __('Modifier l’étape') : __('Ajouter une étape de vie')">
        <form wire:submit="saveEvent" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="eventType" class="label">{{ __('Étape') }}</label>
                    <select wire:model.live="event.type" id="eventType" class="input">
                        @foreach (\App\Models\LifeEvent::TYPES as $key => $label)<option value="{{ $key }}">{{ __($label) }}</option>@endforeach
                    </select>
                </div>
                <div><label for="eventDate" class="label">{{ __('Date') }}</label><input wire:model="event.occurred_on" id="eventDate" type="date" class="input">
                    @error('event.occurred_on') <p class="error">{{ $message }}</p> @enderror</div>
            </div>
            @if (($event['type'] ?? '') === 'other')
                <div><label for="eventLabel" class="label">{{ __('Intitulé') }}</label><input wire:model="event.label" id="eventLabel" class="input">
                    @error('event.label') <p class="error">{{ $message }}</p> @enderror</div>
            @endif
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label for="eventPlace" class="label">{{ __('Lieu') }}</label><input wire:model="event.place" id="eventPlace" class="input"></div>
                <div><label for="eventOfficiant" class="label">{{ __('Officiant') }}</label><input wire:model="event.officiant" id="eventOfficiant" class="input"></div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label for="eventWitnesses" class="label">{{ __('Témoins') }}</label><input wire:model="event.witnesses" id="eventWitnesses" class="input"></div>
                <div><label for="eventRegister" class="label">{{ __('N° dans le registre') }}</label><input wire:model="event.register_number" id="eventRegister" class="input font-mono">
                    <p class="hint">{{ __('Même celui d’un ancien registre papier.') }}</p></div>
            </div>
            <div><label for="eventNotes" class="label">{{ __('Remarques') }}</label><textarea wire:model="event.notes" id="eventNotes" rows="2" class="input"></textarea></div>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'event' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Enregistrer') }}</button></div>
        </form>
    </x-modal>

    <x-modal name="household" :title="__('Rejoindre un ménage')">
        <div class="space-y-4">
            <div>
                <label for="joinRole" class="label">{{ __('Place dans le ménage') }}</label>
                <select wire:model="householdRole" id="joinRole" class="input">
                    @foreach (\App\Models\Household::ROLES as $key => $label)<option value="{{ $key }}">{{ __($label) }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="joinSearch" class="label">{{ __('Ménage') }}</label>
                <input wire:model.live.debounce.300ms="householdSearch" id="joinSearch" type="search" class="input" placeholder="{{ __('Nom du ménage ou quartier') }}">
            </div>
            <ul class="space-y-1">
                @foreach ($households as $h)
                    <li><button type="button" wire:click="joinHousehold({{ $h->id }})" class="flex w-full items-center gap-2 rounded-xl px-3 py-2.5 text-left text-sm hover:bg-sand-100">
                        <x-icon name="house" class="size-4 text-ochre-600" />
                        <span class="flex-1"><span class="font-semibold text-ink-700">{{ $h->name }}</span> <span class="text-sand-700">· {{ $h->district }}</span></span>
                        <span class="text-xs text-sand-700">{{ trans_choice(':count pers.|:count pers.', $h->members_count) }}</span>
                    </button></li>
                @endforeach
            </ul>
        </div>
    </x-modal>
</div>
