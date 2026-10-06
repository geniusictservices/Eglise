@php use App\Support\Money; @endphp
<div>
    <a href="{{ route('finances.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Finances') }}</a>
    <x-page-header :title="__('Paiements déclarés')" :description="__('Les paiements faits par mobile money : la personne donne l’ID de la transaction et une capture ; la finance vérifie sur son téléphone, puis valide ou rejette.')">
        <x-slot:actions>
            @if ($canDeclare)<button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Déclarer un paiement') }}</button>@endif
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-wrap gap-2">
        @foreach (\App\Models\PaymentDeclaration::STATUSES as $key => $label)
            <button type="button" wire:click="$set('status', '{{ $key }}')" @class(['chip', '!border-ink-700 !bg-ink-700 !text-white' => $status === $key])>{{ __($label) }}@if ($key === 'pending' && $pendingCount) <span class="badge bg-ochre-500 text-on-accent">{{ $pendingCount }}</span>@endif</button>
        @endforeach
    </div>

    <ul class="space-y-2.5">
        @forelse ($declarations as $d)
            <li wire:key="d-{{ $d->id }}" class="card flex flex-wrap items-center gap-x-4 gap-y-2 p-4">
                <span class="icon-tile bg-leaf-50 text-leaf-600"><x-icon name="smartphone" class="size-5" /></span>
                <div class="min-w-0 flex-1">
                    <p class="font-semibold text-ink-800">{{ $d->declarantName() }} <span class="font-normal text-sand-700">· {{ $d->pledge ? __('Promesse : :c', ['c' => $d->pledge->campaign?->name ?? __('générale')]) : $d->category?->name }}</span></p>
                    <p class="text-sm text-sand-700">{{ $d->operator }} · <span class="font-mono">{{ $d->transaction_reference }}</span> · {{ __('payé le :d', ['d' => $d->paid_on->translatedFormat('j M Y')]) }}@if ($d->source === 'member') · {{ __('déclaré par le membre') }}@endif</p>
                    @if ($d->status === 'rejected')<p class="text-sm text-terra-600">{{ __('Rejeté : :r', ['r' => $d->reject_reason]) }}</p>@endif
                    @if ($d->status === 'validated')<p class="text-xs text-sand-700">{{ __('Validé le :d par :n', ['d' => $d->reviewed_at->translatedFormat('j M'), 'n' => $d->reviewer?->name]) }}</p>@endif
                </div>
                <span class="text-lg font-semibold tabular text-ink-800">{{ Money::format($d->amount, $d->currency) }}</span>
                @if ($d->status === 'pending' && $canValidate)
                    <button type="button" wire:click="review({{ $d->id }})" class="btn-primary !min-h-0 !py-2">{{ __('Vérifier') }}</button>
                @elseif ($d->status === 'validated' && $d->finance_transaction_id)
                    <a href="{{ route('finances.receipt', $d->finance_transaction_id) }}" target="_blank" class="btn-secondary !min-h-0 !py-2"><x-icon name="printer" class="size-4" /> {{ __('Reçu') }}</a>
                @endif
            </li>
        @empty
            <li class="card p-8 text-center text-sand-700">{{ $status === 'pending' ? __('Aucun paiement à vérifier.') : __('Rien ici pour le moment.') }}</li>
        @endforelse
    </ul>

    {{-- Déclarer un paiement --}}
    <x-modal name="declaration" :title="__('Déclarer un paiement mobile money')" max-width="max-w-xl">
        <form wire:submit="save" class="space-y-4">
            <div>
                <p class="label">{{ __('Qui a payé ?') }}</p>
                @if ($member)
                    <div class="flex items-center gap-3 rounded-xl bg-ochre-50 px-3 py-2">
                        <span class="flex-1 text-sm"><span class="font-semibold text-ink-800">{{ $member->officialName() }}</span> <span class="font-mono text-xs text-sand-700">{{ $member->number }}</span></span>
                        <button type="button" wire:click="$set('memberId', null)" class="rounded-lg p-1.5 text-sand-500 hover:bg-white" aria-label="{{ __('Changer') }}"><x-icon name="x" class="size-4" /></button>
                    </div>
                @else
                    <input wire:model.live.debounce.300ms="memberSearch" type="search" class="input" placeholder="{{ __('Membre : nom, numéro ou téléphone') }}" aria-label="{{ __('Rechercher le membre') }}">
                    <ul class="mt-1 space-y-1">
                        @foreach ($candidates as $c)
                            <li><button type="button" wire:click="chooseMember({{ $c->id }})" class="flex w-full gap-2 rounded-xl px-3 py-2 text-left text-sm hover:bg-sand-100"><span class="font-semibold text-ink-700">{{ $c->officialName() }}</span> <span class="font-mono text-xs text-sand-700">{{ $c->number }}</span></button></li>
                        @endforeach
                    </ul>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        <input wire:model="form.declarant_name" class="input" placeholder="{{ __('… ou nom de la personne') }}" aria-label="{{ __('Nom') }}">
                        <input wire:model="form.declarant_phone" type="tel" class="input" placeholder="{{ __('Son téléphone') }}" aria-label="{{ __('Téléphone') }}">
                    </div>
                @endif
                @error('memberId') <p class="error">{{ $message }}</p> @enderror
                @error('form.declarant_phone') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="dc-purpose" class="label">{{ __('Pour') }}</label>
                <select wire:model="form.purpose" id="dc-purpose" class="input">
                    @foreach ($pledges as $p)<option value="pledge:{{ $p->id }}">{{ __('Promesse : :c', ['c' => $p->campaign?->name ?? __('générale')]) }}</option>@endforeach
                    @foreach ($categories as $c)<option value="category:{{ $c->id }}">{{ $c->name }}</option>@endforeach
                </select>
            </div>
            <div class="grid gap-4 sm:grid-cols-[1fr_6rem_9rem]">
                <div><label for="dc-amount" class="label">{{ __('Montant') }}</label><input wire:model="form.amount" id="dc-amount" type="number" step="0.01" min="0" class="input tabular">@error('form.amount') <p class="error">{{ $message }}</p> @enderror</div>
                <div><label for="dc-cur" class="label">{{ __('Devise') }}</label><select wire:model="form.currency" id="dc-cur" class="input">@foreach ($currencies as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select></div>
                <div><label for="dc-op" class="label">{{ __('Opérateur') }}</label><select wire:model="form.operator" id="dc-op" class="input">@foreach (\App\Livewire\Finances\Declarations\Index::OPERATORS as $o)<option value="{{ $o }}">{{ $o }}</option>@endforeach</select></div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label for="dc-ref" class="label">{{ __('ID de la transaction') }}</label><input wire:model="form.transaction_reference" id="dc-ref" class="input font-mono uppercase">@error('form.transaction_reference') <p class="error">{{ $message }}</p> @enderror</div>
                <div><label for="dc-date" class="label">{{ __('Date du paiement') }}</label><input wire:model="form.paid_on" id="dc-date" type="date" max="{{ today()->toDateString() }}" class="input"></div>
            </div>
            <div>
                <label class="label">{{ __('Capture d’écran du message de confirmation') }}</label>
                <label class="btn-secondary cursor-pointer !min-h-0 !py-2"><x-icon name="image" class="size-4" /> {{ $screenshot ? __('Capture choisie') : __('Choisir la capture') }}
                    <input type="file" wire:model="screenshot" accept="image/*" class="sr-only"></label>
                @error('screenshot') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div><label for="dc-msg" class="label">{{ __('Message (facultatif)') }}</label><input wire:model="form.message" id="dc-msg" class="input"></div>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'declaration' })">{{ __('Annuler') }}</button><button class="btn-primary" wire:loading.attr="disabled" wire:target="screenshot,save">{{ __('Enregistrer') }}</button></div>
        </form>
    </x-modal>

    {{-- Vérifier --}}
    <x-modal name="review" :title="__('Vérifier le paiement')" max-width="max-w-2xl">
        @if ($review)
            <div class="grid gap-5 sm:grid-cols-[1fr_14rem]">
                <div class="space-y-3">
                    <dl class="space-y-1.5 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-sand-700">{{ __('Payé par') }}</dt><dd class="font-semibold text-ink-800">{{ $review->declarantName() }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-sand-700">{{ __('Montant') }}</dt><dd class="text-lg font-semibold tabular text-ink-800">{{ Money::format($review->amount, $review->currency) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-sand-700">{{ __('Opérateur') }}</dt><dd>{{ $review->operator }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-sand-700">{{ __('ID') }}</dt><dd class="font-mono font-semibold">{{ $review->transaction_reference }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-sand-700">{{ __('Date') }}</dt><dd>{{ $review->paid_on->translatedFormat('j F Y') }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-sand-700">{{ __('Pour') }}</dt><dd class="text-right">{{ $review->pledge ? __('Promesse : :c', ['c' => $review->pledge->campaign?->name ?? __('générale')]) : $review->category?->name }}</dd></div>
                        @if ($review->message)<div class="text-sand-700">« {{ $review->message }} »</div>@endif
                    </dl>
                    @if ($duplicates['declarations'] || $duplicates['transactions'])
                        <p class="rounded-xl bg-terra-50 p-3 text-sm font-semibold text-terra-700"><x-icon name="triangle-alert" class="mr-1 inline size-4" /> {{ __('Cet ID a déjà été enregistré : c’est peut-être un doublon.') }}</p>
                    @endif
                    <p class="rounded-xl bg-ochre-50 p-3 text-sm text-ink-800">{{ __('Vérifiez sur le téléphone du compte que cette transaction a bien été reçue, avec ce montant.') }}</p>
                    <div>
                        <label for="rv-account" class="label">{{ __('Compte qui a reçu le paiement') }}</label>
                        <select wire:model="accountId" id="rv-account" class="input">@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>
                        @error('accountId') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <button type="button" wire:click="approve" class="btn-primary w-full"><x-icon name="circle-check" class="size-4" /> {{ __('Valider le paiement') }}</button>
                    <div class="flex gap-2 border-t border-sand-100 pt-3">
                        <input wire:model="rejectReason" class="input" placeholder="{{ __('Motif du rejet') }}" aria-label="{{ __('Motif du rejet') }}">
                        <button type="button" wire:click="reject" class="btn-danger shrink-0">{{ __('Rejeter') }}</button>
                    </div>
                    @error('rejectReason') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    @if ($review->screenshot_path)
                        <a href="{{ route('finances.declarations.screenshot', $review) }}" target="_blank"><img src="{{ route('finances.declarations.screenshot', $review) }}" alt="{{ __('Capture du paiement') }}" class="max-h-96 w-full rounded-xl border border-sand-200 object-contain"></a>
                    @else
                        <div class="grid h-48 place-items-center rounded-xl border border-dashed border-sand-300 text-center text-sm text-sand-700">{{ __('Pas de capture') }}</div>
                    @endif
                </div>
            </div>
        @endif
    </x-modal>
</div>
