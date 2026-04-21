<?php

namespace App\Tests\Service;

use App\Service\ConfigService;
use App\Service\DisplayPreferencesService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Service\ResetInterface;

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

        return new DisplayPreferencesService($config);
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
        $this->assertSame('Europe/Paris', $prefs->getTimezone());
        $this->assertSame('fr', $prefs->getDateFormat());
        $this->assertSame('24h', $prefs->getTimeFormat());
        $this->assertSame('indigo', $prefs->getThemeColor());
        $this->assertSame('#6366f1', $prefs->getThemeColorHex());
        $this->assertSame('poster', $prefs->getDefaultView());
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
            'display_default_view'  => 'table',
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
        $this->assertSame('table', $prefs->getDefaultView());
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

        $prefs = new DisplayPreferencesService($config);
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
        ]);

        $all = $prefs->all();

        $this->assertSame('dashboard', $all['home_page']);
        $this->assertFalse($all['toasts']);
        $this->assertSame(10, $all['qbit_refresh_seconds']);
        $this->assertSame('#6366f1', $all['theme_color_hex']);
    }
}
