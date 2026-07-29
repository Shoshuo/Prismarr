<?php

namespace App\Tests\Controller;

use App\Controller\DashboardController;
use App\Repository\Media\WatchlistItemRepository;
use App\Service\DashboardLayoutService;
use App\Service\HealthService;
use App\Service\Media\HoundarrClient;
use App\Service\Media\JellyseerrClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\Media\TautulliClient;
use App\Service\Media\TmdbClient;
use App\Service\Media\UnraidClient;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * widgetHoundarr() gating: unconfigured Houndarr gets an empty body (hidden
 * client-side) and never triggers an upstream call. No admin gate — the
 * totals are harmless read-only counts visible to every logged-in user.
 */
#[AllowMockObjectsWithoutExpectations]
class DashboardWidgetHoundarrTest extends TestCase
{
    private function makeController(bool $configured, ?HoundarrClient $houndarr): DashboardController
    {
        $health = $this->createMock(HealthService::class);
        $health->method('isConfigured')->willReturnCallback(
            fn(string $s) => $s === 'houndarr' ? $configured : false
        );

        return new DashboardController(
            $health,
            $this->createMock(RadarrClient::class),
            $this->createMock(SonarrClient::class),
            $this->createMock(JellyseerrClient::class),
            $this->createMock(TmdbClient::class),
            $this->createMock(WatchlistItemRepository::class),
            $this->createMock(ServiceInstanceProvider::class),
            new NullLogger(),
            $this->createMock(TranslatorInterface::class),
            $this->createMock(CacheInterface::class),
            $this->createMock(TautulliClient::class),
            new DashboardLayoutService($this->createMock(\App\Service\ConfigService::class)),
            $this->createMock(UnraidClient::class),
            $houndarr,
        );
    }

    public function testUnconfiguredGetsEmptyBodyAndNoHoundarrCall(): void
    {
        $houndarr = $this->createMock(HoundarrClient::class);
        $houndarr->expects($this->never())->method('widget');

        $resp = $this->makeController(configured: false, houndarr: $houndarr)->widgetHoundarr();
        $this->assertSame('', $resp->getContent());
    }

    public function testMissingClientGetsEmptyBody(): void
    {
        $resp = $this->makeController(configured: true, houndarr: null)->widgetHoundarr();
        $this->assertSame('', $resp->getContent());
    }

    public function testSectionRegistered(): void
    {
        $this->assertContains('houndarr', \App\Dashboard\DashboardSections::DEFAULT_ORDER);
        $this->assertArrayHasKey('houndarr', \App\Dashboard\DashboardSections::META);
    }
}
