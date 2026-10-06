@php use App\Support\Money; @endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">{{ $organization->level_label }}</p>
            <h1 class="page-title mt-1">{{ __('Bonjour, :name', ['name' => \Illuminate\Support\Str::of(auth()->user()->name)->explode(' ')->first()]) }}</h1>
            <p class="mt-1 text-sand-700">{{ $organization->name }} · {{ now()->timezone($organization->timezone)->translatedFormat('l j F Y') }}</p>
        </div>
        @if ($trialDaysLeft !== null)
            <span class="badge bg-ochre-100 text-ochre-700"><x-icon name="clock" class="size-3.5" /> {{ trans_choice('Essai gratuit : :count jour restant|Essai gratuit : :count jours restants', $trialDaysLeft) }}</span>
        @endif
    </div>

    {{-- Chiffres clés --}}
    <div class="grid grid-cols-3 gap-3 sm:gap-4">
        @foreach ($stats as $stat)
            <a href="{{ route($stat['route']) }}" class="card group p-4 transition hover:border-ink-200 sm:p-5">
                <div class="flex items-center justify-between">
                    <x-icon :name="$stat['icon']" class="size-5 text-ink-400" />
                    <x-icon name="arrow-right" class="hidden size-4 text-sand-300 transition group-hover:translate-x-0.5 group-hover:text-ink-400 sm:block" />
                </div>
                <p class="mt-3 font-display text-3xl font-bold text-ink-700 tabular">{{ $stat['value'] }}</p>
                <p class="mt-0.5 text-xs text-sand-700 sm:text-sm">{{ $stat['label'] }}</p>
            </a>
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-[1.2fr_1fr]">
        {{-- Premiers pas --}}
        @if (count($checklist))
            <section class="card p-5 sm:p-6">
                <h2 class="text-lg font-semibold">{{ __('Premiers pas') }}</h2>
                <p class="mt-1 text-sm text-sand-700">{{ __('Quatre étapes pour bien démarrer avec Waumini.') }}</p>
                <ol class="mt-4 space-y-2">
                    @foreach ($checklist as $item)
                        <li>
                            <a href="{{ route($item['route']) }}" class="flex items-center gap-3 rounded-xl border border-sand-100 px-3 py-3 hover:border-ink-200 hover:bg-ink-50/40"
                               @if (! empty($item['pwa'])) x-data :class="$store.pwa.installed && 'opacity-70'" @endif>
                                @if (! empty($item['pwa']))
                                    <span class="grid size-7 shrink-0 place-items-center rounded-full border-2" :class="$store.pwa.installed ? 'border-ink-600 bg-ink-600 text-white' : 'border-sand-300 text-transparent'"><x-icon name="check" class="size-4" /></span>
                                @else
                                    <span @class(['grid size-7 shrink-0 place-items-center rounded-full border-2', 'border-ink-600 bg-ink-600 text-white' => $item['done'], 'border-sand-300 text-transparent' => ! $item['done']])><x-icon name="check" class="size-4" /></span>
                                @endif
                                <span @class(['flex-1 text-sm', 'text-sand-500 line-through' => $item['done'], 'font-bold text-ink-700' => ! $item['done']])>{{ $item['label'] }}</span>
                                <x-icon name="chevron-right" class="size-4 text-sand-300" />
                            </a>
                        </li>
                    @endforeach
                </ol>
            </section>
        @endif

        {{-- Taux du jour --}}
        <section class="card p-5 sm:p-6">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">{{ __('Taux du jour') }}</h2>
                @can('currencies.manage')
                    <a href="{{ route('currencies.index') }}" class="text-sm font-bold text-ink-600 hover:underline">{{ __('Mettre à jour') }}</a>
                @endcan
            </div>
            <ul class="mt-4 divide-y divide-sand-100">
                @forelse ($rates as $rate)
                    <li class="flex items-center justify-between gap-3 py-3">
                        <span>
                            <span class="font-bold text-ink-700">{{ $rate['currency'] }}</span>
                            <span class="block text-xs text-sand-700">{{ $rate['name'] }}</span>
                        </span>
                        @if ($rate['rate'])
                            <span class="text-right">
                                <span class="font-display text-lg font-semibold text-ink-700 tabular">{{ Money::rate($rate['rate'], $rate['currency']) }}</span>
                                @unless ($rate['today'])
                                    <span class="block text-xs font-bold text-terra-600">{{ __('Pas encore saisi aujourd’hui') }}</span>
                                @endunless
                            </span>
                        @else
                            <span class="badge bg-terra-50 text-terra-600">{{ __('Aucun taux') }}</span>
                        @endif
                    </li>
                @empty
                    <li class="py-3 text-sm text-sand-700">{{ __('Seul le dollar est utilisé.') }}</li>
                @endforelse
            </ul>
        </section>
    </div>

    {{-- Activité récente --}}
    @can('audit.view')
        <section class="card p-5 sm:p-6">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">{{ __('Activité récente') }}</h2>
                <a href="{{ route('audit.index') }}" class="text-sm font-bold text-ink-600 hover:underline">{{ __('Tout le journal') }}</a>
            </div>
            <ul class="mt-3 divide-y divide-sand-100">
                @forelse ($activity as $log)
                    <li class="flex items-start gap-3 py-3 text-sm">
                        <span class="mt-1.5 size-2 shrink-0 rounded-full bg-ochre-500"></span>
                        <span class="min-w-0 flex-1">
                            <span class="font-bold text-ink-700">{{ $log->user?->name ?? __('Système') }}</span>
                            {{ \App\Support\AuditPresenter::sentence($log) }}
                        </span>
                        <time class="shrink-0 text-xs text-sand-500" datetime="{{ $log->created_at->toIso8601String() }}">{{ $log->created_at->diffForHumans() }}</time>
                    </li>
                @empty
                    <li class="py-3 text-sm text-sand-700">{{ __('Aucune activité pour le moment.') }}</li>
                @endforelse
            </ul>
        </section>
    @endcan

    {{-- Modules à venir --}}
    <section class="rounded-2xl border border-dashed border-sand-300 p-5 sm:p-6">
        <h2 class="text-lg font-semibold">{{ __('Bientôt dans Waumini') }}</h2>
        <p class="mt-1 text-sm text-sand-700">{{ __('Les modules arrivent étape par étape. Suivez l’avancement sur la feuille de route.') }}</p>
        <div class="mt-4 flex flex-wrap gap-2">
            @foreach ([__('Membres et ménages'), __('Finances et promesses'), __('Plan d’action et budget'), __('Paie'), __('Groupes et activités'), __('Documents et attestations'), __('Suivi pastoral'), __('Espace membre'), __('Site vitrine')] as $module)
                <span class="badge bg-sand-100 text-sand-700">{{ $module }}</span>
            @endforeach
        </div>
    </section>
</div>
