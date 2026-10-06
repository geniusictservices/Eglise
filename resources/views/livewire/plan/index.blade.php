@php use App\Support\Money; use App\Models\PlanAction; @endphp
<div>
    <x-page-header :title="__('Plan d’action')" :description="__('La vision de la communauté, ses objectifs pour l’exercice et les actions qui les réalisent, avec leur avancement.')">
        <x-slot:actions>
            <select wire:model.live="year" class="input !w-auto" aria-label="{{ __('Exercice') }}">@foreach ($years as $y => $label)<option value="{{ $y }}">{{ __('Exercice :y', ['y' => $label]) }}</option>@endforeach</select>
        </x-slot:actions>
    </x-page-header>

    {{-- La vision --}}
    <section class="wax wax-veil wax-veil-strong mb-5 overflow-hidden rounded-[22px] p-5 text-white sm:p-6">
        <div class="flex flex-wrap items-center gap-5">
            <span class="ring-progress size-20" style="--v: {{ $overall }}"><span class="size-14 text-sm">{{ $overall }} %</span></span>
            <div class="min-w-0 flex-1">
                @if ($currentVision)
                    <p class="text-sm text-ochre-300">{{ __('Vision :a-:b', ['a' => $currentVision->starts_year, 'b' => $currentVision->ends_year]) }}</p>
                    <h1 class="text-xl font-semibold text-white sm:text-2xl">{{ $currentVision->title }}</h1>
                    @if ($currentVision->statement)<p class="mt-1 max-w-3xl text-sm text-ink-100">{{ $currentVision->statement }}</p>@endif
                @else
                    <p class="text-sm text-ochre-300">{{ __('Exercice :y', ['y' => $yearLabel]) }}</p>
                    <h1 class="text-xl font-semibold text-white">{{ __('La vision n’est pas encore écrite.') }}</h1>
                @endif
                <p class="mt-2 text-sm text-ink-100">{{ trans_choice(':count objectif cette année|:count objectifs cette année', $objectives->count()) }} · {{ __('avancement moyen :p %', ['p' => $overall]) }}@if ($late) · <span class="font-semibold text-ochre-300">{{ trans_choice(':count action en retard|:count actions en retard', $late) }}</span>@endif</p>
            </div>
        </div>
        @if ($canManage)
            <div class="mt-4 flex flex-wrap gap-2">
                <button type="button" wire:click="editVision" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="pencil" class="size-4" /> {{ $currentVision ? __('Modifier la vision') : __('Écrire la vision') }}</button>
                <button type="button" wire:click="editObjective" class="btn-accent !min-h-0 !py-2"><x-icon name="plus" class="size-4" /> {{ __('Nouvel objectif') }}</button>
            </div>
        @endif
    </section>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <select wire:model.live="department" class="input !w-auto" aria-label="{{ __('Département') }}">
            <option value="">{{ __('Tous les départements') }}</option>
            @foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
        </select>
    </div>

    <div class="space-y-5">
        @forelse ($objectives as $o)
            <section wire:key="o-{{ $o->id }}" class="card p-5 sm:p-6">
                <div class="flex flex-wrap items-start gap-4">
                    <span class="ring-progress size-14 shrink-0" style="--v: {{ $o->progress() }}"><span class="size-10 text-xs">{{ $o->progress() }} %</span></span>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-lg">{{ $o->title }}</h2>
                        <p class="text-sm text-sand-700">{{ collect([$o->department?->name, $o->indicator ? __('Indicateur : :i', ['i' => $o->indicator]) : null])->filter()->implode(' · ') }}</p>
                        @if ($o->description)<p class="mt-1 text-sm text-ink-700">{{ $o->description }}</p>@endif
                    </div>
                    @if ($canManage)
                        <button type="button" wire:click="editObjective({{ $o->id }})" class="rounded-lg p-1.5 text-sand-500 hover:bg-sand-100" aria-label="{{ __('Modifier l’objectif') }}"><x-icon name="pencil" class="size-4" /></button>
                    @endif
                </div>

                <ul class="mt-4 space-y-2">
                    @forelse ($o->actions as $a)
                        <li wire:key="a-{{ $a->id }}" @class(['rounded-xl border p-3', 'border-terra-200 bg-terra-50/40' => $a->isLate(), 'border-sand-200' => ! $a->isLate(), 'opacity-60' => $a->status === 'cancelled'])>
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="min-w-0 flex-1 basis-full sm:basis-auto">
                                    <span @class(['block font-semibold text-ink-800', 'line-through' => $a->status === 'cancelled'])>{{ $a->title }}</span>
                                    <span class="block text-xs text-sand-700">
                                        {{ collect([$a->department?->name, $a->responsibleName(), $a->due_on ? __('pour le :d', ['d' => $a->due_on->translatedFormat('j M Y')]) : null, $a->estimated_cost !== null ? Money::format($a->estimated_cost, 'USD') : null])->filter()->implode(' · ') }}
                                    </span>
                                </span>
                                <span @class(['badge', 'bg-leaf-50 text-leaf-600' => $a->status === 'done', 'bg-ochre-100 text-ochre-700' => $a->status === 'ongoing' && ! $a->isLate(), 'bg-terra-50 text-terra-600' => $a->isLate(), 'bg-sand-100 text-sand-700' => in_array($a->status, ['planned', 'cancelled'], true) && ! $a->isLate()])>{{ $a->isLate() ? __('En retard') : __(PlanAction::STATUSES[$a->status]) }}</span>
                                @if ($updatable[$a->id] ?? false)
                                    <button type="button" wire:click="editProgress({{ $a->id }})" class="btn-ghost !min-h-0 !px-2 !py-1 text-sm">{{ __('Avancement') }}</button>
                                @endif
                                @if ($canManage)
                                    <button type="button" wire:click="editAction({{ $a->id }})" class="rounded-lg p-1.5 text-sand-500 hover:bg-sand-100" aria-label="{{ __('Modifier l’action') }}"><x-icon name="pencil" class="size-4" /></button>
                                @endif
                            </div>
                            <div class="mt-2 flex items-center gap-3">
                                <span class="block h-2 flex-1 overflow-hidden rounded-full bg-sand-100"><span @class(['block h-full rounded-full', 'bg-leaf-500' => $a->progress >= 100, 'bg-ochre-500' => $a->progress < 100]) style="width: {{ $a->progress }}%"></span></span>
                                <span class="w-10 text-right text-xs font-semibold tabular text-ink-700">{{ $a->progress }} %</span>
                            </div>
                            @if ($a->updates->first()?->note)<p class="mt-1 text-xs text-sand-700">« {{ $a->updates->first()->note }} » · {{ $a->updates->first()->user?->name }}, {{ $a->updates->first()->created_at->translatedFormat('j M') }}</p>@endif
                        </li>
                    @empty
                        <li class="text-sm text-sand-700">{{ __('Aucune action pour cet objectif.') }}</li>
                    @endforelse
                </ul>
                @if ($canManage)
                    <button type="button" wire:click="editAction(null, {{ $o->id }})" class="btn-secondary mt-3 !min-h-0 !py-1.5 text-sm"><x-icon name="plus" class="size-4" /> {{ __('Ajouter une action') }}</button>
                @endif
            </section>
        @empty
            <div class="card p-8 text-center text-sand-700">{{ __('Aucun objectif pour l’exercice :y.', ['y' => $yearLabel]) }}</div>
        @endforelse
    </div>

    <x-modal name="vision" :title="__('La vision')">
        <form wire:submit="saveVision" class="space-y-4">
            <div><label for="vs-title" class="label">{{ __('La vision en une phrase') }}</label><input wire:model="vision.title" id="vs-title" class="input" placeholder="{{ __('Exemple : une église qui grandit et sert son quartier') }}">@error('vision.title') <p class="error">{{ $message }}</p> @enderror</div>
            <div><label for="vs-text" class="label">{{ __('Explication') }}</label><textarea wire:model="vision.statement" id="vs-text" rows="4" class="input"></textarea></div>
            <div class="grid grid-cols-2 gap-4">
                <div><label for="vs-from" class="label">{{ __('De') }}</label><input wire:model="vision.starts_year" id="vs-from" type="number" class="input"></div>
                <div><label for="vs-to" class="label">{{ __('À') }}</label><input wire:model="vision.ends_year" id="vs-to" type="number" class="input">@error('vision.ends_year') <p class="error">{{ $message }}</p> @enderror</div>
            </div>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'vision' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Enregistrer') }}</button></div>
        </form>
    </x-modal>

    <x-modal name="objective" :title="$objectiveId ? __('Modifier l’objectif') : __('Nouvel objectif')">
        <form wire:submit="saveObjective" class="space-y-4">
            <div><label for="ob-title" class="label">{{ __('Objectif') }}</label><input wire:model="objective.title" id="ob-title" class="input" placeholder="{{ __('Exemple : accueillir et intégrer les nouveaux venus') }}">@error('objective.title') <p class="error">{{ $message }}</p> @enderror</div>
            <div><label for="ob-ind" class="label">{{ __('Indicateur (facultatif)') }}</label><input wire:model="objective.indicator" id="ob-ind" class="input" placeholder="{{ __('Exemple : 60 nouveaux membres baptisés') }}"></div>
            <div><label for="ob-dept" class="label">{{ __('Département (facultatif)') }}</label><select wire:model="objective.department_id" id="ob-dept" class="input"><option value="">{{ __('Toute la communauté') }}</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div>
            <div><label for="ob-desc" class="label">{{ __('Description') }}</label><textarea wire:model="objective.description" id="ob-desc" rows="3" class="input"></textarea></div>
            <div class="flex flex-wrap justify-end gap-2">
                @if ($objectiveId)<button type="button" wire:click="deleteObjective({{ $objectiveId }})" wire:confirm="{{ __('Supprimer cet objectif et ses actions ?') }}" class="btn-ghost mr-auto text-terra-600">{{ __('Supprimer') }}</button>@endif
                <button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'objective' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Enregistrer') }}</button>
            </div>
        </form>
    </x-modal>

    <x-modal name="action" :title="$actionId ? __('Modifier l’action') : __('Nouvelle action')">
        <form wire:submit="saveAction" class="space-y-4">
            <div><label for="ac-title" class="label">{{ __('Action') }}</label><input wire:model="action.title" id="ac-title" class="input" placeholder="{{ __('Exemple : cours des nouveaux convertis, un samedi sur deux') }}">@error('action.title') <p class="error">{{ $message }}</p> @enderror</div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label for="ac-dept" class="label">{{ __('Département') }}</label><select wire:model="action.department_id" id="ac-dept" class="input"><option value="">{{ __('Toute la communauté') }}</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div>
                <div><label for="ac-status" class="label">{{ __('État') }}</label><select wire:model="action.status" id="ac-status" class="input">@foreach (PlanAction::STATUSES as $k => $l)<option value="{{ $k }}">{{ __($l) }}</option>@endforeach</select></div>
            </div>
            <div>
                <p class="label">{{ __('Responsable') }}</p>
                @if ($responsible)
                    <div class="flex items-center gap-3 rounded-xl bg-ochre-50 px-3 py-2"><span class="flex-1 text-sm font-semibold text-ink-800">{{ $responsible->officialName() }}</span>
                        <button type="button" wire:click="$set('action.responsible_member_id', null)" class="rounded-lg p-1.5 text-sand-500 hover:bg-white" aria-label="{{ __('Changer') }}"><x-icon name="x" class="size-4" /></button></div>
                @else
                    <input wire:model.live.debounce.300ms="responsibleSearch" type="search" class="input" placeholder="{{ __('Membre : nom ou numéro') }}" aria-label="{{ __('Rechercher le responsable') }}">
                    <ul class="mt-1 space-y-1">@foreach ($candidates as $c)<li><button type="button" wire:click="chooseResponsible({{ $c->id }})" class="w-full rounded-xl px-3 py-2 text-left text-sm hover:bg-sand-100"><span class="font-semibold text-ink-700">{{ $c->officialName() }}</span></button></li>@endforeach</ul>
                    <input wire:model="action.responsible_name" class="input mt-2" placeholder="{{ __('… ou un nom') }}" aria-label="{{ __('Nom du responsable') }}">
                @endif
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <div><label for="ac-start" class="label">{{ __('Début') }}</label><input wire:model="action.starts_on" id="ac-start" type="date" class="input"></div>
                <div><label for="ac-due" class="label">{{ __('Échéance') }}</label><input wire:model="action.due_on" id="ac-due" type="date" class="input">@error('action.due_on') <p class="error">{{ $message }}</p> @enderror</div>
                <div><label for="ac-cost" class="label">{{ __('Coût estimé (USD)') }}</label><input wire:model="action.estimated_cost" id="ac-cost" type="number" step="0.01" min="0" class="input tabular"></div>
            </div>
            <div><label for="ac-desc" class="label">{{ __('Description') }}</label><textarea wire:model="action.description" id="ac-desc" rows="2" class="input"></textarea></div>
            <p class="hint">{{ __('Le coût se finance par une ligne du budget : prévoyez-la dans la proposition du département.') }}</p>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'action' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Enregistrer') }}</button></div>
        </form>
    </x-modal>

    <x-modal name="progress" :title="__('Avancement')">
        <form wire:submit="saveProgress" class="space-y-4">
            @if ($progressAction)<p class="font-semibold text-ink-800">{{ $progressAction->title }}</p>@endif
            <div x-data>
                <label for="pg-value" class="label">{{ __('Où en est l’action ?') }} <span class="font-semibold tabular" x-text="$wire.progress + ' %'"></span></label>
                <input wire:model="progress" id="pg-value" type="range" min="0" max="100" step="5" class="w-full accent-ochre-500">
            </div>
            <div><label for="pg-note" class="label">{{ __('Ce qui a été fait') }}</label><textarea wire:model="progressNote" id="pg-note" rows="3" class="input" placeholder="{{ __('Exemple : 12 participants au premier cours') }}"></textarea></div>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'progress' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Enregistrer') }}</button></div>
        </form>
    </x-modal>
</div>
