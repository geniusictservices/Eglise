<?php

namespace App\Livewire\Admin\Tickets;

use App\Models\SupportTicket;
use App\Services\SupportTickets;
use App\Support\SupportAccess;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Une demande d'une communauté, côté Genius ICT : répondre, prendre en charge, clore. */
#[Layout('layouts::admin')]
class Show extends Component
{
    public SupportTicket $ticket;

    public string $body = '';

    public function mount(SupportTicket $ticket): void
    {
        $this->authorize('admin.support');
        $this->ticket = $ticket;
    }

    public function reply(SupportTickets $tickets): void
    {
        $this->authorize('admin.support');
        $this->validate(['body' => 'required|string|max:5000'], attributes: ['body' => __('réponse')]);
        try {
            $tickets->reply($this->ticket, auth()->user(), $this->body, fromStaff: true);
        } catch (InvalidArgumentException $e) {
            $this->addError('body', $e->getMessage());

            return;
        }
        $this->body = '';
        $this->ticket->refresh();
        $this->dispatch('notify', message: __('Réponse envoyée ; la communauté est prévenue.'), type: 'success');
    }

    public function takeOver(SupportTickets $tickets): void
    {
        $this->authorize('admin.support');
        $tickets->assign($this->ticket, auth()->user());
        $this->ticket->refresh();
    }

    public function close(SupportTickets $tickets): void
    {
        $this->authorize('admin.support');
        $tickets->close($this->ticket);
        $this->ticket->refresh();
    }

    public function render()
    {
        $this->ticket->loadMissing(['organization', 'opener', 'assignee']);
        $root = $this->ticket->organization->root();

        return view('livewire.admin.tickets.show', [
            'messages' => $this->ticket->messages()->with('author')->get(),
            'root' => $root,
            'supportOpen' => SupportAccess::granted($this->ticket->organization) ? $this->ticket->organization : (SupportAccess::granted($root) ? $root : null),
        ])->title($this->ticket->number);
    }
}
