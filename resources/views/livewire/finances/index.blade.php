@php use App\Support\Money; @endphp
<div class="space-y-6">
    <x-page-header :title="__('Finances')" :description="__('Les comptes de :name : caisses, mobile money et banques, dans chaque devise.', ['name' => current_organization()->displayName()])">
        <x-slot:actions>
            @can('finance.settings')<a href="{{ route('finances.settings') }}" class="btn-secondary"><x-icon name="settings" class="size-4" /><span class="hidden sm:inline">{{ __('Comptes et catégories') }}</span></a>@endcan
            @can('finance.income')<a href="{{ route('finances.income') }}" class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Recette') }}</a>@endcan
        </x-slot:actions>
    </x-page-header>

    @if ($pendingDeclarations)
        <a href="{{ route('finances.declarations') }}" class="flex items-center gap-3 rounded-2xl border border-ochre-300 bg-ochre-50 p-4 text-sm text-ink-800 hover:bg-ochre-100">
            <x-icon name="smartphone" class="size-5 text-ochre-600" />
            <span class="flex-1 font-semibold">{{ trans_choice(':count paiement mobile money déclaré attend votre vérification.|:count paiements mobile money déclarés attendent votre vérification.', $pendingDeclarations) }}</span>
            <x-icon name="chevron-right" class="size-4" />
        </a>
    @endif

    @if ($pendingExpenses || $overdueAdvances)
        <a href="{{ route('finances.expenses') }}" class="mt-3 flex items-center gap-3 rounded-2xl border border-ochre-300 bg-ochre-50 p-4 text-sm text-ink-800 hover:bg-ochre-100">
            <x-icon name="banknote" class="size-5 text-ochre-600" />
            <span class="flex-1 font-semibold">{{ collect([
                $pendingExpenses ? trans_choice(':count demande de dépense attend votre action.|:count demandes de dépense attendent votre action.', $pendingExpenses) : null,
                $overdueAdvances ? trans_choice(':count avance n’est pas justifiée à temps.|:count avances ne sont pas justifiées à temps.', $overdueAdvances) : null,
            ])->filter()->implode(' ') }}</span>
            <x-icon name="chevron-right" class="size-4" />
        </a>
    @endif

    @unless ($hasAccounts)
        <div class="card flex flex-col items-center px-6 py-12 text-center">
            <span class="icon-tile size-14 bg-leaf-50 text-leaf-600"><x-icon name="wallet" class="size-7" /></span>
            <h2 class="mt-4 text-xl">{{ __('Créez vos comptes') }}</h2>
            <p class="mt-1 max-w-md text-sand-700">{{ __('Une caisse physique pour les espèces du culte, un compte mobile money, un compte en banque : chacun dans les devises que vous utilisez.') }}</p>
            @can('finance.settings')<a href="{{ route('finances.settings') }}" class="btn-primary mt-5"><x-icon name="plus" class="size-4" /> {{ __('Créer un compte') }}</a>@endcan
        </div>
    @else
        {{-- Trésorerie --}}
        <section class="wax wax-veil wax-veil-strong overflow-hidden rounded-[22px] p-5 text-white sm:p-6">
            <p class="text-sm text-ink-100">{{ __('Trésorerie totale') }}</p>
            <div class="mt-1 flex flex-wrap items-baseline gap-x-6 gap-y-1">
                @foreach ($byCurrency as $currency => $sum)
                    <span class="text-2xl font-semibold tabular text-white sm:text-3xl">{{ Money::format($sum, $currency) }}</span>
                @endforeach
            </div>
            @if ($totalUsd !== null && $byCurrency->count() > 1)<p class="mt-1 text-sm text-ochre-300">{{ __('soit environ :t au taux du jour', ['t' => Money::format($totalUsd, 'USD')]) }}</p>@endif
            @if ($reserved['total'] > 0 && $totalUsd !== null)
                @php $free = (float) (string) $totalUsd - $reserved['total']; @endphp
                <p class="mt-2 text-sm text-ink-100">{{ __('Dont :r réservés aux projets (:p) ; libre pour le fonctionnement : :f.', ['r' => Money::format($reserved['total'], 'USD'), 'p' => $reserved['projects']->take(3)->map(fn ($r) => $r['project']->name)->implode(', ').($reserved['projects']->count() > 3 ? '…' : ''), 'f' => Money::format(max(0, $free), 'USD')]) }}</p>
                @if ($free < -0.004)
                    <p class="mt-2 rounded-xl bg-terra-600/90 px-3 py-2 text-sm font-semibold text-white"><x-icon name="triangle-alert" class="mr-1 inline size-4" /> {{ __('Les comptes contiennent :m de moins que l’argent des projets : cet argent a servi à autre chose et doit être remis.', ['m' => Money::format(-$free, 'USD')]) }}</p>
                @endif
            @endif
            <div class="mt-4 flex flex-wrap gap-2">
                @can('finance.income')<a href="{{ route('finances.collections') }}" class="btn-accent !min-h-0 !py-2"><x-icon name="hand-coins" class="size-4" /> {{ __('Collecte du culte') }}</a>
                <a href="{{ route('finances.income') }}" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="download" class="size-4" /> {{ __('Recette') }}</a>@endcan
                @can('finance.exchange')<a href="{{ route('finances.transfer') }}" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="arrow-left-right" class="size-4" /> {{ __('Virement ou change') }}</a>@endcan
                <a href="{{ route('finances.expenses') }}" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="banknote" class="size-4" /> {{ __('Dépenses') }}</a>
                <a href="{{ route('finances.journal') }}" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="history" class="size-4" /> {{ __('Opérations') }}</a>
                @can('finance.reports')<a href="{{ route('finances.reports') }}" class="btn !min-h-0 bg-white/15 !py-2 text-white hover:bg-white/25"><x-icon name="file-text" class="size-4" /> {{ __('Rapports') }}</a>@endcan
            </div>
        </section>

        {{-- Comptes --}}
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($balances as $rows)
                @php $account = $rows->first()['account']; @endphp
                <a href="{{ route('finances.journal', ['compte' => $account->id]) }}" class="card flex flex-col p-4 transition hover:border-ochre-300">
                    <span class="flex items-start gap-3">
                        <span class="icon-tile bg-leaf-50 text-leaf-600"><x-icon :name="$account->icon()" class="size-5" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-semibold text-ink-700">{{ $account->name }}</span>
                            <span class="block truncate text-xs text-sand-700">{{ collect([__(\App\Models\CashAccount::KINDS[$account->kind]), $account->provider])->filter()->implode(' · ') }}</span>
                        </span>
                    </span>
                    <span class="mt-3 space-y-1">
                        @foreach ($rows as $b)
                            <span class="flex items-baseline justify-between gap-3">
                                <span class="text-xs font-semibold text-sand-700">{{ $b['currency'] }}</span>
                                <span @class(['text-lg font-semibold tabular', 'text-ink-800' => ! $b['balance']->isNegative(), 'text-terra-600' => $b['balance']->isNegative()])>{{ Money::format($b['balance'], $b['currency']) }}</span>
                            </span>
                        @endforeach
                    </span>
                </a>
            @endforeach
        </div>

        <div class="grid gap-5 lg:grid-cols-[1fr_1.3fr]">
            <section class="card p-5 sm:p-6">
                <h2 class="text-lg first-letter:uppercase">{{ now()->translatedFormat('F Y') }}</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-sand-700">{{ __('Recettes') }}</dt><dd class="font-semibold tabular text-leaf-600">{{ Money::format($income, 'USD') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-sand-700">{{ __('Dépenses') }}</dt><dd class="font-semibold tabular text-terra-600">{{ Money::format($expense, 'USD') }}</dd></div>
                    <div class="flex justify-between border-t border-sand-100 pt-2"><dt class="font-semibold text-ink-800">{{ __('Résultat') }}</dt><dd class="font-semibold tabular text-ink-800">{{ Money::format($income - $expense, 'USD') }}</dd></div>
                </dl>
                @if ($byCategory->isNotEmpty())
                    <h3 class="mb-2 mt-5 text-sm font-semibold text-ink-700">{{ __('Recettes par catégorie') }}</h3>
                    <ul class="space-y-2">
                        @foreach ($byCategory as $row)
                            @php $percent = $income > 0 ? (int) round($row->total / $income * 100) : 0; @endphp
                            <li class="text-sm">
                                <div class="flex justify-between"><span class="text-ink-800">{{ $row->category?->name ?? __('Sans catégorie') }}</span><span class="tabular text-sand-700">{{ Money::format($row->total, 'USD') }}</span></div>
                                <div class="mt-1 h-1.5 rounded-full bg-sand-100"><div class="h-1.5 rounded-full bg-ochre-500" style="width: {{ $percent }}%"></div></div>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <p class="mt-4 text-xs text-sand-700">{{ __('Montants en dollars, au taux du jour de chaque opération.') }}</p>
            </section>

            <section class="card p-5 sm:p-6">
                <div class="mb-3 flex items-center justify-between"><h2 class="text-lg">{{ __('Dernières opérations') }}</h2><a href="{{ route('finances.journal') }}" class="text-sm font-semibold text-ink-600 hover:underline">{{ __('Tout voir') }}</a></div>
                <ul class="divide-y divide-sand-100">
                    @forelse ($recent as $t)
                        <li @class(['flex items-center gap-3 py-2.5 text-sm', 'opacity-50 line-through' => $t->cancelled_at])>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-semibold text-ink-800">{{ $t->category?->name ?? __(\App\Models\FinanceTransaction::TYPES[$t->type]) }}@if ($t->member_id && $canSeeNames) · <span class="font-normal">{{ $t->member?->fullName() }}</span>@endif</span>
                                <span class="block text-xs text-sand-700">{{ $t->occurred_on->translatedFormat('j M') }} · {{ $t->account->name }}</span>
                            </span>
                            <span @class(['font-semibold tabular', 'text-leaf-600' => $t->isInflow(), 'text-terra-600' => ! $t->isInflow()])>{{ $t->isInflow() ? '+' : '−' }} {{ Money::format($t->amount, $t->currency) }}</span>
                        </li>
                    @empty
                        <li class="py-2 text-sm text-sand-700">{{ __('Aucune opération pour le moment.') }}</li>
                    @endforelse
                </ul>
            </section>
        </div>
    @endunless
</div>
