@php
    $year = now()->year;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => __('Carte de :name', ['name' => $member->fullName()])])
    <style>
        .card-face { width: 85.6mm; height: 54mm; }
        /* À l'écran, les cartes sont agrandies pour être lisibles ; l'impression reste à la taille réelle. */
        @media screen and (min-width: 768px) { .cards { zoom: 1.45; } }
        @media print {
            @page { size: A4; margin: 15mm; }
            body { background: #fff !important; }
            .no-print { display: none !important; }
            .sheet { box-shadow: none !important; padding: 0 !important; }
            .card-face { box-shadow: none !important; outline: 0.2mm dashed #8F8577; outline-offset: 1.5mm; break-inside: avoid; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body class="min-h-dvh bg-sand-100">
    <div class="no-print sticky top-0 z-10 border-b border-sand-200 bg-white/95 backdrop-blur">
        <div class="mx-auto flex max-w-4xl flex-wrap items-center gap-3 px-4 py-3">
            <a href="{{ auth()->user()->can('members.view') ? route('members.show', $member) : route('member.space') }}" class="inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ $member->fullName() }}</a>
            <span class="flex-1"></span>
            <div class="flex rounded-xl border border-sand-200 bg-sand-50 p-0.5 text-sm font-semibold" role="group" aria-label="{{ __('Photo sur la carte') }}">
                <a href="{{ route('members.card', $member) }}" @class(['rounded-lg px-3 py-1.5', 'bg-ink-700 text-white' => $withPhoto, 'text-ink-600' => ! $withPhoto]) @if ($withPhoto) aria-current="true" @endif>{{ __('Avec photo') }}</a>
                <a href="{{ route('members.card', [$member, 'photo' => 0]) }}" @class(['rounded-lg px-3 py-1.5', 'bg-ink-700 text-white' => ! $withPhoto, 'text-ink-600' => $withPhoto]) @if (! $withPhoto) aria-current="true" @endif>{{ __('Sans photo') }}</a>
            </div>
            <button type="button" onclick="window.print()" class="btn-primary"><x-icon name="printer" class="size-4" /> {{ __('Imprimer') }}</button>
        </div>
    </div>

    <main class="sheet mx-auto max-w-4xl px-4 py-8">
        <p class="no-print mb-6 text-center text-sm text-sand-700">{{ __('Format carte bancaire (85,6 × 54 mm). Imprimez sur du papier épais, découpez le long des pointillés, collez recto et verso, puis plastifiez.') }}</p>

        <div class="cards flex flex-wrap items-start justify-center gap-8">
            {{-- Recto --}}
            <section class="card-face relative flex flex-col overflow-hidden rounded-[3.2mm] bg-white shadow-xl shadow-ink-900/15" aria-label="{{ __('Recto') }}">
                <div class="wax wax-veil wax-veil-strong flex h-[13mm] shrink-0 items-center gap-[2.5mm] px-[3.5mm] text-white">
                    <span class="grid size-[8mm] shrink-0 place-items-center rounded-[1.8mm] bg-ochre-500 text-[3mm] font-semibold text-on-accent">{{ $root->initials() }}</span>
                    <div class="min-w-0 leading-tight">
                        <p class="truncate text-[3mm] font-semibold">{{ $root->name }}</p>
                        <p class="truncate text-[2.3mm] text-ink-100">{{ $organization->is($root) ? __('Carte de membre') : $organization->name }}</p>
                    </div>
                </div>
                <div class="flex flex-1 gap-[3mm] px-[3.5mm] py-[3mm]">
                    @if ($withPhoto)
                        {{-- Photo d'identité au format passeport (35 × 45 mm), réduite à 21 × 27 mm --}}
                        @if ($member->photo_path)
                            <img src="{{ route('members.photo', $member) }}" alt="" class="h-[27mm] w-[21mm] shrink-0 rounded-[1.2mm] object-cover">
                        @else
                            <span class="grid h-[27mm] w-[21mm] shrink-0 place-items-center rounded-[1.2mm] border-[0.3mm] border-dashed border-sand-300 bg-sand-50 text-center text-[2mm] leading-tight text-sand-500">{{ __('Photo') }}<br>{{ __('passeport') }}</span>
                        @endif
                    @endif
                    <div class="flex min-w-0 flex-1 flex-col">
                        <p class="text-[3.6mm] font-semibold uppercase leading-tight text-ink-800">{{ $member->last_name }}</p>
                        <p class="text-[3mm] leading-tight text-ink-800">{{ trim($member->middle_name.' '.$member->first_name) }}</p>
                        <dl class="mt-auto space-y-[0.6mm] text-[2.3mm] leading-tight">
                            <div><dt class="inline text-sand-700">{{ __('N°') }}</dt> <dd class="inline font-mono font-semibold text-ink-800">{{ $member->number }}</dd></div>
                            @if ($member->joined_on)<div><dt class="inline text-sand-700">{{ __('Membre depuis') }}</dt> <dd class="inline font-semibold text-ink-800">{{ $member->joined_on->year }}</dd></div>@endif
                            @if ($member->status)<div><dt class="inline text-sand-700">{{ __('Statut') }}</dt> <dd class="inline font-semibold text-ink-800">{{ $member->status->name }}</dd></div>@endif
                        </dl>
                    </div>
                </div>
                <div class="h-[1.6mm] shrink-0 bg-gradient-to-r from-ochre-500 via-terra-500 to-leaf-500"></div>
            </section>

            {{-- Verso --}}
            <section class="card-face flex items-center gap-[3.5mm] overflow-hidden rounded-[3.2mm] bg-white px-[4mm] shadow-xl shadow-ink-900/15" aria-label="{{ __('Verso') }}">
                <div class="size-[34mm] shrink-0 [&>svg]:size-full">{!! $qr !!}</div>
                <div class="min-w-0 text-[2.3mm] leading-snug text-ink-800">
                    <p class="text-[2.8mm] font-semibold text-ink-700">{{ __('Carte vérifiable') }}</p>
                    <p class="mt-[0.8mm] text-sand-700">{{ __('Scannez ce code pour vérifier que la carte est valide.') }}</p>
                    <p class="mt-[2mm] font-semibold">{{ $organization->name }}</p>
                    @if ($organization->address)<p class="text-sand-700">{{ $organization->address }}</p>@endif
                    @if ($organization->phone)<p class="tabular text-sand-700">{{ \App\Support\Phone::format($organization->phone) }}</p>@endif
                    <p class="mt-[2mm] flex items-center gap-[1mm] text-[2mm] text-sand-500"><x-logo-mark style="width: 3mm; height: 3mm" /> Waumini</p>
                </div>
            </section>
        </div>

        <p class="no-print mt-8 text-center text-xs text-sand-700">{{ __('Lien de vérification :') }} <span class="break-all font-mono">{{ $url }}</span></p>
    </main>
</body>
</html>
