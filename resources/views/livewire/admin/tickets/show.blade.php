<div class="grid gap-5 lg:grid-cols-[2fr_1fr]">
    <div class="min-w-0">
        <a href="{{ route('admin.tickets') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Tickets') }}</a>
        <p class="text-sm text-sand-700">{{ $ticket->number }} · {{ __(\App\Models\SupportTicket::CATEGORIES[$ticket->category]) }}</p>
        <h1 class="page-title mb-5 mt-1">{{ $ticket->subject }}</h1>

        @include('partials.ticket-thread', ['messages' => $messages, 'staffSide' => true])

        <form wire:submit="reply" class="card mt-5 space-y-3 p-4 sm:p-5">
            <label for="tk-reply" class="label">{{ __('Répondre à la communauté') }}</label>
            <textarea wire:model="body" id="tk-reply" rows="5" class="input"></textarea>
            @error('body') <p class="error">{{ $message }}</p> @enderror
            <div class="flex flex-wrap justify-end gap-2">
                @if ($ticket->status !== 'closed')<button type="button" wire:click="close" class="btn-ghost"><x-icon name="circle-check" class="size-4" /> {{ __('Marquer comme réglé') }}</button>@endif
                <button class="btn-primary"><x-icon name="send" class="size-4" /> {{ __('Envoyer') }}</button>
            </div>
        </form>
    </div>

    <aside class="card min-w-0 space-y-4 self-start p-5 sm:p-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-sand-700">{{ __('Communauté') }}</p>
            <a href="{{ route('admin.communities.show', $root) }}" class="font-semibold text-ink-700 hover:underline">{{ $ticket->organization->name }}</a>
            @if ($ticket->organization->id !== $root->id)<p class="text-sm text-sand-700">{{ $root->name }}</p>@endif
        </div>
        @if ($ticket->opener)
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-sand-700">{{ __('Demandé par') }}</p>
                <p class="font-semibold text-ink-800">{{ $ticket->opener->name }}</p>
                <a href="https://wa.me/{{ ltrim($ticket->opener->phone, '+') }}" target="_blank" rel="noopener" class="text-sm font-semibold text-leaf-600 hover:underline">{{ $ticket->opener->formattedPhone() }} · WhatsApp</a>
            </div>
        @endif
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-sand-700">{{ __('Pris en charge par') }}</p>
            <p class="font-semibold text-ink-800">{{ $ticket->assignee?->name ?? '—' }}</p>
            @if ($ticket->assigned_to !== auth()->id())<button type="button" wire:click="takeOver" class="mt-1 text-sm font-semibold text-ink-700 underline">{{ __('Je m’en occupe') }}</button>@endif
        </div>
        <div class="border-t border-sand-100 pt-4">
            @if ($supportOpen)
                <p class="text-sm text-leaf-600">{{ __('Accès du support autorisé jusqu’au :d.', ['d' => $supportOpen->support_access_until->translatedFormat('j M à H:i')]) }}</p>
                <a href="{{ route('admin.communities.show', $root) }}" class="btn-secondary mt-2 w-full"><x-icon name="eye" class="size-4" /> {{ __('Voir la communauté') }}</a>
            @else
                <p class="text-sm text-sand-700">{{ __('Pour voir ce qu’elle voit, demandez-lui d’autoriser l’accès du support (Paramètres › Support).') }}</p>
            @endif
        </div>
    </aside>
</div>
