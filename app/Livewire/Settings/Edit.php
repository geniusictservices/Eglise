<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\WritesInOrganization;
use App\Support\Phone;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Paramètres')]
class Edit extends Component
{
    use WritesInOrganization;

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
        $this->organization()->update(['support_access_until' => $value ? now()->addDays(7) : null]);
        $this->notify($value ? __('Le support Genius ICT peut accéder à votre communauté pendant 7 jours.') : __('Accès du support révoqué.'));
    }

    public function render()
    {
        $organization = $this->organization();

        return view('livewire.settings.edit', [
            'organization' => $organization,
            'defaults' => trans('terms', [], 'fr'),
            'inherited' => collect(array_keys(trans('terms', [], 'fr')))
                ->mapWithKeys(fn ($key) => [$key => $organization->parent ? $organization->parent->term($key) : __('terms.'.$key)]),
            'locales' => config('waumini.locales'),
        ]);
    }
}
