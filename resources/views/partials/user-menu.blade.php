@php $user = auth()->user(); @endphp
<div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
    <button type="button" @click="open = !open" @class(['flex items-center gap-2 rounded-full p-1 sm:pr-3', 'hover:bg-white/10' => $onDark, 'hover:bg-sand-100' => ! $onDark]) aria-haspopup="menu" :aria-expanded="open" aria-label="{{ __('Mon compte') }}">
        <span class="grid size-9 place-items-center rounded-full bg-ochre-500 text-sm font-semibold text-on-accent">{{ $user->initials() }}</span>
        <span @class(['hidden max-w-40 truncate text-left text-sm font-semibold sm:block', 'text-white' => $onDark, 'text-ink-800' => ! $onDark])>{{ $user->name }}</span>
        <x-icon name="chevron-down" @class(['hidden size-4 sm:block', 'text-ink-200' => $onDark, 'text-sand-500' => ! $onDark]) />
    </button>
    <div x-cloak x-show="open" x-transition.origin.top.right class="absolute right-0 z-40 mt-2 w-64 overflow-hidden rounded-2xl border border-sand-200 bg-white text-ink-900 shadow-xl shadow-ink-900/15" role="menu">
        <div class="border-b border-sand-100 px-4 py-3">
            <p class="truncate font-semibold text-ink-800">{{ $user->name }}</p>
            <p class="text-sm text-sand-700 tabular">{{ $user->formattedPhone() }}</p>
        </div>
        <a href="{{ route('profile.edit') }}" class="flex items-center gap-3 px-4 py-3 text-sm hover:bg-sand-50" role="menuitem">
            <x-icon name="user-round" class="size-4 text-ink-400" /> {{ __('Mon profil') }}
        </a>
        @if ($user->isPlatformStaff())
            <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-3 px-4 py-3 text-sm font-semibold text-ochre-700 hover:bg-sand-50" role="menuitem">
                <x-icon name="shield-check" class="size-4" /> {{ __('Espace Genius ICT') }}
            </a>
        @endif
        <a href="{{ route('help.index') }}" class="flex items-center gap-3 px-4 py-3 text-sm hover:bg-sand-50" role="menuitem">
            <x-icon name="circle-help" class="size-4 text-ink-400" /> {{ __('Aide et manuel') }}
        </a>
        <a href="{{ route('install') }}" class="flex items-center gap-3 px-4 py-3 text-sm hover:bg-sand-50" role="menuitem">
            <x-icon name="download" class="size-4 text-ink-400" /> {{ __('Installer l’application') }}
        </a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="flex w-full items-center gap-3 border-t border-sand-100 px-4 py-3 text-left text-sm text-terra-600 hover:bg-terra-50" role="menuitem">
                <x-icon name="log-out" class="size-4" /> {{ __('Se déconnecter') }}
            </button>
        </form>
    </div>
</div>
