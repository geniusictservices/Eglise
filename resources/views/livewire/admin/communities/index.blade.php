<div>
    <x-page-header :title="__('Communautés')" :description="__('Églises indépendantes et sièges de dénomination inscrits sur Waumini.')" />

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <div class="relative min-w-0 flex-1 sm:max-w-sm">
            <x-icon name="search" class="pointer-events-none absolute left-3.5 top-1/2 size-5 -translate-y-1/2 text-sand-500" />
            <input wire:model.live.debounce.300ms="search" type="search" class="input pl-11" placeholder="{{ __('Nom, ville ou téléphone') }}" aria-label="{{ __('Rechercher') }}">
        </div>
        <select wire:model.live="status" class="input !w-auto" aria-label="{{ __('État') }}">
            <option value="">{{ __('Tous les états') }}</option>
            @foreach (\App\Models\Organization::STATUSES as $key => [$label])<option value="{{ $key }}">{{ __($label) }}</option>@endforeach
        </select>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="withDemos" class="size-5"> {{ __('Démos publiques') }}</label>
    </div>

    <div class="card overflow-hidden">
        <ul class="divide-y divide-sand-100">
            @forelse ($communities as $c)
                @php $sub = $latest->get($c->id); @endphp
                <li wire:key="c-{{ $c->id }}">
                    <a href="{{ route('admin.communities.show', $c) }}" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 hover:bg-sand-50">
                        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-ochre-100 text-sm font-semibold text-ochre-700">{{ $c->initials() }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-semibold text-ink-700">{{ $c->name }}</span>
                            <span class="block text-sm text-sand-700">{{ collect([$c->city, $c->levels_count ? trans_choice(':count niveau|:count niveaux', $c->levels_count) : null, __('inscrite le :date', ['date' => $c->created_at->translatedFormat('j M Y')])])->filter()->implode(' · ') }}</span>
                        </span>
                        <span class="text-right text-sm">
                            <x-org-status :status="$c->status" />
                            <span class="block text-xs text-sand-700">
                                @if ($sub) {{ $sub->plan->name }} · {{ __('jusqu’au :date', ['date' => $sub->ends_on->translatedFormat('j M Y')]) }}
                                @elseif ($c->trial_ends_at) {{ __('essai jusqu’au :date', ['date' => $c->trial_ends_at->translatedFormat('j M Y')]) }}
                                @endif
                            </span>
                        </span>
                    </a>
                </li>
            @empty
                <li class="px-4 py-10 text-center text-sand-700">{{ __('Aucune communauté ne correspond.') }}</li>
            @endforelse
        </ul>
    </div>
    <div class="mt-4">{{ $communities->links() }}</div>
</div>
