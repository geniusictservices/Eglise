<div>
    <x-page-header :title="__('Nouveautés')" :description="__('Ce qui attend votre attention ou votre décision. Une nouveauté reste ici tant que vous n’avez pas ouvert la page concernée.')">
        <x-slot:actions>
            @if ($unread)<button type="button" wire:click="markAllRead" class="btn-secondary"><x-icon name="check" class="size-4" /> {{ __('Tout marquer comme lu') }}</button>@endif
        </x-slot:actions>
    </x-page-header>

    {{-- Recevoir les nouveautés sur ce téléphone --}}
    <div x-data="pushToggle(@js(config('waumini.push.public_key')))" class="card mb-5 flex flex-wrap items-center gap-x-4 gap-y-3 p-4">
        <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-ochre-50 text-ochre-600"><x-icon name="smartphone" class="size-5" /></span>
        <div class="min-w-0 flex-1 basis-52">
            <p class="font-semibold text-ink-800">{{ __('Sur cet appareil') }}</p>
            <p class="text-sm text-sand-700" x-show="supported && enabled">{{ __('Les nouveautés s’affichent sur cet appareil, même quand Waumini est fermé.') }}</p>
            <p class="text-sm text-sand-700" x-show="supported && ! enabled && ! denied">{{ __('Activez les notifications pour être prévenu sans ouvrir Waumini.') }}</p>
            <p class="text-sm text-terra-700" x-show="supported && denied && ! enabled">{{ __('Les notifications sont bloquées pour Waumini : autorisez-les dans les réglages du navigateur, puis revenez ici.') }}</p>
            <p class="text-sm text-sand-700" x-show="! supported">{{ __('Cet appareil ne reçoit pas les notifications. Sur iPhone, installez d’abord Waumini sur l’écran d’accueil.') }}</p>
            <p class="mt-1 text-sm text-terra-700" x-show="error" x-text="error"></p>
            @if ($devices)<p class="mt-1 text-xs text-sand-600">{{ trans_choice('Activé sur :count appareil.|Activé sur :count appareils.', $devices) }}</p>@endif
        </div>
        <button type="button" x-show="supported && (enabled || ! denied)" x-cloak @click="toggle()" :disabled="busy" class="w-full justify-center sm:w-auto" :class="enabled ? 'btn-secondary' : 'btn-primary'">
            <x-icon name="bell" class="size-4" /> <span x-text="enabled ? @js(__('Désactiver')) : @js(__('Activer'))"></span>
        </button>
    </div>

    <div class="mb-4 flex gap-1 rounded-2xl border border-sand-200 bg-white p-1" role="tablist">
        @foreach (['unread' => trans_choice('Non ouverte (:count)|Non ouvertes (:count)', $unread), 'all' => __('Toutes')] as $key => $label)
            <button type="button" role="tab" wire:click="$set('show', '{{ $key }}')" aria-selected="{{ $show === $key ? 'true' : 'false' }}" @class(['flex-1 rounded-xl px-4 py-2 text-sm font-semibold', 'bg-ink-700 text-white' => $show === $key, 'text-ink-600' => $show !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    <ul class="space-y-2">
        @forelse ($items as $n)
            <li wire:key="n-{{ $n->id }}">
                <a href="{{ route('notifications.open', $n->id) }}" @class(['card flex items-start gap-3 p-4 transition hover:border-ochre-300', 'border-l-4 border-l-ochre-500' => ! $n->read_at])>
                    <span @class(['grid size-10 shrink-0 place-items-center rounded-xl', 'bg-ochre-50 text-ochre-600' => ! $n->read_at, 'bg-sand-100 text-sand-600' => $n->read_at])><x-icon :name="$n->data['icon'] ?? 'bell'" class="size-5" /></span>
                    <span class="min-w-0 flex-1">
                        <span @class(['block text-ink-800', 'font-semibold' => ! $n->read_at])>{{ $n->data['title'] }}</span>
                        @if ($n->data['body'] ?? null)<span class="mt-0.5 block text-sm text-sand-700">{{ $n->data['body'] }}</span>@endif
                        <span class="mt-1 block text-xs text-sand-600">
                            {{ $n->created_at->diffForHumans() }}
                            @if ($n->organization_id && $n->organization_id !== ($currentOrganization->id ?? null)) · {{ $organizations[$n->organization_id] ?? '' }}@endif
                        </span>
                    </span>
                    <x-icon name="chevron-right" class="mt-2.5 size-4 text-sand-400" />
                </a>
            </li>
        @empty
            <li class="card p-8 text-center text-sm text-sand-700">
                <x-icon name="circle-check" class="mx-auto mb-2 size-8 text-leaf-500" />
                {{ $show === 'unread' ? __('Rien de nouveau : tout est à jour.') : __('Aucune nouveauté pour le moment.') }}
            </li>
        @endforelse
    </ul>

    <div class="mt-4">{{ $items->links() }}</div>
</div>
