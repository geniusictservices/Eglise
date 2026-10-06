<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\AuditLog;
use App\Support\DocumentIdentity;
use App\Support\OrganizationLogo;
use App\Support\Phone;
use App\Support\SupportAccess;
use App\Support\Theme;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Title('Paramètres')]
class Edit extends Component
{
    use WithFileUploads, WritesInOrganization;

    #[Url(as: 'onglet', except: 'general')]
    public string $tab = 'general';

    public string $name = '';

    public string $shortName = '';

    public string $levelLabel = '';

    public string $phone = '';

    public string $email = '';

    public string $province = '';

    public string $city = '';

    public string $address = '';

    public string $locale = 'fr';

    public string $timezone = '';

    /** Libellés renommés : clé => texte. */
    public array $terms = [];

    public bool $supportAccess = false;

    public int $supportDays = 7;

    // Apparence
    public string $preset = 'wax';

    public string $primaryColor = '';

    public string $accentColor = '';

    public bool $showPattern = true;

    public bool $hasOwnTheme = false;

    // Identité juridique et documents
    public array $legal = [];

    public bool $legalInherit = true;

    /** Ce qui s'affiche sur les documents : clé => oui/non. */
    public array $display = [];

    public string $footer = '';

    public string $documentFooter = '';

    public string $receiptFormat = 'a4';

    public $logo = null;

    public function mount(): void
    {
        $this->authorize('organization.settings');
        $o = $this->organization();

        $this->fill([
            'name' => $o->name, 'shortName' => (string) $o->short_name, 'levelLabel' => $o->level_label,
            'phone' => (string) $o->phone, 'email' => (string) $o->email, 'province' => (string) $o->province,
            'city' => (string) $o->city, 'address' => (string) $o->address, 'locale' => $o->locale, 'timezone' => $o->timezone,
            'terms' => $o->terminology ?? [],
            'supportAccess' => $o->support_access_until?->isFuture() ?? false,
        ]);

        $this->loadTheme();
        $this->loadIdentity();
    }

    private function loadIdentity(): void
    {
        $o = $this->organization();
        $identity = $o->documentIdentity();
        $this->legal = collect(DocumentIdentity::LEGAL_FIELDS)->mapWithKeys(fn ($l, $k) => [$k => (string) ($o->legal[$k] ?? '')])->all();
        $this->legalInherit = ! $o->isRoot() && ($o->legal['inherit'] ?? true);
        $display = $identity->display();
        $this->display = collect(DocumentIdentity::DISPLAY)->mapWithKeys(fn ($d, $k) => [$k => $display[$k]])->all();
        $this->footer = $display['footer'];
        $this->documentFooter = $display['document_footer'];
        $this->receiptFormat = $display['receipt_format'];
    }

    public function saveIdentity(): void
    {
        $this->authorizeWrite('organization.settings');
        $this->validate([
            'legal.*' => 'nullable|string|max:255',
            'footer' => 'nullable|string|max:300',
            'documentFooter' => 'nullable|string|max:300',
            'receiptFormat' => ['required', Rule::in(array_keys(DocumentIdentity::RECEIPT_FORMATS))],
            'logo' => 'nullable|image|max:4096',
        ], attributes: ['logo' => __('logo'), 'footer' => __('texte de pied de page')]);

        $organization = $this->organization();
        $legal = collect($this->legal)->only(array_keys(DocumentIdentity::LEGAL_FIELDS))->map(fn ($v) => trim((string) $v) ?: null)->filter()->all();
        if (! $organization->isRoot()) {
            $legal['inherit'] = $this->legalInherit;
        }

        $settings = $organization->settings ?? [];
        $settings['documents'] = [
            'show' => collect($this->display)->only(array_keys(DocumentIdentity::DISPLAY))->map(fn ($v) => (bool) $v)->all(),
            'footer' => trim($this->footer) ?: null,
            'document_footer' => trim($this->documentFooter) ?: null,
            'receipt_format' => $this->receiptFormat,
        ];

        $attributes = ['legal' => $legal ?: null, 'settings' => $settings];
        if ($this->logo) {
            $old = $organization->logo_path;
            $attributes['logo_path'] = OrganizationLogo::store($this->logo->getRealPath(), $organization);
            OrganizationLogo::delete($old);
        }
        $organization->update($attributes);

        $this->reset('logo');
        $this->notify(__('Identité et documents enregistrés.'));
    }

    public function removeLogo(): void
    {
        $this->authorizeWrite('organization.settings');
        $organization = $this->organization();
        OrganizationLogo::delete($organization->logo_path);
        $organization->update(['logo_path' => null]);
        $this->notify(__('Logo retiré.'));
    }

    private function loadTheme(): void
    {
        $o = $this->organization();
        $own = $o->settings['theme'] ?? null;
        $theme = $o->theme();

        $this->hasOwnTheme = (bool) $own;
        $this->preset = $own['preset'] ?? (array_search([$theme->primary, $theme->accent], array_map(fn ($p) => [$p['primary'], $p['accent']], Theme::PRESETS), true) ?: 'custom');
        $this->primaryColor = $theme->primary;
        $this->accentColor = $theme->accent;
        $this->showPattern = $theme->pattern;
    }

