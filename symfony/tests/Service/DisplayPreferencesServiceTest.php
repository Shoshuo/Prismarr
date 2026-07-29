<?php

namespace App\Tests\Service;

use App\Service\ConfigService;
use App\Service\DisplayPreferencesService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Service\ResetInterface;

#[AllowMockObjectsWithoutExpectations]
class DisplayPreferencesServiceTest extends TestCase
{
    /**
     * @param array<string, string|null> $stored
     */
    private function serviceWith(array $stored): DisplayPreferencesService
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('get')
            ->willReturnCallback(fn(string $key) => $stored[$key] ?? null);

        return new DisplayPreferencesService($config, new \App\Service\ThemeService($config));
    }

    public function testImplementsResetInterface(): void
    {
        $this->assertInstanceOf(ResetInterface::class, $this->serviceWith([]));
    }

    public function testDefaultsWhenNothingStored(): void
    {
        $prefs = $this->serviceWith([]);

        $this->assertSame('dashboard', $prefs->getHomePage());
        $this->assertTrue($prefs->areToastsEnabled());
        // Issue #12 — empty timezone preference falls back to the system
        // zone (the init script wires this to $TZ from docker compose).
        // The test sets it to UTC for determinism via the makeCmd setup.
        $this->assertSame(date_default_timezone_get(), $prefs->getTimezone());
        $this->assertSame('fr', $prefs->getDateFormat());
        $this->assertSame('24h', $prefs->getTimeFormat());
        $this->assertSame('theme_default', $prefs->getThemeColor());
        $this->assertSame('#6366f1', $prefs->getThemeColorHex());
        $this->assertSame(2, $prefs->getQbitRefreshSeconds());
        $this->assertSame('comfortable', $prefs->getUiDensity());
    }

    public function testStoredValuesOverrideDefaults(): void
    {
        $prefs = $this->serviceWith([
            'display_home_page'     => 'films',
            'display_toasts'        => '0',
            'display_timezone'      => 'America/New_York',
            'display_date_format'   => 'iso',
            'display_time_format'   => '12h',
            'display_theme_color'   => 'green',
            'display_qbit_refresh'  => '5',
            'display_ui_density'    => 'compact',
        ]);

        $this->assertSame('films', $prefs->getHomePage());
        $this->assertFalse($prefs->areToastsEnabled());
        $this->assertSame('America/New_York', $prefs->getTimezone());
        $this->assertSame('iso', $prefs->getDateFormat());
        $this->assertSame('12h', $prefs->getTimeFormat());
        $this->assertSame('green', $prefs->getThemeColor());
        $this->assertSame('#22c55e', $prefs->getThemeColorHex());
        $this->assertSame(5, $prefs->getQbitRefreshSeconds());
        $this->assertSame('compact', $prefs->getUiDensity());
    }

    public function testEmptyStringFallsBackToDefault(): void
    {
        // The setting repo may persist '' instead of null — we treat both the
        // same way so an admin clearing a field doesn't leave it blank.
        $prefs = $this->serviceWith(['display_home_page' => '']);

        $this->assertSame('dashboard', $prefs->getHomePage());
    }

    public function testUnknownThemeColorFallsBackToDefaultHex(): void
    {
        // An orphaned DB value (e.g. after an options pruning upgrade) must
        // not crash the template — we fall back to the indigo default.
        $prefs = $this->serviceWith(['display_theme_color' => 'neon-yellow']);

        $this->assertSame('#6366f1', $prefs->getThemeColorHex());
    }

    public function testResetClearsInRequestCache(): void
    {
        // Simulates a worker request that reads a value, then /admin/settings
        // is saved and config->invalidate() + this->reset() are called by
        // Symfony before the next worker request.
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturnOnConsecutiveCalls('films', 'series');

        $prefs = new DisplayPreferencesService($config, new \App\Service\ThemeService($config));
        $this->assertSame('films', $prefs->getHomePage());

        // Without reset the cached 'films' would be returned.
        $prefs->reset();
        $this->assertSame('series', $prefs->getHomePage());
    }

    public function testAllReturnsTypedPayload(): void
    {
        $prefs = $this->serviceWith([
            'display_toasts'       => '0',
            'display_qbit_refresh' => '10',
            'display_page_size'    => '100',
        ]);

        $all = $prefs->all();

        $this->assertSame('dashboard', $all['home_page']);
        $this->assertFalse($all['toasts']);
        $this->assertSame(10, $all['qbit_refresh_seconds']);
        $this->assertSame(100, $all['page_size']);
        $this->assertSame('#6366f1', $all['theme_color_hex']);
        $this->assertSame('99, 102, 241', $all['theme_color_rgb']);
    }

    public function testPageSizeFallsBackToDefault(): void
    {
        $this->assertSame(200, $this->serviceWith([])->getPageSize(), 'unset → default 200');
        $this->assertSame(500, $this->serviceWith(['display_page_size' => '500'])->getPageSize());
    }

    public function testThemeColorRgbMatchesEachPaletteEntry(): void
    {
        $this->assertSame('99, 102, 241',   $this->serviceWith(['display_theme_color' => 'indigo'])->getThemeColorRgb());
        $this->assertSame('239, 68, 68',    $this->serviceWith(['display_theme_color' => 'red'])->getThemeColorRgb());
        $this->assertSame('34, 197, 94',    $this->serviceWith(['display_theme_color' => 'green'])->getThemeColorRgb());
        $this->assertSame('245, 158, 11',   $this->serviceWith(['display_theme_color' => 'orange'])->getThemeColorRgb());
        $this->assertSame('236, 72, 153',   $this->serviceWith(['display_theme_color' => 'pink'])->getThemeColorRgb());
        $this->assertSame('59, 130, 246',   $this->serviceWith(['display_theme_color' => 'blue'])->getThemeColorRgb());
        $this->assertSame('99, 102, 241',   $this->serviceWith(['display_theme_color' => 'neon-yellow'])->getThemeColorRgb());
    }

    public function testFormatDateHonorsPreference(): void
    {
        $dt = new \DateTimeImmutable('2026-04-21 14:30:00', new \DateTimeZone('Europe/Paris'));

        $this->assertSame('21/04/2026', $this->serviceWith(['display_date_format' => 'fr'])->formatDate($dt));
        $this->assertSame('Apr 21, 2026', $this->serviceWith(['display_date_format' => 'us'])->formatDate($dt));
        $this->assertSame('2026-04-21', $this->serviceWith(['display_date_format' => 'iso'])->formatDate($dt));
        $this->assertNull($this->serviceWith([])->formatDate(null));
    }

    public function testFormatTimeHonorsPreference(): void
    {
        $dt = new \DateTimeImmutable('2026-04-21 14:30:00', new \DateTimeZone('Europe/Paris'));

        // display_timezone explicit so the test isolates time-format
        // formatting from the (now system-aware) timezone fallback added
        // for issue #12.
        $this->assertSame('14:30', $this->serviceWith(['display_time_format' => '24h', 'display_timezone' => 'Europe/Paris'])->formatTime($dt));
        $this->assertSame('2:30 PM', $this->serviceWith(['display_time_format' => '12h', 'display_timezone' => 'Europe/Paris'])->formatTime($dt));
    }

    public function testFormatTimeAppliesTimezone(): void
    {
        // 14:30 Paris = 08:30 New York (summer DST).
        $dt = new \DateTimeImmutable('2026-06-15 14:30:00', new \DateTimeZone('Europe/Paris'));

        $this->assertSame('08:30', $this->serviceWith([
            'display_timezone'    => 'America/New_York',
            'display_time_format' => '24h',
        ])->formatTime($dt));
    }

    public function testFormatDateTimeJoinsDateAndTime(): void
    {
        $dt = new \DateTimeImmutable('2026-04-21 14:30:00', new \DateTimeZone('Europe/Paris'));
        $prefs = $this->serviceWith([
            'display_date_format' => 'iso',
            'display_time_format' => '24h',
            // Pin the zone for determinism — the system fallback would
            // depend on the host TZ in CI vs dev (issue #12).
            'display_timezone'    => 'Europe/Paris',
        ]);

        $this->assertSame('2026-04-21 · 14:30', $prefs->formatDateTime($dt));
    }

    public function testInvalidStoredTimezoneFallsBackToRawDatetime(): void
    {
        // An invalid timezone in the DB must not crash the render.
        $dt = new \DateTimeImmutable('2026-04-21 14:30:00', new \DateTimeZone('Europe/Paris'));
        $prefs = $this->serviceWith([
            'display_timezone'    => 'Not/A-Real/Zone',
            'display_time_format' => '24h',
        ]);

        // The raw Paris-tz datetime is used verbatim.
        $this->assertSame('14:30', $prefs->formatTime($dt));
    }
}
