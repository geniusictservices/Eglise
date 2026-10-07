@php use App\Support\Money; use App\Models\Budget; use App\Models\BudgetProposal; @endphp
<div>
    <x-page-header :title="__('Budget')" :description="__('Les départements proposent leurs dépenses et leurs recettes, la finance arbitre et présente le budget, le pasteur l’approuve. Chaque révision garde la version précédente.')">
        <x-slot:actions>
            <select wire:model.live="year" class="input !w-auto" aria-label="{{ __('Exercice') }}">@foreach ($years as $y => $label)<option value="{{ $y }}">{{ __('Exercice :y', ['y' => $label]) }}</option>@endforeach</select>
        </x-slot:actions>
    </x-page-header>

    @foreach ($pendingOverruns as $o)
        <a href="{{ $o->expense ? route('finances.expenses.show', $o->expense) : ($o->payRun ? route('payroll.run', $o->payRun) : route('budget.execution')) }}" class="mb-3 flex items-center gap-3 rounded-2xl border border-ochre-300 bg-ochre-50 p-4 text-sm text-ink-800 hover:bg-ochre-100">
            <x-icon name="triangle-alert" class="size-5 text-ochre-600" />
            <span class="flex-1 font-semibold">{{ __('Dépassement de :m demandé :e : il attend votre décision.', ['m' => Money::format($o->amount, 'USD'), 'e' => $o->expense ? '('.$o->expense->number.')' : ($o->payRun ? '('.__('paie :p', ['p' => $o->payRun->label()]).')' : '')]) }}</span>
            <x-icon name="chevron-right" class="size-4" />
        </a>
    @endforeach

    {{-- Le budget de l'exercice --}}
    <section class="wax wax-veil wax-veil-strong mb-5 overflow-hidden rounded-[22px] p-5 text-white sm:p-6">
        @if ($adopted)
            <p class="text-sm text-ochre-300">{{ __('Budget :y · version :v adoptée le :d par :n', ['y' => $yearLabel, 'v' => $adopted->version, 'd' => $adopted->approved_at->translatedFormat('j M Y'), 'n' => $adopted->approver?->name]) }}</p>
            <div class="mt-3 grid grid-cols-3 gap-3">
                @php $solde = $adopted->total('income') - $adopted->total('expense'); @endphp
                @foreach ([[__('Recettes prévues'), $adopted->total('income')], [__('Dépenses prévues'), $adopted->total('expense')], [$solde < 0 ? __('Déficit') : __('Excédent'), $solde]] as [$label, $value])
                    <div><p class="text-xs text-ink-100">{{ $label }}</p><p class="text-xl font-semibold tabular text-white sm:text-2xl">{{ Money::format($value, 'USD') }}</p></div>
                @endforeach
            </div>
        @else
            <p class="text-sm text-ochre-300">{{ __('Exercice :y', ['y' => $yearLabel]) }}</p>
            <p class="text-xl font-semibold text-white">{{ $pending ? __('Le budget est en préparation.') : __('Pas encore de budget adopté.') }}</p>
        @endif
        <div class="mt-4 flex flex-wrap gap-2">
            @if ($adopted)<a href="{{ route('budget.version', $adopted) }}" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="file-text" class="size-4" /> {{ __('Voir le budget adopté') }}</a>@endif
            @if ($adopted)<a href="{{ route('budget.execution', ['exercice' => $year]) }}" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="circle-dollar-sign" class="size-4" /> {{ __('Suivi du budget') }}</a>@endif
            @if ($pending)
                <a href="{{ route('budget.version', $pending) }}" class="btn-accent !min-h-0 !py-2"><x-icon name="pencil" class="size-4" /> {{ $pending->status === 'submitted' ? __('Version :v à approuver', ['v' => $pending->version]) : __('Version :v en préparation', ['v' => $pending->version]) }}</a>
            @elseif ($canArbitrate && ! $adopted && $versions->isEmpty())
                <button type="button" wire:click="prepare" class="btn-accent !min-h-0 !py-2"><x-icon name="plus" class="size-4" /> {{ __('Préparer le budget') }}</button>
            @elseif ($canArbitrate && $adopted)
                <button type="button" @click="$dispatch('open-modal', { name: 'revise' })" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="refresh-cw" class="size-4" /> {{ __('Réviser le budget') }}</button>
            @endif
        </div>
    </section>

    {{-- Les départements --}}
    <section class="card mb-5 p-5 sm:p-6">
        <h2 class="mb-1 text-lg">{{ __('Propositions des départements') }}</h2>
        <p class="mb-4 text-sm text-sand-700">{{ __('Chaque département prévoit, séparément, ses dépenses et ses recettes, avec leur justification, puis les envoie à la finance.') }}</p>
        @if ($mine === [] && ! $linked)
            <p class="mb-4 rounded-xl bg-ochre-50 p-3 text-sm text-ink-800">{{ __('Votre compte n’est relié à aucune fiche de membre : demandez à l’administrateur de le relier (écran Utilisateurs) pour proposer le budget de votre département.') }}</p>
        @endif
        <ul class="divide-y divide-sand-100">
            @foreach ($departments as $d)
                @php $p = $proposals->get($d->id); $allowed = $mine === null || in_array($d->id, $mine, true); $t = $byDepartment[$d->id] ?? null; @endphp
                <li wire:key="d-{{ $d->id }}" class="py-3">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="min-w-0 basis-full font-semibold text-ink-800 sm:basis-0 sm:flex-1">{{ $d->name }}</span>
                        @if ($p)
                            <span @class(['badge mr-auto sm:mr-0', 'bg-leaf-50 text-leaf-600' => $p->status === 'submitted', 'bg-sand-100 text-sand-700' => $p->status === 'draft'])>{{ __(BudgetProposal::STATUSES[$p->status]) }}@if ($p->return_note) · {{ __('renvoyée') }}@endif</span>
                        @endif
                        @if ($allowed && ($canPropose || $p))
                            <a href="{{ route('budget.proposal', ['year' => $year, 'department' => $d->id]) }}" class="btn-ghost !min-h-0 !px-2 !py-1.5 text-sm">{{ $p ? __('Ouvrir') : __('Proposer') }} <x-icon name="chevron-right" class="size-4" /></a>
                        @endif
                    </div>
                    @if (($p && $p->lines->isNotEmpty()) || $t)
                        <div class="mt-1.5 grid grid-cols-2 gap-2">
                            @foreach (['expense' => [__('Dépenses'), 'bg-terra-500'], 'income' => [__('Recettes'), 'bg-leaf-500']] as $type => [$label, $dot])
                                <div class="rounded-xl bg-sand-50 px-3 py-2 text-xs text-sand-700">
                                    <p class="flex items-center gap-1.5 font-semibold text-ink-700"><span class="size-1.5 rounded-full {{ $dot }}"></span>{{ $label }}</p>
                                    <p class="tabular">{{ __('Proposé : :m', ['m' => Money::format($p?->total($type) ?? 0, 'USD')]) }}</p>
                                    @if ($t)<p class="font-semibold tabular text-ink-700">{{ __('Adopté : :m', ['m' => Money::format($t[$type], 'USD')]) }}</p>@endif
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-xs text-sand-700">{{ __('Aucune proposition') }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    @if ($versions->isNotEmpty())
        <section class="card p-5 sm:p-6">
            <h2 class="mb-3 text-lg">{{ __('Versions') }}</h2>
            <ul class="divide-y divide-sand-100">
                @foreach ($versions as $v)
                    <li><a href="{{ route('budget.version', $v) }}" class="flex flex-wrap items-center gap-3 py-2.5 text-sm hover:bg-sand-50">
                        <span class="font-semibold text-ink-800">{{ __('Version :v', ['v' => $v->version]) }}</span>
                        <span class="min-w-0 flex-1 truncate text-sand-700">{{ $v->reason ?? __('Budget initial') }}</span>
                        <span @class(['badge', 'bg-leaf-50 text-leaf-600' => $v->status === 'adopted', 'bg-ochre-100 text-ochre-700' => in_array($v->status, ['draft', 'submitted'], true), 'bg-sand-100 text-sand-700' => $v->status === 'superseded'])>{{ __(Budget::STATUSES[$v->status]) }}</span>
                    </a></li>
                @endforeach
            </ul>
        </section>
    @endif

    <x-modal name="revise" :title="__('Réviser le budget')">
        <form wire:submit="revise" class="space-y-4">
            <p class="text-sm text-ink-800">{{ __('Une nouvelle version est créée, copie du budget adopté. Vous la modifiez, puis le pasteur l’approuve. Jusque-là, le budget adopté reste en vigueur.') }}</p>
            <div><label for="rv-reason" class="label">{{ __('Motif de la révision') }}</label><textarea wire:model="reason" id="rv-reason" rows="3" class="input" placeholder="{{ __('Exemple : la toiture a cédé, travaux urgents à financer') }}"></textarea>@error('reason') <p class="error">{{ $message }}</p> @enderror</div>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'revise' })">{{ __('Retour') }}</button><button class="btn-primary">{{ __('Créer la révision') }}</button></div>
        </form>
    </x-modal>
</div>
