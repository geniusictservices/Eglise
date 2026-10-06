<div class="mx-auto max-w-3xl">
    <a href="{{ route('support.index') }}" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-ink-600 hover:underline"><x-icon name="chevron-left" class="size-4" /> {{ __('Mes demandes') }}</a>
    <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm text-sand-700">{{ $ticket->number }} · {{ __(\App\Models\SupportTicket::CATEGORIES[$ticket->category]) }}</p>
            <h1 class="page-title mt-1">{{ $ticket->subject }}</h1>
        </div>
        <span @class(['badge', 'bg-ochre-100 text-ochre-700' => $ticket->status === 'open', 'bg-leaf-50 text-leaf-600' => $ticket->status === 'answered', 'bg-sand-100 text-sand-700' => $ticket->status === 'closed'])>{{ __(\App\Models\SupportTicket::STATUSES[$ticket->status]) }}</span>
    </div>

    @include('partials.ticket-thread', ['messages' => $messages, 'staffSide' => false])

    <form wire:submit="reply" class="card mt-5 space-y-3 p-4 sm:p-5">
        <label for="tk-reply" class="label">{{ $ticket->status === 'closed' ? __('Rouvrir avec un nouveau message') : __('Répondre') }}</label>
        <textarea wire:model="body" id="tk-reply" rows="4" class="input"></textarea>
        @error('body') <p class="error">{{ $message }}</p> @enderror
        <div class="flex flex-wrap justify-end gap-2">
            @if ($ticket->status !== 'closed')<button type="button" wire:click="close" class="btn-ghost"><x-icon name="circle-check" class="size-4" /> {{ __('C’est réglé') }}</button>@endif
            <button class="btn-primary"><x-icon name="send" class="size-4" /> {{ __('Envoyer') }}</button>
        </div>
    </form>
</div>
