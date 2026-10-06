<?php

namespace App\Support;

/**
 * Couleurs de l'espace d'une communauté.
 *
 * La communauté choisit une couleur principale et une couleur d'accent ;
 * on en déduit les nuances utilisées par l'interface (variables CSS
 * --color-ink-* et --color-ochre-*) et la couleur du texte posé sur
 * l'accent, pour qu'il reste lisible.
 */
class Theme
{
    public const DEFAULT = 'wax';

    /** Palettes prêtes à l'emploi. */
    public const PRESETS = [
        'wax' => ['name' => 'Wax indigo', 'primary' => '#2C2F6B', 'accent' => '#E39B2C'],
        'lac' => ['name' => 'Lac Kivu', 'primary' => '#0F5468', 'accent' => '#E39B2C'],
        'foret' => ['name' => 'Forêt', 'primary' => '#1E5B3A', 'accent' => '#E2B33C'],
        'bordeaux' => ['name' => 'Bordeaux', 'primary' => '#6B1F2E', 'accent' => '#E3A93C'],
        'royal' => ['name' => 'Royal', 'primary' => '#4A2A78', 'accent' => '#E0B23A'],
        'terre' => ['name' => 'Terre', 'primary' => '#5A3A22', 'accent' => '#D98A2B'],
        'nuit' => ['name' => 'Nuit', 'primary' => '#1B2A4A', 'accent' => '#3AA6C9'],
    ];

    public function __construct(
        public readonly string $primary,
        public readonly string $accent,
        public readonly bool $pattern = true,
    ) {}

    public static function default(): self
    {
        return new self(self::PRESETS[self::DEFAULT]['primary'], self::PRESETS[self::DEFAULT]['accent']);
    }

    /** @param array{preset?: string, primary?: string, accent?: string, pattern?: bool}|null $settings */
    public static function fromSettings(?array $settings): self
    {
        if (! $settings) {
            return self::default();
        }

        $preset = self::PRESETS[$settings['preset'] ?? ''] ?? null;

        return new self(
            self::validHex($settings['primary'] ?? null) ?? $preset['primary'] ?? self::PRESETS[self::DEFAULT]['primary'],
            self::validHex($settings['accent'] ?? null) ?? $preset['accent'] ?? self::PRESETS[self::DEFAULT]['accent'],
            $settings['pattern'] ?? true,
        );
    }

    public function isDefault(): bool
    {
        return strcasecmp($this->primary, self::PRESETS[self::DEFAULT]['primary']) === 0
            && strcasecmp($this->accent, self::PRESETS[self::DEFAULT]['accent']) === 0;
    }

    /** Variables CSS à poser sur :root. */
    public function variables(): array
    {
        $p = $this->primary;
        $a = $this->accent;

        return [
            '--color-ink-50' => self::mix($p, '#FFFFFF', 0.93),
            '--color-ink-100' => self::mix($p, '#FFFFFF', 0.85),
            '--color-ink-200' => self::mix($p, '#FFFFFF', 0.70),
            '--color-ink-300' => self::mix($p, '#FFFFFF', 0.50),
            '--color-ink-400' => self::mix($p, '#FFFFFF', 0.30),
            '--color-ink-500' => self::mix($p, '#FFFFFF', 0.15),
            '--color-ink-600' => self::mix($p, '#FFFFFF', 0.07),
            '--color-ink-700' => strtoupper($p),
            '--color-ink-800' => self::mix($p, '#000000', 0.18),
            '--color-ink-900' => self::mix($p, '#000000', 0.38),
            '--color-ochre-50' => self::mix($a, '#FFFFFF', 0.92),
            '--color-ochre-100' => self::mix($a, '#FFFFFF', 0.78),
            '--color-ochre-300' => self::mix($a, '#FFFFFF', 0.45),
            '--color-ochre-500' => strtoupper($a),
            '--color-ochre-600' => self::mix($a, '#000000', 0.12),
            '--color-ochre-700' => self::mix($a, '#000000', 0.40),
            '--color-on-accent' => self::readableOn($a),
        ];
    }

    public function css(): string
    {
        $vars = collect($this->variables())->map(fn ($v, $k) => "{$k}:{$v}")->implode(';');

        return ":root{{$vars}}";
    }

    /** Contraste (WCAG) entre deux couleurs. */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** Le texte blanc doit rester lisible sur la couleur principale. */
    public static function primaryIsReadable(string $hex): bool
    {
        return self::contrast($hex, '#FFFFFF') >= 4.5;
    }

    public static function validHex(?string $hex): ?string
    {
        return $hex && preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) ? strtoupper($hex) : null;
    }

    private static function readableOn(string $hex): string
    {
        return self::contrast($hex, '#2A1B04') >= self::contrast($hex, '#FFFFFF') ? '#2A1B04' : '#FFFFFF';
    }

    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private static function mix(string $from, string $to, float $amount): string
    {
        [$r1, $g1, $b1] = self::rgb($from);
        [$r2, $g2, $b2] = self::rgb($to);

        return sprintf('#%02X%02X%02X',
            round($r1 + ($r2 - $r1) * $amount),
            round($g1 + ($g2 - $g1) * $amount),
            round($b1 + ($b2 - $b1) * $amount));
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(function ($c) {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
