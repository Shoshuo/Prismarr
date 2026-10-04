<?php
namespace App\Tests\Service;

use App\Service\ConfigService;
use App\Service\DashboardLayoutService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class DashboardLayoutServiceTest extends TestCase
{
    /** @param array<string,?string> $stored */
    private function serviceFor(array $stored): DashboardLayoutService
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturnCallback(fn(string $k) => $stored[$k] ?? null);
        return new DashboardLayoutService($config);
    }

    public function testEmptyConfigGivesDefaultOrderAllVisible(): void
    {
        $r = $this->serviceFor([])->resolve();
        self::assertSame(
            ['upcoming', 'requests', 'health', 'plex', 'transmission', 'watchlist', 'trending', 'recent'],
            array_column($r, 'key'),
        );
        foreach ($r as $row) {
            self::assertTrue($row['visible']);
        }
    }

    public function testStoredOrderIsHonored(): void
    {
        $r = $this->serviceFor(['dashboard_section_order' => 'recent,plex,upcoming'])->resolve();
        // Stored keys first, in stored order; the rest appended in default order.
        self::assertSame(
            ['recent', 'plex', 'upcoming', 'requests', 'health', 'transmission', 'watchlist', 'trending'],
            array_column($r, 'key'),
        );
    }

    public function testUnknownKeysAreDroppedAndMissingAppended(): void
    {
        $r = $this->serviceFor(['dashboard_section_order' => 'trending,bogus,recent'])->resolve();
        $keys = array_column($r, 'key');
        self::assertNotContains('bogus', $keys);
        self::assertSame(['trending', 'recent', 'upcoming', 'requests', 'health', 'plex', 'transmission', 'watchlist'], $keys);
    }

    public function testDuplicateKeysAreCollapsed(): void
    {
        $r = $this->serviceFor(['dashboard_section_order' => 'plex,plex,recent'])->resolve();
        $keys = array_column($r, 'key');
        self::assertSame(1, count(array_keys($keys, 'plex', true)));
    }

    public function testHiddenFlagMarksSectionNotVisible(): void
    {
        $r = $this->serviceFor(['dashboard_hide_health' => '1'])->resolve();
        $byKey = array_column($r, 'visible', 'key');
        self::assertFalse($byKey['health']);
        self::assertTrue($byKey['plex']);
    }

    public function testResolutionIsCachedUntilReset(): void
    {
        $calls = 0;
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturnCallback(function (string $k) use (&$calls) {
            $calls++;
            return null; // all defaults (empty order, all visible)
        });
        $svc = new DashboardLayoutService($config);

        // First resolve: uncached, should call get() once for order + 7 times for per-section visibility = 8 total.
        $svc->resolve();
        self::assertSame(9, $calls, 'First resolve should call ConfigService::get() 9 times (1 order + 8 sections)');

        // Second resolve: cached, should not call get() again.
        $svc->resolve();
        self::assertSame(9, $calls, 'Second resolve should use cache and not call ConfigService::get()');

        // After reset: cache cleared, next resolve should call get() again.
        $svc->reset();
        $svc->resolve();
        self::assertSame(18, $calls, 'After reset, resolve should call ConfigService::get() another 9 times');
    }
}
