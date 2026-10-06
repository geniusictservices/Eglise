<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/**
 * Prépare le travail des traducteurs.
 *
 *   php artisan waumini:traductions sw            exporte les textes à traduire (CSV, ouvrable dans Excel)
 *   php artisan waumini:traductions sw --importer importe le CSV complété dans lang/sw.json
 *
 * Le français est la langue source : les textes de l'interface sont écrits
 * en français dans le code, et chaque langue les traduit dans lang/{code}.json.
 */
class Translations extends Command
{
    protected $signature = 'waumini:traductions {locale : sw, ln, kg ou lua} {--importer : Importe lang/a-traduire/{locale}.csv}';

    protected $description = 'Exporte ou importe les textes à traduire pour une langue';

    public function handle(): int
    {
        $locale = $this->argument('locale');

        if (! array_key_exists($locale, config('waumini.locales')) || $locale === 'fr') {
            $this->error('Langue inconnue. Choisissez parmi : '.implode(', ', array_diff(array_keys(config('waumini.locales')), ['fr'])));

            return self::FAILURE;
        }

        $jsonPath = lang_path("{$locale}.json");
        $csvPath = lang_path("a-traduire/{$locale}.csv");
        $existing = File::exists($jsonPath) ? json_decode(File::get($jsonPath), true) : [];

        if ($this->option('importer')) {
            return $this->import($csvPath, $jsonPath, $existing);
        }

        $strings = $this->collectStrings();
        File::ensureDirectoryExists(dirname($csvPath));
        $handle = fopen($csvPath, 'w');
        fwrite($handle, "\xEF\xBB\xBF"); // BOM : Excel reconnaît l'UTF-8
        fputcsv($handle, ['francais', 'traduction'], ';');
        $missing = 0;

        foreach ($strings as $string) {
            $translation = $existing[$string] ?? '';
            $missing += $translation === '' ? 1 : 0;
            fputcsv($handle, [$string, $translation], ';');
        }

        fclose($handle);
        $this->info(count($strings)." textes exportés dans {$csvPath} ({$missing} à traduire).");

        return self::SUCCESS;
    }

    private function import(string $csvPath, string $jsonPath, array $existing): int
    {
        if (! File::exists($csvPath)) {
            $this->error("Fichier introuvable : {$csvPath}");

            return self::FAILURE;
        }

        $handle = fopen($csvPath, 'r');
        $header = fgetcsv($handle, separator: ';');
        $count = 0;

        while (($row = fgetcsv($handle, separator: ';')) !== false) {
            [$source, $translation] = array_pad($row, 2, '');
            $source = ltrim($source, "\xEF\xBB\xBF");

            if (trim($translation) !== '') {
                $existing[$source] = trim($translation);
                $count++;
            }
        }

        fclose($handle);
        ksort($existing);
        File::put($jsonPath, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        $this->info("{$count} traductions importées dans {$jsonPath}.");

        return self::SUCCESS;
    }

    /** Cherche les __('…') et trans_choice('…') dans le code et les vues. */
    private function collectStrings(): array
    {
        $strings = [];
        $finder = (new Finder)->files()->in([app_path(), resource_path('views')])->name(['*.php']);

        foreach ($finder as $file) {
            preg_match_all("/(?:__|trans_choice)\\(\\s*'((?:[^'\\\\]|\\\\.)+)'/u", $file->getContents(), $matches);

            foreach ($matches[1] as $match) {
                $string = stripslashes($match);

                if (! str_starts_with($string, 'terms.')) {
                    $strings[$string] = true;
                }
            }
        }

        $strings = array_keys($strings);
        sort($strings);

        return $strings;
    }
}
