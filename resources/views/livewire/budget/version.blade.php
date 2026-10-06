@php use App\Support\Money; use App\Models\Budget; $b = $budget; $income = $b->total('income'); $expense = $b->total('expense'); $solde = $income - $expense; @endphp
<div>
    <a href="{{ route('budget.index', ['exercice' => $b->fiscal_year]) }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Budget') }}</a>

    <section class="wax wax-veil wax-veil-strong mb-5 overflow-hidden rounded-[22px] p-5 text-white sm:p-6">
        <p class="text-sm text-ochre-300">{{ __('Exercice :y · version :v', ['y' => $yearLabel, 'v' => $b->version]) }} · {{ __(Budget::STATUSES[$b->status]) }}</p>
        <h1 class="text-2xl font-semibold text-white">{{ $b->reason ? __('Révision : :r', ['r' => $b->reason]) : __('Budget de l’exercice :y', ['y' => $yearLabel]) }}</h1>
        <div class="mt-3 grid grid-cols-3 gap-3">
            @foreach ([[__('Recettes prévues'), $income], [__('Dépenses prévues'), $expense], [$solde < 0 ? __('Déficit') : __('Excédent'), $solde]] as [$label, $value])
                <div><p class="text-xs text-ink-100">{{ $label }}</p><p class="text-xl font-semibold tabular text-white sm:text-2xl">{{ Money::format($value, 'USD') }}</p></div>
            @endforeach
        </div>
        @if ($adopted)
            <p class="mt-2 text-xs text-ink-100">{{ __('Budget en vigueur (version :v) : :i de recettes, :e de dépenses.', ['v' => $adopted->version, 'i' => Money::format($adopted->total('income'), 'USD'), 'e' => Money::format($adopted->total('expense'), 'USD')]) }}</p>
        @endif
        <div class="mt-4 flex flex-wrap gap-2">
            @if ($canArbitrate)
                <button type="button" wire:click="importProposals" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="download" class="size-4" /> {{ __('Reprendre les propositions') }}</button>
                <button type="button" wire:click="editLine(null, 'expense')" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="plus" class="size-4" /> {{ __('Ajouter une ligne') }}</button>
            @endif
            @if ($b->status === 'adopted' || $b->status === 'superseded')
                <a href="{{ route('budget.print', $b) }}" target="_blank" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="printer" class="size-4" /> {{ __('Imprimer ou PDF') }}</a>
            @endif
        </div>
    </section>

    @if ($solde < 0)
        <p class="mb-4 rounded-2xl border border-terra-100 bg-terra-50 p-4 text-sm font-semibold text-terra-700"><x-icon name="triangle-alert" class="mr-1 inline size-4" /> {{ __('Les dépenses prévues dépassent les recettes de :m : il faut dire d’où viendra l’argent, ou réduire des besoins.', ['m' => Money::format(-$solde, 'USD')]) }}</p>
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

    @foreach (['income' => __('Recettes prévues'), 'expense' => __('Dépenses prévues')] as $type => $title)
        <section class="card mb-5 p-5 sm:p-6">
            <h2 class="mb-3 text-lg">{{ $title }} <span class="text-base font-normal text-sand-700">· {{ Money::format($b->total($type), 'USD') }}</span></h2>
            @forelse ($groups[$type] as $group => $lines)
                <div class="mb-4 last:mb-0">
                    <h3 class="mb-1 flex items-baseline gap-2 text-sm font-semibold text-ink-700"><span class="flex-1">{{ $group }}</span><span class="tabular">{{ Money::format($lines->sum('amount'), 'USD') }}</span></h3>
                    <ul class="divide-y divide-sand-100 rounded-xl border border-sand-200">
                        @foreach ($lines as $l)
                            <li wire:key="bl-{{ $l->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-semibold text-ink-800">{{ $l->label }}</span>
                                    <span class="block text-xs text-sand-700">{{ $l->category?->name }}@if ($l->proposed_amount !== null && (float) $l->proposed_amount !== (float) $l->amount) · {{ __('proposé : :m', ['m' => Money::format($l->proposed_amount, 'USD')]) }}@endif @if ($l->note) · {{ $l->note }}@endif</span>
                                </span>
                                @if ($canArbitrate)
                                    <input wire:model.blur="amounts.{{ $l->id }}" type="number" step="0.01" min="0" class="input !w-32 !py-1.5 text-right tabular" aria-label="{{ __('Montant arrêté') }}">
                                    <button type="button" wire:click="editLine({{ $l->id }})" class="rounded-lg p-1.5 text-sand-500 hover:bg-sand-100" aria-label="{{ __('Modifier') }}"><x-icon name="pencil" class="size-4" /></button>
                                    <button type="button" wire:click="deleteLine({{ $l->id }})" wire:confirm="{{ __('Retirer cette ligne du budget ?') }}" class="rounded-lg p-1.5 text-sand-500 hover:bg-terra-50 hover:text-terra-600" aria-label="{{ __('Retirer') }}"><x-icon name="trash-2" class="size-4" /></button>
                                @else
                                    <span class="font-semibold tabular text-ink-800">{{ Money::format($l->amount, 'USD') }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <p class="text-sm text-sand-700">{{ __('Aucune ligne.') }}</p>
            @endforelse
        </section>
    @endforeach

    <x-modal name="line" :title="$lineId ? __('Modifier la ligne') : __('Ajouter une ligne')">
        <form wire:submit="saveLine" class="space-y-4">
            <div class="grid grid-cols-2 gap-2" role="radiogroup">
                @foreach (['expense' => __('Dépense'), 'income' => __('Recette')] as $k => $label)
                    <label @class(['cursor-pointer rounded-xl border p-3 text-center text-sm font-semibold', 'border-ochre-400 bg-ochre-50 text-ink-800' => ($line['type'] ?? '') === $k, 'border-sand-200 text-ink-600' => ($line['type'] ?? '') !== $k])>
                        <input type="radio" wire:model.live="line.type" value="{{ $k }}" class="sr-only">{{ $label }}</label>
                @endforeach
            </div>
            <div><label for="bl-label" class="label">{{ __('Objet') }}</label><input wire:model="line.label" id="bl-label" class="input">@error('line.label') <p class="error">{{ $message }}</p> @enderror</div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label for="bl-dept" class="label">{{ __('Département') }}</label><select wire:model="line.department_id" id="bl-dept" class="input">@if (($line['type'] ?? '') === 'income')<option value="">{{ __('Recettes générales') }}</option>@endif @foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>@error('line.department_id') <p class="error">{{ $message }}</p> @enderror</div>
                <div><label for="bl-cat" class="label">{{ __('Catégorie') }}</label><select wire:model="line.category_id" id="bl-cat" class="input">@foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
            </div>
            <div><label for="bl-amount" class="label">{{ __('Montant (USD)') }}</label><input wire:model="line.amount" id="bl-amount" type="number" step="0.01" min="0" class="input tabular">@error('line.amount') <p class="error">{{ $message }}</p> @enderror</div>
            <div><label for="bl-note" class="label">{{ __('Remarque de l’arbitrage (facultatif)') }}</label><input wire:model="line.note" id="bl-note" class="input"></div>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'line' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Enregistrer') }}</button></div>
        </form>
    </x-modal>
</div>
