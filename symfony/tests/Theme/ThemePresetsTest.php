<?php
namespace App\Tests\Theme;

use App\Theme\ColorMath;
use App\Theme\ThemePresets;
use PHPUnit\Framework\TestCase;

final class ThemePresetsTest extends TestCase
{
    public function testDefaultKeyExists(): void
    {
        self::assertArrayHasKey(ThemePresets::DEFAULT_KEY, ThemePresets::PRESETS);
        self::assertSame('midnight', ThemePresets::DEFAULT_KEY);
    }

    public function testMidnightReproducesCurrentDarkBackground(): void
    {
        $bg = ThemePresets::PRESETS['midnight']['bg'];
        // Current look is #111111 ≈ hsl(0,0%,7%).
        self::assertSame('#111111', ColorMath::hslToHex($bg[0], $bg[1], $bg[2]));
        self::assertFalse(ThemePresets::PRESETS['midnight']['light']);
    }

    public function testEveryPresetHasCompleteShape(): void
    {
        foreach (ThemePresets::PRESETS as $key => $p) {
            foreach (['label_key', 'light', 'bg', 'primary', 'positive', 'negative', 'contrast', 'textSaturation'] as $field) {
                self::assertArrayHasKey($field, $p, "preset $key missing $field");
            }
            self::assertIsBool($p['light']);
            foreach (['bg', 'primary', 'positive', 'negative'] as $c) {
                self::assertCount(3, $p[$c], "preset $key.$c must be [H,S,L]");
            }
        }
    }

    public function testOptionLabelsMatchKeys(): void
    {
        self::assertSame(array_keys(ThemePresets::PRESETS), ThemePresets::keys());
        self::assertSame(
            array_keys(ThemePresets::PRESETS),
            array_keys(ThemePresets::optionLabels())
        );
    }
}
