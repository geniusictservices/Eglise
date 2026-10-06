@php use App\Support\Money; @endphp
<div>
    <x-page-header :title="__('Paie')" :description="__('La finance prépare la paie de chaque période, le pasteur l’approuve, puis la finance paie depuis la caisse.')">
        <x-slot:actions>
            @if ($canManage)<button type="button" wire:click="askPrepare" class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Préparer une paie') }}</button>@endif
            <a href="{{ route('payroll.payees') }}" class="btn-secondary"><x-icon name="users" class="size-4" /> {{ __('Bénéficiaires') }}</a>
            <a href="{{ route('payroll.advances') }}" class="btn-secondary"><x-icon name="hand-coins" class="size-4" /> {{ __('Avances') }}</a>
            <a href="{{ route('payroll.settings') }}" class="btn-secondary"><x-icon name="sliders-horizontal" class="size-4" /><span class="hidden sm:inline">{{ __('Réglages') }}</span></a>
        </x-slot:actions>
    </x-page-header>

    @foreach ($alerts as $alert)
        <a href="{{ $alert['url'] }}" class="mb-3 flex items-center gap-3 rounded-2xl border border-ochre-300 bg-ochre-50 p-4 text-sm text-ink-800 hover:bg-ochre-100">
            <x-icon name="clock" class="size-5 text-ochre-600" /><span class="flex-1 font-semibold">{{ $alert['text'] }}</span><x-icon name="chevron-right" class="size-4" />
        </a>
    @endforeach

    <section class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($schedules as $s)
            <div class="card p-4">
                <p class="font-semibold text-ink-800">{{ $s->name }}</p>
                <p class="text-sm text-sand-700">{{ $s->describe() }} · {{ trans_choice(':count personne|:count personnes', $payees->where('pay_schedule_id', $s->id)->count()) }}</p>
                @if (! $s->isPerService())
                    <p class="mt-2 space-x-3 text-lg font-semibold tabular text-ink-800">@forelse ($mass[$s->id] ?? [] as $currency => $total)<span>{{ Money::format($total, $currency) }}</span>@empty<span class="text-sm font-normal text-sand-700">{{ __('Personne') }}</span>@endforelse</p>
                @else
                    <p class="mt-2 text-sm text-sand-700">{{ __('Selon le nombre de prestations') }}</p>
                @endif
            </div>
        @endforeach
    </section>

    @if ($salaryBudget)
        <section class="card mb-5 p-5">
            <div class="mb-2 flex items-center gap-3"><h2 class="flex-1 text-base">{{ __('Budget des salaires :y', ['y' => $salaryBudget['year']]) }}</h2>
                <a href="{{ route('budget.execution') }}" class="text-sm font-semibold text-ink-600 hover:underline">{{ __('Suivi du budget') }}</a></div>
            <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                @foreach ([[__('Prévu'), $salaryBudget['planned']], [__('Payé'), $salaryBudget['actual']], [__('Engagé'), $salaryBudget['committed']], [__('Disponible'), $salaryBudget['available']]] as [$label, $value])
                    <div><dt class="text-xs text-sand-700">{{ $label }}</dt><dd @class(['text-lg font-semibold tabular', 'text-terra-600' => $label === __('Disponible') && $value < 0, 'text-ink-800' => ! ($label === __('Disponible') && $value < 0)])>{{ Money::format($value, 'USD') }}</dd></div>
                @endforeach
            </dl>
            @php $used = $salaryBudget['planned'] > 0 ? min(100, round(($salaryBudget['actual'] + $salaryBudget['committed']) / $salaryBudget['planned'] * 100)) : 100; @endphp
            <span class="mt-3 block h-2 overflow-hidden rounded-full bg-sand-100"><span @class(['block h-full rounded-full', 'bg-terra-500' => $used >= 100, 'bg-leaf-500' => $used < 100]) style="width: {{ $used }}%"></span></span>
        </section>
    @elseif ($canManage)
        <p class="mb-5 rounded-2xl border border-ochre-300 bg-ochre-50 p-4 text-sm text-ink-800">{{ __('Pas de budget adopté pour cet exercice : la paie n’est pas contrôlée. Dans le budget, « Reprendre la masse salariale » prévoit les salaires de chacun.') }}</p>
    @endif

    <h2 class="mb-2 text-base">{{ __('Les paies') }}</h2>
    <ul class="space-y-2.5">
        @forelse ($runs as $r)
            @php $t = $r->totals(); @endphp
            <li wire:key="r-{{ $r->id }}">
                <a href="{{ route('payroll.run', $r) }}" class="card flex flex-wrap items-center gap-x-4 gap-y-1 p-4 transition hover:border-ochre-300">
                    <span class="min-w-0 flex-1">
                        <span class="block font-semibold text-ink-800">{{ $r->label() }}</span>
                        <span class="block text-sm text-sand-700">{{ $r->schedule?->name }} · {{ trans_choice(':count bulletin|:count bulletins', $r->slips->where('net', '>', 0)->count()) }}</span>
                    </span>
                    <span class="text-right font-semibold tabular text-ink-800">@foreach ($t as $currency => $sum)<span class="block">{{ Money::format($sum['net'], $currency) }}</span>@endforeach</span>
                    <span @class(['badge', 'bg-ochre-100 text-ochre-700' => in_array($r->status, ['draft', 'submitted', 'approved'], true), 'bg-leaf-50 text-leaf-600' => $r->status === 'paid', 'bg-sand-100 text-sand-700' => $r->status === 'cancelled'])>{{ __(\App\Models\PayRun::STATUSES[$r->status]) }}</span>
                </a>
            </li>
        @empty
            <li class="card p-8 text-center text-sand-700">{{ __('Aucune paie pour le moment.') }}</li>
        @endforelse
    </ul>

    <x-modal name="prepare" :title="__('Préparer une paie')">
        <form wire:submit="prepare" class="space-y-4">
            <div><label for="pp-schedule" class="label">{{ __('Rythme') }}</label><select wire:model.live="scheduleId" id="pp-schedule" class="input">@foreach ($schedules as $s)<option value="{{ $s->id }}">{{ $s->name }} · {{ $s->describe() }}</option>@endforeach</select></div>
            <div><label for="pp-start" class="label">{{ __('Début de la période') }}</label><input wire:model.live="start" id="pp-start" type="date" class="input">@error('start') <p class="error">{{ $message }}</p> @enderror</div>
            @if ($preview)<p class="rounded-xl bg-sand-50 p-3 text-sm text-ink-800">{{ __('Période du :a au :b. Tous les bénéficiaires de ce rythme y sont repris ; vous pourrez ajuster chaque bulletin.', ['a' => $preview[0]->translatedFormat('j F Y'), 'b' => $preview[1]->translatedFormat('j F Y')]) }}</p>@endif
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'prepare' })">{{ __('Annuler') }}</button><button class="btn-primary">{{ __('Préparer') }}</button></div>
        </form>
    </x-modal>
</div>
