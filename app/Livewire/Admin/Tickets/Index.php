<?php

namespace App\Livewire\Admin\Tickets;

use App\Models\SupportTicket;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Les demandes des communautés, côté Genius ICT. */
#[Layout('layouts::admin')]
#[Title('Tickets de support')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'etat', except: 'open')]
    public string $status = 'open';

    #[Url(as: 'miens', except: false)]
    public bool $mine = false;

    public function mount(): void
    {
        $this->authorize('admin.support');
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        return view('livewire.admin.tickets.index', [
            'tickets' => SupportTicket::with(['organization', 'opener', 'assignee'])
                ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
                ->when($this->mine, fn ($q) => $q->where('assigned_to', auth()->id()))
                ->latest('last_activity_at')->paginate(30),
            'counts' => SupportTicket::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }
}
