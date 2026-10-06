<?php

namespace App\Livewire\Support;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\SupportTicket;
use App\Services\SupportTickets;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Component;

/** Une demande d'aide et ses échanges, côté communauté. */
class Show extends Component
{
    use WritesInOrganization;

    public SupportTicket $ticket;

    public string $body = '';

    public function mount(SupportTicket $ticket): void
    {
        $this->authorize('organization.view');
        abort_unless($ticket->organization_id === $this->organization()->id
            && ($ticket->opened_by === auth()->id() || Gate::allows('organization.settings')), 404);
        $this->ticket = $ticket;
    }

    public function reply(SupportTickets $tickets): void
    {
        $this->validate(['body' => 'required|string|max:5000'], attributes: ['body' => __('message')]);
        try {
            $tickets->reply($this->ticket, auth()->user(), $this->body, fromStaff: false);
        } catch (InvalidArgumentException $e) {
            $this->addError('body', $e->getMessage());

            return;
        }
        $this->body = '';
        $this->ticket->refresh();
    }

    public function close(SupportTickets $tickets): void
    {
        $tickets->close($this->ticket);
        $this->ticket->refresh();
        $this->notify(__('Merci ! La demande est marquée comme réglée.'));
    }

    public function render()
    {
        return view('livewire.support.show', [
            'messages' => $this->ticket->messages()->with('author')->get(),
        ])->title($this->ticket->subject);
    }
}
