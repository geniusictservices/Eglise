<?php

namespace App\Http\Controllers;

use App\Models\Member;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/** Sert la photo d'un membre aux seuls utilisateurs autorisés à voir sa fiche. */
class MemberPhotoController extends Controller
{
    public function __invoke(int $member)
    {
        $member = Member::withoutOrganizationScope()->with('organization')->findOrFail($member);
        $current = current_organization();

        abort_unless($member->organization_id === $current->id || in_array($current->id, $member->organization->ancestorIds(), true), 404);
        // Chacun voit sa propre carte et sa photo depuis son espace membre.
        abort_unless(($member->user_id && $member->user_id === auth()->id()) || Gate::allows('members.view', $member->organization), 403);
        abort_unless($member->photo_path && Storage::disk('local')->exists($member->photo_path), 404);

        return Storage::disk('local')->response($member->photo_path, null, [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
