@php use App\Support\Money; @endphp
<div>
    <x-page-header :title="__('Paie')" :description="__('La finance prépare la paie de chaque période, le pasteur l’approuve, puis la finance paie depuis la caisse.')">
        <x-slot:actions>
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
</div>
