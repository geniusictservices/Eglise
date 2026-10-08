<?php

namespace App\Http\Controllers;

use App\Models\IssuedDocument;
use App\Support\QrCode;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

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

    /** La photo figée d'un document, pour la comparer avec le papier présenté (pas pour un document annulé). */
    public function photo(string $token)
    {
        $document = IssuedDocument::withoutOrganizationScope()->where('token', $token)->whereNull('cancelled_at')->firstOrFail();
        abort_unless($document->photo_path && Storage::disk('local')->exists($document->photo_path), 404);

        return Storage::disk('local')->response($document->photo_path, null, ['Cache-Control' => 'private, max-age=3600', 'X-Robots-Tag' => 'noindex']);
    }

    /** Page publique ouverte en scannant le QR code : seulement de quoi authentifier le papier. */
    public function verify(string $token)
    {
        $document = IssuedDocument::withoutOrganizationScope()->with(['organization', 'type'])->where('token', $token)->first();

        return response()->view('documents.verify', ['document' => $document], $document ? 200 : 404)
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
