<?php

namespace Tests\Unit;

use App\Support\BrandPalette;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BrandPaletteTest extends TestCase
{
    public function test_contrast_ratio_between_black_and_white_is_the_maximum(): void
    {
        $this->assertEqualsWithDelta(21.0, BrandPalette::contrastRatio('#000000', '#ffffff'), 0.01);
    }

    public function test_contrast_ratio_of_a_colour_against_itself_is_one(): void
    {
        $this->assertEqualsWithDelta(1.0, BrandPalette::contrastRatio('#467235', '#467235'), 0.01);
    }

    public function test_contrast_ratio_is_symmetric(): void
    {
        $this->assertSame(
            BrandPalette::contrastRatio('#467235', '#ffffff'),
            BrandPalette::contrastRatio('#ffffff', '#467235'),
        );
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function brandColours(): array
    {
        return [
            'default forest green' => ['#467235', true],
            'pure black' => ['#000000', true],
            'amber accent' => ['#ffbf00', false],
            'white' => ['#ffffff', false],
        ];
    }

    #[DataProvider('brandColours')]
    public function test_meets_contrast_against_white_for_white_text(string $hex, bool $expected): void
    {
        $this->assertSame($expected, BrandPalette::meetsContrast($hex, '#ffffff'));
    }

    public function test_contrast_ratio_accepts_hex_values_without_alpha(): void
    {
        $this->assertEqualsWithDelta(
            BrandPalette::contrastRatio('#467235', '#ffffff'),
            BrandPalette::contrastRatio('467235', 'ffffff'),
            0.001,
        );
    }
}
