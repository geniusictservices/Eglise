<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Exporte le manuel d'utilisation (docs/manuel) en site web statique :
 * une page de lecture, un fragment HTML par chapitre et les captures en
 * JPEG, légères pour les téléphones. Le site se lit sans Waumini, hors
 * de l'application (hébergement simple, GitHub Pages, partage en ligne).
 */
class ExportManual extends Command
{
    protected $signature = 'waumini:manuel-web {dossier : où écrire le site} {--fragment : page sans en-tête HTML, pour une publication en Artifact}';

    protected $description = 'Exporte le manuel d’utilisation en site web statique';

    private const SOURCE = 'docs/manuel';

    public function handle(): int
    {
        $out = rtrim($this->argument('dossier'), '/');
        File::ensureDirectoryExists("{$out}/chapitres");
        $readme = File::get(base_path(self::SOURCE.'/README.md'));

        // Le sommaire : ses rubriques et ses chapitres, dans l'ordre et avec la numérotation du README.
        $groups = [];
        foreach (preg_split('/\R/', Str::between($readme, '## Sommaire', '## Qui fait quoi')) as $line) {
            if (preg_match('/^### (.+)$/', $line, $m)) {
                $groups[] = ['title' => trim($m[1]), 'chapters' => []];
            } elseif (preg_match('/^(?:(\d+)\.|-)\s+\[(.+?)\]\(([a-z0-9-]+)\.md\)/', $line, $m) && $groups) {
                $groups[count($groups) - 1]['chapters'][] = ['slug' => $m[3], 'num' => $m[1] !== '' ? $m[1] : null, 'title' => $m[2]];
            }
        }

        $slugs = collect($groups)->pluck('chapters')->flatten(1)->pluck('slug');
        $intro = preg_replace('/^## Sommaire.*?(?=^## )/ms', '', $readme);
        File::put("{$out}/chapitres/accueil.html", $this->render($intro));
        foreach ($slugs as $slug) {
            $markdown = File::get(base_path(self::SOURCE."/{$slug}.md"));
            // Le numéro vient du sommaire : on le retire du titre du chapitre.
            $markdown = preg_replace('/^# \d+\.\s*/m', '# ', $markdown, 1);
            File::put("{$out}/chapitres/{$slug}.html", $this->render($markdown));
        }
        File::put("{$out}/sommaire.json", json_encode(['groups' => $groups, 'date' => now()->translatedFormat('j F Y')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $count = 0;
        foreach (['bureau', 'mobile'] as $device) {
            File::ensureDirectoryExists("{$out}/captures/{$device}");
            foreach (File::files(base_path(self::SOURCE."/captures/{$device}")) as $file) {
                if ($file->getExtension() !== 'png') {
                    continue;
                }
                $image = imagecreatefrompng($file->getPathname());
                $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
                imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
                imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
                imagejpeg($flat, "{$out}/captures/{$device}/".$file->getBasename('.png').'.jpg', 78);
                $count++;
            }
        }

        // La page de lecture. Un hébergement classique a besoin de l'en-tête HTML complet ; la publication en Artifact l'ajoute elle-même.
        $page = File::get(resource_path('manuel-web/index.html'));
        File::put("{$out}/index.html", $this->option('fragment') ? $page
            : "<!DOCTYPE html>\n<html lang=\"fr\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1, viewport-fit=cover\">\n".str_replace('<style>', "<style>\nbody { margin: 0; }", $page)."\n</html>\n");

        $this->info("Manuel exporté dans {$out} : {$slugs->count()} chapitres, {$count} captures.");

        return self::SUCCESS;
    }

    /** Markdown vers HTML : liens entre chapitres en #c-…, captures en JPEG relatives, tableaux défilants. */
    private function render(string $markdown): string
    {
        $markdown = preg_replace_callback('/\]\(([a-z0-9-]+)\.md(#[^)]*)?\)/', fn ($m) => '](#c-'.$m[1].(isset($m[2]) ? '--'.ltrim($m[2], '#') : '').')', $markdown);
        $markdown = preg_replace('/\]\(README\.md\)/', '](#c-accueil)', $markdown);
        $markdown = preg_replace('/\]\(\.\.\/[^)]+\)/', '](#c-accueil)', $markdown);
        $markdown = preg_replace('/src="captures\/(bureau|mobile)\/([a-z0-9-]+)\.png"/', 'src="captures/$1/$2.jpg" loading="lazy"', $markdown);

        $html = Str::markdown($markdown, ['html_input' => 'allow', 'allow_unsafe_links' => false]);
        $html = preg_replace_callback('/<h([23])>(.+?)<\/h\1>/', fn ($m) => '<h'.$m[1].' id="'.self::anchor($m[2]).'">'.$m[2].'</h'.$m[1].'>', $html);

        // Les captures (ordinateur et téléphone côte à côte) et les tableaux de texte n'ont pas la même mise en page.
        return preg_replace_callback('/<table>.*?<\/table>/s', fn ($m) => str_contains($m[0], '<img')
            ? '<div class="captures">'.strip_tags($m[0], '<img>').'</div>'
            : '<div class="tableau">'.$m[0].'</div>', $html);
    }

    private static function anchor(string $heading): string
    {
        $text = mb_strtolower(html_entity_decode(strip_tags($heading), ENT_QUOTES | ENT_HTML5));

        return str_replace(' ', '-', preg_replace('/[^\p{L}\p{N}\- ]/u', '', $text));
    }
}
