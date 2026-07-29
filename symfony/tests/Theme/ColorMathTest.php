<?php
namespace App\Tests\Theme;

use App\Theme\ColorMath;
use PHPUnit\Framework\TestCase;

final class ColorMathTest extends TestCase
{
    public function testIndigoHslToRgbTupleMatchesTablerFormat(): void
    {
        // #6366f1 (Tabler indigo) = hsl(239, 84%, 66.8%)
        self::assertSame('99, 102, 241', ColorMath::hslToRgbTuple(239, 84, 66.8));
    }

    public function testIndigoHslToHex(): void
    {
        self::assertSame('#6366f1', ColorMath::hslToHex(239, 84, 66.8));
    }

    public function testPureBlackAndWhite(): void
    {
        self::assertSame('#000000', ColorMath::hslToHex(0, 0, 0));
        self::assertSame('#ffffff', ColorMath::hslToHex(0, 0, 100));
    }

    public function testClampBounds(): void
    {
        self::assertSame(0.0, ColorMath::clamp(-10));
        self::assertSame(100.0, ColorMath::clamp(140));
        self::assertSame(42.0, ColorMath::clamp(42));
    }
}
