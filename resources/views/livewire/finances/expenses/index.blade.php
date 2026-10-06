@php use App\Support\Money; @endphp
<div>
    <a href="{{ route('finances.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Finances') }}</a>
    <x-page-header :title="__('Dépenses')" :description="__('Chaque dépense suit le circuit : demande, contrôle, approbation, décaissement, justification.')">
        <x-slot:actions>
            @if ($canRequest)<a href="{{ route('finances.expenses.create') }}" class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Demander une dépense') }}</a>@endif
        </x-slot:actions>
    </x-page-header>

    @if ($overdue)
        <p class="mb-4 rounded-2xl border border-terra-100 bg-terra-50 p-4 text-sm font-semibold text-terra-700"><x-icon name="triangle-alert" class="mr-1 inline size-4" />
            {{ trans_choice(':count avance n’a pas été justifiée à temps.|:count avances n’ont pas été justifiées à temps.', $overdue) }}</p>
    @endif

    <div class="mb-4 flex gap-2 overflow-x-auto pb-1">
        @foreach (['submitted' => __('À contrôler'), 'checked' => __('À approuver'), 'approved' => __('À décaisser'), 'disbursed' => __('À justifier'), 'justified' => __('Terminées'), 'rejected' => __('Refusées'), 'all' => __('Toutes')] as $key => $label)
            <button type="button" wire:click="$set('step', '{{ $key }}')" @class(['chip shrink-0', '!border-ink-700 !bg-ink-700 !text-white' => $step === $key])>
                {{ $label }}@if ($key !== 'all' && ($counts[$key] ?? 0)) <span @class(['badge', 'bg-ochre-500 text-on-accent' => in_array($key, ['submitted', 'checked', 'approved', 'disbursed'], true), 'bg-sand-100 text-sand-700' => ! in_array($key, ['submitted', 'checked', 'approved', 'disbursed'], true)])>{{ $counts[$key] }}</span>@endif
            </button>
        @endforeach
    </div>

    <ul class="space-y-2.5">
        @forelse ($requests as $r)
            <li wire:key="r-{{ $r->id }}">
                <a href="{{ route('finances.expenses.show', $r) }}" class="card flex flex-wrap items-center gap-x-4 gap-y-2 p-4 transition hover:border-ochre-300">
                    <span @class(['icon-tile', 'bg-ochre-100 text-ochre-700' => $r->is_advance, 'bg-terra-50 text-terra-600' => ! $r->is_advance])><x-icon :name="$r->is_advance ? 'hand-coins' : 'upload'" class="size-5" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-semibold text-ink-800">{{ $r->title }}</span>
                        <span class="block truncate text-sm text-sand-700"><span class="font-mono text-xs">{{ $r->number }}</span> · {{ $r->department?->name ?? __('Sans département') }} · {{ $r->requester?->name }}@if ($r->is_advance) · {{ __('avance') }}@endif</span>
                    </span>
                    <span class="text-right">
                        <span class="block font-semibold tabular text-ink-800">{{ Money::format($r->amount, $r->currency) }}</span>
                        <span @class(['badge', 'bg-ochre-100 text-ochre-700' => in_array($r->status, ['submitted', 'checked', 'approved', 'disbursed'], true), 'bg-leaf-50 text-leaf-600' => $r->status === 'justified', 'bg-terra-50 text-terra-600' => in_array($r->status, ['rejected', 'cancelled'], true) || $r->isOverdue()])>
                            {{ $r->isOverdue() ? __('Avance en retard') : __(\App\Models\ExpenseRequest::STATUSES[$r->status]) }}@if ($r->status === 'checked') ({{ $r->approvedCount() }}/{{ $r->approvals_required }})@endif
                        </span>
                    </span>
                </a>
            </li>
        @empty
            <li class="card p-8 text-center text-sand-700">{{ __('Aucune demande ici.') }}</li>
        @endforelse
    </ul>
</div>
