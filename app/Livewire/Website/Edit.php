<?php

namespace App\Livewire\Website;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\CashAccount;
use App\Models\FinanceCategory;
use App\Models\Group;
use App\Models\Website;
use App\Models\WebsitePhoto;
use App\Services\Websites;
use App\Support\DocumentIdentity;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Title('Site vitrine')]
class Edit extends Component
{
    use WithFileUploads, WritesInOrganization;

    #[Url(as: 'onglet', except: 'general')]
    public string $tab = 'general';

    public array $form = [];

    public $cover = null;

    /** Les responsables présentés : nom, fonction, photo déjà enregistrée. */
    public array $leaders = [];

    /** Les nouvelles photos des responsables, par position dans la liste. */
    public array $leaderPhotos = [];

    /** Les photos choisies pour la galerie, et leur légende commune. */
    public array $newPhotos = [];

    public string $photoCaption = '';

    public function mount(Websites $websites): void
    {
        $this->authorize('website.manage');
        $website = $websites->for($this->organization());
        $this->form = $website->only(['is_published', 'theme', 'tagline', 'welcome_title', 'welcome_text', 'verse_text', 'verse_reference', 'about_text', 'beliefs_text', 'pastor_name', 'pastor_message', 'giving_text', 'whatsapp', 'map_url', 'facebook_url', 'youtube_url']) + [
            'pages' => $website->pages ?? Website::DEFAULT_PAGES,
            'giving_accounts' => array_map('strval', $website->giving_accounts ?? []),
            'giving_categories' => array_map('strval', $website->giving_categories ?? []),
            'public_groups' => array_map('strval', $website->public_groups ?? []),
        ];
        $this->leaders = collect($website->leaders ?? [])->map(fn ($l) => ['name' => $l['name'], 'role' => $l['role'] ?? '', 'photo_path' => $l['photo_path'] ?? null])->all();
        $this->form = array_map(fn ($v) => $v ?? '', $this->form);
        $this->form['is_published'] = (bool) $this->form['is_published'];
        $this->form['theme'] = $this->form['theme'] ?: 'chaleureux';
    }

    public function save(Websites $websites): void
    {
        $this->authorizeWrite('website.manage');
        $this->validate([
            'form.theme' => ['required', Rule::in(array_keys(Website::THEMES))],
            'form.tagline' => 'nullable|string|max:120', 'form.welcome_title' => 'nullable|string|max:150',
            'form.welcome_text' => 'nullable|string|max:1500', 'form.verse_text' => 'nullable|string|max:500', 'form.verse_reference' => 'nullable|string|max:80',
            'leaders' => 'array|max:'.Website::MAX_LEADERS, 'leaders.*.name' => 'nullable|string|max:120', 'leaders.*.role' => 'nullable|string|max:120',
            'leaderPhotos.*' => 'nullable|image|max:8192', 'form.about_text' => 'nullable|string|max:6000', 'form.beliefs_text' => 'nullable|string|max:6000',
            'form.pastor_name' => 'nullable|string|max:120', 'form.pastor_message' => 'nullable|string|max:3000', 'form.giving_text' => 'nullable|string|max:1500',
            'form.whatsapp' => 'nullable|string|max:20', 'form.map_url' => 'nullable|url:https|max:500',
            'form.facebook_url' => 'nullable|url:https|max:300', 'form.youtube_url' => 'nullable|url:https|max:300',
            'cover' => 'nullable|image|max:8192',
        ], attributes: ['leaderPhotos.*' => __('photo'), 'leaders.*.name' => __('nom'), 'form.map_url' => __('lien de la carte'), 'form.facebook_url' => __('page Facebook'), 'form.youtube_url' => __('chaîne YouTube'), 'cover' => __('photo')]);

        $website = $websites->save($this->organization(), $this->form);
        try {
            $websites->saveLeaders($website, $this->leaders, $this->leaderPhotos);
        } catch (InvalidArgumentException $e) {
            $this->addError('leaderPhotos', $e->getMessage());

            return;
        }
        $this->leaderPhotos = [];
        $this->leaders = collect($website->fresh()->leaders ?? [])->map(fn ($l) => ['name' => $l['name'], 'role' => $l['role'] ?? '', 'photo_path' => $l['photo_path']])->all();
        if ($this->cover) {
            try {
                $websites->storeCover($website, $this->cover);
            } catch (InvalidArgumentException $e) {
                $this->addError('cover', $e->getMessage());

                return;
            }
            $this->reset('cover');
        }
        $this->notify($website->is_published ? __('Site enregistré : les changements sont en ligne.') : __('Site enregistré. Il n’est pas encore publié.'));
    }

