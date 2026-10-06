@php use App\Models\Group; use App\Models\GroupMeeting; use App\Support\Phone; @endphp
<div>
    <a href="{{ route('groups.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Groupes') }}</a>

    <section class="wax wax-veil wax-veil-strong mb-5 overflow-hidden rounded-[22px] p-5 text-white sm:p-6">
        <div class="flex flex-wrap items-center gap-4">
            <span class="icon-tile size-14 bg-white/15 text-ochre-300"><x-icon name="handshake" class="size-7" /></span>
            <div class="min-w-0 flex-1">
                <p class="text-sm text-ochre-300">{{ __(Group::KINDS[$group->kind]) }}@if ($group->department) · {{ $group->department->name }}@endif @unless ($group->is_active) · {{ __('En sommeil') }}@endunless</p>
                <h1 class="text-2xl font-semibold text-white">{{ $group->name }}</h1>
                @if ($group->schedule())<p class="mt-1 flex items-center gap-1.5 text-sm text-ink-100"><x-icon name="calendar" class="size-4" /> {{ $group->schedule() }}</p>@endif
            </div>
            @if ($canSetUp)
                <div class="flex w-full flex-wrap gap-2 sm:w-auto">
                    <button type="button" wire:click="edit" class="btn-accent !min-h-0 !py-2"><x-icon name="pencil" class="size-4" /> {{ __('Modifier') }}</button>
                    <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                        <button type="button" @click="open = ! open" class="btn !min-h-0 bg-white/15 !px-3 !py-2 text-white hover:bg-white/25" aria-label="{{ __('Plus d’actions') }}"><x-icon name="ellipsis" class="size-4" /></button>
                        <div x-cloak x-show="open" x-transition class="absolute right-0 z-20 mt-2 w-64 rounded-2xl border border-sand-200 bg-white p-1.5 text-ink-800 shadow-xl">
                            <button type="button" wire:click="toggleActive" class="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left text-sm hover:bg-sand-50">
                                <x-icon :name="$group->is_active ? 'archive' : 'archive-restore'" class="size-4" /> {{ $group->is_active ? __('Mettre en sommeil') : __('Réactiver') }}
                            </button>
                            <button type="button" wire:click="delete" wire:confirm="{{ __('Supprimer ce groupe et ses rencontres ?') }}" class="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left text-sm text-terra-600 hover:bg-terra-50">
                                <x-icon name="trash-2" class="size-4" /> {{ __('Supprimer') }}
                            </button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
        <dl class="mt-5 grid grid-cols-3 gap-3 border-t border-white/15 pt-4 text-center">
            <div><dt class="text-xs text-ink-100">{{ __('Personnes') }}</dt><dd class="text-xl font-semibold tabular">{{ $people->count() }}</dd></div>
            <div><dt class="text-xs text-ink-100">{{ __('Présence moyenne') }}</dt><dd class="text-xl font-semibold tabular">{{ $rate !== null ? $rate.' %' : '—' }}</dd></div>
            <div><dt class="text-xs text-ink-100">{{ __('Rencontres') }}</dt><dd class="text-xl font-semibold tabular">{{ $meetings->total() }}</dd></div>
        </dl>
    </section>

    <div class="grid gap-5 lg:grid-cols-[1.3fr_1fr]">
        <div class="space-y-5">
            @if ($absentees->isNotEmpty())
                <section class="rounded-[18px] border border-ochre-300 bg-ochre-50 p-5">
                    <h2 class="mb-1 flex items-center gap-2 text-base"><x-icon name="heart-handshake" class="size-5 text-ochre-600" /> {{ __('À visiter') }}</h2>
                    <p class="mb-3 text-sm text-sand-700">{{ __('Absents aux trois dernières rencontres, sans s’être excusés.') }}</p>
                    <ul class="space-y-2">
                        @foreach ($absentees as $m)
                            <li class="flex items-center gap-3">
                                <a href="{{ route('members.show', $m) }}" class="min-w-0 flex-1 text-sm font-semibold text-ink-800 hover:underline">{{ $m->officialName() }}</a>
                                @if ($m->phone)<a href="tel:{{ $m->phone }}" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-sm text-ink-700 hover:bg-white tabular"><x-icon name="phone" class="size-4" /> {{ Phone::format($m->phone) }}</a>@endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($group->dues_amount)
                <section class="card min-w-0 overflow-hidden">
                    <div class="px-5 pb-2 pt-5 sm:px-6">
                        <h2 class="text-lg">{{ __('Cotisations') }}</h2>
                        <p class="text-sm text-sand-700">{{ __(':m par mois. Touchez une case pour marquer la cotisation payée.', ['m' => \App\Support\Money::format($group->dues_amount, $group->dues_currency)]) }}</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead><tr class="text-left text-xs text-sand-700"><th class="px-5 py-2 font-semibold sm:px-6">{{ __('Personne') }}</th>@foreach ($periods as $p)<th class="px-2 py-2 text-center font-semibold">{{ ucfirst($p->translatedFormat('M')) }}</th>@endforeach</tr></thead>
                            <tbody class="divide-y divide-sand-100">
                                @foreach ($people as $m)
                                    <tr wire:key="due-{{ $m->id }}">
                                        <td class="max-w-40 truncate px-5 py-2 font-semibold text-ink-700 sm:px-6">{{ $m->fullName() }}</td>
                                        @foreach ($periods as $p)
                                            @php $paid = isset($dues[$m->id.'|'.$p->format('Y-m')]); @endphp
                                            <td class="px-2 py-1.5 text-center">
                                                <button type="button" @if ($canManage) wire:click="toggleDue({{ $m->id }}, '{{ $p->format('Y-m') }}')" @else disabled @endif
                                                    @class(['grid size-8 mx-auto place-items-center rounded-lg', 'bg-leaf-500 text-white' => $paid, 'bg-sand-100 text-sand-400' => ! $paid])
                                                    aria-label="{{ $paid ? __(':p a payé :m', ['p' => $m->fullName(), 'm' => $p->translatedFormat('F')]) : __(':p n’a pas payé :m', ['p' => $m->fullName(), 'm' => $p->translatedFormat('F')]) }}">
                                                    <x-icon :name="$paid ? 'check' : 'x'" class="size-4" />
                                                </button>
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot><tr class="border-t-2 border-sand-200 text-xs text-sand-700"><td class="px-5 py-2 sm:px-6">{{ __('Reçu') }}</td>@foreach ($periods as $p)<td class="px-2 py-2 text-center font-semibold text-ink-700 tabular">{{ \App\Support\Money::format($dues->filter(fn ($d, $k) => str_ends_with($k, '|'.$p->format('Y-m')))->flatten()->sum('amount'), $group->dues_currency) }}</td>@endforeach</tr></tfoot>
                        </table>
                    </div>
                </section>
            @endif

            <section class="card p-5 sm:p-6">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-lg">{{ __('Rencontres') }}</h2>
                    @if ($canManage)<button type="button" wire:click="openMeeting" class="btn-primary !min-h-0 !py-2"><x-icon name="check" class="size-4" /> {{ __('Noter une rencontre') }}</button>@endif
                </div>
                <ul class="divide-y divide-sand-100">
                    @forelse ($meetings as $mt)
                        @php $total = $mt->attendances->count(); $present = $mt->presentCount(); @endphp
                        <li wire:key="mt-{{ $mt->id }}">
                            <button type="button" @if ($canManage) wire:click="openMeeting({{ $mt->id }})" @else disabled @endif class="flex w-full items-center gap-3 py-3 text-left">
                                <span class="grid size-12 shrink-0 place-items-center rounded-xl bg-ink-50 text-center leading-none text-ink-700"><span class="text-lg font-semibold">{{ $mt->held_on->format('d') }}</span><span class="text-[10px] uppercase">{{ $mt->held_on->translatedFormat('M') }}</span></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-semibold text-ink-800">{{ $mt->topic ?: $mt->held_on->translatedFormat('l j F Y') }}</span>
                                    <span class="mt-1 block h-1.5 overflow-hidden rounded-full bg-sand-100"><span class="block h-full rounded-full bg-leaf-500" style="width: {{ $total ? round($present / $total * 100) : 0 }}%"></span></span>
                                    <span class="mt-1 block text-xs text-sand-700 tabular">{{ __(':p présents sur :t', ['p' => $present, 't' => $total]) }}@if ($mt->visitors) · {{ trans_choice(':count visiteur|:count visiteurs', $mt->visitors) }}@endif</span>
                                </span>
                                @if ($canManage)<x-icon name="chevron-right" class="size-4 text-sand-400" />@endif
                            </button>
                        </li>
                    @empty
                        <li class="py-3 text-sm text-sand-700">{{ __('Aucune rencontre notée. Après chaque rencontre, cochez les présents : Waumini signale ceux qui s’absentent.') }}</li>
                    @endforelse
                </ul>
                <div class="mt-3">{{ $meetings->links() }}</div>
            </section>
        </div>

        <div class="space-y-5">
            <section class="card p-5 sm:p-6">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h2 class="whitespace-nowrap text-lg">{{ trans_choice(':count personne|:count personnes', $people->count()) }}</h2>
                    @if ($canSetUp)<button type="button" @click="$dispatch('open-modal', { name: 'leader' })" class="btn-ghost !min-h-0 !py-1.5 text-sm">{{ __('Changer de responsable') }}</button>@endif
                </div>
                <ul class="divide-y divide-sand-100">
                    @foreach ($people as $m)
                        @php $isLeader = $m->id === $group->leader_member_id; @endphp
                        <li class="flex flex-wrap items-center gap-3 py-3" wire:key="gm-{{ $m->id }}">
                            <a href="{{ route('members.show', $m) }}" class="flex min-w-0 flex-1 basis-full items-center gap-3 sm:basis-60">
                                @include('livewire.members.partials.avatar', ['member' => $m, 'size' => 'size-10 text-sm'])
                                <span class="min-w-0">
                                    <span class="block truncate font-semibold text-ink-700">{{ $m->officialName() }}</span>
                                    <span class="block whitespace-nowrap text-sm text-sand-700 tabular">{{ Phone::format($m->phone) ?: $m->number }}</span>
                                </span>
                            </a>
                            @if ($isLeader)
                                <span class="badge ml-[52px] bg-ochre-100 text-ochre-700 sm:ml-auto"><x-icon name="badge-check" class="size-3.5" /> {{ __('Responsable') }}</span>
                            @elseif ($canManage)
                                <div class="ml-[52px] flex items-center gap-1 sm:ml-auto">
                                    <select wire:change="setRole({{ $m->id }}, $event.target.value)" class="input !min-h-0 w-auto !py-1.5 text-sm" aria-label="{{ __('Rôle dans le groupe') }}">
                                        @foreach (Group::ROLES as $key => $label)<option value="{{ $key }}" @selected($m->pivot->role === $key)>{{ __($label) }}</option>@endforeach
                                    </select>
                                    <button type="button" wire:click="removeMember({{ $m->id }})" wire:confirm="{{ __('Retirer :name du groupe ?', ['name' => $m->fullName()]) }}" class="rounded-lg p-2 text-sand-500 hover:bg-terra-50 hover:text-terra-600" aria-label="{{ __('Retirer') }}"><x-icon name="x" class="size-4" /></button>
                                </div>
                            @else
                                <span class="badge bg-sand-100 text-sand-700">{{ __(Group::ROLES[$m->pivot->role] ?? '') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>

            @if ($canManage)
                <section class="card p-5 sm:p-6">
                    <h2 class="mb-3 text-lg">{{ __('Ajouter un membre') }}</h2>
                    <div class="space-y-3">
                        <input wire:model.live.debounce.300ms="memberSearch" type="search" class="input" placeholder="{{ __('Nom, numéro ou téléphone') }}" aria-label="{{ __('Rechercher un membre') }}">
                        <select wire:model="newRole" class="input" aria-label="{{ __('Rôle dans le groupe') }}">
                            @foreach (Group::ROLES as $key => $label)<option value="{{ $key }}">{{ __($label) }}</option>@endforeach
                        </select>
                        <ul class="space-y-1">
                            @foreach ($candidates as $c)
                                <li><button type="button" wire:click="addMember({{ $c->id }})" class="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left hover:bg-sand-100">
                                    @include('livewire.members.partials.avatar', ['member' => $c, 'size' => 'size-8 text-[11px]'])
                                    <span class="flex-1 text-sm"><span class="font-semibold text-ink-700">{{ $c->officialName() }}</span> <span class="font-mono text-xs text-sand-700">{{ $c->number }}</span></span>
                                    <x-icon name="plus" class="size-4 text-ink-600" />
                                </button></li>
                            @endforeach
                            @if (trim($memberSearch) !== '' && $candidates->isEmpty())<li class="text-sm text-sand-700">{{ __('Aucun membre ne correspond.') }}</li>@endif
                        </ul>
                    </div>
                </section>
            @endif
            @if ($group->description)<p class="rounded-[18px] border border-dashed border-sand-300 p-5 text-sm text-sand-700">{{ $group->description }}</p>@endif
        </div>
    </div>

    @if ($canSetUp)
        @include('livewire.groups.partials.form-modal', ['title' => __('Modifier le groupe'), 'withLeader' => false])

        <x-modal name="leader" :title="__('Changer de responsable')">
            <p class="mb-3 text-sm text-sand-700">{{ __('Le groupe a toujours un responsable. L’actuel, :name, reste dans le groupe comme membre.', ['name' => $group->leader?->fullName()]) }}</p>
            <input wire:model.live.debounce.300ms="leaderSearch" type="search" class="input" placeholder="{{ __('Membre : nom, numéro ou téléphone') }}" aria-label="{{ __('Rechercher le nouveau responsable') }}">
            <ul class="mt-2 space-y-1">
                @foreach ($leaderCandidates as $c)
                    <li><button type="button" wire:click="changeLeader({{ $c->id }})" wire:confirm="{{ __(':name devient responsable du groupe ?', ['name' => $c->fullName()]) }}" class="w-full rounded-xl px-3 py-2 text-left text-sm hover:bg-sand-100"><span class="font-semibold text-ink-700">{{ $c->officialName() }}</span> <span class="font-mono text-xs text-sand-700">{{ $c->number }}</span></button></li>
                @endforeach
            </ul>
        </x-modal>
    @endif

    @if ($canManage)
        <x-modal name="meeting" :title="__('Rencontre du groupe')">
            <form wire:submit="saveMeeting" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-[1fr_2fr]">
                    <div><label for="m-date" class="label">{{ __('Date') }}</label><input wire:model="meeting.held_on" id="m-date" type="date" max="{{ today()->toDateString() }}" class="input">@error('meeting.held_on') <p class="error">{{ $message }}</p> @enderror</div>
                    <div><label for="m-topic" class="label">{{ __('Thème') }} <span class="font-normal text-sand-700">{{ __('(facultatif)') }}</span></label><input wire:model="meeting.topic" id="m-topic" class="input" placeholder="{{ __('Exemple : la prière, Matthieu 6') }}"></div>
                </div>
                <div>
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <p class="label !mb-0">{{ __('Présences') }}</p>
                        <button type="button" wire:click="allPresent" class="btn-ghost !min-h-0 !py-1 text-sm">{{ __('Tous présents') }}</button>
                    </div>
                    <ul class="max-h-80 divide-y divide-sand-100 overflow-y-auto rounded-xl border border-sand-200">
                        @foreach ($people as $m)
                            <li class="flex flex-wrap items-center gap-2 px-3 py-2" wire:key="att-{{ $m->id }}">
                                <span class="min-w-0 flex-1 basis-40 truncate text-sm font-semibold text-ink-700">{{ $m->officialName() }}</span>
                                <span class="flex gap-1" role="radiogroup" aria-label="{{ $m->fullName() }}">
                                    @foreach (GroupMeeting::STATUSES as $status => $label)
                                        <label class="cursor-pointer">
                                            <input type="radio" wire:model="attendance.{{ $m->id }}" value="{{ $status }}" class="peer sr-only">
                                            <span @class(['block rounded-lg border px-2.5 py-1 text-xs font-semibold border-sand-300 text-sand-700',
                                                'peer-checked:border-leaf-500 peer-checked:bg-leaf-500 peer-checked:text-white' => $status === 'present',
                                                'peer-checked:border-ochre-500 peer-checked:bg-ochre-500 peer-checked:text-white' => $status === 'excused',
                                                'peer-checked:border-terra-600 peer-checked:bg-terra-600 peer-checked:text-white' => $status === 'absent'])>{{ __($label) }}</span>
                                        </label>
                                    @endforeach
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="grid gap-4 sm:grid-cols-[1fr_2fr]">
                    <div><label for="m-visitors" class="label">{{ __('Visiteurs') }}</label><input wire:model="meeting.visitors" id="m-visitors" type="number" min="0" class="input tabular">@error('meeting.visitors') <p class="error">{{ $message }}</p> @enderror</div>
                    <div><label for="m-notes" class="label">{{ __('Notes') }}</label><textarea wire:model="meeting.notes" id="m-notes" rows="2" class="input" placeholder="{{ __('Sujets de prière, nouvelles…') }}"></textarea></div>
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    @php $existing = $meetings->firstWhere(fn ($x) => $x->held_on->toDateString() === ($meeting['held_on'] ?? null)); @endphp
                    @if ($existing)<button type="button" wire:click="deleteMeeting({{ $existing->id }})" wire:confirm="{{ __('Supprimer cette rencontre et ses présences ?') }}" class="btn-ghost mr-auto text-terra-600">{{ __('Supprimer') }}</button>@endif
                    <button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'meeting' })">{{ __('Annuler') }}</button>
                    <button class="btn-primary">{{ __('Enregistrer') }}</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
