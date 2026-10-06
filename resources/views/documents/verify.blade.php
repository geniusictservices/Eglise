<x-layouts.simple :title="__('Vérification d’un document')">
    <div class="mx-auto max-w-md">
        @if (! $document)
            <div class="card p-6 text-center">
                <span class="icon-tile mx-auto size-14 bg-terra-500 text-white"><x-icon name="x" class="size-7" /></span>
                <h1 class="mt-4 text-xl">{{ __('Document inconnu') }}</h1>
                <p class="mt-1 text-sand-700">{{ __('Ce code ne correspond à aucun document délivré avec Waumini. Le papier présenté n’est peut-être pas authentique.') }}</p>
            </div>
        @else
            @php $valid = ! $document->isCancelled(); @endphp
            <div class="card overflow-hidden">
                <div @class(['flex items-center gap-3 px-5 py-4 text-white', 'bg-leaf-500' => $valid, 'bg-terra-500' => ! $valid])>
                    <x-icon :name="$valid ? 'circle-check' : 'triangle-alert'" class="size-7" />
                    <div>
                        <p class="text-lg font-semibold">{{ $valid ? __('Document authentique') : __('Document annulé') }}</p>
                        <p class="text-sm text-white/85">{{ __('Vérifié le :date', ['date' => now()->translatedFormat('j F Y à H:i')]) }}</p>
                    </div>
                </div>
                <dl class="divide-y divide-sand-100 px-5 text-sm">
                    @foreach ([__('Document') => $document->title, __('Numéro') => $document->number, __('Délivré à') => $document->beneficiary,
                        __('Délivré le') => $document->issued_on->translatedFormat('j F Y'), __('Par') => $document->organization->name,
                        __('Signataire') => collect([$document->signatory, $document->signatory_title])->filter()->implode(', ')] as $label => $value)
                        @if ($value)<div class="grid grid-cols-[7rem_1fr] gap-3 py-3"><dt class="text-sand-700">{{ $label }}</dt><dd @class(['font-semibold text-ink-800', 'font-mono' => $label === __('Numéro')])>{{ $value }}</dd></div>@endif
                    @endforeach
                    @unless ($valid)
                        <div class="py-3 text-terra-700">{{ __('Ce document a été annulé le :d. Il ne vaut plus. Renseignez-vous auprès du secrétariat.', ['d' => $document->cancelled_at->translatedFormat('j F Y')]) }}</div>
                    @endunless
                </dl>
            </div>
            <p class="mt-4 text-center text-xs text-sand-700">{{ __('Comparez ces informations avec le papier présenté. Pour protéger la vie privée, le texte complet n’est pas affiché.') }}</p>
        @endif
    </div>
</x-layouts.simple>
