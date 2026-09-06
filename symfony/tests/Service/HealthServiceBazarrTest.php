<?php

namespace App\Tests\Service;

use App\Service\ConfigService;
use App\Service\HealthService;
use App\Service\Media\BazarrClient;
use App\Service\Media\JellyseerrClient;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\Media\TmdbClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class HealthServiceBazarrTest extends TestCase
{
    /** @param array<string, ?string> $settings */
    private function makeService(array $settings, ?BazarrClient $bazarr = null): HealthService
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturnCallback(fn(string $k) => $settings[$k] ?? null);
        $config->method('has')->willReturnCallback(
            fn(string $k) => ($settings[$k] ?? null) !== null && $settings[$k] !== ''
        );

        return new HealthService(
            $this->createMock(RadarrClient::class),
            $this->createMock(SonarrClient::class),
            $this->createMock(ProwlarrClient::class),
            $this->createMock(JellyseerrClient::class),
            $this->createMock(QBittorrentClient::class),
            $this->createMock(TmdbClient::class),
            $config,
            bazarr: $bazarr,
        );
    }

    public function testIsConfiguredTrueWhenUrlAndKeyPresent(): void
    {
        $this->assertTrue($this->makeService([
            'bazarr_url' => 'http://bazarr:6767', 'bazarr_api_key' => 'bzr_k',
        ])->isConfigured('bazarr'));
    }

    public function testIsConfiguredFalseWhenKeyMissing(): void
    {
        $this->assertFalse($this->makeService([
            'bazarr_url' => 'http://bazarr:6767',
        ])->isConfigured('bazarr'));
    }

    public function testIsConfiguredFalseWhenUrlMissing(): void
    {
        $this->assertFalse($this->makeService([
            'bazarr_api_key' => 'bzr_k',
        ])->isConfigured('bazarr'));
    }

    public function testIsConfiguredFalseWhenNothingSet(): void
    {
        $this->assertFalse($this->makeService([])->isConfigured('bazarr'));
    }
}
