<?php
namespace App\Tests\Dashboard;

use App\Dashboard\DashboardSections;
use PHPUnit\Framework\TestCase;

final class DashboardSectionsTest extends TestCase
{
    public function testDefaultOrderIsTheTenKnownSections(): void
    {
        self::assertSame(
            ['upcoming', 'requests', 'health', 'houndarr', 'plex', 'watchlist', 'trending', 'recent', 'server', 'network'],
            DashboardSections::DEFAULT_ORDER,
        );
    }

    public function testKeysMatchesDefaultOrder(): void
    {
        self::assertSame(DashboardSections::DEFAULT_ORDER, DashboardSections::keys());
    }

    public function testEverySectionHasALabel(): void
    {
        foreach (DashboardSections::DEFAULT_ORDER as $key) {
            self::assertArrayHasKey($key, DashboardSections::META);
            self::assertNotSame('', DashboardSections::META[$key]['label']);
        }
    }

    public function testIsValidAcceptsKnownAndRejectsUnknown(): void
    {
        self::assertTrue(DashboardSections::isValid('plex'));
        self::assertFalse(DashboardSections::isValid('nope'));
    }
}
