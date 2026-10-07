<?php

namespace App\Console\Commands;

use App\Support\QrCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Prépare le livret de présentation de Waumini (20 pages A5) pour les
 * responsables d'églises : la page HTML, ses captures en JPEG et ses polices,
 * dans un dossier autonome. Les PDF (lecture et impression en livret) se font
 * ensuite avec node scripts/livret/pdf.mjs {dossier}.
 */
class ExportBooklet extends Command
{
    protected $signature = 'waumini:livret {dossier : où écrire le livret}
        {--site= : adresse du site de Waumini (par défaut APP_URL)}
        {--telephone= : numéro d’appel et WhatsApp de Genius ICT (par défaut WAUMINI_CONTACT_PHONE)}';

    protected $description = 'Prépare le livret de présentation de Waumini (HTML, captures, polices)';

    /** Extraits découpés dans les captures : nom => [capture, x, y, largeur, hauteur]. */
    private const EXTRACTS = [
        'recu' => ['bureau/42-recu', 315, 101, 736, 472],
        'attestation' => ['bureau/90-document-imprime', 286, 93, 794, 727],
    ];

    public function handle(): int
    {
        $out = rtrim($this->argument('dossier'), '/');
        $site = rtrim($this->option('site') ?: config('app.url'), '/');
        if (str_contains($site, 'localhost') || str_contains($site, '127.0.0.1')) {
            $this->warn("L’adresse du site est {$site} : passez --site=https://… pour que le QR code fonctionne une fois imprimé.");
        }

        $count = 0;
        foreach (['bureau', 'mobile'] as $device) {
            File::ensureDirectoryExists("{$out}/captures/{$device}");
            foreach (File::files(base_path("docs/livret/captures/{$device}")) as $file) {
                $image = imagecreatefrompng($file->getPathname());
                $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
                imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
                imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
                imagejpeg($flat, "{$out}/captures/{$device}/".$file->getBasename('.png').'.jpg', 84);
                $count++;
            }
        }

        // Des extraits : le document lui-même, sans l'écran autour.
        File::ensureDirectoryExists("{$out}/captures/extraits");
        foreach (self::EXTRACTS as $name => [$source, $x, $y, $width, $height]) {
            $image = imagecrop(imagecreatefrompng(base_path("docs/livret/captures/{$source}.png")), compact('x', 'y', 'width', 'height'));
            imagejpeg($image, "{$out}/captures/extraits/{$name}.jpg", 88);
        }

        File::ensureDirectoryExists("{$out}/polices");
        $fonts = base_path('node_modules/@fontsource/lexend/files');
        foreach ([400, 500, 600, 700] as $weight) {
            File::copy("{$fonts}/lexend-latin-{$weight}-normal.woff2", "{$out}/polices/lexend-{$weight}.woff2");
        }
        File::copy("{$fonts}/lexend-latin-ext-400-normal.woff2", "{$out}/polices/lexend-ext-400.woff2");

        $html = view('livret.livret', [
            'logoWhite' => File::get(base_path('branding/logo/waumini-vertical-blanc.svg')),
            'logoWhiteHorizontal' => File::get(base_path('branding/logo/waumini-horizontal-blanc.svg')),
            'qr' => QrCode::svg("{$site}/demo", 300),
            'siteLabel' => preg_replace('#^https?://#', '', $site),
            'phoneNumber' => $this->option('telephone') ?: config('waumini.contact.phone'),
            'email' => config('waumini.contact.email'),
            'packs' => config('waumini.packs'),
            'edition' => now()->translatedFormat('F Y'),
        ])->render();
        File::put("{$out}/livret.html", $html);

        $this->info("Livret préparé dans {$out}/livret.html ({$count} captures). PDF : node scripts/livret/pdf.mjs {$out}");

        return self::SUCCESS;
    }
}
