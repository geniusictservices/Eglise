<?php

namespace App\Livewire\Notifications;

use App\Models\Organization;
use App\Models\PushSubscription;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Les nouveautés de l'utilisateur, dans toutes ses communautés. */
#[Title('Nouveautés')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'voir')]
    public string $show = 'unread';

    public function updatedShow(): void
    {
        $this->resetPage();
    }

    public function markAllRead(): void
    {
        DatabaseNotification::where('notifiable_type', 'user')->where('notifiable_id', auth()->id())->whereNull('read_at')->update(['read_at' => now()]);
        $this->dispatch('notify', message: __('Toutes les nouveautés sont marquées comme lues.'), type: 'success');
    }

    public function render()
    {
        $query = DatabaseNotification::where('notifiable_type', 'user')->where('notifiable_id', auth()->id())->latest();
        $unread = (clone $query)->whereNull('read_at')->count();
        $items = $query->when($this->show === 'unread', fn ($q) => $q->whereNull('read_at'))->paginate(30);

        return view('livewire.notifications.index', [
            'items' => $items,
            'unread' => $unread,
            'organizations' => Organization::whereIn('id', $items->pluck('organization_id')->filter()->unique())->pluck('name', 'id'),
            'devices' => PushSubscription::where('user_id', auth()->id())->count(),
        ])->layout(current_organization() ? 'layouts::app' : 'layouts::admin');
    }
}
