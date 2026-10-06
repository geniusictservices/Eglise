<div x-data="{ open: false, q: '' }" @click.outside="open = false" @keydown.escape="open = false" class="relative">
    <button type="button" @click="open = !open" class="flex w-full items-center gap-3 rounded-2xl bg-white/10 px-3 py-2.5 text-left ring-1 ring-white/15 backdrop-blur-sm hover:bg-white/15" :aria-expanded="open">
        <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-ochre-500 text-sm font-semibold text-on-accent">
            {{ $current->initials() }}
        </span>
        <span class="min-w-0 flex-1">
            <span class="block truncate text-[11px] uppercase tracking-wider text-ink-200">{{ $current->level_label }}</span>
            <span class="block truncate font-semibold text-white">{{ $current->displayName() }}</span>
        </span>
        @if ($organizations->count() > 1)
            <x-icon name="chevrons-up-down" class="size-4 text-ink-200" />
        @endif
    </button>

    @if ($organizations->count() > 1)
        <div x-cloak x-show="open" x-transition.origin.top class="absolute inset-x-0 z-50 mt-2 overflow-hidden rounded-2xl border border-sand-200 bg-white text-ink-900 shadow-2xl">
            @if ($organizations->count() > 6)
                <div class="border-b border-sand-100 p-2">
                    <input x-model="q" type="search" class="input !min-h-0 !py-2 text-sm" placeholder="{{ __('Rechercher…') }}" aria-label="{{ __('Rechercher une communauté') }}">
                </div>
            @endif
            <ul class="max-h-80 overflow-y-auto py-1">
                @foreach ($organizations as $organization)
                    <li x-show="q === '' || @js(mb_strtolower($organization->name)).includes(q.toLowerCase())">
                        <form method="POST" action="{{ route('organizations.switch', $organization) }}">
                            @csrf
                            <button type="submit" class="flex w-full items-center gap-2 py-2 pr-3 text-left text-sm hover:bg-sand-50" style="padding-left: {{ 0.75 + ($organization->depth - $minDepth) * 1 }}rem">
                                @if ($organization->depth > $minDepth)<span class="text-sand-300">└</span>@endif
                                <span class="min-w-0 flex-1 truncate @if($organization->id === $current->id) font-semibold text-ink-700 @endif">{{ $organization->name }}</span>
                                <span class="shrink-0 text-xs text-sand-500">{{ $organization->level_label }}</span>
                                @if ($organization->id === $current->id)<x-icon name="check" class="size-4 text-ochre-600" />@endif
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
