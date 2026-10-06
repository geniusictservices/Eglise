<?php

namespace App\Http\Controllers;

use App\Models\CollectionSheet;
use App\Services\Collections;
use Illuminate\Support\Facades\Gate;

/** Procès-verbal de la collecte, à imprimer et à faire signer par les compteurs. */
class CollectionPrintController extends Controller
{
    public function __invoke(CollectionSheet $sheet, Collections $collections)
    {
        abort_unless(Gate::allows('finance.view'), 403);
        $sheet->load(['account', 'lines.category', 'envelopes.member', 'envelopes.category', 'validator', 'organization']);

        return view('finances.collection-print', [
            'sheet' => $sheet,
            'organization' => $sheet->organization,
            'identity' => $sheet->organization->documentIdentity(),
            'summary' => $collections->summary($sheet),
            'canSeeNames' => Gate::allows('finance.contributions.view'),
        ]);
    }
}
