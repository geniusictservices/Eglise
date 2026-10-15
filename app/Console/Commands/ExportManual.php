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
    protected $signature = 'waumini:manuel-web {dossier : où écrire le site} {--fragment : page sans en-tête HTML, pour une publication en Artifact}
        {--telechargements= : dossier des archives et scripts SQL d’une livraison, ajoutés au manuel avec leur page}';

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
        if ($downloads = $this->option('telechargements')) {
            $groups[] = ['title' => 'Équipe Genius ICT', 'chapters' => [['slug' => 'telechargements', 'num' => null, 'title' => 'Téléchargements : installer et mettre à jour']]];
            File::put("{$out}/chapitres/telechargements.html", $this->downloads(rtrim($downloads, '/'), $out));
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

    /** Ce que chaque fichier d'une livraison permet de faire, d'après son nom. */
    private const DOWNLOADS = [
        '/installation.*\.zip$/' => 'Première installation : le code, les dépendances PHP, l’interface compilée et les captures du manuel.',
        '/code-seul.*\.zip$/' => 'Mise à jour d’un serveur dont les dépendances n’ont pas changé de version : seulement le code de Waumini. Le dossier vendor du serveur reste en place.',
        '/mise-a-jour.*\.zip$/' => 'Mise à jour avec les dépendances PHP, sans les captures du manuel déjà présentes sur le serveur.',
        '/base-vide.*\.sql$/' => 'Base de données vide, toutes les tables créées : première installation sans terminal. Dans phpMyAdmin, onglet Importer, une seule fois, dans une base vide.',
        '/mise-a-jour-base.*\.sql$/' => 'Mise à jour d’une base déjà en service : à importer une seule fois, après une sauvegarde. Le fichier dit en tête depuis quelle version il part.',
    ];

    /** La page des téléchargements : les fichiers d'une livraison, copiés à côté du manuel. */
    private function downloads(string $source, string $out): string
    {
        File::ensureDirectoryExists("{$out}/telechargements");
        $rows = '';
        $files = collect(File::files($source))->filter(fn ($f) => in_array($f->getExtension(), ['zip', 'sql'], true))->sortBy(fn ($f) => $f->getFilename());
        foreach ($files as $file) {
            $name = $file->getFilename();
            $use = collect(self::DOWNLOADS)->first(fn ($text, $pattern) => preg_match($pattern, $name)) ?? '';
            File::copy($file->getPathname(), "{$out}/telechargements/{$name}");
            $size = $file->getSize() >= 1048576 ? number_format($file->getSize() / 1048576, 1, ',', ' ').' Mo' : number_format($file->getSize() / 1024, 0, ',', ' ').' Ko';
            $rows .= '<tr><td><a href="telechargements/'.e($name).'" download>'.e($name).'</a></td><td>'.e($use).'</td><td>'.$size.'</td></tr>';
        }
        $complete = $files->contains(fn ($f) => str_contains($f->getFilename(), 'installation'));

        return '<h1>Téléchargements : installer et mettre à jour</h1>'
            .'<blockquote><p>Cette page sert à l’équipe Genius ICT, qui installe Waumini sur un serveur. Une église n’a rien à télécharger : Waumini s’utilise dans le navigateur, ou s’installe sur le téléphone depuis le navigateur (<a href="#c-02-installer">chapitre 2</a>).</p></blockquote>'
            .'<p>Livraison du '.e(now()->translatedFormat('j F Y')).'.</p>'
            .'<div class="tableau"><table><thead><tr><th>Fichier</th><th>À quoi il sert</th><th>Taille</th></tr></thead><tbody>'.$rows.'</tbody></table></div>'
            .($complete ? '' : '<p>L’archive d’installation complète et la mise à jour avec dépendances sont trop lourdes pour cette page : demandez-les à l’équipe Genius ICT.</p>')
            .'<h2 id="mettre-à-jour">Mettre à jour un serveur</h2><ol>'
            .'<li>Faire une <strong>sauvegarde</strong> : espace Genius ICT, <strong>Sauvegardes</strong>, <strong>Sauvegarder maintenant</strong>.</li>'
            .'<li>Envoyer l’archive dans le dossier de Waumini avec le gestionnaire de fichiers de cPanel, puis l’<strong>extraire par-dessus</strong> l’installation. Le fichier <code>.env</code> et le dossier <code>storage</code> restent en place.</li>'
            .'<li>Mettre la base à jour : <code>php artisan migrate --force</code> dans le terminal de cPanel, ou, sans terminal, importer <strong>une seule fois</strong> le script SQL de mise à jour dans phpMyAdmin.</li>'
            .'<li>Vérifier que l’adresse <code>/up</code> répond « Application up » et que la connexion fonctionne.</li></ol>'
            .'<p>Le guide complet, avec la première installation, est dans l’archive : <code>docs/deploiement-lws.md</code>.</p>';
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
