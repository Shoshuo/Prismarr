<?php
namespace App\Tests\Service;

use App\Service\ConfigService;
use App\Service\ThemeService;
use App\Theme\ThemePresets;
use PHPUnit\Framework\TestCase;

final class ThemeServiceTest extends TestCase
{
    private function serviceFor(?string $stored): ThemeService
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->with('display_theme')->willReturn($stored);
        return new ThemeService($config);
    }

    public function testUnknownKeyFallsBackToDefault(): void
    {
        $r = $this->serviceFor('does-not-exist')->resolve();
        self::assertSame(ThemePresets::DEFAULT_KEY, $r['key']);
    }

    public function testNullStoredFallsBackToDefault(): void
    {
        $r = $this->serviceFor(null)->resolve();
        self::assertSame('midnight', $r['key']);
    }

    public function testMidnightResolvesExpectedPrimaryAndBackground(): void
    {
        $r = $this->serviceFor('midnight')->resolve();
        self::assertFalse($r['light']);
        self::assertSame('#6366f1', $r['primary_hex']);
        self::assertSame('99, 102, 241', $r['primary_rgb']);
        self::assertSame('hsl(0, 0%, 6.5%)', $r['css']['--tblr-body-bg']);
        self::assertSame('hsl(0, 0%, 11%)',  $r['css']['--prismarr-surface']);
        self::assertSame('hsl(0, 0%, 8.5%)', $r['css']['--prismarr-surface-2']);
        self::assertSame('hsl(0, 0%, 5%)',   $r['css']['--prismarr-sidebar']);
        self::assertArrayHasKey('--prismarr-surface', $r['css']);
        self::assertArrayHasKey('--tblr-border-color', $r['css']);
        self::assertSame('hsla(0, 0%, 6.5%, 0.95)', $r['css']['--prismarr-topbar-bg']);
    }

    public function testLightPresetSetsLightFlag(): void
    {
        $r = $this->serviceFor('catppuccin_latte')->resolve();
        self::assertTrue($r['light']);
    }

    public function testResolutionIsCachedUntilReset(): void
    {
        $svc = $this->serviceFor('midnight');
        $first = $svc->resolve();
        $svc->reset();
        $second = $svc->resolve();
        self::assertSame($first, $second);
    }
}
