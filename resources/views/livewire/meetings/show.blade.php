@php use App\Models\Meeting; $m = $meeting; @endphp
<div>
    <a href="{{ route('meetings.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Réunions') }}</a>

    <section class="wax wax-veil wax-veil-strong mb-5 overflow-hidden rounded-[22px] p-5 text-white sm:p-6">
        <p class="text-sm text-ochre-300">{{ __(Meeting::KINDS[$m->kind]) }}@if ($m->department) · {{ $m->department->name }}@endif · {{ $m->status === 'held' ? __('Tenue') : __('Prévue') }}</p>
        <h1 class="text-2xl font-semibold text-white">{{ $m->title }}</h1>
        <p class="text-sm text-ink-100">{{ $m->held_at->translatedFormat('l j F Y, H:i') }}@if ($m->place) · {{ $m->place }}@endif</p>
        <div class="mt-4 flex flex-wrap gap-2">
            @if ($canManage)<button type="button" wire:click="markHeld" class="btn-accent !min-h-0 !py-2"><x-icon name="check" class="size-4" /> {{ $m->status === 'held' ? __('Remettre à « prévue »') : __('La réunion s’est tenue') }}</button>@endif
            <a href="{{ route('meetings.print', $m) }}" target="_blank" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="printer" class="size-4" /> {{ __('Procès-verbal') }}</a>
        </div>
    </section>

    <div class="grid gap-5 lg:grid-cols-[1.3fr_1fr] lg:items-start">
        <div class="space-y-5">
            <section class="card space-y-4 p-5 sm:p-6">
                <h2 class="text-lg">{{ __('Ordre du jour et procès-verbal') }}</h2>
                @if ($canManage)
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label for="m-title" class="label">{{ __('Objet') }}</label><input wire:model.blur="form.title" id="m-title" class="input">@error('form.title') <p class="error">{{ $message }}</p> @enderror</div>
                        <div><label for="m-date" class="label">{{ __('Date et heure') }}</label><input wire:model.blur="form.held_at" id="m-date" type="datetime-local" class="input"></div>
                        <div><label for="m-place" class="label">{{ __('Lieu') }}</label><input wire:model.blur="form.place" id="m-place" class="input"></div>
                        <div><label for="m-chair" class="label">{{ __('Présidée par') }}</label><input wire:model.blur="form.chair" id="m-chair" class="input"></div>
                        <div class="sm:col-span-2"><label for="m-sec" class="label">{{ __('Secrétaire de séance') }}</label><input wire:model.blur="form.secretary" id="m-sec" class="input"></div>
                    </div>
                    <div><label for="m-agenda" class="label">{{ __('Ordre du jour') }}</label><textarea wire:model.blur="form.agenda" id="m-agenda" rows="4" class="input" placeholder="{{ __('1. Prière d’ouverture
