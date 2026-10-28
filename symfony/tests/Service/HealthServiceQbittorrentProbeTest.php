<?php

namespace App\Tests\Service;

use App\Entity\ServiceInstance;
use App\Service\ConfigService;
use App\Service\HealthService;
use App\Service\Media\JellyseerrClient;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\Media\TmdbClient;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Issue #131 — qBittorrent connection probes per instance, keeping the
 * reverse-proxy rules of issue #10: URL-only = no /auth/login, and no
 * Referer on that probe (a proxy forwards it and qBit's CSRF check rejects
 * any Referer that isn't its own host). Gluetun instances get a read-only
 * GET of their control server.
 */
#[AllowMockObjectsWithoutExpectations]
final class HealthServiceQbittorrentProbeTest extends TestCase
{
    private function makeService(?ServiceInstanceProvider $instances = null): HealthService
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturn(null);
        return new HealthService(
            $this->createMock(RadarrClient::class),
            $this->createMock(SonarrClient::class),
            $this->createMock(ProwlarrClient::class),
            $this->createMock(JellyseerrClient::class),
            $this->createMock(QBittorrentClient::class),
            $this->createMock(TmdbClient::class),
            $config,
            null,
            $instances,
        );
    }

    /** @return array<string, mixed>|null */
    private function probeFor(HealthService $svc, ?array $overrides): ?array
    {
        return (new ReflectionMethod(HealthService::class, 'probeFor'))->invoke($svc, 'qbittorrent', $overrides);
    }

    private function defaultProvider(ServiceInstance $default): ServiceInstanceProvider
    {
        $provider = $this->createMock(ServiceInstanceProvider::class);
        $provider->method('getDefault')->willReturn($default);
        return $provider;
    }

    public function testNoAuthProbeHitsVersionWithoutReferer(): void
    {
        $probe = HealthService::qbittorrentProbe('http://qbit.lan:8080/', '', '');

        self::assertSame('http://qbit.lan:8080/api/v2/app/version', $probe['url']);
        self::assertArrayNotHasKey('method', $probe);
        self::assertSame([], $probe['headers'] ?? [], 'no Referer in reverse-proxy mode');
    }

    public function testOneMissingCredentialIsStillNoAuth(): void
    {
        self::assertStringEndsWith('/api/v2/app/version', HealthService::qbittorrentProbe('http://qbit.lan:8080', 'admin', '')['url']);
        self::assertStringEndsWith('/api/v2/app/version', HealthService::qbittorrentProbe('http://qbit.lan:8080', '', 'pass')['url']);
    }

    public function testCredentialsProbeLogsInWithReferer(): void
    {
        $probe = HealthService::qbittorrentProbe('http://qbit.lan:8080', 'admin', 'p&ss');

        self::assertSame('http://qbit.lan:8080/api/v2/auth/login', $probe['url']);
        self::assertSame('POST', $probe['method']);
        self::assertContains('Referer: http://qbit.lan:8080', $probe['headers']);
        self::assertSame('username=admin&password=p%26ss', $probe['body']);
    }

    public function testSettingsProbeUsesTheDefaultInstanceWhenNoOverride(): void
    {
        $default = new ServiceInstance(ServiceInstance::TYPE_QBITTORRENT, 'qbittorrent-1', 'qBittorrent 1', 'http://10.0.0.2:8080');
        $default->setUsername('admin');
        $default->setPassword('pass');

        $probe = $this->probeFor($this->makeService($this->defaultProvider($default)), null);

        self::assertSame('http://10.0.0.2:8080/api/v2/auth/login', $probe['url']);
    }

    public function testSettingsProbeOverridesWinOverTheDefaultInstance(): void
    {
        $default = new ServiceInstance(ServiceInstance::TYPE_QBITTORRENT, 'qbittorrent-1', 'qBittorrent 1', 'http://10.0.0.2:8080');

        $probe = $this->probeFor($this->makeService($this->defaultProvider($default)), [
            'qbittorrent_url' => 'http://10.0.0.9:8080',
        ]);

        self::assertSame('http://10.0.0.9:8080/api/v2/app/version', $probe['url']);
    }

    public function testSettingsProbeWithoutAnyInstanceIsNull(): void
    {
        $provider = $this->createMock(ServiceInstanceProvider::class);
        $provider->method('getDefault')->willReturn(null);

        self::assertNull($this->probeFor($this->makeService($provider), null));
    }

    public function testDiagnoseInstanceRejectsAnotherServiceType(): void
    {
        $radarr = new ServiceInstance(ServiceInstance::TYPE_RADARR, 'radarr-1', 'Radarr', 'http://10.0.0.4:7878', 'k');

        self::assertSame(
            ['ok' => false, 'category' => 'unknown', 'http' => null],
            $this->makeService()->diagnoseInstance($radarr),
        );
    }

    public function testDiagnoseInstanceReportsABlockedUrlWithoutThrowing(): void
    {
        // The SSRF guard runs before any socket is opened.
        $qbt = new ServiceInstance(ServiceInstance::TYPE_QBITTORRENT, 'qbittorrent-1', 'qBittorrent 1', 'http://169.254.169.254');

        $result = $this->makeService()->diagnoseInstance($qbt);

        self::assertFalse($result['ok']);
        self::assertNull($result['http']);
    }

    public function testGluetunProbeIsAReadOnlyGetWithTheApiKeyHeader(): void
    {
        $probe = HealthService::gluetunProbe('http://gluetun:8000/', 'k3y');

        self::assertSame('http://gluetun:8000/v1/publicip/ip', $probe['url']);
        self::assertArrayNotHasKey('method', $probe, 'GET only, never a write');
        self::assertSame(['X-API-Key: k3y'], $probe['headers']);
        self::assertArrayNotHasKey('headers', HealthService::gluetunProbe('http://gluetun:8000', ''));
    }

    public function testDiagnoseInstanceProbesAGluetunInstance(): void
    {
        // Connection refused at once on port 1: diagnosed, never thrown.
        $gluetun = new ServiceInstance(ServiceInstance::TYPE_GLUETUN, 'gluetun-1', 'Gluetun 1', 'http://127.0.0.1:1');

        $result = $this->makeService()->diagnoseInstance($gluetun);

        self::assertFalse($result['ok']);
        self::assertSame('network', $result['category']);
    }
}
