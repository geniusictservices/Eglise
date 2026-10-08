@php use App\Support\Money; use App\Models\Budget; $b = $budget; $income = $b->total('income'); $expense = $b->total('expense'); $solde = $income - $expense; @endphp
<div>
    <a href="{{ route('budget.index', ['exercice' => $b->fiscal_year]) }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Budget') }}</a>

    <section class="wax wax-veil wax-veil-strong mb-5 overflow-hidden rounded-[22px] p-5 text-white sm:p-6">
        <p class="text-sm text-ochre-300">{{ __('Exercice :y · version :v', ['y' => $yearLabel, 'v' => $b->version]) }} · {{ __(Budget::STATUSES[$b->status]) }}</p>
        <h1 class="text-2xl font-semibold text-white">{{ $b->reason ? __('Révision : :r', ['r' => $b->reason]) : __('Budget de l’exercice :y', ['y' => $yearLabel]) }}</h1>
        <div class="mt-3 grid grid-cols-3 gap-3">
            @foreach ([[__('Recettes prévues'), $income], [__('Dépenses prévues'), $expense], [$solde < 0 ? __('Déficit') : __('Excédent'), $solde]] as [$label, $value])
                <div class="min-w-0"><p class="text-xs text-ink-100">{{ $label }}</p><p class="text-base font-semibold tabular text-white sm:text-2xl">{{ Money::format($value, 'USD') }}</p></div>
            @endforeach
        </div>
        @php $reserved = $b->lines->where('type', 'income')->whereNotNull('project_id')->sum(fn ($l) => max(0, $state['income'][$l->id]['free'])); @endphp
        @if ($reserved > 0.004)
            <p class="mt-2 text-xs text-ink-100">{{ __('Dont :m réservés aux projets : leur argent non dépensé cette année passe sur l’année suivante.', ['m' => Money::format($reserved, 'USD')]) }}</p>
        @endif
        @if ($adopted)
            <p class="mt-2 text-xs text-ink-100">{{ __('Budget en vigueur (version :v) : :i de recettes, :e de dépenses.', ['v' => $adopted->version, 'i' => Money::format($adopted->total('income'), 'USD'), 'e' => Money::format($adopted->total('expense'), 'USD')]) }}</p>
        @endif
        <div class="mt-4 flex flex-wrap gap-2">
            @if ($canArbitrate)
                <button type="button" wire:click="importProposals" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="download" class="size-4" /> {{ __('Reprendre les propositions') }}</button>
                <button type="button" wire:click="importPayroll" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="briefcase" class="size-4" /> {{ __('Reprendre la masse salariale') }}</button>
                <button type="button" wire:click="importProjects" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="target" class="size-4" /> {{ __('Reprendre les projets') }}</button>
                <button type="button" wire:click="autoFund" class="btn !min-h-0 bg-ochre-500 !py-2 text-on-accent hover:bg-ochre-400"><x-icon name="git-merge" class="size-4" /> {{ __('Répartir les recettes') }}</button>
            @endif
            @if ($b->status === 'adopted' || $b->status === 'superseded')
                <a href="{{ route('budget.print', $b) }}" target="_blank" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="printer" class="size-4" /> {{ __('Imprimer ou PDF') }}</a>
            @endif
        </div>
    </section>

    @if ($solde < 0)
        <p class="mb-4 rounded-2xl border border-terra-100 bg-terra-50 p-4 text-sm font-semibold text-terra-700"><x-icon name="triangle-alert" class="mr-1 inline size-4" /> {{ __('Les dépenses prévues dépassent les recettes de :m : il faut dire d’où viendra l’argent, ou réduire des besoins.', ['m' => Money::format(-$solde, 'USD')]) }}</p>
    @endif
    @if ($state['unfunded'] > 0)
        <div class="mb-4 rounded-2xl border border-ochre-300 bg-ochre-50 p-4 text-sm text-ink-800">
            <p class="font-semibold"><x-icon name="unlink" class="mr-1 inline size-4 text-ochre-600" /> {{ trans_choice(':count dépense prévue n’a pas encore de financement complet (il manque :m).|:count dépenses prévues n’ont pas encore de financement complet (il manque :m).', $state['unfunded'], ['m' => Money::format($state['missing'], 'USD')]) }}</p>
            <p class="mt-1">{{ __('Chaque dépense prévue dit quelles recettes prévues la paient : dîmes, offrandes, promesses, collecte d’un projet… Le budget ne peut pas être présenté avant.') }}</p>
            @if ($canArbitrate)<button type="button" wire:click="autoFund" class="btn-secondary mt-3 !min-h-0 !py-1.5 text-sm"><x-icon name="git-merge" class="size-4" /> {{ __('Répartir automatiquement') }}</button>@endif
        </div>
    @elseif ($b->lines->where('type', 'expense')->isNotEmpty())
        <p class="mb-4 rounded-2xl border border-leaf-100 bg-leaf-50 p-3 text-sm text-leaf-600"><x-icon name="link" class="mr-1 inline size-4" /> {{ __('Chaque dépense prévue est financée par des recettes prévues.') }}</p>
    @endif
    @if ($b->return_note && $b->status === 'draft')
        <p class="mb-4 rounded-2xl border border-terra-100 bg-terra-50 p-4 text-sm text-terra-700"><span class="font-semibold">{{ __('Renvoyé par le pasteur :') }}</span> {{ $b->return_note }}</p>
    @endif

    {{-- Les étapes : présentation par la finance, approbation par le pasteur --}}
    @if ($canSubmit || $canApprove || $b->status === 'submitted')
        <section class="card mb-5 border-ochre-300 p-5 sm:p-6">
            @if ($canSubmit)
                <h2 class="text-lg">{{ __('Présenter au pasteur') }}</h2>
                <p class="mb-3 text-sm text-sand-700">{{ __('Une fois l’arbitrage fini, la finance présente le budget. Le pasteur l’approuve, ou le renvoie avec ses remarques.') }}</p>
                @error('note') <p class="error mb-2">{{ $message }}</p> @enderror
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="submit" class="btn-primary"><x-icon name="upload" class="size-4" /> {{ __('Présenter le budget') }}</button>
                    <button type="button" wire:click="discard" wire:confirm="{{ __('Abandonner cette version ? Le budget en vigueur ne change pas.') }}" class="btn-ghost text-terra-600">{{ __('Abandonner la version') }}</button>
                </div>
            @elseif ($canApprove && ! $ownSubmission)
                <h2 class="text-lg">{{ __('Approbation') }}</h2>
                <p class="mb-3 text-sm text-sand-700">{{ __('Présenté par :n le :d.', ['n' => $b->submitter?->name, 'd' => $b->submitted_at->translatedFormat('j M Y')]) }}</p>
                <form wire:submit="approve" class="space-y-3">
                    <textarea wire:model="note" rows="2" class="input" placeholder="{{ __('Remarque (facultatif ; obligatoire pour renvoyer)') }}" aria-label="{{ __('Remarque') }}"></textarea>
                    @error('note') <p class="error">{{ $message }}</p> @enderror
                    <div class="flex flex-wrap gap-2"><button class="btn-primary"><x-icon name="badge-check" class="size-4" /> {{ __('Approuver le budget') }}</button>
                        <button type="button" wire:click="sendBack" class="btn-ghost text-terra-600">{{ __('Renvoyer à la finance') }}</button></div>
                </form>
            @else
                <p class="text-sm text-ink-800"><x-icon name="clock" class="mr-1 inline size-4 text-ochre-600" /> {{ __('Présenté par :n le :d : en attente de l’approbation du pasteur.', ['n' => $b->submitter?->name, 'd' => $b->submitted_at->translatedFormat('j M Y')]) }}</p>
            @endif
        </section>
    @elseif ($b->approved_at)
        <p class="mb-5 rounded-2xl border border-leaf-100 bg-leaf-50 p-4 text-sm text-leaf-600"><x-icon name="badge-check" class="mr-1 inline size-4" /> {{ __('Présenté par :s, approuvé par :n le :d.', ['s' => $b->submitter?->name, 'n' => $b->approver?->name, 'd' => $b->approved_at->translatedFormat('j M Y')]) }}@if ($b->approval_note) « {{ $b->approval_note }} »@endif</p>
    @endif

    @php $projectCount = $b->lines->whereNotNull('project_id')->pluck('project_id')->unique()->count(); @endphp
    @include('livewire.budget.partials.tabs', ['totals' => ['expense' => $expense, 'income' => $income], 'projectsTab' => trans_choice('{0} Aucun projet|{1} :count projet|[2,*] :count projets', $projectCount)])

    @if ($tab === 'projets')
        <section class="card mb-5 p-5 sm:p-6" wire:key="section-projets">
            <div class="mb-1 flex flex-wrap items-center gap-3">
                <h2 class="flex-1 text-lg">{{ __('Projets de l’exercice') }}</h2>
                @if ($canArbitrate)<button type="button" wire:click="importProjects" class="btn-secondary !min-h-0 !py-1.5 text-sm"><x-icon name="download" class="size-4" /> {{ __('Reprendre les projets') }}</button>@endif
            </div>
            <p class="mb-4 text-sm text-sand-700">{{ __('La tranche de l’année de chaque projet : ce qu’il prévoit de collecter, ce qui lui reste des années précédentes, ce qu’il prévoit de dépenser, et ce que les recettes ordinaires lui apportent.') }}</p>
            <ul class="space-y-3">
                @forelse ($projectRows as $row)
                    @php $pr = $row['project']; @endphp
                    <li wire:key="bp-{{ $pr->id }}" class="rounded-xl border border-sand-200 p-3">
                        <div class="flex flex-wrap items-baseline gap-x-3">
                            <a href="{{ route('projects.show', $pr) }}" class="min-w-0 flex-1 font-semibold text-ink-800 hover:underline">{{ $pr->name }}</a>
                            @if ($row['missing'] > 0.004)<span class="badge bg-ochre-100 text-ochre-700">{{ __('Il manque :m', ['m' => Money::format($row['missing'], 'USD')]) }}</span>@else<span class="badge bg-leaf-50 text-leaf-600">{{ __('Financé') }}</span>@endif
                        </div>
                        <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-sm sm:grid-cols-4">
                            <div><dt class="text-xs text-sand-700">{{ __('Collecte prévue') }}</dt><dd class="tabular text-leaf-600">{{ Money::format($row['income'], 'USD') }}</dd></div>
                            <div><dt class="text-xs text-sand-700">{{ __('Solde reporté') }}</dt><dd class="tabular">{{ Money::format($row['carried'], 'USD') }}</dd></div>
                            <div><dt class="text-xs text-sand-700">{{ __('Dépenses prévues') }}</dt><dd class="tabular text-terra-600">{{ Money::format($row['expense'], 'USD') }}</dd></div>
                            <div><dt class="text-xs text-sand-700">{{ __('Recettes ordinaires') }}</dt><dd class="tabular">{{ Money::format($row['ordinary'], 'USD') }}</dd></div>
                        </dl>
                    </li>
                @empty
                    <li class="text-sm text-sand-700">{{ __('Aucun projet dans ce budget.') }}</li>
                @endforelse
            </ul>
            @if ($missingProjects->isNotEmpty())
                <p class="mt-4 text-sm text-ochre-700"><x-icon name="info" class="mr-1 inline size-4" /> {{ __('Ont une tranche cette année mais ne sont pas encore dans le budget : :l.', ['l' => $missingProjects->pluck('name')->implode(', ')]) }}</p>
            @endif
        </section>
    @else
    <section class="card mb-5 p-5 sm:p-6" wire:key="section-{{ $type }}">
        <div class="mb-3 flex items-center gap-3">
            <h2 class="flex-1 text-lg">{{ $type === 'expense' ? __('Dépenses prévues') : __('Recettes prévues') }}</h2>
            @if ($canArbitrate)<button type="button" wire:click="editLine" class="btn-secondary !min-h-0 !py-1.5 text-sm"><x-icon name="plus" class="size-4" /> {{ $type === 'expense' ? __('Ajouter une dépense') : __('Ajouter une recette') }}</button>@endif
        </div>
        @forelse ($groups as $group => $lines)
            <div class="mb-4 last:mb-0">
                <h3 class="mb-1 flex items-baseline gap-2 text-sm font-semibold text-ink-700"><span class="flex-1">{{ $group }}</span><span class="tabular">{{ Money::format($lines->sum('amount'), 'USD') }}</span></h3>
                <ul class="divide-y divide-sand-100 rounded-xl border border-sand-200">
                    @foreach ($lines as $l)
                        <li wire:key="bl-{{ $l->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold text-ink-800">{{ $l->label }}</span>
                                <span class="block text-xs text-sand-700">@if ($l->project)<span class="font-semibold text-ochre-700">{{ $l->source ? __(\App\Models\BudgetLine::SOURCES[$l->source]) : __('Projet') }}</span> · @endif{{ $l->category?->name }}@if ($l->proposed_amount !== null && (float) $l->proposed_amount !== (float) $l->amount) · {{ __('proposé : :m', ['m' => Money::format($l->proposed_amount, 'USD')]) }}@endif @if ($l->note) · {{ $l->note }}@endif</span>
                                @if ($type === 'expense')
                                    @php $f = $state['expense'][$l->id]; @endphp
                                    <span class="mt-0.5 block text-xs">
                                        @if ($f['sources']->isNotEmpty())<span class="text-ink-700">{{ __('Financée par') }} {{ $f['sources']->map(fn ($src) => $src['line']->label.' '.Money::format($src['amount'], 'USD'))->implode(' · ') }}</span>@endif
                                        @if ($f['missing'] > 0.004)<span class="font-semibold text-terra-600">@if ($f['sources']->isNotEmpty()) · @endif{{ __('Sans financement : :m', ['m' => Money::format($f['missing'], 'USD')]) }}</span>@endif
                                        @if ($canArbitrate)<button type="button" wire:click="editFunding({{ $l->id }})" class="ml-1 font-semibold text-ink-700 underline">{{ __('Financement') }}</button>@endif
                                    </span>
                                @else
                                    @php $f = $state['income'][$l->id]; @endphp
                                    <span class="mt-0.5 block text-xs text-ink-700">{{ __('Finance :a des dépenses · libre :f', ['a' => Money::format($f['allocated'], 'USD'), 'f' => Money::format($f['free'], 'USD')]) }}</span>
                                @endif
                            </span>
                            @if ($canArbitrate)
                                <input wire:model.blur="amounts.{{ $l->id }}" type="number" step="0.01" min="0" class="input !w-32 !py-1.5 text-right tabular" aria-label="{{ __('Montant arrêté') }}">
                                <button type="button" wire:click="editLine({{ $l->id }})" class="rounded-lg p-1.5 text-sand-500 hover:bg-sand-100" aria-label="{{ __('Modifier') }}"><x-icon name="pencil" class="size-4" /></button>
                                <button type="button" wire:click="deleteLine({{ $l->id }})" wire:confirm="{{ __('Retirer cette ligne du budget ?') }}" class="rounded-lg p-1.5 text-sand-500 hover:bg-terra-50 hover:text-terra-600" aria-label="{{ __('Retirer') }}"><x-icon name="trash-2" class="size-4" /></button>
                            @else
                                <span @class(['font-semibold tabular', 'text-terra-600' => $type === 'expense', 'text-leaf-600' => $type === 'income'])>{{ Money::format($l->amount, 'USD') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @empty
            <p class="text-sm text-sand-700">{{ $type === 'expense' ? __('Aucune dépense prévue.') : __('Aucune recette prévue.') }}</p>
        @endforelse
    </section>
    @endif

    <x-modal name="funding" :title="__('Financement de la dépense')">
        @if ($fundingLine)
            @php $taken = collect($funding)->sum(fn ($a) => (float) $a); $rest = (float) $fundingLine->amount - $taken; @endphp
            <p class="text-sm font-semibold text-ink-800">{{ $fundingLine->label }} · <span class="tabular text-terra-600">{{ Money::format($fundingLine->amount, 'USD') }}</span></p>
            <p class="mb-3 text-xs text-sand-700">{{ __('Dites quelle part de chaque recette prévue paie cette dépense. Une recette ne finance pas plus que ce qu’elle prévoit.') }}@if ($fundingLine->project_id) {{ __('L’argent du projet passe en premier.') }}@endif</p>
            <form wire:submit="saveFunding" class="space-y-2">
                <ul class="max-h-[50vh] divide-y divide-sand-100 overflow-y-auto rounded-xl border border-sand-200">
                    @forelse ($fundingSources as $src)
                        @php $own = (float) ($b->fundings->where('expense_line_id', $fundingLine->id)->firstWhere('income_line_id', $src->id)?->amount ?? 0); $free = $state['income'][$src->id]['free'] + $own; @endphp
                        <li wire:key="fs-{{ $src->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold text-ink-800">{{ $src->label }}</span>
                                <span class="block text-xs text-sand-700">{{ __('Prévu :p · libre :f', ['p' => Money::format($src->amount, 'USD'), 'f' => Money::format(max(0, $free), 'USD')]) }}@if ($src->project) · <span class="text-ochre-700">{{ __('argent du projet') }}</span>@endif</span>
                            </span>
                            <input wire:model.live.debounce.400ms="funding.{{ $src->id }}" type="number" step="0.01" min="0" class="input !w-28 !py-1.5 text-right tabular" aria-label="{{ __('Part de :r', ['r' => $src->label]) }}">
                            <button type="button" wire:click="fundFrom({{ $src->id }})" class="rounded-lg px-2 py-1 text-xs font-semibold text-ink-700 hover:bg-sand-100">{{ __('Tout') }}</button>
                        </li>
                    @empty
                        <li class="px-3 py-2 text-sm text-sand-700">{{ __('Aucune recette prévue ne peut financer cette dépense : ajoutez d’abord les recettes prévues.') }}</li>
                    @endforelse
                </ul>
                <p @class(['text-sm font-semibold', 'text-leaf-600' => abs($rest) < 0.005, 'text-terra-600' => abs($rest) >= 0.005])>
                    {{ $rest >= 0.005 ? __('Reste à financer : :m', ['m' => Money::format($rest, 'USD')]) : ($rest <= -0.005 ? __('Trop financé de :m', ['m' => Money::format(-$rest, 'USD')]) : __('Dépense entièrement financée.')) }}
                </p>
                @error('funding') <p class="error">{{ $message }}</p> @enderror
                @error('funding.*') <p class="error">{{ $message }}</p> @enderror
                <div class="flex justify-end gap-2 pt-1"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'funding' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Enregistrer') }}</button></div>
            </form>
        @endif
    </x-modal>

    <x-modal name="line" :title="($line['type'] ?? 'expense') === 'expense' ? ($lineId ? __('Modifier la dépense prévue') : __('Nouvelle dépense prévue')) : ($lineId ? __('Modifier la recette prévue') : __('Nouvelle recette prévue'))">
        <form wire:submit="saveLine" class="space-y-4">
            <div><label for="bl-label" class="label">{{ __('Objet') }}</label><input wire:model="line.label" id="bl-label" class="input">@error('line.label') <p class="error">{{ $message }}</p> @enderror</div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label for="bl-dept" class="label">{{ __('Département') }}</label><select wire:model="line.department_id" id="bl-dept" class="input">@if (($line['type'] ?? '') === 'income')<option value="">{{ __('Recettes générales') }}</option>@endif @foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>@error('line.department_id') <p class="error">{{ $message }}</p> @enderror</div>
                <div><label for="bl-cat" class="label">{{ __('Catégorie') }}</label><select wire:model="line.category_id" id="bl-cat" class="input">@foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
            </div>
            <div><label for="bl-project" class="label">{{ __('Projet (facultatif)') }}</label><select wire:model="line.project_id" id="bl-project" class="input"><option value="">{{ __('Aucun : fonctionnement ordinaire') }}</option>@foreach ($openProjects as $op)<option value="{{ $op->id }}">{{ $op->name }}</option>@endforeach</select>
                <p class="mt-1 text-xs text-sand-700">{{ ($line['type'] ?? '') === 'income' ? __('Une recette d’un projet ne finance que ce projet.') : __('Une dépense d’un projet compte dans l’argent du projet.') }}</p></div>
            <div><label for="bl-amount" class="label">{{ __('Montant (USD)') }}</label><input wire:model="line.amount" id="bl-amount" type="number" step="0.01" min="0" class="input tabular">@error('line.amount') <p class="error">{{ $message }}</p> @enderror</div>
            <div><label for="bl-note" class="label">{{ __('Remarque de l’arbitrage (facultatif)') }}</label><input wire:model="line.note" id="bl-note" class="input"></div>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'line' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Enregistrer') }}</button></div>
        </form>
    </x-modal>
</div>