    public function choosePreset(string $preset): void
    {
        abort_unless(isset(Theme::PRESETS[$preset]), 404);
        $this->preset = $preset;
        $this->primaryColor = Theme::PRESETS[$preset]['primary'];
        $this->accentColor = Theme::PRESETS[$preset]['accent'];
        $this->resetValidation();
    }

    public function updatedPrimaryColor(): void
    {
        $this->preset = 'custom';
    }

    public function updatedAccentColor(): void
    {
        $this->preset = 'custom';
    }

    public function saveTheme()
    {
        $this->authorizeWrite('organization.settings');

        $this->validate([
            'primaryColor' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/', function ($attribute, $value, $fail) {
                if (! Theme::primaryIsReadable($value)) {
                    $fail(__('Cette couleur est trop claire : le texte blanc posé dessus serait illisible. Choisissez une couleur plus foncée.'));
                }
            }],
            'accentColor' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], attributes: ['primaryColor' => __('couleur principale'), 'accentColor' => __('couleur d’accent')]);

        $organization = $this->organization();
        $organization->update(['settings' => array_merge($organization->settings ?? [], [
            'theme' => [
                'preset' => $this->preset,
                'primary' => strtoupper($this->primaryColor),
                'accent' => strtoupper($this->accentColor),
                'pattern' => $this->showPattern,
            ],
        ])]);

        session()->flash('status', __('Apparence enregistrée.'));

        return $this->redirectRoute('settings.edit', ['onglet' => 'apparence']);
    }

    /** Revient aux couleurs du niveau supérieur (ou de Waumini). */
    public function resetTheme()
    {
        $this->authorizeWrite('organization.settings');
        $organization = $this->organization();
        $settings = $organization->settings ?? [];
        unset($settings['theme']);
        $organization->update(['settings' => $settings ?: null]);

        session()->flash('status', __('Couleurs par défaut rétablies.'));

        return $this->redirectRoute('settings.edit', ['onglet' => 'apparence']);
    }

    public function saveGeneral(): void
    {
        $this->authorizeWrite('organization.settings');

        $this->validate([
            'name' => 'required|string|max:150',
            'shortName' => 'nullable|string|max:60',
            'levelLabel' => 'required|string|max:60',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email',
            'province' => 'nullable|string|max:80',
            'city' => 'nullable|string|max:80',
            'address' => 'nullable|string|max:200',
            'locale' => ['required', Rule::in(array_keys(config('waumini.locales')))],
            'timezone' => ['required', Rule::in(['Africa/Lubumbashi', 'Africa/Kinshasa', 'Africa/Kigali', 'Africa/Kampala', 'Africa/Bujumbura', 'Africa/Nairobi', 'Europe/Paris', 'Europe/Brussels', 'UTC'])],
        ], attributes: ['name' => __('nom'), 'levelLabel' => __('niveau')]);

        $this->organization()->update([
            'name' => $this->name,
            'short_name' => $this->shortName ?: null,
            'level_label' => $this->levelLabel,
            'phone' => Phone::normalize($this->phone) ?? ($this->phone ?: null),
            'email' => $this->email ?: null,
            'province' => $this->province ?: null,
            'city' => $this->city ?: null,
            'address' => $this->address ?: null,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
        ]);

        $this->notify(__('Paramètres enregistrés.'));
    }

    public function saveTerms(): void
    {
        $this->authorizeWrite('organization.settings');
        $keys = array_keys(trans('terms', [], 'fr'));
        $clean = collect($this->terms)->only($keys)->map(fn ($v) => trim((string) $v))->filter()->all();

        $this->organization()->update(['terminology' => $clean ?: null]);
        $this->notify(__('Libellés enregistrés.'));
    }

    public function updatedSupportAccess(bool $value): void
    {
        $this->authorizeWrite('support.grant');
        $days = in_array($this->supportDays, SupportAccess::DURATIONS, true) ? $this->supportDays : 7;
        $this->organization()->update(['support_access_until' => $value ? now()->addDays($days) : null]);
        $this->notify($value ? trans_choice('Le support Genius ICT peut voir votre communauté pendant :count jour.|Le support Genius ICT peut voir votre communauté pendant :count jours.', $days) : __('Accès du support retiré.'));
    }

    public function render()
    {
        $organization = $this->organization();

        return view('livewire.settings.edit', [
            'supportVisits' => $this->tab === 'support' ? AuditLog::with('user')->where('organization_id', $organization->id)
                ->whereIn('event', ['support_opened', 'support_closed'])->latest('id')->limit(10)->get() : collect(),
            'organization' => $organization,
            'defaults' => trans('terms', [], 'fr'),
            'inherited' => collect(array_keys(trans('terms', [], 'fr')))
                ->mapWithKeys(fn ($key) => [$key => $organization->parent ? $organization->parent->term($key) : __('terms.'.$key)]),
            'locales' => config('waumini.locales'),
            'presets' => Theme::PRESETS,
            'identity' => $organization->documentIdentity(),
            'root' => $organization->root(),
            'preview' => Theme::validHex($this->primaryColor) && Theme::validHex($this->accentColor)
                ? new Theme($this->primaryColor, $this->accentColor, $this->showPattern)
                : $organization->theme(),
        ]);
    }
}
