<a href="{{ route('notifications.index') }}" wire:poll.60s.visible x-data x-effect="window.wauminiBadge?.({{ $count }})"
   @class(['relative grid size-10 shrink-0 place-items-center rounded-full', 'hover:bg-white/10' => $onDark, 'text-ink-600 hover:bg-sand-100' => ! $onDark])
   aria-label="{{ $count ? trans_choice(':count nouveauté non ouverte|:count nouveautés non ouvertes', $count) : __('Nouveautés') }}">
    <x-icon name="bell" class="size-5" />
    @if ($count)
        <span class="absolute right-0.5 top-0.5 grid h-5 min-w-5 place-items-center rounded-full bg-terra-600 px-1 text-[11px] font-semibold leading-none text-white ring-2 {{ $onDark ? 'ring-ink-800' : 'ring-sand-50' }} tabular">{{ $count > 99 ? '99+' : $count }}</span>
    @endif
</a>
