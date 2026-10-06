<?php

namespace App\Livewire\Site;

use App\Models\DemoRequest as DemoRequestModel;
use App\Support\Phone;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

/** Formulaire « Demander une démonstration » du site public. */
class DemoRequest extends Component
{
    public string $name = '';

    public string $phone = '';

    public string $community = '';

    public string $city = '';

    public string $members = '';

    public string $message = '';

    // Piège à robots : un humain ne remplit jamais ce champ caché.
    public string $website = '';

    public bool $sent = false;

    public function submit(): void
    {
        if ($this->website !== '') {
            $this->sent = true;

            return;
        }

        $key = 'demo-request:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('name', __('Trop de demandes depuis cet appareil. Réessayez plus tard.'));

            return;
        }

        $phone = Phone::normalize($this->phone);
        $this->validate([
            'name' => 'required|string|max:120',
            'phone' => ['required', fn ($a, $v, $fail) => $phone ? null : $fail(__('Ce numéro de téléphone n’est pas valide.'))],
            'community' => 'required|string|max:150',
            'city' => 'nullable|string|max:100',
            'members' => 'nullable|string|max:30',
            'message' => 'nullable|string|max:2000',
        ], attributes: ['name' => __('nom'), 'phone' => __('téléphone'), 'community' => __('communauté')]);

        RateLimiter::hit($key, 3600);

        DemoRequestModel::create([
            'name' => $this->name,
            'phone' => $phone,
            'community' => $this->community,
            'city' => $this->city ?: null,
            'members' => $this->members ?: null,
            'message' => $this->message ?: null,
            'ip_address' => request()->ip(),
        ]);

        $this->sent = true;
    }

    public function render()
    {
        return view('livewire.site.demo-request');
    }
}
