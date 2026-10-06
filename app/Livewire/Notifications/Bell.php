<?php

namespace App\Livewire\Notifications;

use App\Services\Notifier;
use Livewire\Component;

/** La cloche de l'en-tête : le nombre de nouveautés pas encore ouvertes. */
class Bell extends Component
{
    public bool $onDark = false;

    public function render(Notifier $notifier)
    {
        return view('livewire.notifications.bell', ['count' => $notifier->unreadCount(auth()->user())]);
    }
}
