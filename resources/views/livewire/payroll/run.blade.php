@php use App\Support\Money; use App\Models\PayRun; $r = $run; $perService = $r->schedule?->isPerService(); @endphp
<div>
    <a href="{{ route('payroll.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Paie') }}</a>

    <section class="wax wax-veil wax-veil-strong mb-5 overflow-hidden rounded-[22px] p-5 text-white sm:p-6">
        <p class="text-sm text-ochre-300">{{ $r->schedule?->name }} · {{ __(PayRun::STATUSES[$r->status]) }}</p>
        <h1 class="text-2xl font-semibold text-white">{{ __('Paie : :p', ['p' => $r->label()]) }}</h1>
        <div class="mt-3 flex flex-wrap gap-x-8 gap-y-2">
            @foreach ($totals as $currency => $t)
                <div><p class="text-xs text-ink-100">{{ __('Net à payer (:c)', ['c' => $currency]) }}</p><p class="text-2xl font-semibold tabular text-white">{{ Money::format($t['net'], $currency) }}</p>
                    <p class="text-xs text-ink-100">{{ __('Brut :g · retenues :d', ['g' => Money::format($t['gross'], $currency), 'd' => Money::format($t['deductions'] + $t['advances'], $currency)]) }}</p></div>
            @endforeach
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            <a href="{{ route('payroll.print', $r) }}" target="_blank" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="printer" class="size-4" /> {{ __('État de paie') }}</a>
            @if ($canEdit)<button type="button" wire:click="recomputeAll" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="refresh-cw" class="size-4" /> {{ __('Mettre à jour les bulletins') }}</button>@endif
            @if ($canCancel)<button type="button" wire:click="cancel" wire:confirm="{{ __('Annuler cette paie ?') }}" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25 sm:ml-auto">{{ __('Annuler la paie') }}</button>@endif
        </div>
    </section>

    @if ($r->return_note && $r->status === 'draft')
        <p class="mb-4 rounded-2xl border border-terra-100 bg-terra-50 p-4 text-sm text-terra-700"><span class="font-semibold">{{ __('Renvoyée par le pasteur :') }}</span> {{ $r->return_note }}</p>
    @endif

    {{-- L'étape --}}
    @if ($canEdit || $r->status === 'submitted' || $canPay)
        <section class="card mb-5 border-ochre-300 p-5 sm:p-6">
            @if ($canEdit)
                <h2 class="text-lg">{{ __('Présenter au pasteur') }}</h2>
                <p class="mb-3 text-sm text-sand-700">{{ $perService ? __('Saisissez le nombre de prestations de chacun, ajoutez les primes ou retenues ponctuelles, puis présentez la paie.') : __('Vérifiez les bulletins, ajoutez les primes ou retenues ponctuelles, puis présentez la paie.') }}</p>
                @error('note') <p class="error mb-2">{{ $message }}</p> @enderror
                <button type="button" wire:click="submit" class="btn-primary"><x-icon name="upload" class="size-4" /> {{ __('Présenter la paie') }}</button>
            @elseif ($canApprove)
                <h2 class="text-lg">{{ __('Approbation') }}</h2>
                <p class="mb-3 text-sm text-sand-700">{{ __('Présentée par :n le :d.', ['n' => $r->submitter?->name, 'd' => $r->submitted_at->translatedFormat('j M Y')]) }}</p>
                <form wire:submit="approve" class="space-y-3">
                    <textarea wire:model="note" rows="2" class="input" placeholder="{{ __('Remarque (facultatif ; obligatoire pour renvoyer)') }}" aria-label="{{ __('Remarque') }}"></textarea>
                    @error('note') <p class="error">{{ $message }}</p> @enderror
                    <div class="flex flex-wrap gap-2"><button class="btn-primary"><x-icon name="badge-check" class="size-4" /> {{ __('Approuver la paie') }}</button>
                        <button type="button" wire:click="sendBack" class="btn-ghost text-terra-600">{{ __('Renvoyer à la finance') }}</button></div>
                </form>
            @elseif ($r->status === 'submitted')
                <p class="text-sm text-ink-800"><x-icon name="clock" class="mr-1 inline size-4 text-ochre-600" /> {{ __('Présentée par :n : en attente de l’approbation du pasteur.', ['n' => $r->submitter?->name]) }}</p>
            @elseif ($canPay)
                <h2 class="text-lg">{{ __('Payer') }}</h2>
                <p class="mb-3 text-sm text-sand-700">{{ __('Approuvée par :n le :d. Choisissez le compte ; si la devise payée diffère, l’équivalent est calculé au taux du jour.', ['n' => $r->approver?->name, 'd' => $r->approved_at->translatedFormat('j M Y')]) }}</p>
                <div class="space-y-3">
                    @foreach ($payment as $currency => $choice)
                        <div class="flex flex-wrap items-end gap-2 rounded-xl bg-sand-50 p-3">
                            <p class="w-full text-sm font-semibold text-ink-800">{{ __('Bulletins en :c : :m', ['c' => $currency, 'm' => Money::format($totals[$currency]['net'] ?? 0, $currency)]) }}</p>
                            <div class="min-w-48 flex-1"><label class="label" for="pay-acc-{{ $currency }}">{{ __('Compte') }}</label>
                                <select wire:model.live="payment.{{ $currency }}.account" id="pay-acc-{{ $currency }}" class="input">@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
                            <div><label class="label" for="pay-cur-{{ $currency }}">{{ __('Payé en') }}</label>
                                <select wire:model.live="payment.{{ $currency }}.currency" id="pay-cur-{{ $currency }}" class="input">@foreach ($accounts->firstWhere('id', (int) $choice['account'])?->currencies->where('is_active', true) ?? [] as $c)<option value="{{ $c->currency }}">{{ $c->currency }}</option>@endforeach</select></div>
                            <button type="button" wire:click="pay('{{ $currency }}')" wire:confirm="{{ __('Enregistrer la sortie de toute la paie en :c ?', ['c' => $currency]) }}" class="btn-primary"><x-icon name="banknote" class="size-4" /> {{ __('Payer') }}</button>
                            @php $bal = $balances->first(fn ($b) => $b['account']->id === (int) $choice['account'] && $b['currency'] === $choice['currency']); @endphp
                            @if ($bal)<p class="w-full text-xs text-sand-700">{{ __('Solde : :m', ['m' => Money::format($bal['balance'], $bal['currency'])]) }}</p>@endif
                            @error("payment.$currency.account") <p class="error w-full">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    @elseif ($r->approved_at)
        <p class="mb-5 rounded-2xl border border-leaf-100 bg-leaf-50 p-4 text-sm text-leaf-600"><x-icon name="badge-check" class="mr-1 inline size-4" /> {{ __('Présentée par :s, approuvée par :n le :d.', ['s' => $r->submitter?->name, 'n' => $r->approver?->name, 'd' => $r->approved_at->translatedFormat('j M Y')]) }}@if ($r->paid_at) {{ __('Payée le :d.', ['d' => $r->paid_at->translatedFormat('j M Y')]) }}@endif</p>
    @endif

    {{-- Le budget des salaires --}}
    @if ($budgetLines)
        @php $short = collect($budgetLines)->sum('missing') > 0; @endphp
        <section @class(['card mb-5 p-5 sm:p-6', 'border-terra-300' => $short])>
            <h2 class="mb-1 text-lg">{{ __('Budget des salaires') }}</h2>
            <p class="mb-3 text-sm text-sand-700">{{ __('Ce que la paie demande, face à ce qui reste sur la ligne « Rémunérations et motivations » de chaque département, en dollars.') }}</p>
            <ul class="divide-y divide-sand-100 text-sm">
                @foreach ($budgetLines as $key => $l)
                    <li class="flex flex-wrap items-center gap-x-4 gap-y-1 py-2.5">
                        <span class="min-w-0 flex-1 font-semibold text-ink-800">{{ $l['department'] }}@if ($l['unbudgeted'])<span class="block text-xs font-normal text-terra-600">{{ __('Pas de ligne des salaires au budget') }}</span>@endif</span>
                        <span class="tabular">{{ __('Paie : :n', ['n' => Money::format($l['needed'], 'USD')]) }}</span>
                        <span @class(['tabular', 'text-terra-600 font-semibold' => $l['missing'] > 0, 'text-ink-700' => $l['missing'] <= 0])>{{ __('Disponible : :a', ['a' => Money::format($l['available'], 'USD')]) }}</span>
                        @if ($l['missing'] > 0 && $canAskOverrun && ! $overruns->where('department_id', $l['department_id'])->where('status', 'pending')->count())
                            <button type="button" wire:click="askOverrun('{{ $key }}')" class="btn-primary !min-h-0 !py-1.5 text-sm"><x-icon name="triangle-alert" class="size-4" /> {{ __('Demander un dépassement de :m', ['m' => Money::format($l['missing'], 'USD')]) }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if ($short)
                <p class="mt-2 rounded-xl bg-terra-50 p-3 text-sm text-terra-700">{{ __('La paie dépasse le budget des salaires : elle ne peut être présentée qu’après un dépassement autorisé par le pasteur, qui dit d’où viendra l’argent.') }}</p>
            @else
                <p class="mt-2 text-sm text-leaf-600"><x-icon name="circle-check" class="mr-1 inline size-4" /> {{ __('La paie tient dans le budget des salaires.') }}</p>
            @endif
        </section>
    @endif

    @foreach ($overruns as $o)
        <section @class(['card mb-5 p-5 sm:p-6', 'border-ochre-300' => $o->status === 'pending'])>
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="flex-1 text-lg">{{ __('Dépassement de :m · :d', ['m' => Money::format($o->amount, 'USD'), 'd' => $o->department?->name]) }}</h2>
                <span @class(['badge', 'bg-ochre-100 text-ochre-700' => $o->status === 'pending', 'bg-leaf-50 text-leaf-600' => $o->status === 'authorized', 'bg-terra-50 text-terra-600' => $o->status === 'refused'])>{{ __(\App\Models\BudgetOverrun::STATUSES[$o->status]) }}</span>
            </div>
            <p class="mt-1 text-sm text-ink-800"><span class="font-semibold">{{ __('D’où vient l’argent :') }}</span> {{ $o->sourceLabel() }}</p>
            <p class="text-sm text-ink-700">{{ $o->reason }}</p>
            <p class="mt-1 text-xs text-sand-700">{{ __('Demandé par :n le :d', ['n' => $o->requester?->name, 'd' => $o->created_at->translatedFormat('j M Y')]) }}@if ($o->decided_at) · {{ $o->status === 'authorized' ? __('autorisé') : __('refusé') }} {{ __('par :n le :d', ['n' => $o->decider?->name, 'd' => $o->decided_at->translatedFormat('j M Y')]) }}@endif @if ($o->decision_note) · « {{ $o->decision_note }} »@endif</p>
            @if ($o->status === 'pending' && $canDecideOverrun && $o->requested_by !== auth()->id())
                <div class="mt-3 space-y-2">
                    <textarea wire:model="decisionNote" rows="2" class="input" placeholder="{{ __('Remarque (obligatoire pour refuser)') }}" aria-label="{{ __('Remarque') }}"></textarea>
                    @error('decisionNote') <p class="error">{{ $message }}</p> @enderror
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="decideOverrun({{ $o->id }}, true)" class="btn-primary"><x-icon name="badge-check" class="size-4" /> {{ __('Autoriser le dépassement') }}</button>
                        <button type="button" wire:click="decideOverrun({{ $o->id }}, false)" class="btn-ghost text-terra-600">{{ __('Refuser') }}</button>
                    </div>
                </div>
            @elseif ($o->status === 'pending')
                <p class="mt-2 text-sm text-ochre-700"><x-icon name="clock" class="mr-1 inline size-4" /> {{ __('En attente de la décision du pasteur.') }}</p>
            @endif
        </section>
    @endforeach

    {{-- Les bulletins --}}
    <section class="card p-5 sm:p-6">
        <h2 class="mb-3 text-lg">{{ __('Bulletins') }}</h2>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[40rem] border-collapse text-sm">
                <thead class="border-b-2 border-ink-700 text-xs text-ink-700">
                    <tr>
                        <th class="px-2 py-1.5 text-left font-semibold">{{ __('Bénéficiaire') }}</th>
                        @if ($perService)<th class="px-2 py-1.5 text-right font-semibold">{{ ucfirst($r->schedule->service_label ?: __('prestations')) }}</th>@endif
                        @foreach ([__('Base'), __('Brut'), __('Retenues'), __('Net')] as $h)<th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold">{{ $h }}</th>@endforeach
                        <th class="px-2 py-1.5"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($r->slips as $s)
                        <tr wire:key="s-{{ $s->id }}" class="border-b border-sand-100">
                            <td class="px-2 py-2"><span class="font-semibold text-ink-800">{{ $s->payee->displayName() }}</span><span class="block text-xs text-sand-700">{{ $s->payee->position }}@if ($s->adjustments) · {{ trans_choice(':count ajustement|:count ajustements', count($s->adjustments)) }}@endif</span></td>
                            @if ($perService)
                                <td class="px-2 py-2 text-right">@if ($canEdit)<input wire:model.blur="quantities.{{ $s->id }}" type="number" step="1" min="0" class="input !w-20 !py-1 text-right tabular" aria-label="{{ __('Prestations') }}">@else<span class="tabular">{{ (float) $s->quantity }}</span>@endif</td>
                            @endif
                            <td class="whitespace-nowrap px-2 py-2 text-right tabular">{{ Money::format($s->base, $s->currency) }}</td>
                            <td class="whitespace-nowrap px-2 py-2 text-right tabular">{{ Money::format($s->gross, $s->currency) }}</td>
                            <td class="whitespace-nowrap px-2 py-2 text-right tabular">{{ ((float) $s->deductions + (float) $s->advance_total) ? Money::format((float) $s->deductions + (float) $s->advance_total, $s->currency) : '·' }}</td>
                            <td class="whitespace-nowrap px-2 py-2 text-right font-semibold tabular text-ink-800">{{ Money::format($s->net, $s->currency) }}@if ($s->paid_at)<span class="block text-xs font-normal text-leaf-600">{{ __('payé') }}@if ($s->paid_currency !== $s->currency) · {{ Money::format($s->paid_amount, $s->paid_currency) }}@endif</span>@endif</td>
                            <td class="whitespace-nowrap px-2 py-2 text-right">
                                @if ($canEdit)<button type="button" wire:click="editSlip({{ $s->id }})" class="rounded-lg p-1.5 text-sand-500 hover:bg-sand-100" aria-label="{{ __('Primes et retenues') }}"><x-icon name="pencil" class="size-4" /></button>@endif
                                <a href="{{ route('payroll.slip', $s) }}" target="_blank" class="rounded-lg p-1.5 text-sand-500 hover:bg-sand-100" aria-label="{{ __('Bulletin') }}"><x-icon name="file-text" class="inline size-4" /></a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-2 py-4 text-sand-700">{{ __('Personne n’est payé selon ce rythme : ajoutez des bénéficiaires.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <x-modal name="overrun" :title="__('Dépasser le budget des salaires')">
        <form wire:submit="requestOverrun" class="space-y-4">
            <p class="text-sm text-ink-800">{{ __('Le pasteur décide. Dites-lui combien il manque et d’où viendra l’argent.') }}</p>
            <div><label for="ov-amount" class="label">{{ __('Montant du dépassement (USD)') }}</label><input wire:model="overrun.amount" id="ov-amount" type="number" step="0.01" min="0" class="input tabular">@error('overrun.amount') <p class="error">{{ $message }}</p> @enderror</div>
            @include('partials.overrun-source')
            <div><label for="ov-reason" class="label">{{ __('Pourquoi la paie dépasse le budget') }}</label><textarea wire:model="overrun.reason" id="ov-reason" rows="3" class="input" placeholder="{{ __('Exemple : recrutement d’une deuxième sentinelle en cours d’année') }}"></textarea>@error('overrun.reason') <p class="error">{{ $message }}</p> @enderror</div>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'overrun' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Envoyer au pasteur') }}</button></div>
        </form>
    </x-modal>

    <x-modal name="slip" :title="$slip ? $slip->payee->displayName() : ''">
        @if ($slip)
            <div class="space-y-4">
                <dl class="space-y-1 text-sm">
                    <div class="flex justify-between"><dt>{{ __('Base') }}</dt><dd class="tabular">{{ Money::format($slip->base, $slip->currency) }}</dd></div>
                    @foreach ($slip->details['lines'] ?? [] as $l)
                        <div class="flex justify-between"><dt>{{ $l['label'] }}</dt><dd @class(['tabular', 'text-leaf-600' => $l['kind'] === 'earning', 'text-terra-600' => $l['kind'] === 'deduction'])>{{ $l['kind'] === 'earning' ? '+' : '−' }} {{ Money::format($l['amount'], $slip->currency) }}</dd></div>
                    @endforeach
                    @foreach ($slip->details['advances'] ?? [] as $a)
                        <div class="flex justify-between"><dt>{{ $a['label'] }}</dt><dd class="tabular text-terra-600">− {{ Money::format($a['amount'], $slip->currency) }}</dd></div>
                    @endforeach
                    <div class="flex justify-between border-t border-sand-200 pt-1 font-semibold"><dt>{{ __('Net') }}</dt><dd class="tabular">{{ Money::format($slip->net, $slip->currency) }}</dd></div>
                </dl>
                @if ($slip->adjustments)
                    <ul class="space-y-1 text-sm">
                        @foreach ($slip->adjustments as $i => $a)
                            <li class="flex items-center gap-2 rounded-lg bg-sand-50 px-2 py-1"><span class="flex-1">{{ $a['label'] }} ({{ $a['kind'] === 'earning' ? __('prime') : __('retenue') }})</span><button type="button" wire:click="removeAdjustment({{ $i }})" class="rounded p-1 text-sand-500 hover:text-terra-600" aria-label="{{ __('Retirer') }}"><x-icon name="x" class="size-4" /></button></li>
                        @endforeach
                    </ul>
                @endif
                <form wire:submit="addAdjustment" class="space-y-3 rounded-xl border border-sand-200 p-3">
                    <p class="text-sm font-semibold text-ink-800">{{ __('Prime ou retenue pour cette paie seulement') }}</p>
                    <input wire:model="adjustment.label" class="input" placeholder="{{ __('Exemple : prime de Noël') }}" aria-label="{{ __('Libellé') }}">@error('adjustment.label') <p class="error">{{ $message }}</p> @enderror
                    <div class="grid grid-cols-2 gap-2">
                        <select wire:model="adjustment.kind" class="input" aria-label="{{ __('Type') }}"><option value="earning">{{ __('Prime') }}</option><option value="deduction">{{ __('Retenue') }}</option></select>
                        <input wire:model="adjustment.amount" type="number" step="0.01" min="0" class="input tabular" placeholder="{{ $slip->currency }}" aria-label="{{ __('Montant') }}">
                    </div>
                    @error('adjustment.amount') <p class="error">{{ $message }}</p> @enderror
                    <div class="flex justify-end"><button class="btn-secondary !min-h-0 !py-1.5"><x-icon name="plus" class="size-4" /> {{ __('Ajouter') }}</button></div>
                </form>
            </div>
        @endif
    </x-modal>
</div>
