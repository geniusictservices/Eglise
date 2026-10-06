<?php

namespace App\Support;

use App\Models\Organization;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Logo d'une communauté : réduit à 512 px au plus, transparence conservée (PNG). */
class OrganizationLogo
{
    public const MAX = 512;

    public static function store(string $source, Organization $organization): string
    {
        $image = @imagecreatefromstring((string) file_get_contents($source));
        if (! $image) {
            throw new \InvalidArgumentException(__('Image illisible.'));
        }

        $w = imagesx($image);
        $h = imagesy($image);
        $scale = min(1, self::MAX / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $out = imagecreatetruecolor($nw, $nh);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $image, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagepng($out, null, 8);
        $path = 'logos/'.$organization->id.'-'.Str::random(6).'.png';
        Storage::disk('local')->put($path, (string) ob_get_clean());

        return $path;
    }

    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }
}
