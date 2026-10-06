@php use App\Support\Money; @endphp
<div>
    <a href="{{ route('budget.index', ['exercice' => $year]) }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Budget') }}</a>
    <x-page-header :title="__('Budget de :d', ['d' => $department->name])" :description="__('Exercice :y. Les montants sont en dollars ; un montant saisi en francs est converti au taux du jour.', ['y' => $yearLabel])" />

    @if ($proposal?->return_note && $proposal->status === 'draft')
        <p class="mb-4 rounded-2xl border border-terra-100 bg-terra-50 p-4 text-sm text-terra-700"><span class="font-semibold">{{ __('Renvoyée par la finance :') }}</span> {{ $proposal->return_note }}</p>
    @elseif ($proposal?->status === 'submitted')
        <p class="mb-4 rounded-2xl border border-leaf-100 bg-leaf-50 p-4 text-sm text-leaf-600"><x-icon name="circle-check" class="mr-1 inline size-4" /> {{ __('Envoyée à la finance le :d par :n.', ['d' => $proposal->submitted_at->translatedFormat('j M Y'), 'n' => $proposal->submitter?->name]) }}</p>
    @endif

    <div class="mb-5 grid grid-cols-2 gap-3">
        <div class="card p-4"><p class="text-sm text-sand-700">{{ __('Besoins (dépenses)') }}</p><p class="text-xl font-semibold tabular text-terra-600">{{ Money::format($proposal?->total('expense') ?? 0, 'USD') }}</p></div>
        <div class="card p-4"><p class="text-sm text-sand-700">{{ __('Recettes prévues') }}</p><p class="text-xl font-semibold tabular text-leaf-600">{{ Money::format($proposal?->total('income') ?? 0, 'USD') }}</p></div>
    </div>

    @foreach (['expense' => __('Besoins'), 'income' => __('Recettes prévues')] as $type => $title)
        <section class="card mb-5 p-5 sm:p-6">
            <div class="mb-3 flex items-center gap-3">
                <h2 class="flex-1 text-lg">{{ $title }}</h2>
                @if ($canEdit)<button type="button" wire:click="editLine(null, '{{ $type }}')" class="btn-secondary !min-h-0 !py-1.5 text-sm"><x-icon name="plus" class="size-4" /> {{ __('Ajouter') }}</button>@endif
            </div>
            <ul class="divide-y divide-sand-100">
                @forelse ($proposal?->lines->where('type', $type) ?? [] as $l)
                    <li wire:key="l-{{ $l->id }}" class="flex items-start gap-3 py-3">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-ink-800">{{ $l->label }}</p>
                            <p class="text-xs text-sand-700">{{ $l->category?->name }}@if ($l->original_currency) · {{ Money::format($l->original_amount, $l->original_currency) }}@endif</p>
                            @if ($l->justification)<p class="mt-1 text-sm text-ink-700">{{ $l->justification }}</p>@endif
                        </div>
                        <span class="font-semibold tabular text-ink-800">{{ Money::format($l->amount, 'USD') }}</span>
                        @if ($canEdit)
                            <button type="button" wire:click="editLine({{ $l->id }})" class="rounded-lg p-1.5 text-sand-500 hover:bg-sand-100" aria-label="{{ __('Modifier') }}"><x-icon name="pencil" class="size-4" /></button>
                            <button type="button" wire:click="deleteLine({{ $l->id }})" wire:confirm="{{ __('Supprimer cette ligne ?') }}" class="rounded-lg p-1.5 text-sand-500 hover:bg-terra-50 hover:text-terra-600" aria-label="{{ __('Supprimer') }}"><x-icon name="trash-2" class="size-4" /></button>
                        @endif
                    </li>
                @empty
                    <li class="py-2 text-sm text-sand-700">{{ $type === 'expense' ? __('Aucun besoin pour le moment.') : __('Aucune recette prévue.') }}</li>
                @endforelse
            </ul>
        </section>
    @endforeach

    <div class="flex flex-wrap justify-end gap-2">
        @if ($canSendBack)<button type="button" @click="$dispatch('open-modal', { name: 'send-back' })" class="btn-ghost text-terra-600">{{ __('Renvoyer pour correction') }}</button>@endif
        @if ($canEdit && $proposal?->lines->isNotEmpty())<button type="button" wire:click="submit" wire:confirm="{{ __('Envoyer la proposition à la finance ? Elle ne sera plus modifiable, sauf si la finance la renvoie.') }}" class="btn-primary"><x-icon name="upload" class="size-4" /> {{ __('Envoyer à la finance') }}</button>@endif
    </div>

    <x-modal name="line" :title="$lineId ? __('Modifier la ligne') : (($line['type'] ?? 'expense') === 'expense' ? __('Nouveau besoin') : __('Nouvelle recette prévue'))">
        <form wire:submit="saveLine" class="space-y-4">
            <div class="grid grid-cols-2 gap-2" role="radiogroup">
                @foreach (['expense' => __('Besoin (dépense)'), 'income' => __('Recette prévue')] as $k => $label)
                    <label @class(['cursor-pointer rounded-xl border p-3 text-center text-sm font-semibold', 'border-ochre-400 bg-ochre-50 text-ink-800' => ($line['type'] ?? '') === $k, 'border-sand-200 text-ink-600' => ($line['type'] ?? '') !== $k])>
                        <input type="radio" wire:model.live="line.type" value="{{ $k }}" class="sr-only">{{ $label }}</label>
                @endforeach
            </div>
            <div><label for="ln-label" class="label">{{ __('Objet') }}</label><input wire:model="line.label" id="ln-label" class="input" placeholder="{{ ($line['type'] ?? '') === 'income' ? __('Exemple : concert de louange de décembre') : __('Exemple : uniformes de la chorale') }}">@error('line.label') <p class="error">{{ $message }}</p> @enderror</div>
            <div><label for="ln-cat" class="label">{{ __('Catégorie') }}</label><select wire:model="line.category_id" id="ln-cat" class="input">@foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
            <div class="grid gap-4 sm:grid-cols-[1fr_7rem]">
                <div><label for="ln-amount" class="label">{{ __('Montant pour l’exercice') }}</label><input wire:model="line.amount" id="ln-amount" type="number" step="0.01" min="0" class="input tabular">@error('line.amount') <p class="error">{{ $message }}</p> @enderror</div>
                <div><label for="ln-cur" class="label">{{ __('Devise') }}</label><select wire:model="line.currency" id="ln-cur" class="input">@foreach ($currencies as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select></div>
            </div>
            <div><label for="ln-just" class="label">{{ __('Justification') }}</label><textarea wire:model="line.justification" id="ln-just" rows="3" class="input" placeholder="{{ __('Pourquoi ce besoin, comment le montant est calculé…') }}"></textarea></div>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'line' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Enregistrer') }}</button></div>
        </form>
    </x-modal>

    <x-modal name="send-back" :title="__('Renvoyer pour correction')">
        <form wire:submit="sendBack" class="space-y-4">
            <div><label for="sb-note" class="label">{{ __('Remarque pour le département') }}</label><textarea wire:model="returnNote" id="sb-note" rows="3" class="input"></textarea>@error('returnNote') <p class="error">{{ $message }}</p> @enderror</div>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'send-back' })">{{ __('Retour') }}</button><button class="btn-primary">{{ __('Renvoyer') }}</button></div>
        </form>
    </x-modal>
</div>
