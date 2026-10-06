@php $children = $byParent->get($node->id, collect()); @endphp
<li>
    <div class="group flex items-center gap-3 rounded-xl px-2 py-2.5 hover:bg-sand-50" style="padding-left: {{ 0.5 + $level * 1.5 }}rem">
        <span @class(['grid size-9 shrink-0 place-items-center rounded-lg font-display text-sm font-bold',
            'bg-ink-700 text-white' => $level === 0, 'bg-ink-50 text-ink-600' => $level > 0])>
            {{ $node->initials() }}
        </span>
        <div class="min-w-0 flex-1">
            <p class="truncate font-bold text-ink-700">{{ $node->name }}</p>
            <p class="text-xs text-sand-700">{{ $node->level_label }}@if ($node->city) · {{ $node->city }}@endif @if ($children->isNotEmpty()) · {{ trans_choice(':count niveau inférieur|:count niveaux inférieurs', $children->count()) }}@endif</p>
        </div>
        <div class="flex shrink-0 items-center gap-1">
            @if ($level > 0 && auth()->user()->canAccess($node))
                <form method="POST" action="{{ route('organizations.switch', $node) }}">
                    @csrf
                    <button type="submit" class="rounded-lg px-2.5 py-1.5 text-sm font-bold text-ink-600 hover:bg-ink-50">{{ __('Ouvrir') }}</button>
                </form>
            @endif
            @if ($canManage)
                <button type="button" wire:click="startCreate({{ $node->id }})" class="rounded-lg p-2 text-sand-500 hover:bg-ink-50 hover:text-ink-700" aria-label="{{ __('Ajouter un niveau sous :name', ['name' => $node->name]) }}" title="{{ __('Ajouter un niveau inférieur') }}">
                    <x-icon name="square-plus" class="size-5" />
                </button>
            @endif
        </div>
    </div>
    @if ($children->isNotEmpty())
        <ul>
            @foreach ($children as $child)
                @include('livewire.hierarchy.node', ['node' => $child, 'byParent' => $byParent, 'level' => $level + 1])
            @endforeach
        </ul>
    @endif
</li>
