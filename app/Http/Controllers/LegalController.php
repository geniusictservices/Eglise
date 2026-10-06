<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/** Conditions d'utilisation et confidentialité, rédigées dans docs/legal. */
class LegalController extends Controller
{
    public function terms()
    {
        return $this->page('conditions', __('Conditions d’utilisation'));
    }

    public function privacy()
    {
        return $this->page('confidentialite', __('Confidentialité'));
    }

    private function page(string $file, string $title)
    {
        $html = Str::markdown(File::get(base_path("docs/legal/{$file}.md")));

        return view('legal.page', compact('html', 'title'));
    }
}
