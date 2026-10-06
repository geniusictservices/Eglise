<div>
    <x-page-header :title="__('Support Genius ICT')" :description="__('Une question, un problème, une idée ? Écrivez à l’équipe Genius ICT : elle vous répond ici, et vous êtes prévenu dans vos nouveautés.')">
        <x-slot:actions>
            <button type="button" @click="$dispatch('open-modal', { name: 'ticket' })" class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Nouvelle demande') }}</button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-5 lg:grid-cols-[2fr_1fr]">
        <section class="card min-w-0 self-start overflow-hidden">
            <ul class="divide-y divide-sand-100">
                @forelse ($tickets as $t)
                    <li wire:key="t-{{ $t->id }}">
                        <a href="{{ route('support.show', $t) }}" class="flex flex-wrap items-center gap-3 px-5 py-4 hover:bg-sand-50">
                            <span class="min-w-0 flex-1 basis-56">
                                <span class="block font-semibold text-ink-800">{{ $t->subject }}</span>
                                <span class="block text-sm text-sand-700">{{ $t->number }} · {{ __(\App\Models\SupportTicket::CATEGORIES[$t->category]) }}@if ($all && $t->opener) · {{ $t->opener->name }}@endif · {{ $t->last_activity_at?->diffForHumans() }}</span>
                            </span>
                            <span @class(['badge', 'bg-ochre-100 text-ochre-700' => $t->status === 'open', 'bg-leaf-50 text-leaf-600' => $t->status === 'answered', 'bg-sand-100 text-sand-700' => $t->status === 'closed'])>{{ __(\App\Models\SupportTicket::STATUSES[$t->status]) }}</span>
                        </a>
                    </li>
                @empty
                    <li class="px-5 py-10 text-center text-sand-700">{{ __('Aucune demande pour l’instant.') }}</li>
                @endforelse
            </ul>
        </section>

        <aside class="card min-w-0 space-y-3 self-start p-5 sm:p-6">
            <h2 class="flex items-center gap-2 text-lg"><x-icon name="circle-help" class="size-5 text-ochre-600" /> {{ __('Avant d’écrire') }}</h2>
            <p class="text-sm text-sand-700">{{ __('Le manuel répond à beaucoup de questions, avec des captures de chaque écran.') }}</p>
            <a href="{{ route('help.index') }}" class="btn-secondary w-full"><x-icon name="book-open" class="size-4" /> {{ __('Ouvrir le manuel') }}</a>
            <p class="pt-2 text-sm text-sand-700">{{ __('Pour que le support voie ce que vous voyez, autorisez son accès dans Paramètres › Support : il regarde en lecture seule, le temps que vous choisissez.') }}</p>
            @if ($whatsapp)<a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-sm font-semibold text-leaf-600 hover:underline"><x-icon name="message-circle" class="size-4" /> {{ __('Urgent ? WhatsApp') }}</a>@endif
        </aside>
    </div>

    <x-modal name="ticket" :title="__('Nouvelle demande')">
        <form wire:submit="create" class="space-y-4">
            <div><label for="tk-cat" class="label">{{ __('C’est à propos de') }}</label><select wire:model="form.category" id="tk-cat" class="input">@foreach (\App\Models\SupportTicket::CATEGORIES as $k => $l)<option value="{{ $k }}">{{ __($l) }}</option>@endforeach</select></div>
            <div><label for="tk-subject" class="label">{{ __('Sujet') }}</label><input wire:model="form.subject" id="tk-subject" class="input" placeholder="{{ __('Exemple : le reçu ne s’imprime pas en 58 mm') }}">@error('form.subject') <p class="error">{{ $message }}</p> @enderror</div>
            <div><label for="tk-body" class="label">{{ __('Votre message') }}</label><textarea wire:model="form.body" id="tk-body" rows="6" class="input" placeholder="{{ __('Ce que vous faisiez, ce qui s’est passé, ce que vous attendiez.') }}"></textarea>@error('form.body') <p class="error">{{ $message }}</p> @enderror</div>
            <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'ticket' })">{{ __('Annuler') }}</button><button class="btn-primary"><x-icon name="send" class="size-4" /> {{ __('Envoyer') }}</button></div>
        </form>
    </x-modal>
</div>
