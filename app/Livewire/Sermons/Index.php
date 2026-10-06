<?php

namespace App\Livewire\Sermons;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\Sermon;
use App\Models\Website;
use App\Services\Websites;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

#[Title('Prédications')]
class Index extends Component
{
    use WithFileUploads, WithPagination, WritesInOrganization;

    public ?int $editingId = null;

    public array $form = [];

    public $audio = null;

    public function mount(): void
    {
        abort_unless(Gate::any(['sermons.manage', 'website.manage']), 403);
    }

    private function authorizeManage(): void
    {
        abort_if($this->organization()->isReadOnly(), 403);
        abort_unless(Gate::any(['sermons.manage', 'website.manage']), 403);
    }

    public function create(): void
    {
        $this->authorizeManage();
        $this->editingId = null;
        $this->form = ['title' => '', 'preacher' => '', 'preached_on' => (today()->isSunday() ? today() : today()->previous(CarbonInterface::SUNDAY))->toDateString(),
            'passage' => '', 'summary' => '', 'video_url' => '', 'is_published' => true];
        $this->reset('audio');
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'sermon');
    }

    public function edit(int $id): void
    {
        $this->authorizeManage();
        $sermon = Sermon::findOrFail($id);
        $this->editingId = $sermon->id;
        $this->form = ['title' => $sermon->title, 'preacher' => (string) $sermon->preacher, 'preached_on' => $sermon->preached_on->toDateString(),
            'passage' => (string) $sermon->passage, 'summary' => (string) $sermon->summary, 'video_url' => (string) $sermon->video_url, 'is_published' => $sermon->is_published];
        $this->reset('audio');
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'sermon');
    }

    public function save(Websites $websites): void
    {
        $this->authorizeManage();
        $this->validate([
            'form.title' => 'required|string|max:200', 'form.preacher' => 'nullable|string|max:150', 'form.preached_on' => 'required|date',
            'form.passage' => 'nullable|string|max:150', 'form.summary' => 'nullable|string|max:3000', 'form.video_url' => 'nullable|url:https|max:500',
            'audio' => 'nullable|file|mimes:mp3,m4a,aac,ogg,oga,opus,mpga|max:'.Sermon::AUDIO_MAX_KB,
        ], ['audio.max' => __('Le fichier dépasse 15 Mo : réenregistrez-le en qualité « voix » (voir le guide).')],
            ['form.title' => __('titre'), 'form.preached_on' => __('date'), 'form.video_url' => __('lien de la vidéo'), 'audio' => __('audio')]);
        try {
            $websites->saveSermon($this->organization(), $this->form, $this->audio, $this->editingId ? Sermon::findOrFail($this->editingId) : null);
        } catch (InvalidArgumentException $e) {
            $this->addError('form.video_url', $e->getMessage());

            return;
        }
        $this->reset('audio');
        $this->dispatch('close-modal', name: 'sermon');
        $this->notify($this->editingId ? __('Prédication modifiée.') : __('Prédication publiée.'));
    }

    public function delete(Websites $websites, int $id): void
    {
        $this->authorizeManage();
        $websites->deleteSermon(Sermon::findOrFail($id));
        $this->notify(__('Prédication supprimée.'));
    }

    public function render()
    {
        $organization = $this->organization();
        $website = Website::where('organization_id', $organization->id)->first();

        return view('livewire.sermons.index', [
            'sermons' => Sermon::latest('preached_on')->latest('id')->paginate(20),
            'website' => $website,
            'organization' => $organization,
            'canManage' => ! $organization->isReadOnly(),
        ]);
    }
}
