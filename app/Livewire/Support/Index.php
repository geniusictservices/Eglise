<?php

namespace App\Livewire\Support;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\SupportTicket;
use App\Services\SupportTickets;
use App\Support\Platform;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Les demandes d'aide de la communauté à Genius ICT. */
#[Title('Support Genius ICT')]
class Index extends Component
{
    use WritesInOrganization;

    public array $form = ['subject' => '', 'category' => 'question', 'body' => ''];

    public function mount(): void
    {
        $this->authorize('organization.view');
    }

    public function create(SupportTickets $tickets)
    {
        $this->authorize('organization.view');
        $this->validate([
            'form.subject' => 'required|string|max:150', 'form.category' => ['required', Rule::in(array_keys(SupportTicket::CATEGORIES))],
            'form.body' => 'required|string|max:5000',
        ], attributes: ['form.subject' => __('sujet'), 'form.body' => __('message')]);
        $ticket = $tickets->open($this->organization(), auth()->user(), $this->form['subject'], $this->form['category'], $this->form['body']);

        return redirect()->route('support.show', $ticket);
    }

    public function render()
    {
        $organization = $this->organization();
        $all = Gate::allows('organization.settings');

        return view('livewire.support.index', [
            'tickets' => SupportTicket::with('opener')->where('organization_id', $organization->id)
                ->when(! $all, fn ($q) => $q->where('opened_by', auth()->id()))
                ->orderByRaw("status = 'closed'")->latest('last_activity_at')->get(),
            'all' => $all,
            'contact' => Platform::contact(),
            'whatsapp' => Platform::whatsapp(__('Bonjour Genius ICT, j’ai besoin d’aide pour :name.', ['name' => $organization->name])),
        ]);
    }
}
