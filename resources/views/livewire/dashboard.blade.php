@php
    use App\Support\Money;
    $firstName = \Illuminate\Support\Str::of(auth()->user()->name)->explode(' ')->reject(fn ($w) => in_array(mb_strtolower($w), ['pasteur', 'rév.', 'rev.', 'pst', 'pst.']))->first();
    $date = now()->timezone($organization->timezone)->translatedFormat('l j F');
    $done = collect($checklist)->where('done', true)->count();
    $percent = count($checklist) ? (int) round($done / count($checklist) * 100) : 100;
    $tones = ['ink' => 'bg-ink-700 text-white', 'ochre' => 'bg-ochre-500 text-[#2A1B04]', 'terra' => 'bg-terra-500 text-white', 'leaf' => 'bg-leaf-500 text-white'];
@endphp
<div class="space-y-5 lg:space-y-6">
    {{-- Salutation : bandeau wax sur téléphone, titre simple sur ordinateur --}}
    <div class="wax wax-veil -mx-4 -mt-5 px-4 pb-7 pt-5 text-white sm:-mx-6 sm:px-6 lg:hidden">
        <p class="text-sm text-ink-100 first-letter:uppercase">{{ $date }}</p>
        <h1 class="mt-0.5 text-2xl font-semibold text-white">{{ __('Bonjour, :name', ['name' => $firstName]) }}</h1>
        @if ($trialDaysLeft !== null)
            <span class="badge mt-3 bg-ochre-500 text-[#2A1B04]"><x-icon name="clock" class="size-3.5" /> {{ trans_choice('Essai gratuit : :count jour restant|Essai gratuit : :count jours restants', $trialDaysLeft) }}</span>
        @endif
    </div>

    <div class="hidden flex-wrap items-end justify-between gap-4 lg:flex">
        <div>
            <p class="text-sm text-sand-700">{{ $organization->name }} · {{ $date }}</p>
            <h1 class="mt-0.5 text-[1.75rem] font-semibold">{{ __('Bonjour, :name', ['name' => $firstName]) }}</h1>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($trialDaysLeft !== null)
                <span class="badge bg-ochre-100 text-ochre-700"><x-icon name="clock" class="size-3.5" /> {{ trans_choice('Essai gratuit : :count jour restant|Essai gratuit : :count jours restants', $trialDaysLeft) }}</span>
            @endif
        </div>
    </div>

    {{-- Chiffres clés --}}
    <div class="relative z-10 -mt-9 grid grid-cols-2 gap-3 lg:mt-0 lg:grid-cols-4 lg:gap-4">
        @foreach ($stats as $stat)
            <a href="{{ route($stat['route']) }}" class="card group grid content-start gap-0.5 p-3.5 shadow-sm shadow-ink-900/5 transition hover:border-ochre-300 lg:gap-1 lg:p-5">
                <span class="icon-tile size-9 lg:size-10 {{ $tones[$stat['tone']] }}"><x-icon :name="$stat['icon']" class="size-5" /></span>
                <span class="mt-2 text-xs text-sand-700 sm:text-sm">{{ $stat['label'] }}</span>
                <span class="text-xl font-semibold text-ink-800 tabular lg:text-2xl">{{ $stat['value'] }}</span>
                @if (! empty($stat['hint']))<span class="text-xs text-sand-700">{{ $stat['hint'] }}</span>@endif
            </a>
        @endforeach
    </div>

    {{-- Actions rapides --}}
    @if (count($actions))
        <div class="flex flex-wrap gap-2">
            @foreach ($actions as $action)
                <a href="{{ $action['url'] }}" class="chip"><x-icon :name="$action['icon']" class="size-4 {{ $action['color'] }}" /> {{ $action['label'] }}</a>
            @endforeach
        </div>
    @endif

    <div class="grid gap-5 lg:grid-cols-[1.2fr_1fr] lg:gap-6">
        {{-- Premiers pas --}}
        @if (count($checklist))
            <section class="card p-5 sm:p-6">
                <div class="flex items-center gap-4">
                    <span class="ring-progress" style="--v: {{ $percent }}"><span>{{ $percent }} %</span></span>
                    <div>
                        <h2 class="text-lg">{{ __('Premiers pas') }}</h2>
                        <p class="text-sm text-sand-700">{{ __(':done étapes sur :total pour bien démarrer.', ['done' => $done, 'total' => count($checklist)]) }}</p>
                    </div>
                </div>
                <ol class="mt-4 space-y-2">
                    @foreach ($checklist as $item)
                        <li>
                            <a href="{{ route($item['route']) }}" class="flex items-center gap-3 rounded-2xl border border-sand-200 px-3 py-3 hover:border-ochre-300 hover:bg-ochre-50/60"
                               @if (! empty($item['pwa'])) x-data :class="$store.pwa.installed && 'opacity-70'" @endif>
                                @if (! empty($item['pwa']))
                                    <span class="grid size-7 shrink-0 place-items-center rounded-full border-2" :class="$store.pwa.installed ? 'border-leaf-500 bg-leaf-500 text-white' : 'border-sand-300 text-transparent'"><x-icon name="check" class="size-4" /></span>
                                @else
                                    <span @class(['grid size-7 shrink-0 place-items-center rounded-full border-2', 'border-leaf-500 bg-leaf-500 text-white' => $item['done'], 'border-sand-300 text-transparent' => ! $item['done']])><x-icon name="check" class="size-4" /></span>
                                @endif
                                <span @class(['flex-1 text-sm', 'text-sand-500 line-through' => $item['done'], 'font-medium text-ink-800' => ! $item['done']])>{{ $item['label'] }}</span>
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
                <div class="flex items-center gap-3">
                    <span class="icon-tile bg-leaf-500 text-white"><x-icon name="arrow-left-right" class="size-5" /></span>
                    <h2 class="text-lg">{{ __('Taux du jour') }}</h2>
                </div>
                @can('currencies.manage')
                    <a href="{{ route('currencies.index') }}" class="text-sm font-semibold text-ink-600 hover:underline">{{ __('Mettre à jour') }}</a>
                @endcan
            </div>
            <ul class="mt-3 divide-y divide-sand-100">
                @forelse ($rates as $rate)
                    <li class="flex items-center justify-between gap-3 py-3">
                        <span>
                            <span class="font-semibold text-ink-800">{{ $rate['currency'] }}</span>
                            <span class="block text-xs text-sand-700">{{ $rate['name'] }}</span>
                        </span>
                        @if ($rate['rate'])
                            <span class="text-right">
                                <span class="text-lg font-semibold text-ink-800 tabular">{{ Money::rate($rate['rate'], $rate['currency']) }}</span>
                                @unless ($rate['today'])
                                    <span class="block text-xs font-medium text-terra-600">{{ __('Pas encore saisi aujourd’hui') }}</span>
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
                <div class="flex items-center gap-3">
                    <span class="icon-tile bg-ink-700 text-white"><x-icon name="history" class="size-5" /></span>
                    <h2 class="text-lg">{{ __('Activité récente') }}</h2>
                </div>
                <a href="{{ route('audit.index') }}" class="text-sm font-semibold text-ink-600 hover:underline">{{ __('Tout le journal') }}</a>
            </div>
            <ul class="mt-3 divide-y divide-sand-100">
                @forelse ($activity as $log)
                    <li class="flex items-start gap-3 py-3 text-sm">
                        <span class="mt-1.5 size-2 shrink-0 rounded-full bg-ochre-500"></span>
                        <span class="min-w-0 flex-1">
                            <span class="font-semibold text-ink-800">{{ $log->user?->name ?? __('Système') }}</span>
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
    <section class="rounded-[18px] border-[1.5px] border-dashed border-sand-300 p-5 sm:p-6">
        <h2 class="text-lg">{{ __('Bientôt dans Waumini') }}</h2>
        <p class="mt-1 text-sm text-sand-700">{{ __('Les modules arrivent étape par étape. Suivez l’avancement sur la feuille de route.') }}</p>
        <div class="mt-4 flex flex-wrap gap-2">
            @foreach ([__('Membres et ménages'), __('Finances et promesses'), __('Plan d’action et budget'), __('Paie'), __('Groupes et activités'), __('Documents et attestations'), __('Suivi pastoral'), __('Espace membre'), __('Site vitrine')] as $module)
                <span class="badge bg-sand-100 font-medium text-sand-700">{{ $module }}</span>
            @endforeach
        </div>
    </section>
</div>
