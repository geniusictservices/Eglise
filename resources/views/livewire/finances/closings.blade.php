@php use App\Support\Money; @endphp
<div>
    <a href="{{ route('finances.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Finances') }}</a>
    <x-page-header :title="__('Clôtures')" :description="__('Un mois clôturé n’accepte plus d’opération ni d’annulation : ses soldes sont arrêtés. L’administrateur peut le rouvrir, avec un motif qui reste dans l’historique.')">
        <x-slot:actions>
            <select wire:model.live="year" class="input !w-auto" aria-label="{{ __('Exercice') }}">@foreach ($years as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach</select>
        </x-slot:actions>
    </x-page-header>

    <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {{-- Les mois d'avant la première opération ne se clôturent pas : on ne les montre pas. --}}
        @foreach ($months->reject(fn ($m) => $m['before'] && ! $m['closing']) as $m)
            @php $c = $m['closing']; $closed = $c?->isClosed(); @endphp
            <li wire:key="m-{{ $year }}-{{ $m['month'] }}" @class(['card flex flex-col p-4', 'opacity-60' => $m['before'] || $m['future'], 'border-leaf-300' => $closed])>
                <div class="flex items-center gap-2">
                    <span class="flex-1 font-semibold text-ink-800">{{ ucfirst($m['date']->translatedFormat('F')) }}</span>
                    @if ($closed)
                        <span class="badge bg-leaf-50 text-leaf-600"><x-icon name="lock" class="size-3.5" /> {{ __('Clôturé') }}</span>
                    @elseif ($c)
                        <span class="badge bg-terra-50 text-terra-600">{{ __('Rouvert') }}</span>
                    @elseif ($m['future'])
                        <span class="badge bg-sand-100 text-sand-700">{{ $m['date']->isCurrentMonth() ? __('En cours') : __('À venir') }}</span>
                    @elseif (! $m['before'])
                        <span class="badge bg-ochre-100 text-ochre-700">{{ __('Ouvert') }}</span>
                    @endif
                </div>
                @unless ($m['before'] || ($m['future'] && ! $m['date']->isCurrentMonth()))
                    <dl class="mt-2 grid grid-cols-2 gap-x-3 text-sm">
                        <dt class="text-sand-700">{{ __('Recettes') }}</dt><dd class="text-right font-semibold tabular text-leaf-600">{{ Money::format($m['income'], 'USD') }}</dd>
                        <dt class="text-sand-700">{{ __('Dépenses') }}</dt><dd class="text-right font-semibold tabular text-terra-600">{{ Money::format($m['expense'], 'USD') }}</dd>
                    </dl>
                @endunless
                @if ($c)
                    <p class="mt-2 text-xs text-sand-700">
                        @if ($closed) {{ __('Le :d par :n', ['d' => $c->closed_at->translatedFormat('j M Y'), 'n' => $c->closer?->name]) }}
                        @else {{ __('Le :d par :n : « :r »', ['d' => $c->reopened_at->translatedFormat('j M Y'), 'n' => $c->reopener?->name, 'r' => $c->reopen_reason]) }}
                        @endif
                    </p>
                @endif
                <div class="mt-auto flex flex-wrap gap-2 pt-3">
                    @unless ($m['before'] || ($m['future'] && ! $m['date']->isCurrentMonth()))
                        @can('finance.reports')<a href="{{ route('finances.reports', ['annee' => $year, 'mois' => $m['month']]) }}" class="btn-ghost !min-h-0 !px-2 !py-1.5 text-sm"><x-icon name="file-text" class="size-4" /> {{ __('Rapport') }}</a>@endcan
                    @endunless
                    @if ($canClose && $nextToClose === $m['month'])
                        <button type="button" wire:click="askClose({{ $m['month'] }})" class="btn-primary !min-h-0 !py-1.5 text-sm"><x-icon name="lock" class="size-4" /> {{ __('Clôturer') }}</button>
                    @endif
                    @if ($canReopen && $closed)
                        <button type="button" wire:click="askReopen({{ $m['month'] }})" class="btn-ghost !min-h-0 !px-2 !py-1.5 text-sm text-terra-600">{{ __('Rouvrir') }}</button>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>

    <section @class(['card mt-5 flex flex-wrap items-center gap-4 p-5', 'border-leaf-300' => $yearClosing?->isClosed()])>
        <span class="icon-tile bg-ink-50 text-ink-700"><x-icon name="archive" class="size-5" /></span>
        <div class="min-w-0 flex-1">
            <p class="font-semibold text-ink-800">{{ __('Exercice :y', ['y' => $year]) }}</p>
            <p class="text-sm text-sand-700">
                @if ($yearClosing?->isClosed()) {{ __('Clôturé le :d par :n.', ['d' => $yearClosing->closed_at->translatedFormat('j M Y'), 'n' => $yearClosing->closer?->name]) }}
                @elseif ($yearClosing) {{ __('Rouvert le :d : « :r »', ['d' => $yearClosing->reopened_at->translatedFormat('j M Y'), 'r' => $yearClosing->reopen_reason]) }}
                @else {{ $yearBlocker ?? __('Tous les mois sont clôturés : l’exercice peut l’être.') }}
                @endif
            </p>
        </div>
        @can('finance.reports')<a href="{{ route('finances.reports', ['annee' => $year]) }}" class="btn-secondary"><x-icon name="file-text" class="size-4" /> {{ __('Rapport annuel') }}</a>@endcan
        @if ($canClose && ! $yearBlocker)<button type="button" wire:click="askClose(0)" class="btn-primary"><x-icon name="lock" class="size-4" /> {{ __('Clôturer l’exercice') }}</button>@endif
        @if ($canReopen && $yearClosing?->isClosed())<button type="button" wire:click="askReopen(0)" class="btn-ghost text-terra-600">{{ __('Rouvrir') }}</button>@endif
    </section>

    <x-modal name="close" :title="$target ? __('Clôturer :m', ['m' => \Illuminate\Support\Carbon::create($year, $target)->translatedFormat('F Y')]) : __('Clôturer l’exercice :y', ['y' => $year])">
        <form wire:submit="close" class="space-y-4">
            <p class="text-sm text-ink-800">{{ __('Après la clôture, plus aucune opération ne pourra être saisie ou annulée à ces dates. Les soldes de fin de période sont enregistrés.') }}</p>
            @if ($checklist)
                <div class="rounded-xl border border-ochre-300 bg-ochre-50 p-3 text-sm text-ink-800">
                    <p class="mb-1 font-semibold">{{ __('À vérifier avant de clôturer :') }}</p>
                    <ul class="list-disc space-y-0.5 pl-5">
                        @isset($checklist['collections'])<li>{{ trans_choice(':count feuille de collecte n’est pas validée.|:count feuilles de collecte ne sont pas validées.', $checklist['collections']) }}</li>@endisset
                        @isset($checklist['declarations'])<li>{{ trans_choice(':count paiement déclaré attend sa vérification.|:count paiements déclarés attendent leur vérification.', $checklist['declarations']) }}</li>@endisset
                        @isset($checklist['advances'])<li>{{ trans_choice(':count avance n’est pas encore justifiée.|:count avances ne sont pas encore justifiées.', $checklist['advances']) }}</li>@endisset
                    </ul>
                </div>
            @endif
            @if ($blocker)<p class="error">{{ $blocker }}</p>@endif
            @error('target') <p class="error">{{ $message }}</p> @enderror
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'close' })">{{ __('Retour') }}</button><button class="btn-primary" @disabled($blocker)><x-icon name="lock" class="size-4" /> {{ __('Clôturer') }}</button></div>
        </form>
    </x-modal>

    <x-modal name="reopen" :title="__('Rouvrir une période')">
        <form wire:submit="reopen" class="space-y-4">
            <p class="text-sm text-ink-800">{{ $target ? __('Les mois suivants déjà clôturés, et l’exercice, seront rouverts eux aussi. Il faudra les clôturer de nouveau.') : __('L’exercice sera rouvert ; ses mois restent clôturés.') }}</p>
            <div><label for="rp-reason" class="label">{{ __('Motif') }}</label><textarea wire:model="reason" id="rp-reason" rows="3" class="input" placeholder="{{ __('Exemple : facture de septembre oubliée, à enregistrer') }}"></textarea>@error('reason') <p class="error">{{ $message }}</p> @enderror</div>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'reopen' })">{{ __('Retour') }}</button><button class="btn-danger">{{ __('Rouvrir') }}</button></div>
        </form>
    </x-modal>
</div>
