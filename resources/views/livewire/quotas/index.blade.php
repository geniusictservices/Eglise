@php use App\Models\QuotaRule; $usd = fn ($v) => \App\Support\Money::format($v, 'USD'); @endphp
<div>
    <x-page-header :title="__('Quotes-parts')" :description="__('Ce que chaque niveau reverse à son niveau supérieur, selon la règle de la dénomination : un pourcentage des recettes du mois, un montant fixe, ou rien.')" />

    <div class="grid gap-5 lg:grid-cols-2">
        @if ($parent)
            <section class="card min-w-0 p-5 sm:p-6 lg:col-span-2">
                <h2 class="text-lg">{{ __('Ce que nous versons à :p', ['p' => $parent->name]) }}</h2>
                @if (! $parentRule)
                    <p class="mt-1 text-sm text-sand-700">{{ __(':p ne demande pas de quote-part.', ['p' => $parent->name]) }}</p>
                @else
                    <p class="mb-3 mt-1 text-sm text-sand-700">{{ __('Règle : :r, depuis :m.', ['r' => $parentRule->describe(), 'm' => mb_strtolower($quotas->label($parentRule->starts_period))]) }}</p>
                    <ul class="divide-y divide-sand-100 border-t border-sand-200">
                        @foreach ($owed as $o)
                            <li class="flex flex-wrap items-center gap-x-4 gap-y-1.5 py-3">
                                <div class="min-w-0 flex-1 basis-56">
                                    <p class="font-semibold text-ink-800">{{ $quotas->label($o['period']) }}</p>
                                    <p class="text-sm text-sand-700 tabular">{{ __('Recettes :r · dû :d · versé :v', ['r' => $usd($o['base']), 'd' => $usd($o['due']), 'v' => $usd($o['sent'])]) }}@if ($o['sent'] > $o['received']) <span class="text-ochre-700">· {{ __('à confirmer') }}</span>@endif</p>
                                </div>
                                <span @class(['font-semibold tabular', 'text-terra-700' => $o['remaining'] > 0, 'text-leaf-600' => $o['remaining'] == 0])>{{ $o['remaining'] > 0 ? __('Reste :m', ['m' => $usd($o['remaining'])]) : __('À jour') }}</span>
                                @if ($canSend && $o['remaining'] > 0)<button type="button" wire:click="askSend('{{ $o['period'] }}')" class="btn-secondary !min-h-0 !py-1.5 text-sm">{{ __('Verser') }}</button>@endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        @if ($children->isNotEmpty())
            <section class="card min-w-0 p-5 sm:p-6">
                <h2 class="mb-1 text-lg">{{ __('Ce que nous demandons à nos niveaux inférieurs') }}</h2>
                <p class="mb-3 text-sm text-sand-700">{{ trans_choice('Vaut pour :count niveau directement en dessous.|Vaut pour les :count niveaux directement en dessous.', $children->count()) }}</p>
                @if ($canSettings)
                    <form wire:submit="saveRule" class="space-y-3">
                        <div class="space-y-2">
                            @foreach (['none' => __('Aucune quote-part')] + collect(QuotaRule::MODES)->map(fn ($l) => __($l))->all() as $k => $l)
                                <label class="flex items-center gap-3 text-sm"><input type="radio" wire:model.live="rule.mode" value="{{ $k }}" class="size-4"> {{ $l }}</label>
                            @endforeach
                        </div>
                        @if ($rule['mode'] === 'percent')
                            <div class="flex items-center gap-2"><input wire:model="rule.percent" type="number" step="0.5" min="0" max="100" class="input w-28 tabular" aria-label="{{ __('Pourcentage') }}"><span class="text-sm text-sand-700">{{ __('% des recettes du mois (hors quotes-parts reçues et argent des projets)') }}</span></div>
                        @elseif ($rule['mode'] === 'fixed')
                            <div class="flex items-center gap-2"><input wire:model="rule.amount" type="number" step="0.01" min="0" class="input w-36 tabular" aria-label="{{ __('Montant') }}"><select wire:model="rule.currency" class="input w-auto" aria-label="{{ __('Devise') }}"><option>USD</option><option>CDF</option></select><span class="text-sm text-sand-700">{{ __('par mois') }}</span></div>
                        @endif
                        @error('rule.percent') <p class="error">{{ $message }}</p> @enderror
                        <div class="flex justify-end"><button class="btn-primary">{{ __('Enregistrer la règle') }}</button></div>
                    </form>
                @else
                    <p class="text-sm text-ink-800">{{ $rule['mode'] === 'none' ? __('Aucune quote-part.') : __('Une règle est en place.') }}</p>
                @endif
            </section>

            <section class="card min-w-0 p-5 sm:p-6">
                <h2 class="mb-3 text-lg">{{ __('Versements à confirmer') }}</h2>
                <ul class="space-y-2">
                    @forelse ($pending as $p)
                        <li class="flex flex-wrap items-center gap-2"><span class="min-w-0 flex-1 basis-48 text-sm"><span class="font-semibold text-ink-800">{{ $p->from->name }}</span> · {{ \App\Support\Money::format($p->amount, $p->currency) }} · {{ $quotas->label($p->period) }}@if ($p->reference) <span class="text-sand-600">· {{ $p->reference }}</span>@endif</span>
                            @if ($canReceive)<button type="button" wire:click="askReceive({{ $p->id }})" class="btn-primary !min-h-0 !py-1.5 text-sm">{{ __('Confirmer la réception') }}</button>@endif</li>
                    @empty
                        <li class="text-sm text-sand-700">{{ __('Aucun versement en attente.') }}</li>
                    @endforelse
                </ul>
            </section>

            @if ($grid->isNotEmpty())
                <section class="card min-w-0 overflow-hidden lg:col-span-2">
                    <h2 class="px-5 pb-2 pt-5 text-lg sm:px-6">{{ __('Suivi par niveau') }}</h2>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[560px] text-sm">
                            <thead><tr class="border-b border-sand-200 text-left text-xs text-sand-700"><th class="px-5 py-2 font-semibold sm:px-6">{{ __('Niveau') }}</th>@foreach ($gridPeriods as $p)<th class="px-3 py-2 text-right font-semibold">{{ $quotas->label($p) }}</th>@endforeach</tr></thead>
                            <tbody class="divide-y divide-sand-100">
                                @foreach ($children as $c)
                                    <tr><td class="px-5 py-2.5 font-semibold text-ink-800 sm:px-6">{{ $c->name }}</td>
                                        @foreach ($gridPeriods as $p)
                                            @php $o = $grid[$c->id][$p]; @endphp
                                            <td class="px-3 py-2.5 text-right tabular">
                                                @if (! $o)<span class="text-sand-500">—</span>@else
                                                <span class="block text-ink-800">{{ $usd($o['sent']) }} / {{ $usd($o['due']) }}</span>
                                                <span @class(['text-xs font-semibold', 'text-leaf-600' => $o['remaining'] == 0, 'text-terra-700' => $o['remaining'] > 0])>{{ $o['remaining'] == 0 ? __('À jour') : __('reste :m', ['m' => $usd($o['remaining'])]) }}</span>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        @endif
    </div>

    @if ($canSend)
        <x-modal name="send-quota" :title="__('Verser la quote-part')">
            <form wire:submit="sendQuota" class="space-y-4">
                @if ($send['period'])<p class="text-sm text-sand-700">{{ __(':m, à :p. La dépense est enregistrée dans le compte choisi.', ['m' => $quotas->label($send['period']), 'p' => $parent?->name]) }}</p>@endif
                <div class="grid gap-3 sm:grid-cols-[2fr_1fr]">
                    <div><label for="q-acc" class="label">{{ __('Depuis le compte') }}</label><select wire:model="send.account" id="q-acc" class="input">@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
                    <div><label for="q-cur" class="label">{{ __('Devise') }}</label><select wire:model="send.currency" id="q-cur" class="input"><option>USD</option><option>CDF</option></select></div>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label for="q-amt" class="label">{{ __('Montant') }}</label><input wire:model="send.amount" id="q-amt" type="number" step="0.01" min="0" class="input tabular">@error('send.amount') <p class="error">{{ $message }}</p> @enderror</div>
                    <div><label for="q-ref" class="label">{{ __('Référence') }}</label><input wire:model="send.reference" id="q-ref" class="input" placeholder="{{ __('ID mobile money, bordereau…') }}"></div>
                </div>
                <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'send-quota' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Verser') }}</button></div>
            </form>
        </x-modal>
    @endif
    @if ($canReceive)
        <x-modal name="receive-quota" :title="__('Confirmer la réception')">
            <form wire:submit="receive" class="space-y-4">
                <div><label for="q-racc" class="label">{{ __('Reçue dans le compte') }}</label><select wire:model="receiveAccount" id="q-racc" class="input">@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>@error('receiveAccount') <p class="error">{{ $message }}</p> @enderror</div>
                <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'receive-quota' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Confirmer') }}</button></div>
            </form>
        </x-modal>
    @endif
</div>
