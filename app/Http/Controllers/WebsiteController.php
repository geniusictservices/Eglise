<?php

namespace App\Http\Controllers;

use App\Livewire\Finances\Declarations\Index as Declarations;
use App\Models\Organization;
use App\Models\Sermon;
use App\Models\Website;
use App\Services\Websites;
use App\Support\DocumentIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Le site vitrine public d'une communauté : /site/{adresse}. */
class WebsiteController extends Controller
{
    public function __construct(private Websites $websites) {}

    public function home(Request $request, string $site)
    {
        $website = $this->website($request, $site);
        $organization = $website->organization;

        return $this->view($website, 'home', [
            'schedule' => $this->websites->schedule($organization)->take(4),
            'events' => $website->hasPage('evenements') ? $this->websites->upcoming($organization)->take(3) : collect(),
            'announcements' => $website->hasPage('annonces') ? $this->websites->announcements($organization)->take(3) : collect(),
            'sermon' => $website->hasPage('predications') ? $this->websites->sermons($organization)->first() : null,
            'parishes' => $website->hasPage('paroisses') ? $this->websites->parishes($organization) : collect(),
        ]);
    }

    public function page(Request $request, string $site, string $page)
    {
        $website = $this->website($request, $site);
        abort_unless($website->hasPage($page), 404);
        $organization = $website->organization;

        return $this->view($website, $page, match ($page) {
            'programme' => ['schedule' => $this->websites->schedule($organization), 'agenda' => $this->websites->upcoming($organization, 14, all: true)],
            'evenements' => ['events' => $this->websites->upcoming($organization, 120)],
            'annonces' => ['announcements' => $this->websites->announcements($organization)],
            'predications' => ['sermons' => $this->websites->sermons($organization)],
            'paroisses' => ['parishes' => $this->websites->parishes($organization)],
            'don' => ['accounts' => $this->websites->givingAccounts($website), 'categories' => $this->websites->givingCategories($website),
                'currencies' => ['USD', 'CDF'], 'operators' => Declarations::OPERATORS],
            default => [],
        });
    }

    public function sermon(Request $request, string $site, int $sermon)
    {
        $website = $this->website($request, $site);
        abort_unless($website->hasPage('predications'), 404);

        return $this->view($website, 'sermon', ['sermon' => $this->findSermon($website, $sermon)]);
    }

    public function audio(Request $request, string $site, int $sermon)
    {
        $sermon = $this->findSermon($this->website($request, $site), $sermon);
        abort_unless($sermon->audio_path && Storage::disk('local')->exists($sermon->audio_path), 404);

        // Réponse fichier : le lecteur peut avancer dans l'audio (requêtes partielles).
        return response()->file(Storage::disk('local')->path($sermon->audio_path), ['Cache-Control' => 'public, max-age=604800']);
    }

    public function cover(Request $request, string $site)
    {
        $website = $this->website($request, $site);
        abort_unless($website->cover_path && Storage::disk('local')->exists($website->cover_path), 404);

        return Storage::disk('local')->response($website->cover_path, null, ['Cache-Control' => 'public, max-age=2592000, immutable']);
    }

    public function give(Request $request, string $site)
    {
        $website = $this->website($request, $site);
        abort_unless($website->hasPage('don'), 404);
        // Champ piège : invisible pour une personne, rempli par les robots.
        if (filled($request->input('site_web'))) {
            return redirect()->route('website.page', [$site, 'don'])->with('given', true);
        }
        $data = $request->validate([
            'name' => 'required|string|max:150', 'phone' => 'required|string|max:20',
            'amount' => 'required|numeric|min:0.01|max:100000000', 'currency' => ['required', Rule::in(['USD', 'CDF'])],
            'operator' => ['required', Rule::in(Declarations::OPERATORS)], 'reference' => 'required|string|max:100',
            'paid_on' => 'required|date|before_or_equal:today|after:-60 days', 'category_id' => 'nullable|integer', 'message' => 'nullable|string|max:255',
        ], [], ['name' => __('nom'), 'phone' => __('téléphone'), 'amount' => __('montant'), 'operator' => __('opérateur'), 'reference' => __('ID de la transaction'), 'paid_on' => __('date')]);
        $this->websites->declareGift($website, $data);

        return redirect()->route('website.page', [$site, 'don'])->with('given', true);
    }

    private function website(Request $request, string $site): Website
    {
        $organization = Organization::where('slug', $site)->first();
        $preview = $organization && $request->user() && Gate::forUser($request->user())->allows('website.manage', $organization);

        return $this->websites->find($site, $preview) ?? abort(404);
    }

    private function findSermon(Website $website, int $id): Sermon
    {
        return Sermon::withoutOrganizationScope()->where('organization_id', $website->organization_id)->where('is_published', true)->findOrFail($id);
    }

    private function view(Website $website, string $page, array $data = [])
    {
        $organization = $website->organization;

        return view('website.'.$page, $data + [
            'website' => $website,
            'organization' => $organization,
            'identity' => new DocumentIdentity($organization),
            'page' => $page,
        ]);
    }
}
