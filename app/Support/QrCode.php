<?php

namespace App\Support;

use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/** QR codes en SVG, à intégrer directement dans les pages et documents imprimés. */
class QrCode
{
    public static function svg(string $content, int $size = 160): string
    {
        $style = new RendererStyle($size, 1, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(24, 26, 66)));
        $svg = (new Writer(new ImageRenderer($style, new SvgImageBackEnd)))->writeString($content);

        return preg_replace('/^<\?xml.*?\?>\s*/', '', $svg);
    }
}
