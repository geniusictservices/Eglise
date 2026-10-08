@php use App\Support\Money; use App\Models\Budget; $b = $budget; $income = $b->total('income'); $expense = $b->total('expense'); $solde = $summary['gap'] > 0 ? -$summary['gap'] : $summary['surplus'];
    // Chaque ligne en pour cent des recettes prévues : ce qu'une recette apporte, ce qu'une dépense consomme.
    $pct = fn ($amount) => $income > 0 ? (float) $amount / $income * 100 : 0.0;
    $pctLabel = fn ($amount) => ($v = $pct($amount)) > 0 && $v < 0.5 ? '< 1 %' : number_format($v, 0, ',', ' ').' %'; @endphp
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
        @if ($summary['reserved'] > 0.004)
            <p class="mt-2 text-xs text-ink-100">{{ __(':m de recettes des projets ne sont pas comptés dans l’excédent : ils leur restent réservés pour les années suivantes.', ['m' => Money::format($summary['reserved'], 'USD')]) }}</p>
        @endif
        @if ($adopted)
            <p class="mt-2 text-xs text-ink-100">{{ __('Budget en vigueur (version :v) : :i de recettes, :e de dépenses.', ['v' => $adopted->version, 'i' => Money::format($adopted->total('income'), 'USD'), 'e' => Money::format($adopted->total('expense'), 'USD')]) }}</p>
        @endif
        <div class="mt-4 flex flex-wrap gap-2">
            @if ($canArbitrate)
                <button type="button" wire:click="importProposals" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="download" class="size-4" /> {{ __('Reprendre les propositions') }}</button>
                <button type="button" wire:click="importPayroll" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="briefcase" class="size-4" /> {{ __('Reprendre la masse salariale') }}</button>
                <button type="button" wire:click="importProjects" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="target" class="size-4" /> {{ __('Reprendre les projets') }}</button>
            @endif
            @if ($b->status === 'adopted' || $b->status === 'superseded')
                <a href="{{ route('budget.print', $b) }}" target="_blank" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="printer" class="size-4" /> {{ __('Imprimer ou PDF') }}</a>
            @endif
        </div>
    </section>

    {{-- L'équilibre : ce que les dépenses prévues consomment des recettes prévues. --}}
    <section class="card mb-5 p-5 sm:p-6">
        <div class="flex items-baseline gap-2"><h2 class="flex-1 text-lg">{{ __('Équilibre du budget') }}</h2><span @class(['text-lg font-semibold tabular', 'text-terra-600' => $summary['gap'] > 0, 'text-ink-800' => $summary['gap'] <= 0])>{{ $pctLabel($expense) }}</span></div>
        <p class="mb-2 text-sm text-sand-700">{{ __('Les dépenses prévues (:e) consomment cette part des recettes prévues (:i).', ['e' => Money::format($expense, 'USD'), 'i' => Money::format($income, 'USD')]) }}</p>
        <div class="h-2.5 overflow-hidden rounded-full bg-sand-100"><div @class(['h-full rounded-full', 'bg-terra-500' => $summary['gap'] > 0, 'bg-ochre-500' => $summary['gap'] <= 0]) style="width: {{ min(100, $pct($expense)) }}%"></div></div>
        @if ($summary['gap'] > 0)
            <p class="mt-3 rounded-xl border border-terra-100 bg-terra-50 p-3 text-sm font-semibold text-terra-700"><x-icon name="triangle-alert" class="mr-1 inline size-4" /> {{ __('Il manque :m : dites d’où viendra cet argent (une recette prévue de plus), ou réduisez des dépenses. Le budget ne peut pas être présenté avant.', ['m' => Money::format($summary['gap'], 'USD')]) }}</p>
        @elseif ($summary['expense'] > 0)
            <p class="mt-3 text-sm font-semibold text-leaf-600"><x-icon name="badge-check" class="mr-1 inline size-4" /> {{ __('Les dépenses prévues sont couvertes.') }}@if ($summary['surplus'] > 0) {{ __('Il reste :m de marge.', ['m' => Money::format($summary['surplus'], 'USD')]) }}@endif</p>
        @endif
    </section>


    @if ($staleCarryover->isNotEmpty())
        <div class="mb-4 rounded-2xl border border-ochre-300 bg-ochre-50 p-4 text-sm text-ink-800">
            <p class="font-semibold">{{ __('Le solde reporté a changé depuis la reprise des projets :') }}</p>
            <ul class="mt-1">@foreach ($staleCarryover as $r)<li>{{ $r['line']->project->name }} : {{ Money::format($r['line']->amount, 'USD') }} → <span class="font-semibold">{{ Money::format($r['now'], 'USD') }}</span></li>@endforeach</ul>
            <button type="button" wire:click="importProjects" class="btn-secondary mt-3 !min-h-0 !py-1.5 text-sm"><x-icon name="refresh-cw" class="size-4" /> {{ __('Mettre à jour') }}</button>
        </div>
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
            <p class="mb-4 text-sm text-sand-700">{{ __('La tranche de l’année de chaque projet : ce qu’il prévoit de collecter, ce qui lui reste des années précédentes, ce qu’il prévoit de dépenser. Ce qui lui manque est pris sur les recettes ordinaires ; ce qu’il collecte en plus lui reste réservé.') }}</p>
            <ul class="space-y-3">
                @forelse ($summary['projects'] as $row)
                    @php $pr = $row['project']; @endphp
                    <li wire:key="bp-{{ $pr->id }}" class="rounded-xl border border-sand-200 p-3">
                        <div class="flex flex-wrap items-baseline gap-x-3">
                            <a href="{{ route('projects.show', $pr) }}" class="min-w-0 flex-1 font-semibold text-ink-800 hover:underline">{{ $pr->name }}</a>
                            @if ($row['ordinary'] > 0)<span class="badge bg-ochre-100 text-ochre-700">{{ __(':m pris sur les recettes ordinaires', ['m' => Money::format($row['ordinary'], 'USD')]) }}</span>@elseif ($row['expense'] > 0)<span class="badge bg-leaf-50 text-leaf-600">{{ __('Payé par son argent') }}</span>@endif
                        </div>
                        <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-sm sm:grid-cols-4">
                            <div><dt class="text-xs text-sand-700">{{ __('Collecte prévue') }}</dt><dd class="tabular text-leaf-600">{{ Money::format($row['income'], 'USD') }}</dd></div>
                            <div><dt class="text-xs text-sand-700">{{ __('Solde reporté') }}</dt><dd class="tabular">{{ Money::format($row['carried'], 'USD') }}</dd></div>
                            <div><dt class="text-xs text-sand-700">{{ __('Dépenses prévues') }}</dt><dd class="tabular text-terra-600">{{ Money::format($row['expense'], 'USD') }}</dd></div>
                            <div><dt class="text-xs text-sand-700">{{ __('Reste réservé au projet') }}</dt><dd class="tabular">{{ Money::format($row['reserved'], 'USD') }}</dd></div>
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
        <p class="-mt-1 mb-3 text-sm text-sand-700">{{ $type === 'expense' ? __('Pour chaque dépense : la part des recettes prévues qu’elle consomme.') : __('Pour chaque recette : la part qu’elle apporte aux recettes prévues.') }}</p>
        @if ($type === 'income' && $pendingPledges > 0)
            <p class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl bg-sand-50 p-3 text-sm text-ink-800"><span class="min-w-0 flex-1">{{ __('Les promesses en cours (hors projets) attendent encore :m.', ['m' => Money::format($pendingPledges, 'USD')]) }}</span><button type="button" wire:click="addPledges" class="btn-secondary !min-h-0 !py-1 text-xs">{{ __('Ajouter aux recettes prévues') }}</button></p>
        @endif
        @forelse ($groups as $group => $lines)
            <div class="mb-4 last:mb-0">
                <h3 class="mb-1 flex items-baseline gap-2 text-sm font-semibold text-ink-700"><span class="flex-1">{{ $group }}</span><span class="tabular">{{ Money::format($lines->sum('amount'), 'USD') }}</span><span class="w-12 text-right text-xs tabular text-sand-700">{{ $pctLabel($lines->sum('amount')) }}</span></h3>
                <ul class="divide-y divide-sand-100 rounded-xl border border-sand-200">
                    @foreach ($lines as $l)
                        <li wire:key="bl-{{ $l->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold text-ink-800">{{ $l->label }}</span>
                                <span class="block text-xs text-sand-700">@if ($l->project)<span class="font-semibold text-ochre-700">{{ $l->source ? __(\App\Models\BudgetLine::SOURCES[$l->source]) : __('Projet') }}</span> · @endif{{ $l->category?->name }}@if ($l->proposed_amount !== null && (float) $l->proposed_amount !== (float) $l->amount) · {{ __('proposé : :m', ['m' => Money::format($l->proposed_amount, 'USD')]) }}@endif @if ($l->note) · {{ $l->note }}@endif</span>
                                <span class="mt-1 flex items-center gap-2">
                                    <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-sand-100"><span @class(['block h-full rounded-full', 'bg-terra-500' => $type === 'expense', 'bg-leaf-500' => $type === 'income']) style="width: {{ min(100, $pct($l->amount)) }}%"></span></span>
                                    <span @class(['shrink-0 text-xs font-semibold tabular', 'text-terra-600' => $type === 'expense', 'text-leaf-600' => $type === 'income'])>{{ $type === 'expense' ? __('consomme :p', ['p' => $pctLabel($l->amount)]) : __('apporte :p', ['p' => $pctLabel($l->amount)]) }}</span>
                                </span>
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
