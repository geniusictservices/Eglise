<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\CashAccount;
use App\Models\Event;
use App\Models\FinanceCategory;
use App\Models\Organization;
use App\Models\PaymentDeclaration;
use App\Models\Sermon;
use App\Models\Website;
use App\Support\CurrentOrganization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Le site vitrine d'une communauté : quelques textes saisis une fois, le
 * reste vient de Waumini (le programme et les événements du calendrier, les
 * annonces publiques, les prédications, les comptes mobile money). Les dons
 * déclarés sur le site arrivent dans les paiements déclarés de la finance.
 */
class Websites
{
    public const COVER_MAX = 1600;

    public function __construct(private Calendar $calendar, private CircuitNotices $notices) {}

    /** Le site de la communauté, avec des textes de départ s'il n'existe pas encore. */
    public function for(Organization $organization): Website
    {
        return Website::firstOrNew(['organization_id' => $organization->id], [
            'tagline' => $organization->city ? __(':c, :p', ['c' => $organization->city, 'p' => $organization->province ?: 'RDC']) : null,
            'welcome_title' => __('Bienvenue à :n', ['n' => $organization->name]),
            'pages' => Website::DEFAULT_PAGES,
        ]);
    }

    public function save(Organization $organization, array $data): Website
    {
        $website = $this->for($organization);
        $pages = array_values(array_intersect(array_keys(Website::PAGES), (array) ($data['pages'] ?? [])));
        $publish = (bool) ($data['is_published'] ?? false);
        $accounts = CashAccount::withoutOrganizationScope()->where('organization_id', $organization->id)->whereIn('id', (array) ($data['giving_accounts'] ?? []))->pluck('id')->all();
        $categories = FinanceCategory::withoutOrganizationScope()->where('organization_id', $organization->id)->where('type', 'income')
            ->whereIn('id', (array) ($data['giving_categories'] ?? []))->pluck('id')->all();

        $website->fill([
            'theme' => array_key_exists($data['theme'] ?? '', Website::THEMES) ? $data['theme'] : 'chaleureux',
            'pages' => $pages,
            'is_published' => $publish,
            'published_at' => $publish ? ($website->published_at ?? now()) : null,
            'giving_accounts' => $accounts,
            'giving_categories' => $categories,
        ] + collect(['tagline', 'welcome_title', 'welcome_text', 'about_text', 'beliefs_text', 'pastor_name', 'pastor_message', 'giving_text', 'whatsapp', 'map_url', 'facebook_url', 'youtube_url'])
            ->mapWithKeys(fn ($k) => [$k => trim((string) ($data[$k] ?? '')) ?: null])->all());
        $website->save();

        return $website;
    }

    /** La photo d'accueil : réduite à 1600 px de large au plus, en JPEG. */
    public function storeCover(Website $website, UploadedFile $file): void
    {
        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $image) {
            throw new InvalidArgumentException(__('Image illisible.'));
        }
        $w = imagesx($image);
        $h = imagesy($image);
        $scale = min(1, self::COVER_MAX / $w);
        $out = imagescale($image, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
        ob_start();
        imagejpeg($out, null, 80);
        $path = 'websites/'.$website->organization_id.'-'.Str::random(8).'.jpg';
        Storage::disk('local')->put($path, (string) ob_get_clean());
        $this->forgetCover($website);
        $website->update(['cover_path' => $path]);
    }

    public function forgetCover(Website $website): void
    {
        if ($website->cover_path) {
            Storage::disk('local')->delete($website->cover_path);
            $website->update(['cover_path' => null]);
        }
    }

    /** Le site d'une adresse, s'il est publié (ou en aperçu pour ceux qui le gèrent). */
    public function find(string $slug, bool $preview = false): ?Website
    {
        $organization = Organization::where('slug', $slug)->first();
        if (! $organization || $organization->status === 'suspended') {
            return null;
        }
        $website = Website::where('organization_id', $organization->id)->first();
        if (! $website || (! $website->is_published && ! $preview)) {
            return null;
        }
        $website->setRelation('organization', $organization);

        return $website;
    }

    /**
     * Le programme régulier : les cultes et rencontres qui se répètent, ouverts à toute la communauté.
     *
     * @return Collection<int, Event>
     */
    public function schedule(Organization $organization): Collection
    {
        return Event::withoutOrganizationScope()->where('organization_id', $organization->id)->where('is_public', true)
            ->where('repeats', '!=', 'none')->where(fn ($q) => $q->whereNull('repeat_until')->orWhereDate('repeat_until', '>=', today()))
            ->get()->sortBy(fn (Event $e) => [$e->repeats === 'weekly' ? 0 : 1, ($e->starts_on->dayOfWeekIso % 7), $e->start_time])->values();
    }

