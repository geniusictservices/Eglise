@php use App\Support\Money; use App\Models\Project; @endphp
<div>
    <a href="{{ route('projects.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Projets') }}</a>

    <section class="wax wax-veil wax-veil-strong mb-5 overflow-hidden rounded-[22px] p-5 text-white sm:p-6">
        <div class="flex flex-wrap items-center gap-5">
            <span class="ring-progress size-20" style="--v: {{ $p->progress }}"><span class="size-14 text-sm">{{ $p->progress }} %</span></span>
            <div class="min-w-0 flex-1">
                <p class="text-sm text-ochre-300">{{ collect([__(Project::KINDS[$p->kind] ?? ''), $p->span(), $p->theme, $p->department?->name])->filter()->implode(' · ') }}</p>
                <h1 class="text-2xl font-semibold text-white">{{ $p->name }}</h1>
                <p class="mt-1 text-sm text-ink-100">
                    {{ $p->isLate() ? __('En retard') : __(Project::STATUSES[$p->status]) }}@if ($p->responsibleName()) · {{ __('Responsable : :n', ['n' => $p->responsibleName()]) }}@endif
                    @if ($p->ends_on) · {{ __('fin prévue le :d', ['d' => $p->ends_on->translatedFormat('j M Y')]) }}@endif
                </p>
                @if ($p->account)<p class="mt-1 text-sm text-ink-100"><x-icon name="wallet" class="mr-1 inline size-4" /> {{ __('Compte du projet : :a', ['a' => $p->account->name]) }}</p>@endif
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            @if ($canUpdate)<button type="button" wire:click="editProgress" class="btn-accent !min-h-0 !py-2"><x-icon name="refresh-cw" class="size-4" /> {{ __('Mettre à jour l’avancement') }}</button>@endif
            @if ($canPledge)<a href="{{ route('finances.pledges.create', ['projet' => $p->id]) }}" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="handshake" class="size-4" /> {{ __('Nouvelle promesse') }}</a>@endif
            @if ($canRecord)<button type="button" wire:click="openGift" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="hand-coins" class="size-4" /> {{ __('Recevoir un don') }}</button>@endif
            @if ($canRequest)<a href="{{ route('finances.expenses.create', ['projet' => $p->id]) }}" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="upload" class="size-4" /> {{ __('Demander une dépense') }}</a>@endif
            @if ($canManage)<button type="button" wire:click="editProject({{ $p->id }})" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="pencil" class="size-4" /> {{ __('Modifier') }}</button>@endif
        </div>
    </section>

    @if ($seesMoney)
        <section class="card mb-5 p-5 sm:p-6">
            @include('livewire.projects.partials.figures', ['totals' => $totals])
            @if ($totals['goal'])
                <div class="mt-3 h-2.5 overflow-hidden rounded-full bg-sand-100"><div class="h-full rounded-full bg-leaf-500" style="width: {{ $totals['percent'] }}%"></div></div>
                <p class="mt-1 text-xs text-sand-700">{{ __(':p % de l’objectif reçu (dons en nature compris)', ['p' => $totals['percent']]) }}@if ($totals['committed'] > 0) · {{ __(':m engagés (dépenses approuvées, pas encore payées)', ['m' => Money::format($totals['committed'], 'USD')]) }}@endif</p>
            @endif
        </section>
    @endif

    <div class="mb-5 flex gap-1 overflow-x-auto rounded-2xl border border-sand-200 bg-white p-1" role="tablist">
        @foreach (['annees' => __('Années'), 'promesses' => __('Promesses'), 'argent' => __('Recettes et dépenses'), 'avancement' => __('Avancement')] as $key => $label)
            <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" @class(['flex-1 whitespace-nowrap rounded-xl px-4 py-2 text-sm font-semibold', 'bg-ink-700 text-white' => $tab === $key, 'text-ink-600 hover:bg-sand-50' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'annees')
        <section class="card p-5 sm:p-6">
            <h2 class="mb-1 text-lg">{{ __('Année par année') }}</h2>
            <p class="mb-3 text-sm text-sand-700">{{ __('La tranche prévue de chaque exercice (reprise par le budget de l’année), ce qui a été reçu, ce que le budget ordinaire lui réserve, ce qui a été dépensé, et ce qui reste, reporté sur l’année suivante.') }}</p>
            @if ($yearRows->isEmpty())
                <p class="text-sm text-sand-700">{{ __('Aucune année prévue.') }}@if ($canManage) <button type="button" wire:click="editProject({{ $p->id }})" class="font-semibold text-ink-700 underline">{{ __('Prévoir les années') }}</button>@endif</p>
            @else
                {{-- Sur téléphone, une carte par exercice ; le tableau à partir de la tablette. --}}
                <ul class="space-y-3 sm:hidden">
                    @foreach ($yearRows as $y)
                        <li class="rounded-xl bg-sand-50 p-3 text-sm">
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="font-semibold text-ink-800">{{ $y['label'] }}</span>
                                <span @class(['font-semibold tabular', 'text-terra-600' => $y['balance'] < 0, 'text-ink-800' => $y['balance'] >= 0])>{{ __('Reste') }} {{ Money::format($y['balance'], 'USD') }}</span>
                            </div>
                            @if ($y['note'])<p class="text-xs text-sand-700">{{ $y['note'] }}</p>@endif
                            <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1">
                                @if ($y['carried'])<div class="col-span-2 flex items-baseline justify-between"><dt class="text-xs text-sand-700">{{ __('Reporté') }}</dt><dd class="tabular">{{ Money::format($y['carried'], 'USD') }}</dd></div>@endif
                                <div><dt class="text-xs text-sand-700">{{ __('Collecte prévue') }}</dt><dd class="tabular">{{ Money::format($y['income_planned'], 'USD') }}</dd></div>
                                <div><dt class="text-xs text-sand-700">{{ __('Reçu') }}</dt><dd class="tabular text-leaf-600">{{ Money::format($y['income'], 'USD') }}</dd></div>
                                @if ($y['budgeted'])<div class="col-span-2 flex items-baseline justify-between"><dt class="text-xs text-sand-700">{{ __('Budget ordinaire') }}</dt><dd class="tabular text-leaf-600">{{ Money::format($y['budgeted'], 'USD') }}</dd></div>@endif
                                <div><dt class="text-xs text-sand-700">{{ __('Dépenses prévues') }}</dt><dd class="tabular">{{ Money::format($y['expense_planned'], 'USD') }}</dd></div>
                                <div><dt class="text-xs text-sand-700">{{ __('Dépensé') }}</dt><dd class="tabular text-terra-600">{{ Money::format($y['expense'], 'USD') }}</dd></div>
                            </dl>
                        </li>
                    @endforeach
                </ul>
                <div class="hidden overflow-x-auto sm:block">
                    <table class="w-full min-w-[40rem] border-collapse text-sm">
                        <thead class="border-b-2 border-ink-700 text-xs text-ink-700">
                            <tr><th class="px-2 py-1.5 text-left font-semibold">{{ __('Exercice') }}</th>
                                @foreach ([__('Reporté'), __('Collecte prévue'), __('Reçu'), __('Budget ordinaire'), __('Dépenses prévues'), __('Dépensé'), __('Reste')] as $h)<th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold">{{ $h }}</th>@endforeach</tr>
                        </thead>
                        <tbody>
                            @foreach ($yearRows as $y)
                                <tr class="border-b border-sand-100 align-top">
                                    <td class="px-2 py-2"><span class="font-semibold text-ink-800">{{ $y['label'] }}</span>@if ($y['note'])<span class="block text-xs text-sand-700">{{ $y['note'] }}</span>@endif</td>
                                    <td class="px-2 py-2 text-right tabular text-sand-700">{{ $y['carried'] ? Money::format($y['carried'], 'USD') : '·' }}</td>
                                    <td class="px-2 py-2 text-right tabular">{{ Money::format($y['income_planned'], 'USD') }}</td>
                                    <td class="px-2 py-2 text-right tabular text-leaf-600">{{ Money::format($y['income'], 'USD') }}</td>
                                    <td class="px-2 py-2 text-right tabular text-leaf-600">{{ $y['budgeted'] ? Money::format($y['budgeted'], 'USD') : '·' }}</td>
                                    <td class="px-2 py-2 text-right tabular">{{ Money::format($y['expense_planned'], 'USD') }}</td>
                                    <td class="px-2 py-2 text-right tabular text-terra-600">{{ Money::format($y['expense'], 'USD') }}</td>
                                    <td @class(['px-2 py-2 text-right font-semibold tabular', 'text-terra-600' => $y['balance'] < 0, 'text-ink-800' => $y['balance'] >= 0])>{{ Money::format($y['balance'], 'USD') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if ($p->description)<p class="mt-4 whitespace-pre-line text-sm text-ink-800">{{ $p->description }}</p>@endif
        </section>
    @elseif ($tab === 'promesses')
        <section class="card p-5 sm:p-6">
            <h2 class="mb-3 text-lg">{{ trans_choice(':count promesse|:count promesses', $pledgeRows->count()) }}</h2>
            <ul class="divide-y divide-sand-100">
                @forelse ($pledgeRows as $row)
                    @php $pl = $row['pledge']; $pr = $row['progress']; @endphp
                    <li wire:key="pl-{{ $pl->id }}">
                        <a href="{{ route('finances.pledges.show', $pl) }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5 hover:bg-sand-50">
                            <span class="min-w-0 flex-1 font-semibold text-ink-800">{{ $seesNames ? $pl->pledgerName() : __('Promesse n° :n', ['n' => $pl->id]) }}</span>
                            <span class="tabular text-sm">{{ Money::format((string) $pr['received'], $pl->currency) }} <span class="text-sand-700">/ {{ Money::format((string) $pr['promised'], $pl->currency) }}</span></span>
                            <span class="w-12 text-right text-sm font-semibold tabular">{{ $pr['percent'] }} %</span>
                        </a>
                    </li>
                @empty
                    <li class="py-2 text-sm text-sand-700">{{ __('Aucune promesse pour ce projet.') }}</li>
                @endforelse
            </ul>
        </section>
    @elseif ($tab === 'argent')
        <section class="card mb-5 p-5 sm:p-6">
            <h2 class="mb-3 text-lg">{{ __('Mouvements du projet') }}</h2>
            <ul class="divide-y divide-sand-100 text-sm">
                @forelse ($movements as $m)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-0.5 py-2">
                        <span class="min-w-0 flex-1"><span class="block text-ink-800">{{ $m->description }}</span><span class="block text-xs text-sand-700">{{ collect([$m->occurred_on->translatedFormat('j M Y'), $m->account?->name, $seesNames ? ($m->member?->fullName() ?? $m->payer_name) : null])->filter()->implode(' · ') }}</span></span>
                        <span @class(['font-semibold tabular', 'text-leaf-600' => $m->type === 'income', 'text-terra-600' => $m->type === 'expense'])>{{ $m->type === 'expense' ? '−' : '+' }} {{ Money::format($m->amount, $m->currency) }}</span>
                    </li>
                @empty
                    <li class="py-2 text-sand-700">{{ __('Aucun mouvement pour le moment.') }}</li>
                @endforelse
            </ul>
        </section>
        @if ($expenses->isNotEmpty())
            <section class="card p-5 sm:p-6">
                <h2 class="mb-3 text-lg">{{ __('Demandes de dépense') }}</h2>
                <ul class="divide-y divide-sand-100 text-sm">
                    @foreach ($expenses as $e)
                        <li><a href="{{ route('finances.expenses.show', $e) }}" class="flex flex-wrap items-center gap-x-3 py-2 hover:bg-sand-50"><span class="font-mono text-xs text-sand-700">{{ $e->number }}</span><span class="min-w-0 flex-1 text-ink-800">{{ $e->title }}</span><span class="tabular">{{ Money::format($e->amount, $e->currency) }}</span><span class="badge bg-sand-100 text-sand-700">{{ __(\App\Models\ExpenseRequest::STATUSES[$e->status]) }}</span></a></li>
                    @endforeach
                </ul>
            </section>
        @endif
    @else
        <section class="card p-5 sm:p-6">
            <h2 class="mb-3 text-lg">{{ __('Avancement') }}</h2>
            <ul class="space-y-3">
                @forelse ($p->updates as $u)
                    <li class="flex gap-3 text-sm"><span class="w-12 shrink-0 font-semibold tabular text-ink-800">{{ $u->progress }} %</span><span class="min-w-0 flex-1"><span class="block text-sand-700">{{ $u->created_at->translatedFormat('j M Y') }}@if ($u->user) · {{ $u->user->name }}@endif</span>@if ($u->note)<span class="block text-ink-800">{{ $u->note }}</span>@endif</span></li>
                @empty
                    <li class="text-sm text-sand-700">{{ __('Aucun point d’avancement pour le moment.') }}</li>
                @endforelse
            </ul>
        </section>
    @endif

    @include('livewire.projects.partials.form')

    <x-modal name="progress" :title="__('Avancement du projet')">
        <form wire:submit="saveProgress" class="space-y-4">
            <div><label for="pr-val" class="label">{{ __('Avancement : :p %', ['p' => $progress]) }}</label><input wire:model.live="progress" id="pr-val" type="range" min="0" max="100" step="5" class="w-full"></div>
            <div><label for="pr-note" class="label">{{ __('Où en est-on ?') }}</label><textarea wire:model="progressNote" id="pr-note" rows="3" class="input" placeholder="{{ __('Devis reçus, fondations coulées…') }}"></textarea></div>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'progress' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Enregistrer') }}</button></div>
        </form>
    </x-modal>

    <x-modal name="gift" :title="__('Recevoir un don pour le projet')">
        <form wire:submit="saveGift" class="space-y-4">
            <div>
                <p class="label">{{ __('Donné par') }}</p>
                @if ($giver)
                    <div class="flex items-center gap-3 rounded-xl bg-ochre-50 px-3 py-2"><span class="flex-1 text-sm font-semibold text-ink-800">{{ $giver->officialName() }}</span><button type="button" wire:click="$set('gift.member_id', null)" class="rounded-lg p-1.5 text-sand-500 hover:bg-white" aria-label="{{ __('Changer') }}"><x-icon name="x" class="size-4" /></button></div>
                @else
                    <input wire:model.live.debounce.300ms="giverSearch" type="search" class="input" placeholder="{{ __('Membre : nom ou numéro') }}" aria-label="{{ __('Rechercher') }}">
                    <ul class="mt-1 space-y-1">@foreach ($givers as $c)<li><button type="button" wire:click="chooseGiver({{ $c->id }})" class="w-full rounded-xl px-3 py-2 text-left text-sm hover:bg-sand-100"><span class="font-semibold text-ink-700">{{ $c->officialName() }}</span></button></li>@endforeach</ul>
                    <input wire:model="gift.payer_name" class="input mt-2" placeholder="{{ __('… ou nom du donateur (facultatif)') }}" aria-label="{{ __('Nom') }}">
                @endif
            </div>
            <div class="grid gap-4 sm:grid-cols-[1fr_7rem]">
                <div><label for="gf-amount" class="label">{{ __('Montant') }}</label><input wire:model="gift.amount" id="gf-amount" type="number" step="0.01" min="0" class="input tabular">@error('gift.amount') <p class="error">{{ $message }}</p> @enderror</div>
                <div><label for="gf-cur" class="label">{{ __('Devise') }}</label><select wire:model="gift.currency" id="gf-cur" class="input">@foreach ($currencies as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select></div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label for="gf-acc" class="label">{{ __('Reçu dans') }}</label><select wire:model="gift.account_id" id="gf-acc" class="input">@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
                <div><label for="gf-date" class="label">{{ __('Le') }}</label><input wire:model="gift.occurred_on" id="gf-date" type="date" max="{{ today()->toDateString() }}" class="input"></div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label for="gf-method" class="label">{{ __('Moyen') }}</label><select wire:model="gift.payment_method" id="gf-method" class="input"><option value="cash">{{ __('Espèces') }}</option><option value="mobile">{{ __('Mobile money') }}</option><option value="bank">{{ __('Banque') }}</option></select></div>
                <div><label for="gf-ref" class="label">{{ __('Référence') }} <span class="font-normal text-sand-700">{{ __('(facultatif)') }}</span></label><input wire:model="gift.external_reference" id="gf-ref" class="input font-mono"></div>
            </div>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'gift' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Enregistrer le don') }}</button></div>
        </form>
    </x-modal>
</div>
