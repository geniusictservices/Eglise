<div>
    <a href="{{ route('members.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Membres') }}</a>
    <x-page-header :title="__('Importer depuis Excel')"
                   :description="__('Reprenez votre ancien registre en trois étapes. Waumini vérifie chaque ligne avant d’enregistrer quoi que ce soit.')" />

    {{-- Étapes --}}
    @php $step = ! $import || $import->status === 'cancelled' ? 1 : ($import->status === 'analysed' ? 2 : 3); @endphp
    <ol class="mb-6 flex gap-2 text-sm sm:grid sm:grid-cols-3">
        @foreach ([__('Préparer le fichier'), __('Vérifier'), __('Importer')] as $i => $label)
            <li @class(['flex items-center gap-2 rounded-2xl border px-3 py-2.5', 'flex-1' => $step === $i + 1, 'border-ink-700 bg-ink-700 text-white' => $step === $i + 1, 'border-leaf-100 bg-leaf-50 text-leaf-700' => $step > $i + 1, 'border-sand-200 bg-white text-sand-700' => $step < $i + 1])>
                <span @class(['grid size-6 shrink-0 place-items-center rounded-full text-xs font-semibold', 'bg-ochre-500 text-on-accent' => $step === $i + 1, 'bg-leaf-500 text-white' => $step > $i + 1, 'bg-sand-100' => $step < $i + 1])>
                    @if ($step > $i + 1)<x-icon name="check" class="size-3.5" />@else{{ $i + 1 }}@endif
                </span>
                <span @class(['truncate font-semibold', 'hidden sm:inline' => $step !== $i + 1])>{{ $label }}</span>
            </li>
        @endforeach
    </ol>

    @if ($step === 1)
        <div class="grid gap-5 lg:grid-cols-2">
            <section class="card p-5 sm:p-6">
                <span class="icon-tile bg-leaf-50 text-leaf-600"><x-icon name="file-spreadsheet" class="size-5" /></span>
                <h2 class="mt-3 text-lg">{{ __('1. Téléchargez le modèle') }}</h2>
                <p class="mt-1 text-sm text-sand-700">{{ __('Le modèle reprend vos statuts, vos champs et vos listes déroulantes. Recopiez-y votre registre : une ligne par personne.') }}</p>
                <a href="{{ route('members.template') }}" class="btn-secondary mt-4"><x-icon name="download" class="size-4" /> {{ __('Modèle Excel du registre') }}</a>
                <ul class="mt-5 space-y-2 text-sm text-ink-800">
                    <li class="flex gap-2"><x-icon name="check" class="mt-0.5 size-4 shrink-0 text-leaf-600" /> {{ __('Seul le nom est obligatoire.') }}</li>
                    <li class="flex gap-2"><x-icon name="check" class="mt-0.5 size-4 shrink-0 text-leaf-600" /> {{ __('Gardez les numéros de votre ancien registre, ou laissez Waumini les attribuer.') }}</li>
                    <li class="flex gap-2"><x-icon name="check" class="mt-0.5 size-4 shrink-0 text-leaf-600" /> {{ __('Même nom de ménage sur plusieurs lignes : la famille est regroupée.') }}</li>
                </ul>
            </section>

            <section class="card p-5 sm:p-6">
                <span class="icon-tile bg-ochre-100 text-ochre-700"><x-icon name="upload" class="size-5" /></span>
                <h2 class="mt-3 text-lg">{{ __('2. Envoyez le fichier rempli') }}</h2>
                <p class="mt-1 text-sm text-sand-700">{{ __('Excel (.xlsx, .xls), OpenDocument ou CSV, jusqu’à 5 000 lignes.') }}</p>
                <label class="mt-4 flex cursor-pointer flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-sand-300 bg-sand-50 px-4 py-8 text-center hover:border-ochre-300 hover:bg-ochre-50">
                    <x-icon name="file-spreadsheet" class="size-8 text-ochre-600" />
                    <span class="font-semibold text-ink-700">{{ __('Choisir le fichier') }}</span>
                    <span class="text-xs text-sand-700">{{ __('La vérification commence aussitôt.') }}</span>
                    <input type="file" wire:model="file" accept=".xlsx,.xls,.csv,.ods" class="sr-only">
                </label>
                <div wire:loading wire:target="file" class="mt-3 flex items-center gap-2 text-sm font-semibold text-ink-700">
                    <x-icon name="refresh-cw" class="size-4 animate-spin" /> {{ __('Lecture et vérification des lignes…') }}
                </div>
                @error('file') <p class="error mt-3">{{ $message }}</p> @enderror
            </section>
        </div>

    @elseif ($step === 2)
        {{-- Résultat de la vérification --}}
        <section class="card mb-5 p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-sand-700">{{ $import->file_name }}</p>
                    <h2 class="text-lg">{{ trans_choice(':count ligne lue|:count lignes lues', $summary['total']) }}</h2>
                </div>
                <button type="button" wire:click="restart" class="btn-ghost"><x-icon name="undo-2" class="size-4" /> {{ __('Envoyer un autre fichier') }}</button>
            </div>
            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ([
                    ['valides', __('Prêtes à importer'), $summary['valid'], 'bg-leaf-50 text-leaf-700', 'circle-check'],
                    ['doublons', __('Doublons possibles'), $summary['duplicates'], 'bg-ochre-50 text-ochre-700', 'users'],
                    ['erreurs', __('Avec erreurs'), $summary['errors'], 'bg-terra-50 text-terra-700', 'triangle-alert'],
                    ['toutes', __('Ménages'), $summary['households'], 'bg-ink-50 text-ink-700', 'house'],
                ] as [$key, $label, $value, $tone, $icon])
                    <button type="button" wire:click="$set('filter', '{{ $key }}')" @class(["rounded-2xl p-3 text-left $tone", 'ring-2 ring-ink-700' => $filter === $key && $key !== 'toutes'])>
                        <x-icon :name="$icon" class="size-5" />
                        <span class="mt-1 block text-2xl font-semibold tabular">{{ $value }}</span>
                        <span class="block text-xs font-semibold">{{ $label }}</span>
                    </button>
                @endforeach
            </div>
            @if ($unknown)
                <p class="mt-4 rounded-xl bg-sand-100 px-4 py-3 text-sm text-ink-800">
                    <x-icon name="info" class="mr-1 inline size-4 text-ochre-600" />
                    {{ __('Colonnes ignorées (titres non reconnus) : :list', ['list' => implode(', ', $unknown)]) }}
                </p>
            @endif
        </section>

        <div class="mb-3 flex flex-wrap gap-2">
            @foreach (['toutes' => __('Toutes'), 'valides' => __('Prêtes'), 'doublons' => __('Doublons possibles'), 'erreurs' => __('Erreurs')] as $key => $label)
                <button type="button" wire:click="$set('filter', '{{ $key }}')" @class(['chip', '!border-ink-700 !bg-ink-700 !text-white' => $filter === $key])>{{ $label }}</button>
            @endforeach
        </div>

        <div class="card overflow-hidden">
            <ul class="divide-y divide-sand-100">
                @forelse ($rows->take($shown) as $row)
                    <li class="flex gap-3 px-4 py-3" wire:key="l-{{ $row['line'] }}">
                        <span class="w-12 shrink-0 pt-0.5 text-right font-mono text-xs text-sand-700">{{ __('L. :n', ['n' => $row['line']]) }}</span>
                        <span @class(['mt-0.5 grid size-5 shrink-0 place-items-center rounded-full', 'bg-terra-500 text-white' => $row['errors'], 'bg-ochre-500 text-on-accent' => ! $row['errors'] && $row['duplicate'], 'bg-leaf-500 text-white' => ! $row['errors'] && ! $row['duplicate']])>
                            <x-icon :name="$row['errors'] ? 'x' : ($row['duplicate'] ? 'users' : 'check')" class="size-3" />
                        </span>
                        <div class="min-w-0 flex-1 text-sm">
                            <p class="font-semibold text-ink-800">{{ $row['name'] }}
                                @if ($row['data']['number'] ?? null)<span class="ml-1 font-mono text-xs font-normal text-sand-700">{{ $row['data']['number'] }}</span>@endif
                                @if ($row['household'])<span class="ml-1 text-xs font-normal text-sand-700">· {{ $row['household'] }}</span>@endif
                            </p>
                            @foreach ($row['errors'] as $error)<p class="text-terra-600">{{ $error }}</p>@endforeach
                            @if (! $row['errors'] && $row['duplicate'])<p class="text-ochre-700">{{ $row['duplicate'] }}</p>@endif
                            @foreach ($row['warnings'] as $warning)<p class="text-sand-700">{{ $warning }}</p>@endforeach
                        </div>
                    </li>
                @empty
                    <li class="px-4 py-8 text-center text-sm text-sand-700">{{ __('Aucune ligne dans cette catégorie.') }}</li>
                @endforelse
            </ul>
            @if ($rows->count() > $shown)
                <div class="border-t border-sand-100 p-3 text-center"><button type="button" wire:click="showMore" class="btn-ghost">{{ __('Voir plus de lignes (:count restantes)', ['count' => $rows->count() - $shown]) }}</button></div>
            @endif
        </div>

        {{-- Confirmation --}}
        @php $toImport = $summary['valid'] + ($includeDuplicates ? $summary['duplicates'] : 0); @endphp
        <section class="sticky bottom-24 z-10 mt-5 rounded-[22px] border border-sand-200 bg-white p-4 shadow-lg shadow-ink-900/10 lg:bottom-4 sm:p-5">
            <div class="flex flex-wrap items-center gap-4">
                <div class="min-w-0 flex-1 text-sm">
                    @if ($summary['duplicates'])
                        <label class="flex items-start gap-3"><input type="checkbox" wire:model.live="includeDuplicates" class="mt-0.5 size-5">
                            <span>{{ trans_choice('Importer aussi le doublon possible (c’est une autre personne)|Importer aussi les :count doublons possibles (ce sont d’autres personnes)', $summary['duplicates']) }}</span></label>
                    @endif
                    @if ($summary['errors'])
                        <p class="mt-1 text-terra-600">{{ trans_choice(':count ligne en erreur sera ignorée. Corrigez-la dans le fichier pour l’importer plus tard.|:count lignes en erreur seront ignorées. Corrigez-les dans le fichier pour les importer plus tard.', $summary['errors']) }}</p>
                    @endif
                </div>
                <button type="button" wire:click="confirm" wire:loading.attr="disabled" class="btn-primary" @disabled($toImport === 0)
                        wire:confirm="{{ trans_choice('Importer :count membre ?|Importer :count membres ?', $toImport) }}">
                    <x-icon name="upload" class="size-4" /> {{ trans_choice('Importer :count membre|Importer :count membres', $toImport) }}
                </button>
            </div>
        </section>

    @else
        <section class="card flex flex-col items-center px-6 py-10 text-center">
            <span class="icon-tile size-14 bg-leaf-500 text-white"><x-icon name="circle-check" class="size-7" /></span>
            <h2 class="mt-4 text-xl">{{ trans_choice(':count membre importé|:count membres importés', $import->imported_rows) }}</h2>
            <p class="mt-1 text-sand-700">{{ trans_choice(':count ménage créé.|:count ménages créés.', $import->households_created) }} {{ __('Vous pouvez annuler cet import pendant :days jours.', ['days' => \App\Models\MemberImport::CANCEL_DAYS]) }}</p>
            <div class="mt-5 flex flex-wrap justify-center gap-2">
                <a href="{{ route('members.index') }}" class="btn-primary"><x-icon name="contact-round" class="size-4" /> {{ __('Voir le registre') }}</a>
                <button type="button" wire:click="restart" class="btn-secondary">{{ __('Importer un autre fichier') }}</button>
            </div>
        </section>
    @endif

    {{-- Imports précédents --}}
    @if ($history->isNotEmpty())
        <section class="mt-8">
            <h2 class="mb-3 text-lg">{{ __('Imports précédents') }}</h2>
            <ul class="card divide-y divide-sand-100">
                @foreach ($history as $h)
                    <li class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm" wire:key="h-{{ $h->id }}">
                        <x-icon name="file-spreadsheet" class="size-5 text-sand-500" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold text-ink-800">{{ $h->file_name }}</p>
                            <p class="text-sand-700">{{ $h->imported_at?->translatedFormat('j F Y à H:i') }} · {{ $h->user?->name }} ·
                                {{ trans_choice(':count membre|:count membres', $h->imported_rows) }}</p>
                        </div>
                        @if ($h->status === 'cancelled')
                            <span class="badge bg-sand-100 text-sand-700">{{ __('Annulé') }}</span>
                        @elseif ($h->canBeCancelled())
                            <button type="button" wire:click="cancelImport({{ $h->id }})" class="font-semibold text-terra-600 hover:underline"
                                    wire:confirm="{{ __('Annuler cet import ? Les :count membres importés et les ménages créés seront retirés du registre, même s’ils ont été modifiés depuis.', ['count' => $h->imported_rows]) }}">{{ __('Annuler l’import') }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
