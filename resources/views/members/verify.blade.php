<x-layouts.simple :title="__('Vérification d’une carte de membre')">
    <div class="mx-auto max-w-md">
        @if (! $member)
            <div class="card p-6 text-center">
                <span class="icon-tile mx-auto size-14 bg-terra-500 text-white"><x-icon name="x" class="size-7" /></span>
                <h1 class="mt-4 text-xl">{{ __('Carte inconnue') }}</h1>
                <p class="mt-1 text-sand-700">{{ __('Ce code ne correspond à aucune carte de membre émise avec Waumini.') }}</p>
            </div>
        @else
            @php $valid = $member->hasValidCard(); @endphp
            <div class="card overflow-hidden">
                <div @class(['flex items-center gap-3 px-5 py-4 text-white', 'bg-leaf-500' => $valid, 'bg-terra-500' => ! $valid])>
                    <x-icon :name="$valid ? 'circle-check' : 'triangle-alert'" class="size-7" />
                    <div>
                        <p class="text-lg font-semibold">{{ $valid ? __('Carte valide') : __('Carte non valide') }}</p>
                        <p class="text-sm text-white/85">{{ __('Vérifiée le :date', ['date' => now()->translatedFormat('j F Y à H:i')]) }}</p>
                    </div>
                </div>
                <dl class="divide-y divide-sand-100 px-5 text-sm">
                    <div class="grid grid-cols-[8rem_1fr] gap-3 py-3"><dt class="text-sand-700">{{ __('Nom') }}</dt><dd class="font-semibold text-ink-800">{{ $member->officialName() }}</dd></div>
                    <div class="grid grid-cols-[8rem_1fr] gap-3 py-3"><dt class="text-sand-700">{{ __('Numéro') }}</dt><dd class="font-mono font-semibold text-ink-800">{{ $member->number }}</dd></div>
                    <div class="grid grid-cols-[8rem_1fr] gap-3 py-3"><dt class="text-sand-700">{{ __('Communauté') }}</dt><dd class="font-semibold text-ink-800">{{ $member->organization->name }}</dd></div>
                    @unless ($valid)
                        <div class="py-3 text-terra-700">{{ $member->trashed() ? __('Cette personne ne figure plus au registre.') : __('Statut actuel : :status. Renseignez-vous auprès du secrétariat.', ['status' => $member->status?->name ?? __('sans statut')]) }}</div>
                    @endunless
                </dl>
            </div>
            <p class="mt-4 text-center text-xs text-sand-700">{{ __('Pour protéger la vie privée, seules ces informations sont affichées.') }}</p>
        @endif
    </div>
</x-layouts.simple>
