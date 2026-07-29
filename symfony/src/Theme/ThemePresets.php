<?php
namespace App\Theme;

/**
 * Curated, glance-style theme presets. Authored in HSL + multipliers
 * (background / primary / positive / negative + contrast & text-saturation
 * multipliers + light flag), exactly like glance's theme model. ThemeService
 * resolves these into concrete CSS variables.
 *
 * `midnight` is the default and reproduces Prismarr's pre-themes dark look
 * (#111 background, indigo primary) so upgrades are a visual no-op.
 */
final class ThemePresets
{
    public const DEFAULT_KEY = 'midnight';

    // glance HSL values are "H S L"; we store [H, S, L]. Where glance omits
    // positive/negative we fill a sensible green/red; omitted multipliers → 1.0.
    // Authored from https://github.com/glanceapp/glance/blob/main/docs/themes.md
    public const PRESETS = [
        'midnight' => [
            'label_key'      => 'admin.display.theme.preset.midnight',
            'light'          => false,
            'bg'             => [0, 0, 6.5],       // #111111
            'primary'        => [239, 84, 66.8],   // #6366f1 indigo
            'positive'       => [142, 71, 45],     // green
            'negative'       => [0, 84, 60],       // red
            'contrast'       => 1.0,
            'textSaturation' => 1.0,
        ],
        'nord' => [
            'label_key'      => 'admin.display.theme.preset.nord',
            'light'          => false,
            'bg'             => [220, 16, 22],
            'primary'        => [213, 32, 70],
            'positive'       => [92, 28, 65],
            'negative'       => [354, 42, 56],
            'contrast'       => 1.0,
            'textSaturation' => 1.0,
        ],
        'catppuccin_latte' => [
            'label_key'      => 'admin.display.theme.preset.catppuccin_latte',
            'light'          => true,
            'bg'             => [220, 23, 95],
            'primary'        => [220, 91, 54],
            'positive'       => [109, 58, 40],
            'negative'       => [347, 87, 44],
            'contrast'       => 1.0,
            'textSaturation' => 1.0,
        ],
        'catppuccin_frappe' => [
            'label_key'      => 'admin.display.theme.preset.catppuccin_frappe',
            'light'          => false,
            'bg'             => [229, 19, 23],
            'primary'        => [222, 74, 74],
            'positive'       => [96, 44, 68],
            'negative'       => [359, 68, 71],
            'contrast'       => 1.2,
            'textSaturation' => 1.0,
        ],
        'catppuccin_macchiato' => [
            'label_key'      => 'admin.display.theme.preset.catppuccin_macchiato',
            'light'          => false,
            'bg'             => [232, 23, 18],
            'primary'        => [220, 83, 75],
            'positive'       => [105, 48, 72],
            'negative'       => [351, 74, 73],
            'contrast'       => 1.2,
            'textSaturation' => 1.0,
        ],
        'catppuccin_mocha' => [
            'label_key'      => 'admin.display.theme.preset.catppuccin_mocha',
            'light'          => false,
            'bg'             => [240, 21, 15],
            'primary'        => [217, 92, 83],
            'positive'       => [115, 54, 76],
            'negative'       => [347, 70, 65],
            'contrast'       => 1.2,
            'textSaturation' => 1.0,
        ],
        'dracula' => [
            'label_key'      => 'admin.display.theme.preset.dracula',
            'light'          => false,
            'bg'             => [231, 15, 21],
            'primary'        => [265, 89, 79],
            'positive'       => [135, 94, 66],
            'negative'       => [0, 100, 67],
            'contrast'       => 1.2,
            'textSaturation' => 1.0,
        ],
        'gruvbox_dark' => [
            'label_key'      => 'admin.display.theme.preset.gruvbox_dark',
            'light'          => false,
            'bg'             => [0, 0, 16],
            'primary'        => [43, 59, 81],
            'positive'       => [61, 66, 44],
            'negative'       => [6, 96, 59],
            'contrast'       => 1.0,
            'textSaturation' => 1.0,
        ],
        'kanagawa_dark' => [
            'label_key'      => 'admin.display.theme.preset.kanagawa_dark',
            'light'          => false,
            'bg'             => [240, 13, 14],
            'primary'        => [51, 33, 68],
            'positive'       => [142, 40, 55],
            'negative'       => [358, 100, 68],
            'contrast'       => 1.2,
            'textSaturation' => 1.0,
        ],
        'teal_city' => [
            'label_key'      => 'admin.display.theme.preset.teal_city',
            'light'          => false,
            'bg'             => [225, 14, 15],
            'primary'        => [157, 47, 65],
            'positive'       => [142, 45, 55],
            'negative'       => [0, 75, 60],
            'contrast'       => 1.1,
            'textSaturation' => 1.0,
        ],
        'camouflage' => [
            'label_key'      => 'admin.display.theme.preset.camouflage',
            'light'          => false,
            'bg'             => [186, 21, 20],
            'primary'        => [97, 13, 80],
            'positive'       => [142, 40, 55],
            'negative'       => [0, 70, 60],
            'contrast'       => 1.2,
            'textSaturation' => 1.0,
        ],
        'tucan' => [
            'label_key'      => 'admin.display.theme.preset.tucan',
            'light'          => false,
            'bg'             => [50, 1, 6],
            'primary'        => [24, 97, 58],
            'positive'       => [142, 45, 55],
            'negative'       => [209, 88, 54],
            'contrast'       => 1.0,
            'textSaturation' => 1.0,
        ],
        'shades_of_purple' => [
            'label_key'      => 'admin.display.theme.preset.shades_of_purple',
            'light'          => false,
            'bg'             => [243, 33, 25],
            'primary'        => [50, 100, 49],
            'positive'       => [98, 82, 71],
            'negative'       => [12, 77, 52],
            'contrast'       => 1.2,
            'textSaturation' => 1.0,
        ],
        'neon_pink' => [
            'label_key'      => 'admin.display.theme.preset.neon_pink',
            'light'          => false,
            'bg'             => [240, 27, 11],
            'primary'        => [321, 100, 71],
            'positive'       => [165, 78, 51],
            'negative'       => [360, 100, 71],
            'contrast'       => 1.5,
            'textSaturation' => 1.0,
        ],
        'solarized_light' => [
            'label_key'      => 'admin.display.theme.preset.solarized_light',
            'light'          => true,
            'bg'             => [44, 87, 94],
            'primary'        => [205, 69, 49],
            'positive'       => [68, 100, 30],
            'negative'       => [1, 71, 52],
            'contrast'       => 1.0,
            'textSaturation' => 1.0,
        ],
        'peachy' => [
            'label_key'      => 'admin.display.theme.preset.peachy',
            'light'          => true,
            'bg'             => [28, 40, 77],
            'primary'        => [155, 100, 20],
            'positive'       => [142, 45, 40],
            'negative'       => [0, 100, 60],
            'contrast'       => 1.1,
            'textSaturation' => 0.5,
        ],
        'zebra' => [
            'label_key'      => 'admin.display.theme.preset.zebra',
            'light'          => true,
            'bg'             => [0, 0, 95],
            'primary'        => [0, 0, 10],
            'positive'       => [142, 45, 40],
            'negative'       => [0, 90, 50],
            'contrast'       => 1.0,
            'textSaturation' => 1.0,
        ],
    ];

    /** @return array<int,string> */
    public static function keys(): array
    {
        return array_keys(self::PRESETS);
    }

    /** @return array<string,string> key => label translation key */
    public static function optionLabels(): array
    {
        return array_map(static fn (array $p): string => $p['label_key'], self::PRESETS);
    }
}
