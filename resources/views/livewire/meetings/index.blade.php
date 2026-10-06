@php use App\Models\Meeting; @endphp
<div>
    <x-page-header :title="__('Réunions')" :description="__('Conseil, comités, départements, assemblée : les présents, l’ordre du jour, le procès-verbal et les décisions, suivies jusqu’à leur réalisation.')">
        <x-slot:actions>
            @if ($canManage)<button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Nouvelle réunion') }}</button>@endif
        </x-slot:actions>
    </x-page-header>

    @if ($openDecisions)
        <p class="mb-4 rounded-2xl border border-ochre-300 bg-ochre-50 p-4 text-sm font-semibold text-ink-800">{{ trans_choice(':count décision n’est pas encore réalisée.|:count décisions ne sont pas encore réalisées.', $openDecisions) }}</p>
    @endif

    @foreach (['upcoming' => __('À venir'), 'past' => __('Passées')] as $key => $title)
        <h2 class="mb-2 mt-5 text-base first:mt-0">{{ $title }}</h2>
        <ul class="space-y-2.5">
            @forelse ($$key as $m)
                <li wire:key="m-{{ $m->id }}">
                    <a href="{{ route('meetings.show', $m) }}" class="card flex flex-wrap items-center gap-x-4 gap-y-1 p-4 transition hover:border-ochre-300">
                        <span class="grid size-12 shrink-0 place-items-center rounded-xl bg-ink-50 text-center leading-none text-ink-700"><span class="text-lg font-semibold">{{ $m->held_at->format('d') }}</span><span class="text-[10px] uppercase">{{ $m->held_at->translatedFormat('M') }}</span></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-semibold text-ink-800">{{ $m->title }}</span>
                            <span class="block truncate text-sm text-sand-700">{{ __(Meeting::KINDS[$m->kind]) }}@if ($m->department) · {{ $m->department->name }}@endif · {{ $m->held_at->translatedFormat('l j F Y, H:i') }}@if ($m->place) · {{ $m->place }}@endif</span>
                        </span>
                        <span class="text-right text-xs text-sand-700">
                            @if ($m->participants_count){{ trans_choice(':count participant|:count participants', $m->participants_count) }}<br>@endif
                            @if ($m->decisions->isNotEmpty()){{ trans_choice(':count décision|:count décisions', $m->decisions->count()) }}@endif
                        </span>
                        <span @class(['badge', 'bg-leaf-50 text-leaf-600' => $m->status === 'held', 'bg-ochre-100 text-ochre-700' => $m->status === 'planned'])>{{ $m->status === 'held' ? __('Tenue') : __('Prévue') }}</span>
                    </a>
                </li>
            @empty
                <li class="card p-6 text-center text-sm text-sand-700">{{ $key === 'upcoming' ? __('Aucune réunion prévue.') : __('Aucune réunion passée.') }}</li>
            @endforelse
        </ul>
    @endforeach

    <x-modal name="meeting" :title="__('Nouvelle réunion')">
        <form wire:submit="save" class="space-y-4">
            <div><label for="mt-title" class="label">{{ __('Objet') }}</label><input wire:model="form.title" id="mt-title" class="input" placeholder="{{ __('Exemple : conseil de paroisse d’octobre') }}">@error('form.title') <p class="error">{{ $message }}</p> @enderror</div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label for="mt-kind" class="label">{{ __('Type') }}</label><select wire:model.live="form.kind" id="mt-kind" class="input">@foreach (Meeting::KINDS as $k => $l)<option value="{{ $k }}">{{ __($l) }}</option>@endforeach</select></div>
                @if (($form['kind'] ?? '') === 'department')
                    <div><label for="mt-dept" class="label">{{ __('Département') }}</label><select wire:model="form.department_id" id="mt-dept" class="input"><option value="">{{ __('Choisir…') }}</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>@error('form.department_id') <p class="error">{{ $message }}</p> @enderror</div>
                @endif
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label for="mt-date" class="label">{{ __('Date et heure') }}</label><input wire:model="form.held_at" id="mt-date" type="datetime-local" class="input">@error('form.held_at') <p class="error">{{ $message }}</p> @enderror</div>
                <div><label for="mt-place" class="label">{{ __('Lieu') }}</label><input wire:model="form.place" id="mt-place" class="input"></div>
            </div>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'meeting' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Créer') }}</button></div>
        </form>
    </x-modal>
</div>
