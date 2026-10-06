<?php

namespace App\Livewire\Admin;

use App\Services\AuditLogger;
use App\Support\Phone;
use App\Support\Platform;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Coordonnées de Genius ICT et durées de l'essai et du délai de grâce. */
#[Layout('layouts::admin')]
#[Title('Coordonnées et réglages')]
class Settings extends Component
{
    public array $contact = [];

    public int $trialDays = 30;

    public int $graceDays = 30;

    public function mount(): void
    {
        $this->authorize('admin.settings');
        $this->contact = array_map(fn ($v) => (string) $v, Platform::contact());
        $this->contact['phone'] = Phone::format($this->contact['phone']) ?: '';
        $this->trialDays = (int) Platform::get('trial_days');
        $this->graceDays = (int) Platform::get('grace_days');
    }

    public function save(): void
    {
        $this->authorize('admin.settings');
        $this->validate([
            'contact.company' => 'required|string|max:80',
            'contact.city' => 'nullable|string|max:120',
            'contact.email' => 'required|email|max:120',
            'contact.phone' => ['nullable', 'string', 'max:25', fn ($a, $v, $fail) => $v && ! Phone::normalize($v) ? $fail(__('Ce numéro de téléphone n’est pas valide.')) : null],
            'contact.website' => 'nullable|string|max:120',
            'contact.payment' => 'nullable|string|max:300',
            'trialDays' => 'required|integer|min:7|max:90',
            'graceDays' => 'required|integer|min:0|max:90',
        ], attributes: ['contact.phone' => __('numéro WhatsApp'), 'contact.email' => __('e-mail'), 'trialDays' => __('durée de l’essai'), 'graceDays' => __('délai de grâce')]);

        foreach ($this->contact as $key => $value) {
            Platform::set("contact.$key", $key === 'phone' ? Phone::normalize($value) : (trim($value) ?: null));
        }
        Platform::set('trial_days', $this->trialDays);
        Platform::set('grace_days', $this->graceDays);
        app(AuditLogger::class)->record('platform_settings', null, [], ['contact' => $this->contact, 'trial_days' => $this->trialDays, 'grace_days' => $this->graceDays],
            __('a modifié les coordonnées et réglages de la plateforme'));

        $this->dispatch('notify', message: __('Réglages enregistrés.'), type: 'success');
    }

    public function render()
    {
        return view('livewire.admin.settings', ['whatsapp' => Platform::whatsapp(__('Bonjour Genius ICT'))]);
    }
}
