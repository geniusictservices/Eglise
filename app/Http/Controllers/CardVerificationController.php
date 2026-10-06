<?php

namespace App\Http\Controllers;

use App\Models\Member;

/**
 * Page publique ouverte en scannant le QR code d'une carte de membre.
 * Elle ne montre que le strict nécessaire : nom, numéro, communauté, validité.
 */
class CardVerificationController extends Controller
{
    public function __invoke(string $token)
    {
        $member = Member::withoutOrganizationScope()->withTrashed()->with(['organization', 'status'])
            ->where('card_token', $token)->first();

        return response()->view('members.verify', ['member' => $member], $member ? 200 : 404)
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
