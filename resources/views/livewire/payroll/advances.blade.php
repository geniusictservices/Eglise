@php use App\Support\Money; use App\Models\SalaryAdvance; @endphp
<div>
    <a href="{{ route('payroll.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Paie') }}</a>
    <x-page-header :title="__('Avances sur salaire')" :description="__('La finance propose l’avance, le pasteur l’approuve, la finance la paie. Elle est ensuite retenue automatiquement sur les paies suivantes.')">
        <x-slot:actions>
            @if ($canManage)<button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Nouvelle avance') }}</button>@endif
        </x-slot:actions>
    </x-page-header>

    <ul class="space-y-3">
        @forelse ($advances as $a)
            @php $repaid = $a->repaid(); $percent = (float) $a->amount > 0 ? (int) round($repaid / (float) $a->amount * 100) : 0; @endphp
            <li wire:key="a-{{ $a->id }}" @class(['card p-4 sm:p-5', 'border-ochre-300' => in_array($a->status, ['requested', 'approved'], true)])>
                <div class="flex flex-wrap items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-ink-800">{{ $a->payee->displayName() }} · {{ Money::format($a->amount, $a->currency) }}</p>
                        <p class="text-sm text-sand-700">{{ trans_choice('Retenue en :count fois|Retenue en :count fois', $a->installments) }}@if ($a->installments > 1) ({{ __(':m par paie', ['m' => Money::format((float) $a->amount / $a->installments, $a->currency)]) }})@endif · {{ __('proposée par :n le :d', ['n' => $a->requester?->name, 'd' => $a->created_at->translatedFormat('j M Y')]) }}</p>
                        @if ($a->reason)<p class="mt-1 text-sm text-ink-700">{{ $a->reason }}</p>@endif
                        @if ($a->approver)<p class="text-xs text-sand-700">{{ $a->status === 'refused' ? __('Refusée par :n', ['n' => $a->approver->name]) : __('Approuvée par :n le :d', ['n' => $a->approver->name, 'd' => $a->approved_at->translatedFormat('j M Y')]) }}@if ($a->decision_note) · « {{ $a->decision_note }} »@endif</p>@endif
                    </div>
                    <span @class(['badge', 'bg-ochre-100 text-ochre-700' => in_array($a->status, ['requested', 'approved', 'paid'], true), 'bg-leaf-50 text-leaf-600' => $a->status === 'repaid', 'bg-sand-100 text-sand-700' => in_array($a->status, ['refused', 'cancelled'], true)])>{{ __(SalaryAdvance::STATUSES[$a->status]) }}</span>
                </div>
                @if (in_array($a->status, ['paid', 'repaid'], true))
                    <div class="mt-3 flex items-center gap-3">
                        <span class="block h-2 flex-1 overflow-hidden rounded-full bg-sand-100"><span class="block h-full rounded-full bg-leaf-500" style="width: {{ $percent }}%"></span></span>
                        <span class="text-xs font-semibold tabular text-ink-700">{{ __('Retenu :r sur :m', ['r' => Money::format($repaid, $a->currency), 'm' => Money::format($a->amount, $a->currency)]) }}</span>
                    </div>
                @endif
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    @if ($a->status === 'requested' && $canApprove && $a->requested_by !== auth()->id())
                        <input wire:model="note" class="input !w-auto min-w-56 flex-1 !py-1.5 text-sm" placeholder="{{ __('Remarque (obligatoire pour refuser)') }}" aria-label="{{ __('Remarque') }}">
                        <button type="button" wire:click="decide({{ $a->id }}, true)" class="btn-primary !min-h-0 !py-1.5 text-sm"><x-icon name="badge-check" class="size-4" /> {{ __('Approuver') }}</button>
                        <button type="button" wire:click="decide({{ $a->id }}, false)" class="btn-ghost !min-h-0 !py-1.5 text-sm text-terra-600">{{ __('Refuser') }}</button>
                        @error("note-$a->id") <p class="error w-full">{{ $message }}</p> @enderror
                    @elseif ($a->status === 'requested')
                        <p class="text-sm text-ochre-700"><x-icon name="clock" class="mr-1 inline size-4" /> {{ __('En attente de l’approbation du pasteur.') }}</p>
                    @endif
                    @if ($a->status === 'approved' && $canManage)
                        <button type="button" wire:click="askPay({{ $a->id }})" class="btn-primary !min-h-0 !py-1.5 text-sm"><x-icon name="banknote" class="size-4" /> {{ __('Payer l’avance') }}</button>
                    @endif
                    @if ((in_array($a->status, ['requested', 'approved'], true) || ($a->status === 'paid' && $a->repayments->isEmpty())) && $canManage)
                        <button type="button" wire:click="cancel({{ $a->id }})" wire:confirm="{{ $a->status === 'paid' ? __('Annuler cette avance ? Son paiement sera annulé dans le journal et l’argent reviendra au compte.') : __('Annuler cette avance ?') }}" class="btn-ghost !min-h-0 !py-1.5 text-sm text-terra-600">{{ __('Annuler') }}</button>
                    @endif
                </div>
            </li>
        @empty
            <li class="card p-8 text-center text-sand-700">{{ __('Aucune avance sur salaire.') }}</li>
        @endforelse
    </ul>

    <x-modal name="advance" :title="__('Nouvelle avance sur salaire')">
        <form wire:submit="save" class="space-y-4">
            <div><label for="av-payee" class="label">{{ __('Pour') }}</label><select wire:model.live="form.payee_id" id="av-payee" class="input">@foreach ($payees as $p)<option value="{{ $p->id }}">{{ $p->displayName() }}@if ($p->position) · {{ $p->position }}@endif</option>@endforeach</select>@error('form.payee_id') <p class="error">{{ $message }}</p> @enderror</div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label for="av-amount" class="label">{{ __('Montant (:c)', ['c' => $chosen?->currency ?? 'USD']) }}</label><input wire:model.live.debounce.300ms="form.amount" id="av-amount" type="number" step="0.01" min="0" class="input tabular">@error('form.amount') <p class="error">{{ $message }}</p> @enderror</div>
                <div><label for="av-inst" class="label">{{ __('Retenue en … fois') }}</label><input wire:model.live="form.installments" id="av-inst" type="number" min="1" max="12" class="input tabular">@error('form.installments') <p class="error">{{ $message }}</p> @enderror</div>
            </div>
            @if ($each)
                <p @class(['rounded-xl p-3 text-sm', 'bg-terra-50 text-terra-700' => $usual !== null && $each > $usual, 'bg-sand-50 text-ink-800' => ! ($usual !== null && $each > $usual)])>
                    {{ __('Retenue de :m à chaque paie.', ['m' => Money::format($each, $chosen->currency)]) }}
                    @if ($usual !== null) {{ $each > $usual ? __('C’est plus que son net habituel (:n) : la retenue sera limitée à ce qui reste, et l’avance durera plus longtemps.', ['n' => Money::format($usual, $chosen->currency)]) : __('Son net habituel : :n.', ['n' => Money::format($usual, $chosen->currency)]) }}@endif
                </p>
            @endif
            <div><label for="av-reason" class="label">{{ __('Motif') }}</label><textarea wire:model="form.reason" id="av-reason" rows="2" class="input" placeholder="{{ __('Exemple : frais de scolarité des enfants') }}"></textarea>@error('form.reason') <p class="error">{{ $message }}</p> @enderror</div>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'advance' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Proposer au pasteur') }}</button></div>
        </form>
    </x-modal>

    <x-modal name="pay" :title="__('Payer l’avance')">
        <form wire:submit="pay" class="space-y-4">
            @if ($paying)<p class="text-sm text-ink-800">{{ $paying->payee->displayName() }} · <span class="font-semibold">{{ Money::format($paying->amount, $paying->currency) }}</span></p>@endif
            <div class="grid gap-4 sm:grid-cols-[1fr_7rem]">
                <div><label for="ap-account" class="label">{{ __('Compte') }}</label><select wire:model.live="accountId" id="ap-account" class="input">@foreach ($accounts as $acc)<option value="{{ $acc->id }}">{{ $acc->name }}</option>@endforeach</select>@error('accountId') <p class="error">{{ $message }}</p> @enderror</div>
                <div><label for="ap-cur" class="label">{{ __('Payé en') }}</label><select wire:model="payCurrency" id="ap-cur" class="input">@foreach ($accounts->firstWhere('id', (int) $accountId)?->currencies->where('is_active', true) ?? [] as $c)<option value="{{ $c->currency }}">{{ $c->currency }}</option>@endforeach</select></div>
            </div>
            <p class="hint">{{ __('Payée dans une autre devise, l’avance est convertie au taux du jour ; elle reste retenue dans la devise de la paie de la personne.') }}</p>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'pay' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Payer') }}</button></div>
        </form>
    </x-modal>
</div>
