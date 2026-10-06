<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use Illuminate\Support\Facades\Gate;

/** Le procès-verbal d'une réunion, prêt à imprimer et à signer. */
class MeetingPrintController extends Controller
{
    public function __invoke(Meeting $meeting)
    {
        abort_unless(Gate::any(['meetings.manage', 'planning.view']), 403);
        $meeting->load(['department', 'participants.member', 'decisions', 'organization']);

        return view('meetings.print', [
            'm' => $meeting,
            'organization' => $meeting->organization,
            'identity' => $meeting->organization->documentIdentity(),
        ]);
    }
}