    public function addLeader(): void
    {
        if (count($this->leaders) < Website::MAX_LEADERS) {
            $this->leaders[] = ['name' => '', 'role' => '', 'photo_path' => null];
        }
    }

    public function removeLeader(int $index): void
    {
        unset($this->leaders[$index], $this->leaderPhotos[$index]);
        $this->leaders = array_values($this->leaders);
        $this->leaderPhotos = [];
    }

    /** Les photos de la galerie s'enregistrent tout de suite, sans attendre « Enregistrer ». */
    public function uploadPhotos(Websites $websites): void
    {
        $this->authorizeWrite('website.manage');
        $this->validate(['newPhotos' => 'required|array|max:20', 'newPhotos.*' => 'image|max:12288', 'photoCaption' => 'nullable|string|max:160'],
            attributes: ['newPhotos' => __('photos'), 'newPhotos.*' => __('photo'), 'photoCaption' => __('légende')]);
        $added = 0;
        try {
            foreach ($this->newPhotos as $file) {
                $websites->addPhoto($this->organization(), $file, $this->photoCaption);
                $added++;
            }
        } catch (InvalidArgumentException $e) {
            $this->addError('newPhotos', $e->getMessage());
        }
        $this->reset('newPhotos', 'photoCaption');
        if ($added) {
            $this->notify(trans_choice(':count photo ajoutée à la galerie.|:count photos ajoutées à la galerie.', $added));
        }
    }

    public function updatePhotoCaption(int $id, string $caption): void
    {
        $this->authorizeWrite('website.manage');
        WebsitePhoto::findOrFail($id)->update(['caption' => mb_substr(trim($caption), 0, 160) ?: null]);
    }

    public function deletePhoto(Websites $websites, int $id): void
    {
        $this->authorizeWrite('website.manage');
        $websites->deletePhoto(WebsitePhoto::findOrFail($id));
    }

    public function removeCover(Websites $websites): void
    {
        $this->authorizeWrite('website.manage');
        $website = Website::where('organization_id', $this->organization()->id)->first();
        if ($website) {
            $websites->forgetCover($website);
        }
    }

    public function render(Websites $websites)
    {
        $organization = $this->organization();
        $website = Website::where('organization_id', $organization->id)->first();

        return view('livewire.website.edit', [
            'organization' => $organization,
            'website' => $website,
            'address' => route('website.home', $organization->slug),
            'accounts' => CashAccount::where('is_active', true)->orderBy('position')->get(),
            'categories' => FinanceCategory::where('type', 'income')->orderBy('position')->get(),
            'hasChildren' => $organization->children()->exists(),
            'groups' => Group::where('is_active', true)->orderBy('name')->get(),
            'photos' => $this->tab === 'galerie' ? $websites->photos($organization) : collect(),
            'logo' => (new DocumentIdentity($organization))->logoUrl(),
            'counts' => [
                'schedule' => $websites->schedule($organization)->count(),
                'events' => $websites->upcoming($organization)->count(),
                'announcements' => $websites->announcements($organization)->count(),
                'sermons' => $websites->sermons($organization)->count(),
                'photos' => WebsitePhoto::count(),
                'groups' => count($this->form['public_groups'] ?? []),
            ],
            'tabs' => ['general' => __('Accueil et apparence'), 'pages' => __('Pages'), 'galerie' => __('Galerie'), 'textes' => __('Qui sommes-nous'), 'dons' => __('Dons'), 'contact' => __('Contact')],
        ]);
    }
}
