@php use App\Support\Money; use App\Models\ExpenseRequest; $e = $expense; $steps = array_keys(ExpenseRequest::STEPS); $reached = array_search($e->status, $steps, true); @endphp
<div>
    <a href="{{ route('finances.expenses') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Dépenses') }}</a>

    @if (session('status'))<p class="mb-4 rounded-2xl border border-leaf-100 bg-leaf-50 p-4 text-sm font-semibold text-leaf-600">{{ session('status') }}</p>@endif

    <section class="wax wax-veil wax-veil-strong mb-5 overflow-hidden rounded-[22px] p-5 text-white sm:p-6">
        <p class="text-sm text-ochre-300"><span class="font-mono">{{ $e->number }}</span> · {{ $e->department?->name ?? __('Sans département') }} · {{ $e->category?->name }}@if ($e->is_advance) · {{ __('avance à justifier') }}@endif</p>
        <h1 class="text-2xl font-semibold text-white">{{ $e->title }}</h1>
        <p class="mt-1 text-3xl font-semibold tabular text-white">{{ Money::format($e->amount, $e->currency) }}</p>
        <p class="mt-1 text-sm text-ink-100">
            {{ __('Demandée par :n le :d', ['n' => $e->requester?->name, 'd' => $e->created_at->translatedFormat('j M Y')]) }}@if ($e->needed_on) · {{ __('pour le :d', ['d' => $e->needed_on->translatedFormat('j M Y')]) }}@endif
            @if ($e->is_advance && $e->beneficiaryName()) · {{ __('remise à :n', ['n' => $e->beneficiaryName()]) }}@endif
        </p>
    </section>

    {{-- Le circuit : l'étape du statut est faite, la suivante est en cours. --}}
    <ol class="mb-5 grid grid-cols-5 gap-1.5" aria-label="{{ __('Circuit') }}">
        @foreach (ExpenseRequest::STEPS as $key => $label)
            @php $i = $loop->index; $done = $reached !== false && $i <= $reached; $current = $reached !== false && $i === $reached + 1; @endphp
            <li class="text-center">
                <span @class(['mb-1 block h-1.5 rounded-full', 'bg-leaf-500' => $done, 'bg-ochre-500' => $current, 'bg-sand-200' => ! $done && ! $current])></span>
                <span @class(['hidden text-xs font-semibold sm:inline', 'text-leaf-600' => $done, 'text-ochre-700' => $current, 'text-sand-500' => ! $done && ! $current])>{{ __($label) }}</span>
            </li>
        @endforeach
    </ol>
    @if ($reached !== false)
        <p class="-mt-3 mb-5 text-sm font-semibold sm:hidden {{ $e->status === 'justified' ? 'text-leaf-600' : 'text-ochre-700' }}">
            {{ $e->status === 'justified' ? __('Circuit terminé') : __('Étape en cours : :s', ['s' => __(array_values(ExpenseRequest::STEPS)[$reached + 1])]) }}</p>
    @endif

    @if (in_array($e->status, ['rejected', 'cancelled'], true))
        <p class="mb-5 rounded-2xl border border-terra-100 bg-terra-50 p-4 text-sm text-terra-700"><span class="font-semibold">{{ __(ExpenseRequest::STATUSES[$e->status]) }}</span>@if ($e->reject_reason) · {{ $e->reject_reason }}@endif</p>
    @elseif ($e->isOverdue())
        <p class="mb-5 rounded-2xl border border-terra-100 bg-terra-50 p-4 text-sm font-semibold text-terra-700"><x-icon name="triangle-alert" class="mr-1 inline size-4" /> {{ __('Avance à justifier depuis le :d.', ['d' => $e->justify_by->translatedFormat('j M Y')]) }}</p>
    @endif

    <div class="grid gap-5 lg:grid-cols-[1.2fr_1fr] lg:items-start">
        <div class="space-y-5">
            {{-- Le budget de la dépense --}}
            @if ($budgetLine)
                <section @class(['card p-5 sm:p-6', 'border-terra-300' => $missing > 0])>
                    <h2 class="mb-2 text-lg">{{ __('Budget') }} <span class="text-sm font-normal text-sand-700">· {{ $expense->department?->name }} · {{ $expense->category?->name }}</span></h2>
                    @if ($budgetLine['unbudgeted'] ?? false)
                        <p class="text-sm font-semibold text-terra-600">{{ __('Cette dépense n’est pas prévue au budget.') }}</p>
                    @else
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-4">
                            @foreach ([[__('Prévu'), $budgetLine['budgeted'] + $budgetLine['overruns'] - $budgetLine['transfers']], [__('Dépensé'), $budgetLine['actual']], [__('Engagé'), $budgetLine['committed']], [__('Disponible'), $budgetLine['available']]] as [$label, $value])
                                <div><dt class="text-xs text-sand-700">{{ $label }}</dt><dd @class(['font-semibold tabular', 'text-terra-600' => $label === __('Disponible') && $value < $budgetLine['needed'], 'text-ink-800' => ! ($label === __('Disponible') && $value < $budgetLine['needed'])])>{{ Money::format($value, 'USD') }}</dd></div>
                            @endforeach
                        </dl>
                    @endif
                    @if ($missing > 0)
                        <p class="mt-3 rounded-xl bg-terra-50 p-3 text-sm text-terra-700">{{ __('Cette dépense (:n) dépasse le disponible de :m. Il faut une autorisation de dépassement, qui dit d’où viendra l’argent.', ['n' => Money::format($budgetLine['needed'], 'USD'), 'm' => Money::format($missing, 'USD')]) }}</p>
                    @elseif ($expense->status === 'submitted')
                        <p class="mt-3 text-sm text-leaf-600"><x-icon name="circle-check" class="mr-1 inline size-4" /> {{ __('La dépense tient dans le budget.') }}</p>
                    @endif
                </section>
            @endif

            {{-- Les demandes de dépassement --}}
            @foreach ($overruns as $o)
                <section @class(['card p-5 sm:p-6', 'border-ochre-300' => $o->status === 'pending'])>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="flex-1 text-lg">{{ __('Dépassement de :m', ['m' => Money::format($o->amount, 'USD')]) }}</h2>
                        <span @class(['badge', 'bg-ochre-100 text-ochre-700' => $o->status === 'pending', 'bg-leaf-50 text-leaf-600' => $o->status === 'authorized', 'bg-terra-50 text-terra-600' => $o->status === 'refused'])>{{ __(\App\Models\BudgetOverrun::STATUSES[$o->status]) }}</span>
                    </div>
                    <p class="mt-1 text-sm text-ink-800"><span class="font-semibold">{{ __('D’où vient l’argent :') }}</span> {{ $o->sourceLabel() }}</p>
                    <p class="text-sm text-ink-700">{{ $o->reason }}</p>
                    <p class="mt-1 text-xs text-sand-700">{{ __('Demandé par :n le :d', ['n' => $o->requester?->name, 'd' => $o->created_at->translatedFormat('j M Y')]) }}@if ($o->decided_at) · {{ $o->status === 'authorized' ? __('autorisé') : __('refusé') }} {{ __('par :n le :d', ['n' => $o->decider?->name, 'd' => $o->decided_at->translatedFormat('j M Y')]) }}@endif @if ($o->decision_note) · « {{ $o->decision_note }} »@endif</p>
                    @if ($o->status === 'pending' && $canDecideOverrun)
                        <div class="mt-3 space-y-2">
                            <textarea wire:model="decisionNote" rows="2" class="input" placeholder="{{ __('Remarque (obligatoire pour refuser)') }}" aria-label="{{ __('Remarque') }}"></textarea>
                            @error('decisionNote') <p class="error">{{ $message }}</p> @enderror
                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="decideOverrun(true)" class="btn-primary"><x-icon name="badge-check" class="size-4" /> {{ __('Autoriser le dépassement') }}</button>
                                <button type="button" wire:click="decideOverrun(false)" class="btn-ghost text-terra-600">{{ __('Refuser') }}</button>
                            </div>
                        </div>
                    @elseif ($o->status === 'pending')
                        <p class="mt-2 text-sm text-ochre-700"><x-icon name="clock" class="mr-1 inline size-4" /> {{ __('En attente de la décision du pasteur.') }}</p>
                    @endif
                </section>
            @endforeach

            {{-- L'action qui attend --}}
            @if ($canCheck || $canSign || $canDisburse || $canJustify)
                <section class="card border-ochre-300 p-5 sm:p-6">
                    @if ($canCheck)
                        <h2 class="text-lg">{{ __('Contrôle de la finance') }}</h2>
                        <p class="mb-3 text-sm text-sand-700">{{ __('Vérifiez le montant, les pièces et que la dépense est prévue. Ensuite, la demande passe à l’approbation.') }}</p>
                        <form wire:submit="check" class="space-y-3">
                            <textarea wire:model="note" rows="2" class="input" placeholder="{{ __('Remarque (facultatif)') }}" aria-label="{{ __('Remarque') }}"></textarea>
                            @error('note') <p class="error">{{ $message }}</p> @enderror
                            <div class="flex flex-wrap gap-2">
                                @if ($missing > 0)
                                    @if ($canAskOverrun)<button type="button" wire:click="askOverrun" class="btn-primary"><x-icon name="triangle-alert" class="size-4" /> {{ __('Demander un dépassement') }}</button>@endif
                                @else
                                    <button class="btn-primary"><x-icon name="check" class="size-4" /> {{ __('Contrôlée, à approuver') }}</button>
                                @endif
                                <button type="button" class="btn-ghost text-terra-600" @click="$dispatch('open-modal', { name: 'reject' })">{{ __('Refuser') }}</button></div>
                        </form>
                    @elseif ($canSign)
                        <h2 class="text-lg">{{ __('Approbation') }}</h2>
                        <p class="mb-3 text-sm text-sand-700">{{ trans_choice('Signature :n sur :count.|Signature :n sur :count.', $e->approvals_required, ['n' => $e->approvedCount() + 1]) }}</p>
                        <form wire:submit="approve" class="space-y-3">
                            <textarea wire:model="note" rows="2" class="input" placeholder="{{ __('Remarque (facultatif)') }}" aria-label="{{ __('Remarque') }}"></textarea>
                            @error('note') <p class="error">{{ $message }}</p> @enderror
                            <div class="flex flex-wrap gap-2"><button class="btn-primary"><x-icon name="badge-check" class="size-4" /> {{ __('Signer et approuver') }}</button>
                                <button type="button" class="btn-ghost text-terra-600" @click="$dispatch('open-modal', { name: 'reject' })">{{ __('Refuser') }}</button></div>
                        </form>
                    @elseif ($canDisburse)
                        <h2 class="text-lg">{{ $e->is_advance ? __('Remettre l’avance') : __('Décaisser') }}</h2>
                        <p class="mb-3 text-sm text-sand-700">{{ __('La sortie est enregistrée dans le compte choisi, en :c.', ['c' => $e->currency]) }}</p>
                        @if ($accounts->isEmpty())
                            <p class="text-sm font-semibold text-terra-600">{{ __('Aucun compte ne tient de :c.', ['c' => $e->currency]) }}</p>
                        @else
                            <form wire:submit="disburse" class="space-y-3">
                                <div class="space-y-2">
                                    @foreach ($accounts as $b)
                                        @php $short = (float) (string) $b['balance'] < (float) $e->amount; @endphp
                                        <label @class(['flex cursor-pointer items-center gap-3 rounded-xl border p-3', 'border-ochre-400 bg-ochre-50' => $accountId === (string) $b['account']->id, 'border-sand-200' => $accountId !== (string) $b['account']->id])>
                                            <input type="radio" wire:model.live="accountId" value="{{ $b['account']->id }}" class="size-4">
                                            <x-icon :name="$b['account']->icon()" class="size-5 text-ochre-600" />
                                            <span class="flex-1 text-sm font-semibold text-ink-800">{{ $b['account']->name }}</span>
                                            <span @class(['text-sm tabular', 'text-terra-600' => $short, 'text-ink-700' => ! $short])>{{ Money::format($b['balance'], $b['currency']) }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('accountId') <p class="error">{{ $message }}</p> @enderror
                                <button class="btn-primary"><x-icon name="banknote" class="size-4" /> {{ $e->is_advance ? __('Remettre :m', ['m' => Money::format($e->amount, $e->currency)]) : __('Décaisser :m', ['m' => Money::format($e->amount, $e->currency)]) }}</button>
                            </form>
                        @endif
                    @elseif ($canJustify)
                        <h2 class="text-lg">{{ __('Justification') }}</h2>
                        <p class="mb-3 text-sm text-sand-700">{{ $e->is_advance ? __('Indiquez ce qui a vraiment été dépensé et joignez les factures. Le reste est remis en caisse automatiquement.') : __('Joignez les factures ou reçus de la dépense.') }}</p>
                        <form wire:submit="justify" class="space-y-3">
                            <div><label for="spent" class="label">{{ __('Montant dépensé (:c)', ['c' => $e->currency]) }}</label><input wire:model.live.debounce.300ms="spent" id="spent" type="number" step="0.01" min="0" max="{{ (float) $e->amount }}" class="input tabular">@error('spent') <p class="error">{{ $message }}</p> @enderror</div>
                            @if (is_numeric($spent) && (float) $e->amount - (float) $spent > 0.004)
                                <p class="rounded-xl bg-leaf-50 p-3 text-sm text-ink-800">{{ __('Reste rendu dans :a : :m', ['a' => $e->account?->name, 'm' => Money::format((float) $e->amount - (float) $spent, $e->currency)]) }}</p>
                            @endif
                            <textarea wire:model="note" rows="2" class="input" placeholder="{{ __('Remarque (facultatif)') }}" aria-label="{{ __('Remarque') }}"></textarea>
                            <label class="btn-secondary cursor-pointer !min-h-0 !py-2"><x-icon name="upload" class="size-4" /> {{ count($files) ? trans_choice(':count fichier choisi|:count fichiers choisis', count($files)) : __('Factures ou reçus') }}
                                <input type="file" wire:model="files" multiple accept="image/*,application/pdf" class="sr-only"></label>
                            @error('files.*') <p class="error">{{ $message }}</p> @enderror
                            <div><button class="btn-primary" wire:loading.attr="disabled" wire:target="files,justify"><x-icon name="circle-check" class="size-4" /> {{ __('Enregistrer la justification') }}</button></div>
                        </form>
                    @endif
                </section>
            @elseif ($e->status === 'checked' && $ownRequest)
                <p class="card p-4 text-sm text-sand-700">{{ __('Votre demande attend l’approbation : vous ne pouvez pas signer votre propre demande.') }}</p>
            @endif

            @if ($e->description)<section class="card p-5 text-sm"><h2 class="mb-1 text-base">{{ __('Détails') }}</h2><p class="whitespace-pre-line text-ink-800">{{ $e->description }}</p></section>@endif

            {{-- Pièces --}}
            <section class="card p-5 sm:p-6">
                <h2 class="mb-3 text-lg">{{ __('Pièces') }}</h2>
                <ul class="divide-y divide-sand-100">
                    @forelse ($e->attachments as $a)
                        <li class="flex items-center gap-3 py-2 text-sm">
                            <x-icon name="file-text" class="size-4 text-sand-500" />
                            <a href="{{ route('finances.expenses.attachment', $a) }}" target="_blank" class="min-w-0 flex-1 truncate font-semibold text-ink-700 hover:underline">{{ $a->original_name }}</a>
                            <span class="badge bg-sand-100 text-sand-700">{{ __(\App\Models\ExpenseAttachment::KINDS[$a->kind] ?? $a->kind) }}</span>
                        </li>
                    @empty
                        <li class="py-2 text-sm text-sand-700">{{ __('Aucune pièce jointe.') }}</li>
                    @endforelse
                </ul>
                @if ($canAttach && ! $canJustify)
                    <form wire:submit="attach" class="mt-3 flex flex-wrap items-center gap-2">
                        <select wire:model="fileKind" class="input !w-auto" aria-label="{{ __('Type de pièce') }}">@foreach (\App\Models\ExpenseAttachment::KINDS as $k => $l)<option value="{{ $k }}">{{ __($l) }}</option>@endforeach</select>
                        <label class="btn-secondary cursor-pointer !min-h-0 !py-2"><x-icon name="upload" class="size-4" /> {{ count($files) ? trans_choice(':count fichier choisi|:count fichiers choisis', count($files)) : __('Choisir') }}
                            <input type="file" wire:model="files" multiple accept="image/*,application/pdf" class="sr-only"></label>
                        @if (count($files))<button class="btn-primary !min-h-0 !py-2" wire:loading.attr="disabled" wire:target="files,attach">{{ __('Ajouter') }}</button>@endif
                        @error('files') <p class="error w-full">{{ $message }}</p> @enderror @error('files.*') <p class="error w-full">{{ $message }}</p> @enderror
                    </form>
                @endif
            </section>
        </div>

        {{-- Historique --}}
        <aside class="space-y-5">
            <section class="card p-5 sm:p-6">
                <h2 class="mb-3 text-lg">{{ __('Historique') }}</h2>
                <ol class="space-y-3 text-sm">
                    <li class="flex gap-3"><x-icon name="upload" class="mt-0.5 size-4 text-sand-500" /><span><span class="font-semibold text-ink-800">{{ __('Demande') }}</span> · {{ $e->requester?->name }}<span class="block text-xs text-sand-700">{{ $e->created_at->translatedFormat('j M Y, H:i') }}</span></span></li>
                    @if ($e->checked_at)
                        <li class="flex gap-3"><x-icon name="check" class="mt-0.5 size-4 text-leaf-500" /><span><span class="font-semibold text-ink-800">{{ __('Contrôle') }}</span> · {{ $e->checker?->name }}<span class="block text-xs text-sand-700">{{ $e->checked_at->translatedFormat('j M Y, H:i') }}</span>@if ($e->check_note)<span class="block text-ink-700">«&nbsp;{{ $e->check_note }}&nbsp;»</span>@endif</span></li>
                    @endif
                    @foreach ($e->approvals as $ap)
                        <li class="flex gap-3"><x-icon :name="$ap->decision === 'approved' ? 'badge-check' : 'x'" @class(['mt-0.5 size-4', 'text-leaf-500' => $ap->decision === 'approved', 'text-terra-500' => $ap->decision !== 'approved']) />
                            <span><span class="font-semibold text-ink-800">{{ $ap->decision === 'approved' ? __('Signature') : __('Refus') }}</span> · {{ $ap->user?->name }}<span class="block text-xs text-sand-700">{{ $ap->created_at->translatedFormat('j M Y, H:i') }}</span>@if ($ap->note)<span class="block text-ink-700">«&nbsp;{{ $ap->note }}&nbsp;»</span>@endif</span></li>
                    @endforeach
                    @if ($e->status === 'checked')
                        <li class="flex gap-3 text-sand-700"><x-icon name="clock" class="mt-0.5 size-4" /><span>{{ __(':n signature(s) sur :r', ['n' => $e->approvedCount(), 'r' => $e->approvals_required]) }}</span></li>
                    @endif
                    @if ($e->disbursed_at)
                        <li class="flex gap-3"><x-icon name="banknote" class="mt-0.5 size-4 text-leaf-500" /><span><span class="font-semibold text-ink-800">{{ $e->is_advance ? __('Avance remise') : __('Décaissement') }}</span> · {{ $e->disburser?->name }} · {{ $e->account?->name }}
                            <span class="block text-xs text-sand-700">{{ $e->disbursed_at->translatedFormat('j M Y, H:i') }}@if ($e->transaction?->receipt_number) · {{ $e->transaction->receipt_number }}@endif</span>
                            @if ($e->justify_by && $e->status === 'disbursed')<span class="block text-xs font-semibold text-ochre-700">{{ __('À justifier avant le :d', ['d' => $e->justify_by->translatedFormat('j M Y')]) }}</span>@endif</span></li>
                    @endif
                    @if ($e->justified_at)
                        <li class="flex gap-3"><x-icon name="circle-check" class="mt-0.5 size-4 text-leaf-500" /><span><span class="font-semibold text-ink-800">{{ __('Justification') }}</span> · {{ $e->justifier?->name }}
                            <span class="block text-xs text-sand-700">{{ $e->justified_at->translatedFormat('j M Y, H:i') }} · {{ __('dépensé : :m', ['m' => Money::format($e->justified_amount, $e->currency)]) }}@if ((float) $e->amount - (float) $e->justified_amount > 0.004) · {{ __('rendu : :m', ['m' => Money::format((float) $e->amount - (float) $e->justified_amount, $e->currency)]) }}@endif</span>
                            @if ($e->justification_note)<span class="block text-ink-700">«&nbsp;{{ $e->justification_note }}&nbsp;»</span>@endif</span></li>
                    @endif
                </ol>
            </section>
            @if ($canCancel)
                <button type="button" class="btn-ghost w-full text-terra-600" @click="$dispatch('open-modal', { name: 'cancel' })"><x-icon name="x" class="size-4" /> {{ __('Annuler la demande') }}</button>
            @endif
        </aside>
    </div>

    <x-modal name="overrun" :title="__('Demander un dépassement du budget')">
        <form wire:submit="requestOverrun" class="space-y-4">
            <p class="text-sm text-ink-800">{{ __('Le pasteur décide. Dites-lui combien il manque et d’où viendra l’argent.') }}</p>
            <div><label for="ov-amount" class="label">{{ __('Montant du dépassement (USD)') }}</label><input wire:model="overrun.amount" id="ov-amount" type="number" step="0.01" min="0" class="input tabular">@error('overrun.amount') <p class="error">{{ $message }}</p> @enderror</div>
            @include('partials.overrun-source')
            <div><label for="ov-reason" class="label">{{ __('Pourquoi cette dépense ne peut pas attendre') }}</label><textarea wire:model="overrun.reason" id="ov-reason" rows="3" class="input"></textarea>@error('overrun.reason') <p class="error">{{ $message }}</p> @enderror</div>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'overrun' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Envoyer au pasteur') }}</button></div>
        </form>
    </x-modal>

    <x-modal name="reject" :title="__('Refuser la demande')">
        <form wire:submit="reject" class="space-y-4">
            <div><label for="rj-reason" class="label">{{ __('Motif, communiqué au demandeur') }}</label><textarea wire:model="reason" id="rj-reason" rows="3" class="input"></textarea>@error('reason') <p class="error">{{ $message }}</p> @enderror</div>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'reject' })">{{ __('Retour') }}</button><button class="btn-danger">{{ __('Refuser') }}</button></div>
        </form>
    </x-modal>

    <x-modal name="cancel" :title="__('Annuler la demande')">
        <form wire:submit="cancel" class="space-y-4">
            <p class="text-sm text-ink-800">{{ __('La demande :n sera annulée. Elle reste visible dans l’historique.', ['n' => $e->number]) }}</p>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'cancel' })">{{ __('Retour') }}</button><button class="btn-danger">{{ __('Annuler la demande') }}</button></div>
        </form>
    </x-modal>
</div>
