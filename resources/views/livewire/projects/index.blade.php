@php use App\Support\Money; use App\Models\Project; @endphp
<div>
    <x-page-header :title="__('Projets')" :description="__('Les projets de la communauté, sous sa vision : la parcelle, le temple, la convention… Pour chacun, ce qui est prévu chaque année, promis, reçu, dépensé, et où il en est.')">
        <x-slot:actions>
            @if ($canManage)<button type="button" wire:click="editProject" class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Nouveau projet') }}</button>@endif
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
                    <h1 class="text-xl font-semibold text-white">{{ __('La vision n’est pas encore écrite.') }}</h1>
                @endif
                <p class="mt-2 text-sm text-ink-100">{{ __('Avancement moyen des projets en cours : :p %', ['p' => $overall]) }}@if ($late) · <span class="font-semibold text-ochre-300">{{ trans_choice(':count projet en retard|:count projets en retard', $late) }}</span>@endif</p>
            </div>
        </div>
        @if ($canManage)
            <div class="mt-4"><button type="button" wire:click="editVision" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="pencil" class="size-4" /> {{ $currentVision ? __('Modifier la vision') : __('Écrire la vision') }}</button></div>
        @endif
    </section>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <div class="flex gap-1 rounded-2xl border border-sand-200 bg-white p-1" role="tablist">
            @foreach (['ouverts' => __('En cours et prévus'), 'annee' => __('Exercice'), 'finis' => __('Terminés')] as $key => $label)
                <button type="button" role="tab" wire:click="$set('state', '{{ $key }}')" aria-selected="{{ $state === $key ? 'true' : 'false' }}" @class(['rounded-xl px-3 py-1.5 text-sm font-semibold', 'bg-ink-700 text-white' => $state === $key, 'text-ink-600 hover:bg-sand-50' => $state !== $key])>{{ $label }}</button>
            @endforeach
        </div>
        @if ($state === 'annee')<select wire:model.live="year" class="input !w-auto" aria-label="{{ __('Exercice') }}">@foreach ($years as $y => $label)<option value="{{ $y }}">{{ $label }}</option>@endforeach</select>@endif
        <select wire:model.live="department" class="input !w-auto" aria-label="{{ __('Département') }}"><option value="">{{ __('Tous les départements') }}</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
    </div>

    <div class="space-y-6">
        @forelse ($themes as $theme => $items)
            <section wire:key="th-{{ md5($theme) }}">
                @if ($theme !== '')<h2 class="mb-2 text-sm font-semibold uppercase tracking-wider text-ochre-700">{{ $theme }}</h2>@endif
                <ul class="grid gap-3 lg:grid-cols-2">
                    @foreach ($items as $row)
                        @php $p = $row['project']; $t = $row['totals']; @endphp
                        <li wire:key="p-{{ $p->id }}">
                            <a href="{{ route('projects.show', $p) }}" @class(['card block h-full p-4 transition hover:border-ochre-300', 'border-terra-300' => $p->isLate(), 'opacity-70' => ! $p->isActive()])>
                                <div class="flex items-start gap-3">
                                    <span class="ring-progress size-12 shrink-0" style="--v: {{ $p->progress }}"><span class="size-9 text-[10px]">{{ $p->progress }} %</span></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block font-semibold text-ink-800">{{ $p->name }}</span>
                                        <span class="block text-xs text-sand-700">{{ collect([__(Project::KINDS[$p->kind] ?? ''), $p->span(), $p->department?->name, $p->responsibleName()])->filter()->implode(' · ') }}</span>
                                    </span>
                                    <span @class(['badge shrink-0', 'bg-ochre-100 text-ochre-700' => $p->status === 'ongoing', 'bg-ink-50 text-ink-700' => $p->status === 'planned', 'bg-leaf-50 text-leaf-600' => $p->status === 'done', 'bg-sand-100 text-sand-700' => $p->status === 'cancelled'])>{{ $p->isLate() ? __('En retard') : __(Project::STATUSES[$p->status]) }}</span>
                                </div>
                                @if ($t['goal'] || $t['received'] || $t['spent'])
                                    <div class="mt-3">
                                        @if ($t['goal'])
                                            <div class="mb-1.5 h-2 overflow-hidden rounded-full bg-sand-100"><div class="h-full rounded-full bg-leaf-500" style="width: {{ $t['percent'] }}%"></div></div>
                                        @endif
                                        <p class="flex flex-wrap gap-x-3 text-xs text-sand-700">
                                            @if ($t['goal'])<span>{{ __('Objectif :m', ['m' => Money::format($t['goal'], 'USD')]) }}</span>@endif
                                            <span class="text-leaf-600">{{ __('Reçu :m', ['m' => Money::format($t['received'] + $t['in_kind'], 'USD')]) }}</span>
                                            <span class="text-terra-600">{{ __('Dépensé :m', ['m' => Money::format($t['spent'], 'USD')]) }}</span>
                                            <span class="font-semibold text-ink-800">{{ __('Disponible :m', ['m' => Money::format($t['available'], 'USD')]) }}</span>
                                        </p>
                                    </div>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <div class="card p-8 text-center text-sand-700">{{ __('Aucun projet ici.') }}@if ($canManage) <button type="button" wire:click="editProject" class="font-semibold text-ink-700 underline">{{ __('Créer le premier projet') }}</button>@endif</div>
        @endforelse
    </div>

    @include('livewire.projects.partials.form')

    <x-modal name="vision" :title="__('La vision')">
        <form wire:submit="saveVision" class="space-y-4">
            <div><label for="v-title" class="label">{{ __('Vision') }}</label><input wire:model="vision.title" id="v-title" class="input" placeholder="{{ __('Une église qui grandit, forme ses jeunes et sert son quartier') }}">@error('vision.title') <p class="error">{{ $message }}</p> @enderror</div>
            <div><label for="v-text" class="label">{{ __('Ce qu’elle veut dire') }}</label><textarea wire:model="vision.statement" id="v-text" rows="3" class="input"></textarea></div>
            <div class="grid grid-cols-2 gap-4">
                <div><label for="v-from" class="label">{{ __('De') }}</label><input wire:model="vision.starts_year" id="v-from" type="number" class="input tabular"></div>
                <div><label for="v-to" class="label">{{ __('À') }}</label><input wire:model="vision.ends_year" id="v-to" type="number" class="input tabular">@error('vision.ends_year') <p class="error">{{ $message }}</p> @enderror</div>
            </div>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'vision' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Enregistrer') }}</button></div>
        </form>
    </x-modal>
</div>
