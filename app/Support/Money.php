<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Formatage des montants à la française : 1 250,50 $ · 2 850 FC */
class Money
{
    public static function format(BigDecimal|string|int|float|null $amount, string $currency): string
    {
        $decimals = config("waumini.currencies.{$currency}.decimals", 2);
        $symbol = config("waumini.currencies.{$currency}.symbol", $currency);
        $value = BigDecimal::of((string) ($amount ?? 0))->toScale($decimals, RoundingMode::HalfUp);

        $formatted = number_format((float) (string) $value, $decimals, ',', "\u{202F}");

        return $formatted."\u{00A0}".$symbol;
    }

    /** Taux affiché : 1 $ = 2 850 FC */
    public static function rate(BigDecimal|string $rate, string $currency): string
    {
        $value = BigDecimal::of((string) $rate)->strippedOfTrailingZeros();
        $decimals = max(0, $value->getScale());

        return '1 $ = '.number_format((float) (string) $value, min($decimals, 4), ',', "\u{202F}")."\u{00A0}".config("waumini.currencies.{$currency}.symbol", $currency);
    }
}
