<?php

namespace App\Http\Controllers;

use App\Models\LegalDocument;
use Illuminate\Support\Str;

/** Conditions d'utilisation et confidentialité : la version publiée dans l'espace Genius ICT. */
class LegalController extends Controller
{
    public function terms()
    {
        return $this->page('terms');
    }

    public function privacy()
    {
        return $this->page('privacy');
    }

    private function page(string $key)
    {
        $document = LegalDocument::current($key);
        abort_unless($document, 404);

        return view('legal.page', [
            'title' => $document->title,
            'html' => Str::markdown($document->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
            'document' => $document,
        ]);
    }
}
