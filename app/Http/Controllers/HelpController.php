<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Centre d'aide : affiche le manuel d'utilisation (docs/manuel/*.md)
 * dans l'application, avec ses captures d'écran.
 */
class HelpController extends Controller
{
    private const DIR = 'docs/manuel';

    public function show(?string $chapter = null)
    {
        $file = $chapter ? base_path(self::DIR."/{$chapter}.md") : base_path(self::DIR.'/README.md');
        abort_unless($chapter === null || preg_match('/^[a-z0-9-]+$/', $chapter), 404);
        abort_unless(File::exists($file), 404);

        $markdown = File::get($file);
        $title = Str::of($markdown)->match('/^# (.+)$/m')->replaceMatches('/^\d+\.\s*/', '')->toString() ?: __('Aide');

        // Liens entre chapitres et images vers les routes de l'application.
        $markdown = preg_replace_callback('/\]\(([a-z0-9-]+)\.md(#[^)]*)?\)/', fn ($m) => ']('.route('help.show', $m[1]).($m[2] ?? '').')', $markdown);
        $markdown = preg_replace('/\]\(README\.md\)/', ']('.route('help.index').')', $markdown);
        $markdown = preg_replace('/\]\(\.\.\/[^)]+\)/', '](#)', $markdown);
        $markdown = preg_replace_callback('/src="captures\/(bureau|mobile)\/([a-z0-9-]+\.png)"/', fn ($m) => 'src="'.route('help.capture', [$m[1], $m[2]]).'" loading="lazy"', $markdown);

        $html = Str::markdown($markdown, ['html_input' => 'allow', 'allow_unsafe_links' => false]);
        // Chaque capture s'ouvre en grand quand on la touche.
        $html = preg_replace('/<img src="([^"]+)"([^>]*)>/', '<a href="$1" target="_blank" rel="noopener" class="manuel-capture"><img src="$1"$2></a>', $html);
        $html = preg_replace_callback('/<h([23])>(.+?)<\/h\1>/', fn ($m) => '<h'.$m[1].' id="'.self::anchor($m[2]).'">'.$m[2].'</h'.$m[1].'>', $html);

        return view('help.show', [
            'title' => $title,
            'html' => $html,
            'chapter' => $chapter,
            'chapters' => $this->chapters(),
        ]);
    }

    /** Ancre au format GitHub, pour que les liens du manuel marchent aux deux endroits. */
    private static function anchor(string $heading): string
    {
        $text = mb_strtolower(html_entity_decode(strip_tags($heading), ENT_QUOTES | ENT_HTML5));

        return str_replace(' ', '-', preg_replace('/[^\p{L}\p{N}\- ]/u', '', $text));
    }

    public function capture(string $device, string $file)
    {
        abort_unless(in_array($device, ['bureau', 'mobile'], true) && preg_match('/^[a-z0-9-]+\.png$/', $file), 404);
        $path = base_path(self::DIR."/captures/{$device}/{$file}");
        abort_unless(File::exists($path), 404);

        return response()->file($path, ['Cache-Control' => 'public, max-age=604800']);
    }

    /** @return array<string, string> slug => titre */
    private function chapters(): array
    {
        return collect(File::files(base_path(self::DIR)))
            ->filter(fn ($f) => $f->getExtension() === 'md' && $f->getFilename() !== 'README.md')
            ->sortBy(fn ($f) => $f->getFilename() === 'faq.md' ? 'zz' : $f->getFilename())
            ->mapWithKeys(fn ($f) => [
                $f->getBasename('.md') => Str::of(File::get($f->getPathname()))->match('/^# (.+)$/m')->toString(),
            ])
            ->all();
    }
}
