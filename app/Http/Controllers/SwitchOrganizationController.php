<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\Request;

/** Change la communauté dans laquelle l'utilisateur travaille. */
class SwitchOrganizationController extends Controller
{
    public function __invoke(Request $request, Organization $organization)
    {
        abort_unless($request->user()->canAccess($organization), 403);

        $request->user()->forceFill(['current_organization_id' => $organization->id])->saveQuietly();

        return redirect()->route('dashboard')->with('status', __('Vous travaillez maintenant dans : :name', ['name' => $organization->name]));
    }
}
