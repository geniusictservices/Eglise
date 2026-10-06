<?php

namespace Tests\Unit;

use App\Support\Phone;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhoneTest extends TestCase
{
    public static function numbers(): array
    {
        return [
            'format local' => ['0812345678', '+243812345678'],
            'avec espaces' => ['0812 345 678', '+243812345678'],
            'indicatif sans plus' => ['243812345678', '+243812345678'],
            'indicatif avec plus' => ['+243 81 234 5678', '+243812345678'],
            'préfixe international 00' => ['00243812345678', '+243812345678'],
            'numéro rwandais' => ['+250788123456', '+250788123456'],
            'sans zéro initial' => ['812345678', '+243812345678'],
        ];
    }

    #[DataProvider('numbers')]
    public function test_it_normalizes_phone_numbers(string $input, string $expected): void
    {
        $this->assertSame($expected, Phone::normalize($input));
    }

    public function test_it_rejects_invalid_numbers(): void
    {
        $this->assertNull(Phone::normalize('abc'));
        $this->assertNull(Phone::normalize('+12'));
        $this->assertNull(Phone::normalize(''));
    }

    public function test_it_formats_congolese_numbers(): void
    {
        $this->assertSame('+243 812 345 678', Phone::format('+243812345678'));
    }
}
