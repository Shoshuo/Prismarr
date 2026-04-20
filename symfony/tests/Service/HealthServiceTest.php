<?php

namespace App\Tests\Service;

use App\Service\HealthService;
use App\Service\Media\JellyseerrClient;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\Media\TmdbClient;
use PHPUnit\Framework\TestCase;

class HealthServiceTest extends TestCase
{
    private function makeService(
        ?RadarrClient $radarr = null,
        ?SonarrClient $sonarr = null,
        ?ProwlarrClient $prowlarr = null,
        ?JellyseerrClient $jellyseerr = null,
        ?QBittorrentClient $qbittorrent = null,
        ?TmdbClient $tmdb = null,
    ): HealthService {
        return new HealthService(
            $radarr      ?? $this->createMock(RadarrClient::class),
            $sonarr      ?? $this->createMock(SonarrClient::class),
            $prowlarr    ?? $this->createMock(ProwlarrClient::class),
            $jellyseerr  ?? $this->createMock(JellyseerrClient::class),
            $qbittorrent ?? $this->createMock(QBittorrentClient::class),
            $tmdb        ?? $this->createMock(TmdbClient::class),
        );
    }

    public function testIsHealthyCallsTheRightClient(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->expects($this->once())->method('ping')->willReturn(true);

        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->expects($this->never())->method('ping');

        $svc = $this->makeService($radarr, $sonarr);
        $this->assertTrue($svc->isHealthy('radarr'));
    }

    public function testUnknownServiceReturnsTrue(): void
    {
        // No client should be called for an unknown service.
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->expects($this->never())->method('ping');

        $svc = $this->makeService($radarr);
        $this->assertTrue($svc->isHealthy('nonexistent'));
    }

    public function testCacheHitsAvoidSecondPing(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        // Exactly 1 ping — the second isHealthy() must hit the cache.
        $radarr->expects($this->once())->method('ping')->willReturn(true);

        $svc = $this->makeService($radarr);
        $svc->isHealthy('radarr');
        $svc->isHealthy('radarr');
    }

    public function testCachedFailureIsReturnedAsIs(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->expects($this->once())->method('ping')->willReturn(false);

        $svc = $this->makeService($radarr);
        $this->assertFalse($svc->isHealthy('radarr'));
        // Still false on second call — and no extra ping.
        $this->assertFalse($svc->isHealthy('radarr'));
    }

    public function testInvalidateForOneServiceForcesReping(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->expects($this->exactly(2))->method('ping')->willReturn(true);

        $svc = $this->makeService($radarr);
        $svc->isHealthy('radarr');
        $svc->invalidate('radarr');
        $svc->isHealthy('radarr');
    }

    public function testInvalidateAllForcesRepingForAllServices(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->expects($this->exactly(2))->method('ping')->willReturn(true);
        $sonarr = $this->createMock(SonarrClient::class);
        $sonarr->expects($this->exactly(2))->method('ping')->willReturn(true);

        $svc = $this->makeService($radarr, $sonarr);
        $svc->isHealthy('radarr');
        $svc->isHealthy('sonarr');
        $svc->invalidate();
        $svc->isHealthy('radarr');
        $svc->isHealthy('sonarr');
    }

    public function testEachServiceMappedToItsClient(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $sonarr = $this->createMock(SonarrClient::class);
        $prowlarr = $this->createMock(ProwlarrClient::class);
        $jellyseerr = $this->createMock(JellyseerrClient::class);
        $qbit = $this->createMock(QBittorrentClient::class);
        $tmdb = $this->createMock(TmdbClient::class);

        $radarr->expects($this->once())->method('ping')->willReturn(true);
        $sonarr->expects($this->once())->method('ping')->willReturn(true);
        $prowlarr->expects($this->once())->method('ping')->willReturn(true);
        $jellyseerr->expects($this->once())->method('ping')->willReturn(true);
        $qbit->expects($this->once())->method('ping')->willReturn(true);
        $tmdb->expects($this->once())->method('ping')->willReturn(true);

        $svc = $this->makeService($radarr, $sonarr, $prowlarr, $jellyseerr, $qbit, $tmdb);
        $svc->isHealthy('radarr');
        $svc->isHealthy('sonarr');
        $svc->isHealthy('prowlarr');
        $svc->isHealthy('jellyseerr');
        $svc->isHealthy('qbittorrent');
        $svc->isHealthy('tmdb');
    }
}
