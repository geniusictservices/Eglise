<div>
    <a href="{{ route('members.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Membres') }}</a>
    <x-page-header :title="__('Ménages')" :description="__('Les familles de la communauté : chef de ménage, conjoint(e), enfants et personnes à charge.')">
        <x-slot:actions>
            @if ($canManage)
                <button type="button" wire:click="create" class="btn-primary"><x-icon name="house-plus" class="size-4" /> {{ __('Nouveau ménage') }}</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 relative max-w-md">
        <label for="search" class="sr-only">{{ __('Rechercher') }}</label>
        <x-icon name="search" class="pointer-events-none absolute left-3.5 top-1/2 size-5 -translate-y-1/2 text-sand-500" />
        <input wire:model.live.debounce.300ms="search" id="search" type="search" class="input pl-11" placeholder="{{ __('Nom du ménage, quartier ou avenue') }}">
    </div>

    <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @forelse ($households as $h)
            <li wire:key="h-{{ $h->id }}">
                <a href="{{ route('households.show', $h) }}" class="card flex h-full flex-col p-4 transition hover:border-ochre-300">
                    <div class="flex items-start gap-3">
                        <span class="icon-tile bg-leaf-50 text-leaf-600"><x-icon name="house" class="size-5" /></span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold text-ink-700">{{ $h->name }}</p>
                            <p class="truncate text-sm text-sand-700">{{ $h->address() ?: __('Adresse non renseignée') }}</p>
                            @if ($multiLevel && $h->organization_id !== $organization->id)<p class="truncate text-xs font-semibold text-ochre-700">{{ $h->organization->displayName() }}</p>@endif
                        </div>
                    </div>
                    <div class="mt-3 flex items-center justify-between gap-2 border-t border-sand-100 pt-3">
                        <div class="flex -space-x-2">
                            @foreach ($h->members->take(5) as $m)
                                <span class="rounded-full ring-2 ring-white">@include('livewire.members.partials.avatar', ['member' => $m, 'size' => 'size-8 text-[11px]'])</span>
                            @endforeach
                        </div>
                        <span class="text-sm text-sand-700">{{ trans_choice(':count personne|:count personnes', $h->members_count) }}</span>
                    </div>
                </a>
            </li>
        @empty
            <li class="card p-8 text-center text-sand-700 sm:col-span-2 lg:col-span-3">
                {{ $search !== '' ? __('Aucun ménage ne correspond à cette recherche.') : __('Aucun ménage pour l’instant. Créez-en un ici, ou depuis la fiche d’un membre.') }}
            </li>
        @endforelse
    </ul>

    <div class="mt-4">{{ $households->links() }}</div>

    @include('livewire.households.partials.form-modal', ['title' => __('Nouveau ménage')])
</div>
