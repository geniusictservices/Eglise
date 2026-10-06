@php
    $tones = ['ink' => 'bg-ink-700 text-white', 'ochre' => 'bg-ochre-500 text-on-accent', 'terra' => 'bg-terra-500 text-white', 'leaf' => 'bg-leaf-500 text-white', 'sand' => 'bg-sand-500 text-white'];
@endphp
<div>
    <x-page-header :title="__('Départements')"
                   :description="__('Les ministères et services de :name. Chacun présentera ses besoins pour le budget de l’année.', ['name' => current_organization()->displayName()])">
        <x-slot:actions>
            @if ($canManage)
                <button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Nouveau département') }}</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-wrap gap-2">
        @foreach (['' => __('Tous'), 'ministry' => __('Ministères'), 'administrative' => __('Services administratifs')] as $value => $label)
            <button type="button" wire:click="$set('kind', '{{ $value }}')" @class(['chip', '!border-ink-700 !bg-ink-700 !text-white' => $kind === $value])>{{ $label }}</button>
        @endforeach
    </div>

    <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($departments as $d)
            <li wire:key="d-{{ $d->id }}">
                <a href="{{ route('departments.show', $d) }}" @class(['card flex h-full flex-col p-4 transition hover:border-ochre-300', 'opacity-60' => ! $d->is_active])>
                    <div class="flex items-start gap-3">
                        <span class="icon-tile {{ $tones[$d->color] ?? $tones['ink'] }}"><x-icon :name="$d->kind === 'administrative' ? 'briefcase' : 'users-round'" class="size-5" /></span>
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-ink-700">{{ $d->name }}</p>
                            <p class="text-xs text-sand-700">{{ __(\App\Models\Department::KINDS[$d->kind]) }}@unless ($d->is_active) · {{ __('En sommeil') }}@endunless</p>
                        </div>
                    </div>
                    @if ($d->description)<p class="mt-2 line-clamp-2 text-sm text-sand-700">{{ $d->description }}</p>@endif
                    <div class="mt-auto flex items-center justify-between gap-2 border-t border-sand-100 pt-3 text-sm">
                        <span class="truncate text-ink-800">
                            @if ($leader = $d->leaders->sortBy(fn ($m) => $m->pivot->role === 'leader' ? 0 : 1)->first())
                                <x-icon name="badge-check" class="inline size-4 text-ochre-600" /> {{ $leader->fullName() }}
                            @else
                                <span class="text-sand-700">{{ __('Pas de responsable') }}</span>
                            @endif
                        </span>
                        <span class="shrink-0 text-sand-700">{{ trans_choice(':count membre|:count membres', $d->members_count) }}</span>
                    </div>
                </a>
            </li>
        @endforeach
    </ul>

    @if ($canManage && collect($suggestions)->flatten()->isNotEmpty())
        <section class="mt-6 rounded-[18px] border border-dashed border-sand-300 p-5">
            <h2 class="text-base">{{ __('Idées de départements') }}</h2>
            <p class="mb-3 text-sm text-sand-700">{{ __('Touchez un nom pour le créer ; vous pourrez le renommer.') }}</p>
            <div class="flex flex-wrap gap-2">
                @foreach ($suggestions as $kindKey => $names)
                    @foreach ($names as $name)
                        <button type="button" wire:click="create(@js(__($name)), '{{ $kindKey }}')" class="chip"><x-icon name="plus" class="size-3.5 text-ochre-600" /> {{ __($name) }}</button>
                    @endforeach
                @endforeach
            </div>
        </section>
    @endif

    @if ($canManage)
        @include('livewire.departments.partials.form-modal', ['title' => __('Nouveau département')])
    @endif
</div>
