@php use App\Support\Money; @endphp
<div>
    <a href="{{ route('finances.collections') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Collecte du culte') }}</a>

    <section class="wax wax-veil wax-veil-strong mb-5 overflow-hidden rounded-[22px] p-5 text-white sm:p-6">
        <div class="flex flex-wrap items-center gap-4">
            <span class="grid size-14 shrink-0 place-items-center rounded-2xl bg-ochre-500 text-center leading-none text-on-accent">
                <span><span class="block text-xl font-semibold">{{ $sheet->service_date->format('d') }}</span><span class="text-[10px] uppercase">{{ $sheet->service_date->translatedFormat('M') }}</span></span>
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-sm text-ochre-300">{{ __('Feuille de collecte') }} · {{ __(\App\Models\CollectionSheet::STATUSES[$sheet->status]) }}</p>
                <h1 class="text-2xl font-semibold text-white">{{ $sheet->service_label }}</h1>
                <p class="text-sm text-ink-100">{{ $sheet->service_date->translatedFormat('l j F Y') }} · {{ $sheet->account->name }}</p>
            </div>
            <div class="flex w-full flex-wrap gap-2 sm:w-auto">
                <a href="{{ route('finances.collections.print', $sheet) }}" target="_blank" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="printer" class="size-4" /> {{ __('Procès-verbal') }}</a>
                @if ($editable)
                    <button type="button" wire:click="deleteDraft" wire:confirm="{{ __('Supprimer ce brouillon ?') }}" class="btn !min-h-0 bg-white/15 !px-3 !py-2 text-white hover:bg-white/25" aria-label="{{ __('Supprimer le brouillon') }}"><x-icon name="trash-2" class="size-4" /></button>
                @elseif ($sheet->status === 'validated')
                    @can('finance.income')<button type="button" @click="$dispatch('open-modal', { name: 'cancel' })" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="undo-2" class="size-4" /> {{ __('Annuler la feuille') }}</button>@endcan
                @endif
            </div>
        </div>
    </section>

    @if ($sheet->status === 'validated')
        <p class="mb-5 rounded-2xl border border-leaf-100 bg-leaf-50 p-4 text-sm text-ink-800"><x-icon name="circle-check" class="mr-1 inline size-4 text-leaf-600" />
            {{ __('Validée le :date par :name. Les recettes sont dans le journal ; la feuille ne se modifie plus.', ['date' => $sheet->validated_at->translatedFormat('j F Y à H:i'), 'name' => $sheet->validator?->name]) }}</p>
    @elseif ($sheet->status === 'cancelled')
        <p class="mb-5 rounded-2xl border border-terra-100 bg-terra-50 p-4 text-sm text-terra-700">{{ __('Feuille annulée : :r', ['r' => $sheet->cancel_reason]) }}</p>
    @endif

    <div class="grid gap-5 lg:grid-cols-[1.5fr_1fr] lg:items-start">
        <div class="space-y-5">
            @if ($editable)
                <section class="card grid gap-4 p-5 sm:grid-cols-3 sm:p-6">
                    <div><label for="serviceDate" class="label">{{ __('Date') }}</label><input wire:model.blur="serviceDate" id="serviceDate" type="date" max="{{ today()->toDateString() }}" class="input"></div>
                    <div><label for="serviceLabel" class="label">{{ __('Culte') }}</label><input wire:model.blur="serviceLabel" id="serviceLabel" class="input" list="services">
                        <datalist id="services">@foreach ([__('Culte du dimanche'), __('Premier culte'), __('Deuxième culte'), __('Culte des jeunes'), __('Culte d’enseignement'), __('Veillée de prière')] as $o)<option value="{{ $o }}">@endforeach</datalist></div>
                    <div><label for="accountId" class="label">{{ __('Compte qui reçoit') }}</label>
                        <select wire:model.live="accountId" id="accountId" class="input">@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
                </section>
            @endif

            {{-- Comptage des billets --}}
            <section class="card p-5 sm:p-6">
                <h2 class="text-lg">{{ __('Comptage des billets') }}</h2>
                <p class="mb-4 text-sm text-sand-700">{{ __('Facultatif mais conseillé : le nombre de billets de chaque valeur. Le total doit correspondre aux offrandes et enveloppes.') }}</p>
                <div class="grid gap-5 sm:grid-cols-2">
                    @foreach ($denominations as $currency => $values)
                        <div wire:key="count-{{ $currency }}">
                            <p class="mb-2 text-sm font-semibold text-ink-700">{{ $currency }}</p>
                            @if ($values === [])
                                <p class="text-sm text-sand-700">{{ __('Pas de billets définis pour cette devise : saisissez directement les montants.') }}</p>
                            @else
                                <ul class="space-y-1.5">
                                    @foreach ($values as $value)
                                        @php $qty = (int) ($counts[$currency][$value] ?? 0); @endphp
                                        <li class="grid grid-cols-[6.5rem_1fr_7rem] items-center gap-2 text-sm">
                                            <span class="font-semibold tabular text-ink-800">{{ Money::format($value, $currency) }}</span>
                                            @if ($editable)
                                                <input wire:model.blur="counts.{{ $currency }}.{{ $value }}" type="number" min="0" inputmode="numeric" class="input !min-h-0 !py-1.5 text-right tabular" aria-label="{{ __('Nombre de billets de :v', ['v' => Money::format($value, $currency)]) }}">
                                            @else
                                                <span class="text-right tabular">× {{ $qty }}</span>
                                            @endif
                                            <span class="text-right tabular text-sand-700">{{ $qty ? Money::format($qty * $value, $currency) : '—' }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                                @if (isset($summary[$currency]) && $summary[$currency]['counted'])
                                    <p class="mt-2 flex justify-between border-t border-sand-100 pt-2 text-sm font-semibold"><span>{{ __('Total compté') }}</span><span class="tabular">{{ Money::format($summary[$currency]['counted'], $currency) }}</span></p>
                                @endif
                            @endif
                        </div>
                    @endforeach
                </div>
                @error('counts.*.*') <p class="error">{{ $message }}</p> @enderror
            </section>

            {{-- Offrandes collectives --}}
            <section class="card p-5 sm:p-6">
                <h2 class="text-lg">{{ __('Offrandes de la boîte') }}</h2>
                <p class="mb-4 text-sm text-sand-700">{{ __('Les offrandes collectives : seul le total compte, sans nom.') }}</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr><th class="pb-2 text-left font-semibold text-sand-700"></th>@foreach ($currencies as $c)<th class="pb-2 text-right font-semibold text-sand-700">{{ $c }}</th>@endforeach</tr></thead>
                        <tbody>
                            @foreach ($collective as $category)
                                <tr wire:key="line-{{ $category->id }}">
                                    <td class="py-1.5 pr-3 font-semibold text-ink-800">{{ $category->name }}</td>
                                    @foreach ($currencies as $c)
                                        <td class="py-1.5 pl-2">
                                            @if ($editable)
                                                <div class="flex items-center justify-end gap-1">
                                                    @if ($loop->parent->first && isset($summary[$c]) && $summary[$c]['counted'])
                                                        <button type="button" wire:click="useCountFor('{{ $c }}', {{ $category->id }})" class="rounded-lg p-1.5 text-ochre-600 hover:bg-ochre-50" title="{{ __('Reprendre le reste du comptage') }}" aria-label="{{ __('Reprendre le reste du comptage') }}"><x-icon name="refresh-cw" class="size-4" /></button>
                                                    @endif
                                                    <input wire:model.blur="lines.{{ $category->id }}.{{ $c }}" type="number" step="0.01" min="0" inputmode="decimal" class="input !min-h-0 w-32 !py-1.5 text-right tabular" aria-label="{{ $category->name }} · {{ $c }}">
                                                </div>
                                            @else
                                                <p class="text-right tabular">{{ isset($lines[$category->id][$c]) ? Money::format($lines[$category->id][$c], $c) : '—' }}</p>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Enveloppes --}}
            <section class="card p-5 sm:p-6">
                <h2 class="text-lg">{{ __('Enveloppes nominatives') }}</h2>
                <p class="mb-4 text-sm text-sand-700">{{ __('Dîmes et offrandes au nom d’un membre : chacune aura son reçu.') }}</p>
                @if ($editable)
                    <form wire:submit="addEnvelope" class="mb-4 space-y-3 rounded-2xl bg-sand-50 p-4" x-on:envelope-added.window="$refs.search?.focus()">
                        @if ($chosen)
                            <div class="flex items-center gap-3 rounded-xl bg-white px-3 py-2">
                                <span class="flex-1 text-sm"><span class="font-semibold text-ink-800">{{ $chosen->officialName() }}</span> <span class="font-mono text-xs text-sand-700">{{ $chosen->number }}</span></span>
                                <button type="button" wire:click="$set('envelopeMemberId', null)" class="rounded-lg p-1.5 text-sand-500 hover:bg-sand-100" aria-label="{{ __('Changer') }}"><x-icon name="x" class="size-4" /></button>
                            </div>
                        @else
                            <input wire:model.live.debounce.300ms="envelopeSearch" x-ref="search" type="search" class="input" placeholder="{{ __('Membre : nom, numéro ou téléphone') }}" aria-label="{{ __('Rechercher le membre') }}">
                            @if ($candidates->isNotEmpty())
                                <ul class="space-y-1">
                                    @foreach ($candidates as $c)
                                        <li><button type="button" wire:click="chooseMember({{ $c->id }})" class="flex w-full items-center gap-2 rounded-xl bg-white px-3 py-2 text-left text-sm hover:bg-ochre-50"><span class="font-semibold text-ink-700">{{ $c->officialName() }}</span> <span class="font-mono text-xs text-sand-700">{{ $c->number }}</span></button></li>
                                    @endforeach
                                </ul>
                            @endif
                            <input wire:model="envelopeName" class="input" placeholder="{{ __('… ou nom d’un donateur de passage') }}" aria-label="{{ __('Nom du donateur') }}">
                        @endif
                        @error('envelopeMemberId') <p class="error">{{ $message }}</p> @enderror
                        <div class="grid gap-2 sm:grid-cols-[1fr_6rem_8rem_auto]">
                            <select wire:model="envelopeCategory" class="input" aria-label="{{ __('Catégorie') }}">@foreach ($personal as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select>
                            <select wire:model="envelopeCurrency" class="input" aria-label="{{ __('Devise') }}">@foreach ($currencies as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select>
                            <input wire:model="envelopeAmount" type="number" step="0.01" min="0" inputmode="decimal" class="input text-right tabular" placeholder="{{ __('Montant') }}" aria-label="{{ __('Montant') }}">
                            <button class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Ajouter') }}</button>
                        </div>
                        @error('envelopeAmount') <p class="error">{{ $message }}</p> @enderror
                    </form>
                @endif
                <ul class="divide-y divide-sand-100">
                    @forelse ($envelopes as $e)
                        <li class="flex items-center gap-3 py-2 text-sm" wire:key="env-{{ $e->id }}">
                            <span class="min-w-0 flex-1"><span class="font-semibold text-ink-800">{{ $canSeeNames ? ($e->member?->fullName() ?? $e->payer_name) : __('Enveloppe nominative') }}</span> <span class="text-sand-700">· {{ $e->category->name }}</span></span>
                            <span class="font-semibold tabular">{{ Money::format($e->amount, $e->currency) }}</span>
                            @if ($editable)<button type="button" wire:click="removeEnvelope({{ $e->id }})" class="rounded-lg p-1.5 text-sand-500 hover:bg-terra-50 hover:text-terra-600" aria-label="{{ __('Retirer') }}"><x-icon name="x" class="size-4" /></button>@endif
                        </li>
                    @empty
                        <li class="py-2 text-sm text-sand-700">{{ __('Aucune enveloppe.') }}</li>
                    @endforelse
                </ul>
            </section>
        </div>

        {{-- Résumé et validation --}}
        <aside class="space-y-5 lg:sticky lg:top-24">
            <section class="card p-5">
                <h2 class="mb-3 text-lg">{{ __('Résumé') }}</h2>
                @forelse ($summary as $currency => $s)
                    <div class="mb-3 rounded-2xl bg-sand-50 p-3 text-sm last:mb-0">
                        <p class="mb-1 font-semibold text-ink-700">{{ $currency }}</p>
                        <div class="flex justify-between"><span class="text-sand-700">{{ __('Boîte') }}</span><span class="tabular">{{ Money::format($s['collective'], $currency) }}</span></div>
                        <div class="flex justify-between"><span class="text-sand-700">{{ __('Enveloppes') }}</span><span class="tabular">{{ Money::format($s['envelopes'], $currency) }}</span></div>
                        <div class="flex justify-between font-semibold"><span>{{ __('Total déclaré') }}</span><span class="tabular">{{ Money::format($s['declared'], $currency) }}</span></div>
                        @if ($s['counted'])
                            <div class="flex justify-between"><span class="text-sand-700">{{ __('Total compté') }}</span><span class="tabular">{{ Money::format($s['counted'], $currency) }}</span></div>
                            <div @class(['mt-1 flex justify-between rounded-lg px-2 py-1 font-semibold', 'bg-leaf-50 text-leaf-700' => $s['difference']->isZero(), 'bg-terra-50 text-terra-700' => ! $s['difference']->isZero()])>
                                <span>{{ $s['difference']->isZero() ? __('Pas d’écart') : __('Écart') }}</span>@unless ($s['difference']->isZero())<span class="tabular">{{ Money::format($s['difference'], $currency) }}</span>@endunless
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-sand-700">{{ __('Rien de saisi pour le moment.') }}</p>
                @endforelse
            </section>

            <section class="card space-y-3 p-5">
                <p class="label !mb-0">{{ __('Compté par') }}</p>
                @foreach ([0, 1, 2] as $i)
                    @if ($editable)
                        <input wire:model.blur="counters.{{ $i }}" class="input" placeholder="{{ __('Nom de la personne :n', ['n' => $i + 1]) }}" aria-label="{{ __('Compteur :n', ['n' => $i + 1]) }}">
                    @elseif (! empty($counters[$i]))
                        <p class="text-sm text-ink-800">{{ $counters[$i] }}</p>
                    @endif
                @endforeach
                <p class="hint">{{ __('Deux personnes au moins, comme le veut la bonne pratique.') }}</p>
                @if ($editable)
                    <textarea wire:model.blur="notes" rows="2" class="input" placeholder="{{ __('Remarques (facultatif)') }}" aria-label="{{ __('Remarques') }}"></textarea>
                @elseif ($notes)
                    <p class="text-sm text-sand-700">{{ $notes }}</p>
                @endif
            </section>

            @if ($editable)
                <button type="button" wire:click="validateSheet" wire:confirm="{{ __('Valider la collecte ? Les recettes seront enregistrées et la feuille ne pourra plus être modifiée.') }}" class="btn-primary w-full">
                    <x-icon name="circle-check" class="size-4" /> {{ __('Valider la collecte') }}
                </button>
                <p class="text-center text-xs text-sand-700">{{ __('Chaque saisie est enregistrée au fur et à mesure.') }}</p>
            @endif
        </aside>
    </div>

    <x-modal name="cancel" :title="__('Annuler la feuille de collecte')">
        <form wire:submit="cancelSheet" class="space-y-4">
            <p class="text-sm text-ink-800">{{ __('Toutes les recettes de cette feuille seront annulées dans le journal, avec ce motif.') }}</p>
            <div><label for="cancelReason" class="label">{{ __('Motif') }}</label><input wire:model="cancelReason" id="cancelReason" class="input">@error('cancelReason') <p class="error">{{ $message }}</p> @enderror</div>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'cancel' })">{{ __('Retour') }}</button><button class="btn-danger">{{ __('Annuler la feuille') }}</button></div>
        </form>
    </x-modal>
</div>
