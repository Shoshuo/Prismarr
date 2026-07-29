<?php

namespace App\Tests\Service;

use App\Service\ConfigService;
use App\Service\HealthService;
use App\Service\Media\JellyseerrClient;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\Media\TmdbClient;
use App\Service\Media\UnifiClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class UnifiHealthTest extends TestCase
{
    /** @param array<string, ?string> $settings */
    private function makeService(array $settings, ?UnifiClient $unifi = null): HealthService
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
            unifi: $unifi,
        );
    }

    public function testUnifiIsToggleable(): void
    {
        $this->assertContains('unifi', HealthService::TOGGLEABLE_SERVICES);
    }

    public function testConfiguredNeedsUrlAndKey(): void
    {
        $this->assertFalse($this->makeService([])->isConfigured('unifi'));
        $this->assertFalse($this->makeService(['unifi_url' => 'https://192.168.1.1'])->isConfigured('unifi'));
        $this->assertTrue($this->makeService([
            'unifi_url' => 'https://192.168.1.1', 'unifi_api_key' => 'k',
        ])->isConfigured('unifi'));
    }

    public function testKillSwitchDisables(): void
    {
        $this->assertFalse($this->makeService([
            'unifi_url' => 'https://192.168.1.1', 'unifi_api_key' => 'k', 'unifi_enabled' => '0',
        ])->isConfigured('unifi'));
    }

    public function testStatusForPingsTheUnifiClient(): void
    {
        $unifi = $this->createMock(UnifiClient::class);
        $unifi->expects($this->once())->method('ping')->willReturn(true);

        $svc = $this->makeService(
            ['unifi_url' => 'https://192.168.1.1', 'unifi_api_key' => 'k'],
            $unifi,
        );
        $this->assertTrue($svc->isHealthy('unifi'));
    }

    public function testStatusForIsDownWhenPingFails(): void
    {
        $unifi = $this->createMock(UnifiClient::class);
        $unifi->method('ping')->willReturn(false);

        $svc = $this->makeService(
            ['unifi_url' => 'https://192.168.1.1', 'unifi_api_key' => 'k'],
            $unifi,
        );
        $this->assertFalse($svc->isHealthy('unifi'));
    }
}
