<div class="space-y-6">
    <x-page-header :title="__('Vue d’ensemble')" :description="__('Les communautés inscrites sur :domain, leurs essais et leurs abonnements.', ['domain' => config('waumini.domain')])" />

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-4">
        @foreach ([
            [__('Communautés'), number_format($communities, 0, ',', ' '), 'building-2', 'bg-ink-700 text-white', trans_choice(':count abonnée|:count abonnées', $counts['active'] ?? 0)],
            [__('En essai'), $counts['trial'] ?? 0, 'clock', 'bg-ochre-500 text-on-accent', trans_choice(':count finit cette semaine|:count finissent cette semaine', $trialsEnding->count())],
            [__('À régulariser'), ($counts['grace'] ?? 0) + ($counts['read_only'] ?? 0), 'triangle-alert', 'bg-terra-500 text-white', __(':g en délai de grâce, :r en lecture seule', ['g' => $counts['grace'] ?? 0, 'r' => $counts['read_only'] ?? 0])],
            [__('Encaissé ce mois'), number_format((float) $revenueMonth, 2, ',', ' ').' $', 'banknote', 'bg-leaf-500 text-white', trans_choice(':count membre inscrit au total|:count membres inscrits au total', $members, ['count' => number_format($members, 0, ',', ' ')])],
        ] as [$label, $value, $icon, $tone, $hint])
            <div class="card grid content-start gap-1 p-4 lg:p-5">
                <span class="icon-tile {{ $tone }}"><x-icon :name="$icon" class="size-5" /></span>
                <span class="mt-2 text-sm text-sand-700">{{ $label }}</span>
                <span class="text-2xl font-semibold text-ink-800 tabular">{{ $value }}</span>
                <span class="text-xs text-sand-700">{{ $hint }}</span>
            </div>
        @endforeach
    </div>

    @if ($declarations->isNotEmpty() || $openTickets->isNotEmpty())
        <section class="rounded-[18px] border border-ochre-300 bg-ochre-50 p-5 sm:p-6">
            <h2 class="mb-3 text-lg">{{ __('À traiter') }}</h2>
            <ul class="divide-y divide-ochre-100">
                @foreach ($declarations as $d)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5 text-sm">
                        <x-icon name="smartphone" class="size-4 text-ochre-600" />
                        <a href="{{ route('admin.communities.show', $d->organization) }}" class="min-w-0 flex-1 font-semibold text-ink-700 hover:underline">{{ __('Paiement déclaré : :c', ['c' => $d->organization->name]) }}</a>
                        <span class="text-sand-700">{{ $d->plan->name }} · {{ number_format((float) $d->amount, 2, ',', ' ') }} $ · <span class="font-mono">{{ $d->reference }}</span></span>
                    </li>
                @endforeach
                @foreach ($openTickets as $t)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5 text-sm">
                        <x-icon name="circle-help" class="size-4 text-ochre-600" />
                        <a href="{{ route('admin.tickets.show', $t) }}" class="min-w-0 flex-1 font-semibold text-ink-700 hover:underline">{{ $t->subject }}</a>
                        <span class="text-sand-700">{{ $t->organization->name }} · {{ $t->last_activity_at?->diffForHumans() }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="card p-5 sm:p-6">
            <h2 class="mb-3 text-lg">{{ __('Renouvellements des 30 prochains jours') }}</h2>
            <ul class="divide-y divide-sand-100">
                @forelse ($renewals as $s)
                    <li class="flex items-center gap-3 py-2.5 text-sm">
                        <a href="{{ route('admin.communities.show', $s->organization) }}" class="min-w-0 flex-1 truncate font-semibold text-ink-700 hover:underline">{{ $s->organization->name }}</a>
                        <span class="text-sand-700">{{ $s->plan->name }}</span>
                        <span class="whitespace-nowrap font-semibold text-ink-800">{{ $s->ends_on->translatedFormat('j M') }}</span>
                    </li>
                @empty
                    <li class="py-2 text-sm text-sand-700">{{ __('Aucun renouvellement à venir.') }}</li>
                @endforelse
            </ul>
        </section>
        <section class="card p-5 sm:p-6">
            <h2 class="mb-3 text-lg">{{ __('Essais qui finissent cette semaine') }}</h2>
            <ul class="divide-y divide-sand-100">
                @forelse ($trialsEnding as $o)
                    <li class="flex items-center gap-3 py-2.5 text-sm">
                        <a href="{{ route('admin.communities.show', $o) }}" class="min-w-0 flex-1 truncate font-semibold text-ink-700 hover:underline">{{ $o->name }}</a>
                        <span class="whitespace-nowrap text-sand-700">{{ $o->trial_ends_at->diffForHumans() }}</span>
                    </li>
                @empty
                    <li class="py-2 text-sm text-sand-700">{{ __('Aucun essai ne finit cette semaine.') }}</li>
                @endforelse
            </ul>
        </section>
        <section class="card p-5 sm:p-6">
            <h2 class="mb-3 text-lg">{{ __('Dernières inscriptions') }}</h2>
            <ul class="divide-y divide-sand-100">
                @foreach ($recent as $o)
                    <li class="flex items-center gap-3 py-2.5 text-sm">
                        <a href="{{ route('admin.communities.show', $o) }}" class="min-w-0 flex-1 truncate font-semibold text-ink-700 hover:underline">{{ $o->name }}</a>
                        <x-org-status :status="$o->status" />
                        <span class="whitespace-nowrap text-sand-700">{{ $o->created_at->translatedFormat('j M') }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
        <section class="card p-5 sm:p-6">
            <h2 class="mb-3 text-lg">{{ __('Demandes de démonstration') }}</h2>
            <ul class="divide-y divide-sand-100">
                @forelse ($demoRequests as $d)
                    <li class="py-2.5 text-sm">
                        <div class="flex items-center gap-3">
                            <span class="min-w-0 flex-1 truncate font-semibold text-ink-800">{{ $d->community }}</span>
                            <a href="https://wa.me/{{ ltrim($d->phone, '+') }}" target="_blank" rel="noopener" class="font-semibold text-leaf-600 hover:underline">WhatsApp</a>
                        </div>
                        <p class="text-sand-700">{{ $d->name }} · {{ \App\Support\Phone::format($d->phone) }}@if ($d->city) · {{ $d->city }}@endif · {{ $d->created_at->translatedFormat('j M') }}</p>
                    </li>
                @empty
                    <li class="py-2 text-sm text-sand-700">{{ __('Aucune demande pour le moment.') }}</li>
                @endforelse
            </ul>
        </section>
    </div>
</div>