2. Lecture du procès-verbal précédent
3. …') }}"></textarea></div>
                    <div><label for="m-minutes" class="label">{{ __('Procès-verbal') }}</label><textarea wire:model.blur="form.minutes" id="m-minutes" rows="10" class="input" placeholder="{{ __('Le déroulement de la réunion, point par point.') }}"></textarea></div>
                    <p class="hint">{{ __('Chaque champ s’enregistre dès que vous le quittez.') }}</p>
                @else
                    <dl class="space-y-3 text-sm">
                        @if ($m->chair)<div><dt class="font-semibold text-ink-700">{{ __('Présidée par') }}</dt><dd>{{ $m->chair }}</dd></div>@endif
                        @if ($m->agenda)<div><dt class="font-semibold text-ink-700">{{ __('Ordre du jour') }}</dt><dd class="whitespace-pre-line">{{ $m->agenda }}</dd></div>@endif
                        <div><dt class="font-semibold text-ink-700">{{ __('Procès-verbal') }}</dt><dd class="whitespace-pre-line">{{ $m->minutes ?: __('Pas encore rédigé.') }}</dd></div>
                    </dl>
                @endif
            </section>

            <section class="card p-5 sm:p-6">
                <h2 class="mb-3 text-lg">{{ __('Décisions') }}</h2>
                <ul class="divide-y divide-sand-100">
                    @forelse ($m->decisions as $d)
                        <li wire:key="d-{{ $d->id }}" class="flex items-start gap-3 py-3">
                            @if ($canManage)
                                <button type="button" wire:click="toggleDecision({{ $d->id }})" @class(['mt-0.5 grid size-6 shrink-0 place-items-center rounded-full border-2', 'border-leaf-500 bg-leaf-500 text-white' => $d->is_done, 'border-sand-300' => ! $d->is_done]) aria-label="{{ $d->is_done ? __('Marquer à faire') : __('Marquer réalisée') }}">@if ($d->is_done)<x-icon name="check" class="size-3.5" />@endif</button>
                            @else
                                <span @class(['mt-0.5 grid size-6 shrink-0 place-items-center rounded-full border-2', 'border-leaf-500 bg-leaf-500 text-white' => $d->is_done, 'border-sand-300' => ! $d->is_done])>@if ($d->is_done)<x-icon name="check" class="size-3.5" />@endif</span>
                            @endif
                            <div class="min-w-0 flex-1">
                                <p @class(['text-sm text-ink-800', 'line-through opacity-60' => $d->is_done])>{{ $d->text }}</p>
                                <p class="text-xs text-sand-700">{{ collect([$d->responsible, $d->due_on ? __('pour le :d', ['d' => $d->due_on->translatedFormat('j M Y')]) : null, $d->action ? __('action : :a', ['a' => $d->action->title]) : null])->filter()->implode(' · ') }}</p>
                            </div>
                            @if ($canManage)<button type="button" wire:click="removeDecision({{ $d->id }})" wire:confirm="{{ __('Supprimer cette décision ?') }}" class="rounded-lg p-1.5 text-sand-500 hover:bg-terra-50 hover:text-terra-600" aria-label="{{ __('Supprimer') }}"><x-icon name="trash-2" class="size-4" /></button>@endif
                        </li>
                    @empty
                        <li class="py-2 text-sm text-sand-700">{{ __('Aucune décision.') }}</li>
                    @endforelse
                </ul>
                @if ($canManage)
                    <form wire:submit="addDecision" class="mt-4 space-y-3 rounded-xl bg-sand-50 p-4">
                        <div><label for="dc-text" class="label">{{ __('Nouvelle décision') }}</label><textarea wire:model="decision.text" id="dc-text" rows="2" class="input" placeholder="{{ __('Exemple : la Jeunesse organise le tournoi de la paix en décembre') }}"></textarea>@error('decision.text') <p class="error">{{ $message }}</p> @enderror</div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div><label for="dc-resp" class="label">{{ __('Qui la porte') }}</label><input wire:model="decision.responsible" id="dc-resp" class="input"></div>
                            <div><label for="dc-due" class="label">{{ __('Pour le') }}</label><input wire:model="decision.due_on" id="dc-due" type="date" class="input"></div>
                        </div>
                        @if ($actions->isNotEmpty())
                            <div><label for="dc-action" class="label">{{ __('Action du plan liée (facultatif)') }}</label><select wire:model="decision.plan_action_id" id="dc-action" class="input"><option value="">{{ __('Aucune') }}</option>@foreach ($actions as $a)<option value="{{ $a->id }}">{{ $a->title }}</option>@endforeach</select></div>
                        @endif
                        <div class="flex justify-end"><button class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Ajouter la décision') }}</button></div>
                    </form>
                @endif
            </section>
        </div>

        <aside class="card p-5 sm:p-6">
            <h2 class="mb-1 text-lg">{{ __('Participants') }}</h2>
            <p class="mb-3 text-sm text-sand-700">{{ collect(Meeting::ATTENDANCE)->map(fn ($l, $k) => ($counts[$k] ?? 0).' '.mb_strtolower(__($l)).(($counts[$k] ?? 0) > 1 ? 's' : ''))->implode(' · ') }}</p>
            <ul class="divide-y divide-sand-100">
                @forelse ($m->participants as $p)
                    <li wire:key="p-{{ $p->id }}" class="flex items-center gap-2 py-2">
                        <span class="min-w-0 flex-1 truncate text-sm font-semibold text-ink-800">{{ $p->displayName() }}</span>
                        @if ($canManage)
                            <select wire:change="setAttendance({{ $p->id }}, $event.target.value)" class="input !w-auto !py-1 text-sm" aria-label="{{ __('Présence') }}">@foreach (Meeting::ATTENDANCE as $k => $l)<option value="{{ $k }}" @selected($p->attendance === $k)>{{ __($l) }}</option>@endforeach</select>
                            <button type="button" wire:click="removeParticipant({{ $p->id }})" class="rounded-lg p-1.5 text-sand-500 hover:bg-terra-50 hover:text-terra-600" aria-label="{{ __('Retirer') }}"><x-icon name="x" class="size-4" /></button>
                        @else
                            <span class="text-xs text-sand-700">{{ __(Meeting::ATTENDANCE[$p->attendance]) }}</span>
                        @endif
                    </li>
                @empty
                    <li class="py-2 text-sm text-sand-700">{{ __('Personne pour le moment.') }}</li>
                @endforelse
            </ul>
            @if ($canManage)
                <div class="mt-4 space-y-2">
                    <input wire:model.live.debounce.300ms="participantSearch" type="search" class="input" placeholder="{{ __('Ajouter un membre : nom ou numéro') }}" aria-label="{{ __('Rechercher un membre') }}">
                    <ul class="space-y-1">@foreach ($candidates as $c)<li><button type="button" wire:click="addParticipant({{ $c->id }})" class="w-full rounded-xl px-3 py-2 text-left text-sm hover:bg-sand-100"><span class="font-semibold text-ink-700">{{ $c->officialName() }}</span></button></li>@endforeach</ul>
                    <form wire:submit="addParticipant" class="flex gap-2"><input wire:model="participantName" class="input" placeholder="{{ __('… ou un invité') }}" aria-label="{{ __('Nom de l’invité') }}"><button class="btn-secondary !px-3" aria-label="{{ __('Ajouter') }}"><x-icon name="plus" class="size-4" /></button></form>
                    @error('participantName') <p class="error">{{ $message }}</p> @enderror
                    @if ($m->department_id)<button type="button" wire:click="addDepartmentMembers" class="btn-ghost w-full text-sm">{{ __('Ajouter tous les membres du département') }}</button>@endif
                </div>
            @endif
        </aside>
    </div>
</div>
