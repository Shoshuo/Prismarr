<?php

namespace App\Tests\Service;

use App\Service\HealthService;
use App\Service\Media\JellyseerrClient;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\Media\TmdbClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * statusFor()'s 10 s memo used to live only in a per-object array, which a
 * classic (non-worker) FrankenPHP throws away with the request — so every
 * topbar poll re-pinged every service. These tests pin the fix: results are
 * shared through an injected cache pool (cache.app), so two HealthService
 * objects sharing one pool behave like two requests sharing the cache.
 */
#[AllowMockObjectsWithoutExpectations]
class HealthServiceSharedCacheTest extends TestCase
{
    public function testStatusIsSharedAcrossInstancesThroughThePool(): void
    {
        $pool = new ArrayAdapter();

        $pingedOnce = $this->createMock(ProwlarrClient::class);
        $pingedOnce->expects(self::once())->method('ping')->willReturn(true);
        $first = $this->make($pingedOnce, $pool);
        self::assertSame('up', $first->statusFor('prowlarr')['status']);

        // Second instance = second request. Must read the pooled result, not
        // re-ping (a live ping here would fail the ::never expectation).
        $neverPinged = $this->createMock(ProwlarrClient::class);
        $neverPinged->expects(self::never())->method('ping');
        $second = $this->make($neverPinged, $pool);
        self::assertSame('up', $second->statusFor('prowlarr')['status']);
    }

    public function testInvalidateDropsThePooledStatus(): void
    {
        $pool = new ArrayAdapter();

        $before = $this->createMock(ProwlarrClient::class);
        $before->expects(self::once())->method('ping')->willReturn(true);
        $a = $this->make($before, $pool);
        self::assertSame('up', $a->statusFor('prowlarr')['status']);

        $a->invalidate('prowlarr');

        // A fresh instance after invalidate() must re-probe — "Test
        // connection" recovery may not serve a pre-invalidation verdict.
        $after = $this->createMock(ProwlarrClient::class);
        $after->expects(self::once())->method('ping')->willReturn(false);
        $b = $this->make($after, $pool);
        self::assertSame('down', $b->statusFor('prowlarr')['status']);
    }

    public function testGlobalInvalidateDropsThePooledStatus(): void
    {
        $pool = new ArrayAdapter();

        $before = $this->createMock(ProwlarrClient::class);
        $before->expects(self::once())->method('ping')->willReturn(true);
        $a = $this->make($before, $pool);
        self::assertSame('up', $a->statusFor('prowlarr')['status']);

        $a->invalidate();

        $after = $this->createMock(ProwlarrClient::class);
        $after->expects(self::once())->method('ping')->willReturn(true);
        $b = $this->make($after, $pool);
        self::assertSame('up', $b->statusFor('prowlarr')['status']);
    }

    private function make(ProwlarrClient $prowlarr, CacheInterface $pool): HealthService
    {
        // config/serviceHealthCache stay null: isConfigured() and the breaker
        // are skipped, so the tests drive the probe + pool paths directly.
        return new HealthService(
            $this->createMock(RadarrClient::class),
            $this->createMock(SonarrClient::class),
            $prowlarr,
            $this->createMock(JellyseerrClient::class),
            $this->createMock(QBittorrentClient::class),
            $this->createMock(TmdbClient::class),
            statusPool: $pool,
        );
    }
}
