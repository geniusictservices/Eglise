@php use App\Support\Money; @endphp
<div>
    <x-page-header :title="__('Paie')" :description="__('La finance prépare la paie de chaque période, le pasteur l’approuve, puis la finance paie depuis la caisse.')">
        <x-slot:actions>
            @if ($canManage)<button type="button" wire:click="askPrepare" class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Préparer une paie') }}</button>@endif
            <a href="{{ route('payroll.payees') }}" class="btn-secondary"><x-icon name="users" class="size-4" /> {{ __('Bénéficiaires') }}</a>
            <a href="{{ route('payroll.settings') }}" class="btn-secondary"><x-icon name="sliders-horizontal" class="size-4" /><span class="hidden sm:inline">{{ __('Réglages') }}</span></a>
        </x-slot:actions>
    </x-page-header>

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
