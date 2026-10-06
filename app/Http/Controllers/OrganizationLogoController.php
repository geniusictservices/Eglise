<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Support\Facades\Storage;

/** Logo public d'une communauté (documents, site vitrine). */
class OrganizationLogoController extends Controller
{
    public function __invoke(Organization $organization)
    {
        abort_unless($organization->logo_path && Storage::disk('local')->exists($organization->logo_path), 404);

        return Storage::disk('local')->response($organization->logo_path, null, ['Cache-Control' => 'public, max-age=2592000, immutable']);
    }
}
