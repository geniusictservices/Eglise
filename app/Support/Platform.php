<?php

namespace App\Support;

use App\Models\PlatformSetting;

/**
 * Réglages de la plateforme : les valeurs de config/waumini.php, remplacées
 * par celles enregistrées dans l'espace Genius ICT.
 */
class Platform
{
    /** Réglages modifiables et leur valeur de départ (clé de config). */
    public const DEFAULTS = [
        'contact.company' => 'waumini.contact.company',
        'contact.city' => 'waumini.contact.city',
        'contact.email' => 'waumini.contact.email',
        'contact.phone' => 'waumini.contact.phone',
        'contact.website' => 'waumini.contact.website',
        'trial_days' => 'waumini.trial_days',
        'grace_days' => 'waumini.grace_days',
        'annual_discount_months' => 'waumini.annual_discount_months',
        'size_tiers' => 'waumini.size_tiers',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $values = self::load();

        if (array_key_exists($key, $values)) {
            return $values[$key];
        }

        return isset(self::DEFAULTS[$key]) ? config(self::DEFAULTS[$key], $default) : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        PlatformSetting::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => auth()->id()]);
        self::flush();
    }

    /** Coordonnées de Genius ICT affichées sur le site et dans l'application. */
    public static function contact(): array
    {
        return collect(['company', 'city', 'email', 'phone', 'website', 'payment'])
            ->mapWithKeys(fn ($k) => [$k => self::get("contact.$k")])->all();
    }

    /** Lien WhatsApp vers Genius ICT, avec un message prérempli. */
    public static function whatsapp(?string $message = null): ?string
    {
        $phone = self::get('contact.phone');

        return $phone ? 'https://wa.me/'.ltrim($phone, '+').($message ? '?text='.rawurlencode($message) : '') : null;
    }

    public static function flush(): void
    {
        app()->forgetInstance('waumini.platform');
    }

    /** Lus une fois par requête (gardés dans le conteneur, pas dans une variable statique). */
    private static function load(): array
    {
        if (! app()->bound('waumini.platform')) {
            try {
                $values = PlatformSetting::pluck('value', 'key')->all();
            } catch (\Throwable) {
                $values = []; // base pas encore installée
            }
            app()->instance('waumini.platform', $values);
        }

        return app('waumini.platform');
    }
}
