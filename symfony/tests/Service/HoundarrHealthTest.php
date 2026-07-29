<?php

namespace App\Tests\Service;

use App\Service\ConfigService;
use App\Service\HealthService;
use App\Service\Media\HoundarrClient;
use App\Service\Media\JellyseerrClient;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\Media\TmdbClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class HoundarrHealthTest extends TestCase
{
    /** @param array<string, ?string> $settings */
    private function makeService(array $settings, ?HoundarrClient $houndarr = null): HealthService
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
            houndarr: $houndarr,
        );
    }

    public function testHoundarrIsToggleable(): void
    {
        $this->assertContains('houndarr', HealthService::TOGGLEABLE_SERVICES);
    }

    public function testConfiguredNeedsUrlAndKey(): void
    {
        $this->assertFalse($this->makeService([])->isConfigured('houndarr'));
        $this->assertFalse($this->makeService(['houndarr_url' => 'http://houndarr:8877'])->isConfigured('houndarr'));
        $this->assertTrue($this->makeService([
            'houndarr_url' => 'http://houndarr:8877', 'houndarr_api_key' => 'hndarr_k',
        ])->isConfigured('houndarr'));
    }

    public function testKillSwitchDisables(): void
    {
        $this->assertFalse($this->makeService([
            'houndarr_url' => 'http://houndarr:8877', 'houndarr_api_key' => 'hndarr_k', 'houndarr_enabled' => '0',
        ])->isConfigured('houndarr'));
    }

    public function testStatusForPingsTheHoundarrClient(): void
    {
        $houndarr = $this->createMock(HoundarrClient::class);
        $houndarr->expects($this->once())->method('ping')->willReturn(true);

        $svc = $this->makeService(
            ['houndarr_url' => 'http://houndarr:8877', 'houndarr_api_key' => 'hndarr_k'],
            $houndarr,
        );
        $this->assertTrue($svc->isHealthy('houndarr'));
    }

    public function testStatusForIsDownWhenPingFails(): void
    {
        $houndarr = $this->createMock(HoundarrClient::class);
        $houndarr->method('ping')->willReturn(false);

        $svc = $this->makeService(
            ['houndarr_url' => 'http://houndarr:8877', 'houndarr_api_key' => 'hndarr_k'],
            $houndarr,
        );
        $this->assertFalse($svc->isHealthy('houndarr'));
    }

    public function testChipAppearsWhenConfigured(): void
    {
        $houndarr = $this->createMock(HoundarrClient::class);
        $houndarr->method('ping')->willReturn(true);

        $chips = $this->makeService(
            ['houndarr_url' => 'http://houndarr:8877', 'houndarr_api_key' => 'hndarr_k'],
            $houndarr,
        )->chips();
        $ids = array_column($chips, 'id');
        $this->assertContains('houndarr', $ids);

        $this->assertNotContains('houndarr', array_column($this->makeService([])->chips(), 'id'));
    }
}
