<div>
    <x-page-header :title="__('Tickets de support')" :description="__('Les demandes des communautés. Une réponse les prévient dans leurs nouveautés.')" />

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <div class="flex gap-1 rounded-2xl border border-sand-200 bg-white p-1">
            @foreach (['open' => __('À traiter'), 'answered' => __('En attente de la communauté'), 'closed' => __('Réglés'), 'all' => __('Tous')] as $k => $l)
                <button type="button" wire:click="$set('status', '{{ $k }}')" @class(['whitespace-nowrap rounded-xl px-3 py-1.5 text-sm font-semibold', 'bg-ink-700 text-white' => $status === $k, 'text-ink-600 hover:bg-sand-50' => $status !== $k])>{{ $l }}@if ($k !== 'all' && ($counts[$k] ?? 0)) <span class="tabular opacity-75">{{ $counts[$k] }}</span>@endif</button>
            @endforeach
        </div>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="mine" class="size-4"> {{ __('Pris en charge par moi') }}</label>
    </div>

    <section class="card overflow-hidden">
        <ul class="divide-y divide-sand-100">
            @forelse ($tickets as $t)
                <li wire:key="t-{{ $t->id }}">
                    <a href="{{ route('admin.tickets.show', $t) }}" class="flex flex-wrap items-center gap-3 px-5 py-4 hover:bg-sand-50">
                        <span class="min-w-0 flex-1 basis-64">
                            <span class="block font-semibold text-ink-800">{{ $t->subject }}</span>
                            <span class="block text-sm text-sand-700">{{ $t->number }} · {{ $t->organization->name }}@if ($t->opener) · {{ $t->opener->name }}@endif · {{ __(\App\Models\SupportTicket::CATEGORIES[$t->category]) }}</span>
                        </span>
                        <span class="text-right text-sm text-sand-700">{{ $t->last_activity_at?->diffForHumans() }}@if ($t->assignee)<span class="block text-xs">{{ $t->assignee->name }}</span>@endif</span>
                    </a>
                </li>
            @empty
                <li class="px-5 py-10 text-center text-sand-700">{{ __('Aucun ticket.') }}</li>
            @endforelse
        </ul>
        @if ($tickets->hasPages())<div class="border-t border-sand-100 px-5 py-3">{{ $tickets->links() }}</div>@endif
    </section>
</div>
