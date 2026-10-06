@php use App\Models\MemberTransfer; @endphp
<div>
    <x-page-header :title="__('Transferts de membres')" :description="__('Un membre qui déménage passe d’une paroisse à l’autre de la dénomination avec sa fiche et son parcours : la paroisse de départ le demande, celle d’arrivée l’accepte.')" />

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="card min-w-0 p-5 sm:p-6">
            <h2 class="mb-3 text-lg">{{ __('À accepter') }}</h2>
            <ul class="space-y-3">
                @forelse ($incoming as $t)
                    <li class="rounded-xl border border-ochre-300 bg-ochre-50 p-3">
                        <p class="font-semibold text-ink-800">{{ $t->member?->officialName() }} <span class="font-mono text-xs font-normal text-sand-700">{{ $t->old_number }}</span></p>
                        <p class="text-sm text-sand-700">{{ __('Depuis :f · :d', ['f' => $t->from->name, 'd' => $t->created_at->translatedFormat('j M')]) }}@if ($t->reason) · {{ $t->reason }}@endif</p>
                        @if ($canAct)<div class="mt-2 flex gap-2"><button type="button" wire:click="accept({{ $t->id }})" wire:confirm="{{ __('Accueillir :n dans votre communauté ?', ['n' => $t->member?->fullName()]) }}" class="btn-primary !min-h-0 !py-1.5 text-sm">{{ __('Accepter') }}</button><button type="button" wire:click="askRefuse({{ $t->id }})" class="btn-ghost !min-h-0 !py-1.5 text-sm text-terra-600">{{ __('Refuser') }}</button></div>@endif
                    </li>
                @empty
                    <li class="text-sm text-sand-700">{{ __('Aucun transfert à accepter.') }}</li>
                @endforelse
            </ul>
        </section>

        <section class="card min-w-0 p-5 sm:p-6">
            <h2 class="mb-1 text-lg">{{ __('Demandés') }}</h2>
            <p class="mb-3 text-sm text-sand-700">{{ __('Pour transférer un membre, ouvrez sa fiche et touchez Transférer.') }}</p>
            <ul class="space-y-2">
                @forelse ($outgoing as $t)
                    <li class="flex flex-wrap items-center gap-2 text-sm"><span class="min-w-0 flex-1"><span class="font-semibold text-ink-800">{{ $t->member?->officialName() }}</span> → {{ $t->to->name }}</span>
                        @if ($canAct)<button type="button" wire:click="cancel({{ $t->id }})" wire:confirm="{{ __('Annuler cette demande de transfert ?') }}" class="btn-ghost !min-h-0 !py-1 text-sm">{{ __('Annuler') }}</button>@endif</li>
                @empty
                    <li class="text-sm text-sand-700">{{ __('Aucune demande en attente.') }}</li>
                @endforelse
            </ul>
        </section>

        <section class="card min-w-0 overflow-hidden lg:col-span-2">
            <h2 class="px-5 pb-2 pt-5 text-lg sm:px-6">{{ __('Historique') }}</h2>
            <ul class="divide-y divide-sand-100">
                @forelse ($history as $t)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3 text-sm sm:px-6">
                        <span class="min-w-0 flex-1 basis-60"><span class="font-semibold text-ink-800">{{ $t->member?->officialName() }}</span> · {{ $t->from->name }} → {{ $t->to->name }}
                            @if ($t->new_number)<span class="block font-mono text-xs text-sand-700">{{ $t->old_number }} → {{ $t->new_number }}</span>@endif
                            @if ($t->decision_note)<span class="block text-xs text-terra-700">{{ $t->decision_note }}</span>@endif</span>
                        <span class="text-xs text-sand-600">{{ ($t->decided_at ?? $t->updated_at)->translatedFormat('j M Y') }}</span>
                        <span @class(['badge', 'bg-leaf-50 text-leaf-600' => $t->status === 'accepted', 'bg-terra-50 text-terra-700' => $t->status === 'refused', 'bg-sand-100 text-sand-700' => $t->status === 'cancelled'])>{{ __(MemberTransfer::STATUSES[$t->status]) }}</span>
                    </li>
                @empty
                    <li class="px-5 pb-5 text-sm text-sand-700 sm:px-6">{{ __('Aucun transfert pour l’instant.') }}</li>
                @endforelse
            </ul>
        </section>
    </div>

    <x-modal name="refuse-transfer" :title="__('Refuser le transfert')">
        <form wire:submit="refuse" class="space-y-4">
            <input wire:model="note" class="input" placeholder="{{ __('Exemple : la personne ne s’est pas encore présentée chez nous.') }}" aria-label="{{ __('Motif') }}">
            @error('note') <p class="error">{{ $message }}</p> @enderror
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'refuse-transfer' })">{{ __('Retour') }}</button><button class="btn-danger">{{ __('Refuser') }}</button></div>
        </form>
    </x-modal>
</div>
