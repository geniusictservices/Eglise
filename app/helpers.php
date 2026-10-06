<?php

use App\Models\Organization;
use App\Support\CurrentOrganization;

if (! function_exists('term')) {
    /** Libellé tel que la communauté l'a nommé (« Pasteur », « Imam »…). */
    function term(string $key, ?string $default = null): string
    {
        $organization = app(CurrentOrganization::class)->get();

        return $organization ? $organization->term($key, $default) : ($default ?? __('terms.'.$key));
    }
}

if (! function_exists('current_organization')) {
    function current_organization(): ?Organization
    {
        return app(CurrentOrganization::class)->get();
    }
}
