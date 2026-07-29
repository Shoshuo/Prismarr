<?php
namespace App\Theme;

/**
 * Pure HSL → RGB/hex color math. No state, no dependencies — kept separate
 * from ThemeService so the conversion can be unit-tested in isolation and
 * reused if a custom-theme editor lands later.
 */
final class ColorMath
{
    public static function clamp(float $v, float $min = 0.0, float $max = 100.0): float
    {
        return max($min, min($max, $v));
    }

    /** @return array{0:int,1:int,2:int} */
    private static function hslToRgb(int $h, float $s, float $l): array
    {
        $h = (($h % 360) + 360) % 360;
        $s = self::clamp($s) / 100.0;
        $l = self::clamp($l) / 100.0;

        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60.0, 2) - 1));
        $m = $l - $c / 2;

        [$r, $g, $b] = match (true) {
            $h < 60  => [$c, $x, 0.0],
            $h < 120 => [$x, $c, 0.0],
            $h < 180 => [0.0, $c, $x],
            $h < 240 => [0.0, $x, $c],
            $h < 300 => [$x, 0.0, $c],
            default  => [$c, 0.0, $x],
        };

        return [
            (int) round(($r + $m) * 255),
            (int) round(($g + $m) * 255),
            (int) round(($b + $m) * 255),
        ];
    }

    public static function hslToHex(int $h, float $s, float $l): string
    {
        [$r, $g, $b] = self::hslToRgb($h, $s, $l);
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    public static function hslToRgbTuple(int $h, float $s, float $l): string
    {
        [$r, $g, $b] = self::hslToRgb($h, $s, $l);
        return "$r, $g, $b";
    }
}
