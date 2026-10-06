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
                    <p class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-[0.14em] text-ink-200/80">{{ $section['label'] }}</p>
                @endif
                <ul class="space-y-0.5">
                    @foreach ($section['items'] as $item)
                        @php $active = Navigation::isActive($item['route']); @endphp
                        <li>
                            <a href="{{ route($item['route']) }}" @class([
                                'flex items-center gap-3 rounded-xl px-3 py-2.5 text-[15px] transition',
                                'bg-ochre-500 font-semibold text-on-accent shadow-sm' => $active,
                                'text-ink-50/90 hover:bg-white/10 hover:text-white' => ! $active,
                            ]) @if($active) aria-current="page" @endif>
                                <x-icon :name="$item['icon']" @class(['size-5', 'text-on-accent' => $active, 'text-ink-200' => ! $active]) />
                                {{ $item['label'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>

    <div class="border-t border-white/10 px-5 py-4 text-xs text-ink-200">
        <a href="{{ route('help.index') }}" class="flex items-center gap-2 hover:text-white"><x-icon name="circle-help" class="size-4" /> {{ __('Aide et manuel') }}</a>
        <a href="{{ route('install') }}" class="mt-2 flex items-center gap-2 hover:text-white"><x-icon name="download" class="size-4" /> {{ __('Installer l’application') }}</a>
        <p class="mt-2">Waumini · Genius ICT</p>
    </div>
</div>
