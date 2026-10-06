<?php

namespace App\Livewire\Announcements;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\Announcement;
use App\Support\AnnouncementAccess;
use Livewire\Component;

/** Une annonce, ouverte depuis les nouveautés ou partagée. */
class Show extends Component
{
    use WritesInOrganization;

    public Announcement $announcement;

    public function mount(Announcement $announcement): void
    {
        $this->authorize('organization.view');
        $visible = Announcement::whereKey($announcement->id)->where(AnnouncementAccess::visibleQuery(auth()->user(), $this->organization()))->exists();
        abort_unless($visible && $announcement->published_at, 404);
        $this->announcement = $announcement;
    }

    public function render()
    {
        return view('livewire.announcements.show')->title($this->announcement->title);
    }
}
