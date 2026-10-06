<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Services\ExchangeRateService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ExchangeRateTest extends TestCase
{
    use RefreshDatabase;

    public function test_amounts_convert_to_dollars_with_the_rate_of_the_day(): void
    {
        $eglise = $this->createCommunity();
        $rates = app(ExchangeRateService::class);
        $rates->setRate($eglise, 'CDF', '2850');

        $this->assertSame('100.00', (string) $rates->toBase($eglise, 285000, 'CDF'));
        $this->assertSame('285000', (string) $rates->fromBase($eglise, 100, 'CDF'));
        $this->assertSame('42.50', (string) $rates->toBase($eglise, '42.50', 'USD'));
    }

    public function test_the_rate_used_is_the_latest_one_on_or_before_the_date(): void
    {
        $eglise = $this->createCommunity();
        $rates = app(ExchangeRateService::class);
        $rates->setRate($eglise, 'CDF', '2800', now()->subDays(3));
        $rates->setRate($eglise, 'CDF', '2900', now());

        $this->assertSame('2800', (string) $rates->rate($eglise, 'CDF', now()->subDay())->strippedOfTrailingZeros());
        $this->assertSame('2900', (string) $rates->rate($eglise, 'CDF')->strippedOfTrailingZeros());
        $this->assertNull($rates->rate($eglise, 'CDF', now()->subDays(10)));
    }

    public function test_a_parish_without_a_rate_uses_its_parent_rate(): void
    {
        $siege = $this->createCommunity();
        $paroisse = $this->createChild($siege, 'Paroisse');
        $rates = app(ExchangeRateService::class);
        $rates->setRate($siege, 'CDF', '2850');

        $this->assertSame('2850', (string) $rates->rate($paroisse, 'CDF')->strippedOfTrailingZeros());

        $rates->setRate($paroisse, 'CDF', '2875');
        $this->assertSame('2875', (string) $rates->rate($paroisse, 'CDF')->strippedOfTrailingZeros());
    }

    public function test_conversion_without_any_rate_fails_clearly(): void
    {
        $eglise = $this->createCommunity();

        $this->expectException(RuntimeException::class);
        app(ExchangeRateService::class)->toBase($eglise, 1000, 'RWF');
    }

    public function test_rates_are_isolated_between_organizations(): void
    {
        $a = $this->createCommunity('A');
        $b = $this->createCommunity('B');
        $rates = app(ExchangeRateService::class);
        $rates->setRate($a, 'CDF', '2850');
        $rates->setRate($b, 'CDF', '2900');

        $this->inOrganization($a);
        $this->assertSame(['2850'], ExchangeRate::pluck('rate')->map(fn ($r) => rtrim(rtrim($r, '0'), '.'))->all());

        $this->inOrganization($b);
        $this->assertSame(1, ExchangeRate::count());
        $this->assertSame(2, ExchangeRate::withoutOrganizationScope()->count());
    }

    public function test_money_is_formatted_the_french_way(): void
    {
        $this->assertSame("1\u{202F}250,50\u{00A0}$", Money::format('1250.5', 'USD'));
        $this->assertSame("285\u{202F}000\u{00A0}FC", Money::format('285000', 'CDF'));
    }
}
