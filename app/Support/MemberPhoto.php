<?php

namespace App\Support;

use App\Models\Member;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Photos des membres : recadrées en carré de 480 px et gardées hors du
 * dossier public. Elles ne sont servies qu'aux utilisateurs autorisés.
 */
class MemberPhoto
{
    public const SIZE = 480;

    public static function store(string $source, Member $member): string
    {
        $path = 'members/'.$member->organization_id.'/'.$member->id.'-'.Str::random(8).'.jpg';
        Storage::disk('local')->put($path, self::squareJpeg($source));

        return $path;
    }

    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }

    private static function squareJpeg(string $source): string
    {
        $image = @imagecreatefromstring((string) file_get_contents($source));
        if (! $image) {
            throw new \InvalidArgumentException('Image illisible.');
        }

        $image = self::orient($image, $source);
        $w = imagesx($image);
        $h = imagesy($image);
        $side = min($w, $h);
        // Recadrage centré, un peu plus haut que le milieu pour garder le visage.
        $x = (int) (($w - $side) / 2);
        $y = (int) max(0, ($h - $side) * 0.35);

        $out = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagecopyresampled($out, $image, 0, 0, $x, $y, self::SIZE, self::SIZE, $side, $side);

        ob_start();
        imagejpeg($out, null, 82);

        return (string) ob_get_clean();
    }

    /** Remet droites les photos prises au téléphone (orientation EXIF). */
    private static function orient(\GdImage $image, string $source): \GdImage
    {
        $orientation = function_exists('exif_read_data') ? (@exif_read_data($source)['Orientation'] ?? 1) : 1;

        return match ((int) $orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
    }
}
