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
use App\Service\Media\UnraidClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class UnraidHealthTest extends TestCase
{
    /** @param array<string, ?string> $settings */
    private function makeService(array $settings, ?UnraidClient $unraid = null): HealthService
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
            unraid: $unraid,
        );
    }

    public function testUnraidIsToggleable(): void
    {
        $this->assertContains('unraid', HealthService::TOGGLEABLE_SERVICES);
    }

    public function testConfiguredNeedsUrlAndKey(): void
    {
        $this->assertFalse($this->makeService([])->isConfigured('unraid'));
        $this->assertFalse($this->makeService(['unraid_url' => 'https://tower.local'])->isConfigured('unraid'));
        $this->assertTrue($this->makeService([
            'unraid_url' => 'https://tower.local', 'unraid_api_key' => 'k',
        ])->isConfigured('unraid'));
    }

    public function testKillSwitchDisables(): void
    {
        $this->assertFalse($this->makeService([
            'unraid_url' => 'https://tower.local', 'unraid_api_key' => 'k', 'unraid_enabled' => '0',
        ])->isConfigured('unraid'));
    }

    public function testStatusForPingsTheUnraidClient(): void
    {
        $unraid = $this->createMock(UnraidClient::class);
        $unraid->expects($this->once())->method('ping')->willReturn(true);

        $svc = $this->makeService(
            ['unraid_url' => 'https://tower.local', 'unraid_api_key' => 'k'],
            $unraid,
        );
        $this->assertTrue($svc->isHealthy('unraid'));
    }

    public function testStatusForIsDownWhenPingFails(): void
    {
        $unraid = $this->createMock(UnraidClient::class);
        $unraid->method('ping')->willReturn(false);

        $svc = $this->makeService(
            ['unraid_url' => 'https://tower.local', 'unraid_api_key' => 'k'],
            $unraid,
        );
        $this->assertFalse($svc->isHealthy('unraid'));
    }
}
