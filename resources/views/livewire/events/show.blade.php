@php use App\Models\Event; use App\Support\Phone; @endphp
<div>
    <a href="{{ route('events.index', ['mois' => $day->format('Y-m')]) }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Calendrier') }}</a>

    <section class="wax wax-veil wax-veil-strong mb-5 overflow-hidden rounded-[22px] p-5 text-white sm:p-6">
        <div class="flex flex-wrap items-center gap-4">
            <span class="grid size-16 shrink-0 place-items-center rounded-2xl bg-white/15 text-center leading-none"><span><span class="block text-2xl font-semibold">{{ $day->format('d') }}</span><span class="text-xs uppercase text-ochre-300">{{ $day->translatedFormat('M') }}</span></span></span>
            <div class="min-w-0 flex-1">
                <p class="text-sm text-ochre-300">{{ __(Event::KINDS[$event->kind]) }} · {{ $event->audienceLabel() }}</p>
                <h1 class="text-2xl font-semibold text-white">{{ $event->title }}</h1>
                <p class="mt-1 text-sm text-ink-100">
                    {{ ucfirst($day->translatedFormat('l j F Y')) }}@if ($event->hours()) · {{ $event->hours() }}@endif
                    @if ($event->ends_on && ! $event->ends_on->isSameDay($event->starts_on)) · {{ __('jusqu’au :d', ['d' => $event->ends_on->translatedFormat('j F')]) }}@endif
                </p>
                @if ($event->place || $event->recurrenceLabel())
                    <p class="mt-0.5 flex flex-wrap gap-x-3 text-sm text-ink-100">
                        @if ($event->place)<span class="inline-flex items-center gap-1"><x-icon name="map-pin" class="size-4" /> {{ $event->place }}</span>@endif
                        @if ($event->recurrenceLabel())<span class="inline-flex items-center gap-1"><x-icon name="refresh-cw" class="size-4" /> {{ $event->recurrenceLabel() }}</span>@endif
                    </p>
                @endif
            </div>
            @if ($canEdit)
                <div class="flex w-full flex-wrap gap-2 sm:w-auto">
                    <button type="button" wire:click="openEventForm({{ $event->id }})" class="btn-accent !min-h-0 !py-2"><x-icon name="pencil" class="size-4" /> {{ __('Modifier') }}</button>
                    <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                        <button type="button" @click="open = ! open" class="btn !min-h-0 bg-white/15 !px-3 !py-2 text-white hover:bg-white/25" aria-label="{{ __('Plus d’actions') }}"><x-icon name="ellipsis" class="size-4" /></button>
                        <div x-cloak x-show="open" x-transition class="absolute right-0 z-20 mt-2 w-72 rounded-2xl border border-sand-200 bg-white p-1.5 text-ink-800 shadow-xl">
                            @if ($event->repeats !== 'none')
                                @if ($skipped)
                                    <button type="button" wire:click="restore" class="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left text-sm hover:bg-sand-50"><x-icon name="undo-2" class="size-4" /> {{ __('Rétablir cette date') }}</button>
                                @else
                                    <button type="button" wire:click="skip" wire:confirm="{{ __('Annuler l’activité de ce jour seulement ?') }}" class="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left text-sm hover:bg-sand-50"><x-icon name="x" class="size-4" /> {{ __('Annuler cette date seulement') }}</button>
                                @endif
                            @endif
                            <button type="button" wire:click="deleteEvent" wire:confirm="{{ $event->repeats !== 'none' ? __('Supprimer cette activité et toutes ses dates ? Les présences déjà notées restent dans les rapports.') : __('Supprimer cette activité ?') }}" class="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left text-sm text-terra-600 hover:bg-terra-50"><x-icon name="trash-2" class="size-4" /> {{ $event->repeats !== 'none' ? __('Supprimer toutes les dates') : __('Supprimer') }}</button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </section>

    @unless ($skipped)
        @php $shareText = $event->shareText($day, current_organization()); @endphp
        <div class="mb-5 flex flex-wrap gap-2" x-data="{ copied: false }">
            <a href="https://wa.me/?text={{ rawurlencode($shareText) }}" target="_blank" rel="noopener" class="btn !min-h-0 bg-[#25D366] !py-2 text-white hover:bg-[#1EBE5A]"><x-icon name="message-circle" class="size-4" /> {{ __('Partager sur WhatsApp') }}</a>
            @if ($canAnnounce && $day->gte(today()))<a href="{{ route('announcements.index', ['activite' => $event->id, 'date' => $date]) }}" class="btn-secondary !min-h-0 !py-2"><x-icon name="megaphone" class="size-4" /> {{ __('Annoncer') }}</a>@endif
        </div>
    @endunless
    @if ($skipped)
        <p class="mb-5 rounded-2xl border border-terra-100 bg-terra-50 p-4 text-sm font-semibold text-terra-700"><x-icon name="x" class="mr-1 inline size-4" /> {{ __('Cette date est annulée. Les autres dates restent prévues.') }}</p>
    @endif
    @if ($event->description)<p class="mb-5 max-w-3xl whitespace-pre-line text-ink-700">{{ $event->description }}</p>@endif

    <div class="grid gap-5 lg:grid-cols-2">
        @if ($event->tracks_attendance && ! $skipped && $canSeeAttendance)
            {{-- Effectifs --}}
            <section class="card p-5 sm:p-6">
                <h2 class="mb-1 text-lg">{{ __('Effectifs') }}</h2>
                @if ($day->isFuture())
                    <p class="text-sm text-sand-700">{{ __('Les présences se notent le jour même ou après.') }}</p>
                @elseif ($canRecord)
                    <p class="mb-3 text-sm text-sand-700">{{ __('Comptez par catégorie, ou donnez seulement le total. Rien n’est obligatoire.') }}</p>
                    <form wire:submit="saveCounts" class="space-y-3">
                        <div class="grid grid-cols-3 gap-3">
                            @foreach (['men' => __('Hommes'), 'women' => __('Femmes'), 'children' => __('Enfants')] as $k => $l)
                                <div><label for="c-{{ $k }}" class="label">{{ $l }}</label><input wire:model="counts.{{ $k }}" id="c-{{ $k }}" type="number" min="0" inputmode="numeric" class="input tabular"></div>
                            @endforeach
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div><label for="c-total" class="label">{{ __('Total') }}</label><input wire:model="counts.total" id="c-total" type="number" min="0" inputmode="numeric" class="input tabular" placeholder="{{ __('calculé si détail') }}">@error('counts.total') <p class="error">{{ $message }}</p> @enderror</div>
                            <div><label for="c-visitors" class="label">{{ __('dont visiteurs') }}</label><input wire:model="counts.visitors" id="c-visitors" type="number" min="0" inputmode="numeric" class="input tabular"></div>
                        </div>
                        <div><label for="c-notes" class="label">{{ __('Remarques') }}</label><input wire:model="counts.notes" id="c-notes" class="input" placeholder="{{ __('Pluie, fête, panne de courant…') }}"></div>
                        <div class="flex justify-end"><button class="btn-primary">{{ __('Enregistrer') }}</button></div>
                    </form>
                @elseif ($record && $record->total !== null)
                    <p class="text-3xl font-semibold text-ink-800 tabular">{{ $record->total }}</p>
                    <p class="text-sm text-sand-700">{{ collect(['men' => __('hommes'), 'women' => __('femmes'), 'children' => __('enfants'), 'visitors' => __('visiteurs')])->filter(fn ($l, $k) => $record->$k !== null)->map(fn ($l, $k) => $record->$k.' '.$l)->implode(' · ') }}</p>
                @else
                    <p class="text-sm text-sand-700">{{ __('Pas encore noté.') }}</p>
                @endif
            </section>

            {{-- Pointage nominatif --}}
            <section class="card p-5 sm:p-6">
                <div class="mb-1 flex items-center justify-between gap-2">
                    <h2 class="text-lg">{{ __('Pointage des membres') }}</h2>
                    <span class="badge bg-leaf-50 text-leaf-600 tabular">{{ trans_choice(':count pointé|:count pointés', count($checked)) }}</span>
                </div>
                @if ($day->isFuture())
                    <p class="text-sm text-sand-700">{{ __('Le pointage se fait le jour même ou après.') }}</p>
                @else
                    @if ($canRecord)
                        <p class="mb-3 text-sm text-sand-700">{{ __('Facultatif : cherchez un membre et touchez son nom pour le pointer.') }}</p>
                        <input wire:model.live.debounce.250ms="checkinSearch" type="search" class="input" placeholder="{{ __('Nom, numéro ou téléphone') }}" aria-label="{{ __('Rechercher un membre') }}">
                        @if ($checkinCandidates->isNotEmpty())
                            <ul class="mt-2 space-y-1">
                                @foreach ($checkinCandidates as $c)
                                    @php $on = in_array($c->id, $checked, true); @endphp
                                    <li wire:key="cc-{{ $c->id }}"><button type="button" wire:click="toggleCheckin({{ $c->id }})" @class(['flex w-full items-center gap-3 rounded-xl px-3 py-2 text-left text-sm', 'bg-leaf-50' => $on, 'hover:bg-sand-100' => ! $on])>
                                        <x-icon :name="$on ? 'circle-check' : 'plus'" @class(['size-5', 'text-leaf-600' => $on, 'text-ink-500' => ! $on]) />
                                        <span class="flex-1 font-semibold text-ink-700">{{ $c->officialName() }}</span>
                                        <span class="font-mono text-xs text-sand-700">{{ $c->number }}</span>
                                    </button></li>
                                @endforeach
                            </ul>
                        @endif
                    @endif
                    @if ($checkedMembers->isNotEmpty())
                        <ul class="mt-3 flex flex-wrap gap-1.5">
                            @foreach ($checkedMembers as $m)
                                <li wire:key="ck-{{ $m->id }}" class="inline-flex items-center gap-1 rounded-full bg-leaf-50 py-1 pl-3 pr-1 text-sm text-leaf-700">
                                    {{ $m->fullName() }}
                                    @if ($canRecord)<button type="button" wire:click="toggleCheckin({{ $m->id }})" class="rounded-full p-1 hover:bg-white" aria-label="{{ __('Retirer') }}"><x-icon name="x" class="size-3.5" /></button>@endif
                                </li>
                            @endforeach
                        </ul>
                    @elseif (! $canRecord)
                        <p class="text-sm text-sand-700">{{ __('Personne n’a été pointé.') }}</p>
                    @endif
                @endif
            </section>

            {{-- Visiteurs --}}
            @if (! $day->isFuture())
                <section class="card p-5 sm:p-6">
                    <h2 class="mb-1 text-lg">{{ __('Visiteurs') }}</h2>
                    <p class="mb-3 text-sm text-sand-700">{{ __('Notez ceux qui viennent pour la première fois : on les accueille, puis on les revoit dans la semaine.') }}</p>
                    <ul class="mb-3 divide-y divide-sand-100">
                        @forelse ($record?->namedVisitors ?? [] as $v)
                            <li class="flex flex-wrap items-center gap-2 py-2.5" wire:key="v-{{ $v->id }}">
                                <span class="min-w-0 flex-1 basis-40">
                                    <span class="block font-semibold text-ink-800">{{ $v->name }}</span>
                                    <span class="block text-sm text-sand-700">{{ collect([Phone::format($v->phone), $v->invited_by ? __('invité par :n', ['n' => $v->invited_by]) : null])->filter()->implode(' · ') }}</span>
                                </span>
                                @if ($v->phone)<a href="tel:{{ $v->phone }}" class="rounded-lg p-2 text-ink-600 hover:bg-sand-100" aria-label="{{ __('Appeler') }}"><x-icon name="phone" class="size-4" /></a>@endif
                                @if ($canRecord)
                                    <button type="button" wire:click="followedUp({{ $v->id }})" @class(['badge', 'bg-leaf-50 text-leaf-600' => $v->followed_up_at, 'bg-ochre-100 text-ochre-700' => ! $v->followed_up_at])>{{ $v->followed_up_at ? __('Revu') : __('À revoir') }}</button>
                                    <button type="button" wire:click="removeVisitor({{ $v->id }})" wire:confirm="{{ __('Retirer ce visiteur ?') }}" class="rounded-lg p-2 text-sand-500 hover:bg-terra-50 hover:text-terra-600" aria-label="{{ __('Retirer') }}"><x-icon name="x" class="size-4" /></button>
                                @endif
                            </li>
                        @empty
                            <li class="py-2 text-sm text-sand-700">{{ __('Aucun visiteur noté.') }}</li>
                        @endforelse
                    </ul>
                    @if ($canRecord)
                        <form wire:submit="addVisitor" class="grid gap-2 sm:grid-cols-2">
                            <div class="sm:col-span-2"><input wire:model="visitor.name" class="input" placeholder="{{ __('Nom du visiteur') }}" aria-label="{{ __('Nom du visiteur') }}">@error('visitor.name') <p class="error">{{ $message }}</p> @enderror</div>
                            <input wire:model="visitor.phone" type="tel" class="input" placeholder="{{ __('Téléphone') }}" aria-label="{{ __('Téléphone') }}">
                            <input wire:model="visitor.invited_by" class="input" placeholder="{{ __('Invité par') }}" aria-label="{{ __('Invité par') }}">
                            <div class="flex justify-end sm:col-span-2"><button class="btn-secondary"><x-icon name="user-plus" class="size-4" /> {{ __('Ajouter le visiteur') }}</button></div>
                        </form>
                    @endif
                </section>
            @endif
        @endif

        {{-- Inscriptions --}}
        @if ($event->registration && ! $skipped)
            <section class="card p-5 sm:p-6">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-lg">{{ __('Inscriptions') }}</h2>
                    <span class="badge bg-ink-50 text-ink-700 tabular">{{ $registrations->count() }}@if ($event->capacity) / {{ $event->capacity }}@endif</span>
                </div>
                @if ($me && ! $registered && $day->gte(today()))
                    <button type="button" wire:click="registerMe" class="btn-primary mb-3 w-full justify-center"><x-icon name="check" class="size-4" /> {{ __('M’inscrire') }}</button>
                @endif
                @error('registrationSearch') <p class="error mb-2">{{ $message }}</p> @enderror
                <ol class="mb-3 divide-y divide-sand-100">
                    @forelse ($registrations as $i => $r)
                        <li class="flex items-center gap-3 py-2" wire:key="r-{{ $r->id }}">
                            <span class="w-6 text-right text-xs text-sand-600 tabular">{{ $i + 1 }}</span>
                            <span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold text-ink-800">{{ $r->displayName() }}</span>@if ($r->phone)<span class="block text-xs text-sand-700">{{ Phone::format($r->phone) }}</span>@endif</span>
                            @if ($canRegisterOthers || ($me && $r->member_id === $me->id))
                                <button type="button" wire:click="unregister({{ $r->id }})" wire:confirm="{{ __('Retirer cette inscription ?') }}" class="rounded-lg p-2 text-sand-500 hover:bg-terra-50 hover:text-terra-600" aria-label="{{ __('Retirer') }}"><x-icon name="x" class="size-4" /></button>
                            @endif
                        </li>
                    @empty
                        <li class="py-2 text-sm text-sand-700">{{ __('Personne n’est encore inscrit.') }}</li>
                    @endforelse
                </ol>
                @if ($canRegisterOthers)
                    <input wire:model.live.debounce.300ms="registrationSearch" type="search" class="input" placeholder="{{ __('Inscrire un membre : nom ou numéro') }}" aria-label="{{ __('Inscrire un membre') }}">
                    <ul class="mt-1 space-y-1">@foreach ($registrationCandidates as $c)<li><button type="button" wire:click="registerMember({{ $c->id }})" class="w-full rounded-xl px-3 py-2 text-left text-sm hover:bg-sand-100"><span class="font-semibold text-ink-700">{{ $c->officialName() }}</span></button></li>@endforeach</ul>
                    <form wire:submit="registerGuest" class="mt-3 grid gap-2 sm:grid-cols-[2fr_1.4fr_auto]">
                        <input wire:model="guest.name" class="input" placeholder="{{ __('… ou un invité : nom') }}" aria-label="{{ __('Nom de l’invité') }}">
                        <input wire:model="guest.phone" type="tel" class="input" placeholder="{{ __('Téléphone') }}" aria-label="{{ __('Téléphone de l’invité') }}">
                        <button class="btn-secondary">{{ __('Inscrire') }}</button>
                        @error('guest.name') <p class="error sm:col-span-3">{{ $message }}</p> @enderror
                    </form>
                @endif
            </section>
        @endif
    </div>

    @if ($canEdit)
        @include('livewire.events.partials.form-modal')
    @endif
</div>
