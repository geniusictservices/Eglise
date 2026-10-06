@php use App\Support\Navigation; @endphp
<div class="flex h-full flex-col">
    <div class="px-5 pb-4 pt-5">
        <a href="{{ route('dashboard') }}"><x-logo light /></a>
    </div>

    <div class="px-3">
        <livewire:organization-switcher />
    </div>

    <nav class="mt-4 flex-1 space-y-6 overflow-y-auto px-3 pb-6" aria-label="{{ __('Menu') }}">
        @foreach (Navigation::sections() as $section)
            <div>
                @if ($section['label'])
                    <p class="px-3 pb-2 font-display text-[11px] font-semibold uppercase tracking-[0.14em] text-ink-300">{{ $section['label'] }}</p>
                @endif
                <ul class="space-y-0.5">
                    @foreach ($section['items'] as $item)
                        @php $active = Navigation::isActive($item['route']); @endphp
                        <li>
                            <a href="{{ route($item['route']) }}" @class([
                                'flex items-center gap-3 rounded-xl px-3 py-2.5 text-[15px] transition',
                                'bg-ink-600 font-bold text-white' => $active,
                                'text-ink-100 hover:bg-ink-600/60 hover:text-white' => ! $active,
                            ]) @if($active) aria-current="page" @endif>
                                <x-icon :name="$item['icon']" @class(['size-5', 'text-ochre-300' => $active, 'text-ink-300' => ! $active]) />
                                {{ $item['label'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>

    <div class="border-t border-ink-600 px-5 py-4 text-xs text-ink-300">
        <a href="{{ route('install') }}" class="flex items-center gap-2 hover:text-white"><x-icon name="download" class="size-4" /> {{ __('Installer l’application') }}</a>
        <p class="mt-2">Waumini · Genius ICT</p>
    </div>
</div>
