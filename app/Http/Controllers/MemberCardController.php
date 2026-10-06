<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Support\QrCode;
use Illuminate\Support\Facades\Gate;

/** Carte de membre à imprimer (recto et verso), avec son QR code de vérification. */
class MemberCardController extends Controller
{
    public function __invoke(int $id)
    {
        $member = Member::withoutOrganizationScope()->with(['organization', 'status'])->findOrFail($id);
        $current = current_organization();

        abort_unless($member->organization_id === $current->id || in_array($current->id, $member->organization->ancestorIds(), true), 404);
        abort_unless(Gate::allows('members.view', $member->organization), 403);

        $url = route('cards.verify', $member->cardToken());

        return view('members.card', [
            'member' => $member,
            'organization' => $member->organization,
            'root' => $member->organization->root(),
            'qr' => QrCode::svg($url, 220),
            'url' => $url,
            'withPhoto' => request()->boolean('photo', true),
        ]);
    }
}
