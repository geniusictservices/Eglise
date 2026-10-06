<?php

namespace App\Http\Controllers;

use App\Models\IssuedDocument;
use App\Support\QrCode;
use Illuminate\Support\Facades\Gate;

class DocumentController extends Controller
{
    /** Le document à imprimer, avec son QR code. */
    public function print(IssuedDocument $document)
    {
        abort_unless(Gate::any(['documents.issue', 'registers.manage']), 403);
        $document->loadMissing(['organization', 'type']);

        return view('documents.print', [
            'document' => $document,
            'identity' => $document->organization->documentIdentity(),
            'qr' => QrCode::svg($document->verifyUrl(), 200),
        ]);
    }

    /** Page publique ouverte en scannant le QR code : seulement de quoi authentifier le papier. */
    public function verify(string $token)
    {
        $document = IssuedDocument::withoutOrganizationScope()->with(['organization', 'type'])->where('token', $token)->first();

        return response()->view('documents.verify', ['document' => $document], $document ? 200 : 404)
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