    /** Les prochaines dates publiques : événements ponctuels, et les rencontres régulières si $all. */
    public function upcoming(Organization $organization, int $days = 60, bool $all = false): Collection
    {
        return $this->calendar->agenda($organization, today(), today()->addDays($days))
            ->filter(fn ($o) => $o['event']->is_public && ($all || $o['event']->repeats === 'none'))->values();
    }

    public function announcements(Organization $organization): Collection
    {
        return Announcement::withoutOrganizationScope()->where('organization_id', $organization->id)->where('is_public', true)
            ->current()->orderByDesc('pinned')->latest('published_at')->get();
    }

    public function sermons(Organization $organization): Collection
    {
        return Sermon::withoutOrganizationScope()->where('organization_id', $organization->id)->where('is_published', true)->latest('preached_on')->latest('id')->get();
    }

    /** Les comptes mobile money et bancaires montrés sur la page des dons. */
    public function givingAccounts(Website $website): Collection
    {
        return CashAccount::withoutOrganizationScope()->where('organization_id', $website->organization_id)->where('is_active', true)
            ->whereIn('id', $website->giving_accounts ?? [])->whereIn('kind', ['mobile', 'bank'])->orderBy('position')->get();
    }

    public function givingCategories(Website $website): Collection
    {
        return FinanceCategory::withoutOrganizationScope()->where('organization_id', $website->organization_id)->whereIn('id', $website->giving_categories ?? [])->orderBy('position')->get();
    }

    /** Les niveaux en dessous qui ont publié leur site. */
    public function parishes(Organization $organization): Collection
    {
        return Organization::query()->where('path', 'like', $organization->path.'%')->where('id', '!=', $organization->id)->orderBy('depth')->orderBy('name')->get()
            ->map(fn (Organization $o) => ['organization' => $o, 'website' => Website::where('organization_id', $o->id)->where('is_published', true)->first()]);
    }

    /** Un don déclaré depuis le site : la finance le vérifie sur son téléphone, puis le valide ou le rejette. */
    public function declareGift(Website $website, array $data): PaymentDeclaration
    {
        $organization = $website->organization;
        $category = in_array((int) ($data['category_id'] ?? 0), $website->giving_categories ?? [], true) ? (int) $data['category_id'] : null;

        $declaration = app(CurrentOrganization::class)->within($organization, fn () => PaymentDeclaration::create([
            'organization_id' => $organization->id,
            'declarant_name' => trim($data['name']), 'declarant_phone' => trim($data['phone']),
            'amount' => $data['amount'], 'currency' => $data['currency'], 'operator' => $data['operator'],
            'transaction_reference' => $data['reference'], 'paid_on' => Carbon::parse($data['paid_on'] ?? today())->toDateString(),
            'category_id' => $category, 'message' => trim((string) ($data['message'] ?? '')) ?: null, 'source' => 'website',
        ]));
        $this->notices->declarationReceived($declaration);

        return $declaration;
    }

    /** Enregistre une prédication, avec son audio éventuel. */
    public function saveSermon(Organization $organization, array $data, ?UploadedFile $audio = null, ?Sermon $sermon = null): Sermon
    {
        $video = trim((string) ($data['video_url'] ?? '')) ?: null;
        if (! $video && ! $audio && ! $sermon?->audio_path) {
            throw new InvalidArgumentException(__('Ajoutez le lien de la vidéo ou le fichier audio.'));
        }
        $values = [
            'title' => trim($data['title']), 'preacher' => trim((string) ($data['preacher'] ?? '')) ?: null, 'preached_on' => $data['preached_on'],
            'passage' => trim((string) ($data['passage'] ?? '')) ?: null, 'summary' => trim((string) ($data['summary'] ?? '')) ?: null,
            'video_url' => $video, 'is_published' => (bool) ($data['is_published'] ?? true),
        ];
        $sermon ??= new Sermon(['organization_id' => $organization->id, 'created_by' => auth()->id()]);
        $sermon->fill($values);
        if ($audio) {
            $this->forgetAudio($sermon);
            $sermon->audio_path = $audio->storeAs('sermons', $organization->id.'-'.Str::random(10).'.'.($audio->guessExtension() ?: 'mp3'), 'local');
            $sermon->audio_size = $audio->getSize();
        }
        $sermon->save();

        return $sermon;
    }

    public function deleteSermon(Sermon $sermon): void
    {
        $this->forgetAudio($sermon);
        $sermon->delete();
    }

    private function forgetAudio(Sermon $sermon): void
    {
        if ($sermon->audio_path) {
            Storage::disk('local')->delete($sermon->audio_path);
            $sermon->audio_path = null;
            $sermon->audio_size = null;
        }
    }
}
