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
        $html = $this->render($markdown, fn (string $slug, string $hash) => route('help.show', $slug).$hash);

        return view('help.show', [
            'title' => $title,
            'html' => $html,
            'chapter' => $chapter,
            'chapters' => $this->chapters(),
        ]);
    }

    /** Le manuel entier sur une page, dans l'ordre du sommaire : à imprimer ou à enregistrer en PDF. */
    public function printable()
    {
        $readme = File::get(base_path(self::DIR.'/README.md'));
        preg_match_all('/\]\(([a-z0-9-]+)\.md\)/', $readme, $m);
        $order = collect($m[1])->unique()->filter(fn ($slug) => File::exists(base_path(self::DIR."/{$slug}.md")))->values();
        $link = fn (string $slug, string $hash) => $hash !== '' ? $hash : '#chapitre-'.$slug;

        return view('help.print', [
            'intro' => $this->render(preg_replace('/^## Sommaire.*?(?=^## )/ms', '', $readme), $link),
            'chapters' => $order->map(fn ($slug) => [
                'slug' => $slug,
                'title' => Str::of(File::get(base_path(self::DIR."/{$slug}.md")))->match('/^# (.+)$/m')->toString(),
                'html' => str_replace(' loading="lazy"', '', $this->render(File::get(base_path(self::DIR."/{$slug}.md")), $link)),
            ]),
        ]);
    }

    /** Markdown du manuel vers HTML : liens entre chapitres, captures servies par l'application, ancres. */
    private function render(string $markdown, \Closure $link): string
    {
        $markdown = preg_replace_callback('/\]\(([a-z0-9-]+)\.md(#[^)]*)?\)/', fn ($m) => ']('.$link($m[1], $m[2] ?? '').')', $markdown);
        $markdown = preg_replace('/\]\(README\.md\)/', ']('.route('help.index').')', $markdown);
        $markdown = preg_replace('/\]\(\.\.\/[^)]+\)/', '](#)', $markdown);
        $markdown = preg_replace_callback('/src="captures\/(bureau|mobile)\/([a-z0-9-]+\.png)"/', fn ($m) => 'src="'.route('help.capture', [$m[1], $m[2]]).'" loading="lazy"', $markdown);

        $html = Str::markdown($markdown, ['html_input' => 'allow', 'allow_unsafe_links' => false]);
        // Chaque capture s'ouvre en grand quand on la touche.
        $html = preg_replace('/<img src="([^"]+)"([^>]*)>/', '<a href="$1" target="_blank" rel="noopener" class="manuel-capture"><img src="$1"$2></a>', $html);

        return preg_replace_callback('/<h([23])>(.+?)<\/h\1>/', fn ($m) => '<h'.$m[1].' id="'.self::anchor($m[2]).'">'.$m[2].'</h'.$m[1].'>', $html);
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
        // Dans l'ordre du sommaire, qui range les chapitres par thème ; un chapitre absent du sommaire va à la fin.
        preg_match_all('/\]\(([a-z0-9-]+)\.md\)/', File::get(base_path(self::DIR.'/README.md')), $m);
        $order = array_flip(array_values(array_unique($m[1])));

        return collect(File::files(base_path(self::DIR)))
            ->filter(fn ($f) => $f->getExtension() === 'md' && $f->getFilename() !== 'README.md')
            ->sortBy(fn ($f) => [$order[$f->getBasename('.md')] ?? PHP_INT_MAX, $f->getFilename()])
            ->mapWithKeys(fn ($f) => [
                $f->getBasename('.md') => Str::of(File::get($f->getPathname()))->match('/^# (.+)$/m')->toString(),
            ])
            ->all();
    }
}
