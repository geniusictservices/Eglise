<div>
    <a href="{{ $pledge ? route('finances.pledges.show', $pledge) : route('finances.pledges') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Promesses') }}</a>
    <x-page-header :title="$pledge ? __('Modifier la promesse') : __('Nouvelle promesse')" :description="__('Tout le monde peut promettre : un membre, un ménage, un département ou une personne de l’extérieur.')" />

    <form wire:submit="save" class="grid gap-5 lg:grid-cols-[1.3fr_1fr] lg:items-start">
        <div class="space-y-5">
            <section class="card space-y-4 p-5 sm:p-6">
                <div>
                    <label for="projectId" class="label">{{ __('Pour') }}</label>
                    <select wire:model="projectId" id="projectId" class="input">
                        <option value="">{{ __('Sans projet (promesse générale)') }}</option>
                        @foreach ($projects as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                    </select>
                </div>
                <fieldset>
                    <legend class="label">{{ __('Qui promet ?') }}</legend>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        @foreach (['member' => __('Un membre'), 'household' => __('Un ménage'), 'department' => __('Un département'), 'other' => __('Une autre personne')] as $k => $l)
                            <label @class(['cursor-pointer rounded-xl border-[1.5px] px-3 py-2.5 text-center text-sm font-semibold', 'border-ink-700 bg-ink-50 text-ink-800' => $pledgerType === $k, 'border-sand-300 text-ink-600' => $pledgerType !== $k])>
                                <input type="radio" wire:model.live="pledgerType" value="{{ $k }}" class="sr-only"> {{ $l }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                @if (in_array($pledgerType, ['member', 'household'], true))
                    @php $chosen = $pledgerType === 'member' ? $member : $household; @endphp
                    @if ($chosen)
                        <div class="flex items-center gap-3 rounded-xl bg-ochre-50 px-3 py-2">
                            <span class="flex-1 font-semibold text-ink-800">{{ $pledgerType === 'member' ? $chosen->officialName() : $chosen->name }}</span>
                            <button type="button" wire:click="$set('{{ $pledgerType === 'member' ? 'memberId' : 'householdId' }}', null)" class="rounded-lg p-1.5 text-sand-500 hover:bg-white" aria-label="{{ __('Changer') }}"><x-icon name="x" class="size-4" /></button>
                        </div>
                    @else
                        <input wire:model.live.debounce.300ms="search" type="search" class="input" placeholder="{{ $pledgerType === 'member' ? __('Nom, numéro ou téléphone') : __('Nom du ménage') }}" aria-label="{{ __('Rechercher') }}">
                        <ul class="space-y-1">
                            @foreach ($results as $r)
                                <li><button type="button" wire:click="choose('{{ $pledgerType }}', {{ $r['id'] }})" class="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left text-sm hover:bg-sand-100"><span class="font-semibold text-ink-700">{{ $r['label'] }}</span> <span class="text-xs text-sand-700">{{ $r['hint'] }}</span></button></li>
                            @endforeach
                        </ul>
                    @endif
                    @error('memberId') <p class="error">{{ $message }}</p> @enderror
                    @error('householdId') <p class="error">{{ $message }}</p> @enderror
                @elseif ($pledgerType === 'department')
                    <select wire:model="departmentId" class="input" aria-label="{{ __('Département') }}"><option value="">—</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
                    @error('departmentId') <p class="error">{{ $message }}</p> @enderror
                @else
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div><label for="pledgerName" class="label">{{ __('Nom') }}</label><input wire:model="pledgerName" id="pledgerName" class="input">@error('pledgerName') <p class="error">{{ $message }}</p> @enderror</div>
                        <div><label for="pledgerPhone" class="label">{{ __('Téléphone (pour la relance)') }}</label><input wire:model="pledgerPhone" id="pledgerPhone" type="tel" class="input">@error('pledgerPhone') <p class="error">{{ $message }}</p> @enderror</div>
                    </div>
                @endif
            </section>

            <section class="card space-y-4 p-5 sm:p-6">
                <fieldset>
                    <legend class="label">{{ __('Promesse') }}</legend>
                    <div class="flex gap-2">
                        @foreach (['money' => __('En argent'), 'in_kind' => __('En nature')] as $k => $l)
                            <label @class(['flex-1 cursor-pointer rounded-xl border-[1.5px] px-3 py-2.5 text-center text-sm font-semibold', 'border-ink-700 bg-ink-50 text-ink-800' => $kind === $k, 'border-sand-300 text-ink-600' => $kind !== $k])>
                                <input type="radio" wire:model.live="kind" value="{{ $k }}" class="sr-only"> {{ $l }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                @if ($kind === 'in_kind')
                    <div><label for="inKind" class="label">{{ __('Ce qui est promis') }}</label><input wire:model="inKindDescription" id="inKind" class="input" placeholder="{{ __('Exemple : 20 sacs de ciment') }}">@error('inKindDescription') <p class="error">{{ $message }}</p> @enderror</div>
                @endif
                <div class="grid gap-4 sm:grid-cols-[1fr_7rem]">
                    <div><label for="amount" class="label">{{ $kind === 'in_kind' ? __('Valeur estimée') : __('Montant promis') }}</label><input wire:model="amount" id="amount" type="number" step="0.01" min="0" class="input text-lg font-semibold tabular">@error('amount') <p class="error">{{ $message }}</p> @enderror</div>
                    <div><label for="currency" class="label">{{ __('Devise') }}</label><select wire:model="currency" id="currency" class="input">@foreach ($currencies as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select></div>
                </div>
                <div><label for="notes" class="label">{{ __('Remarques') }}</label><textarea wire:model="notes" id="notes" rows="2" class="input"></textarea></div>
            </section>
        </div>

        <aside class="space-y-5">
            <section class="card space-y-4 p-5">
                <div><label for="pledgedOn" class="label">{{ __('Date de la promesse') }}</label><input wire:model="pledgedOn" id="pledgedOn" type="date" class="input"></div>
                <div><label for="frequency" class="label">{{ __('Paiement') }}</label>
                    <select wire:model.live="frequency" id="frequency" class="input">@foreach (\App\Models\Pledge::FREQUENCIES as $k => $l)<option value="{{ $k }}">{{ __($l) }}</option>@endforeach</select></div>
                @if ($frequency !== 'once')
                    <div><label for="installments" class="label">{{ __('Nombre d’échéances') }}</label><input wire:model="installments" id="installments" type="number" min="2" class="input">
                        @if (is_numeric($amount) && $installments > 0)<p class="hint">{{ __('soit :a par échéance', ['a' => \App\Support\Money::format((float) $amount / max(1, $installments), $currency)]) }}</p>@endif</div>
                @endif
                <div><label for="firstDueOn" class="label">{{ $frequency === 'once' ? __('À honorer avant le') : __('Première échéance') }}</label><input wire:model="firstDueOn" id="firstDueOn" type="date" class="input">
                    <p class="hint">{{ __('Sert à repérer les promesses en retard.') }}</p></div>
            </section>
            <button class="btn-primary w-full"><x-icon name="save" class="size-4" /> {{ __('Enregistrer la promesse') }}</button>
        </aside>
    </form>
</div>
