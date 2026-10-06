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
