<?php
namespace App\Tests\Controller;

use App\Controller\AdminSettingsController;
use App\Theme\ThemePresets;
use PHPUnit\Framework\TestCase;

final class AdminSettingsThemeTest extends TestCase
{
    public function testDisplayThemeOptionIsRegistered(): void
    {
        $opts = AdminSettingsController::DISPLAY_OPTIONS;
        self::assertArrayHasKey('display_theme', $opts);
        self::assertSame('select', $opts['display_theme']['type']);
        self::assertSame('midnight', $opts['display_theme']['default']);
    }

    public function testDisplayThemeOptionsMatchPresets(): void
    {
        $opts = AdminSettingsController::DISPLAY_OPTIONS['display_theme']['options'];
        self::assertSame(ThemePresets::keys(), array_keys($opts));
        self::assertSame(ThemePresets::optionLabels(), $opts);
    }

    public function testAccentPickerHasThemeDefaultOption(): void
    {
        $accent = AdminSettingsController::DISPLAY_OPTIONS['display_theme_color'];
        self::assertArrayHasKey('theme_default', $accent['options']);
        self::assertSame('theme_default', $accent['default']);
    }
}
