<?php

namespace App\Livewire\Announcements;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\Announcement;
use App\Support\AnnouncementAccess;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/** Une annonce, ouverte depuis les nouveautés ou partagée. */
class Show extends Component
{
    use WritesInOrganization;

    public Announcement $announcement;

    public function mount(Announcement $announcement): void
    {
        abort_unless(Gate::any(['organization.view', 'member.space']), 403);
        $visible = Announcement::whereKey($announcement->id)->where(AnnouncementAccess::visibleQuery(auth()->user(), $this->organization()))->exists();
        abort_unless($visible && $announcement->published_at, 404);
        $this->announcement = $announcement;
    }

    public function render()
    {
        return view('livewire.announcements.show')->title($this->announcement->title);
    }
}
