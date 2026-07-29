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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class HealthServiceTest extends TestCase
{
    private function makeService(
        ?RadarrClient $radarr = null,
        ?SonarrClient $sonarr = null,
        ?ProwlarrClient $prowlarr = null,
        ?JellyseerrClient $jellyseerr = null,
        ?QBittorrentClient $qbittorrent = null,
        ?TmdbClient $tmdb = null,
        ?ConfigService $config = null,
        ?ServiceInstanceProvider $instances = null,
    ): HealthService {
        return new HealthService(
            $radarr      ?? $this->createMock(RadarrClient::class),
            $sonarr      ?? $this->createMock(SonarrClient::class),
            $prowlarr    ?? $this->createMock(ProwlarrClient::class),
            $jellyseerr  ?? $this->createMock(JellyseerrClient::class),
            $qbittorrent ?? $this->createMock(QBittorrentClient::class),
            $tmdb        ?? $this->createMock(TmdbClient::class),
            $config,
            null,
            $instances,
        );
    }

    /**
     * Convenience: build a provider that flags radarr/sonarr "configured"
     * based on a list of types. Mirrors the legacy ConfigService-only tests.
     *
     * @param list<string> $configuredTypes
     */
    private function provider(array $configuredTypes): ServiceInstanceProvider
    {
        $provider = $this->createMock(ServiceInstanceProvider::class);
        $provider->method('hasAnyEnabled')->willReturnCallback(
            fn(string $type) => in_array($type, $configuredTypes, true)
        );
        return $provider;
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

    /**
     * Issue #9 — when a service has no URL/key in DB, isHealthy() must
     * return null (not false) AND must NOT call ping(). Otherwise every
     * topbar poll spams a "Jellyseerr ping failed" warning to the logs
     * for users who only enabled a subset of the stack.
     */
    public function testUnconfiguredServiceReturnsNullWithoutPinging(): void
    {
        $jellyseerr = $this->createMock(JellyseerrClient::class);
        $jellyseerr->expects($this->never())->method('ping');

        $config = $this->createMock(ConfigService::class);
        $config->method('has')->willReturn(false);

        $svc = $this->makeService(jellyseerr: $jellyseerr, config: $config);
        $this->assertNull($svc->isHealthy('jellyseerr'));
    }

    public function testConfiguredServiceStillPingsWhenConfigPresent(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->expects($this->once())->method('ping')->willReturn(true);

        // v1.1.0 — radarr "configured" means the provider has at least one
        // enabled instance, not the presence of radarr_url in `setting`.
        $svc = $this->makeService(
            radarr: $radarr,
            config: $this->createMock(ConfigService::class),
            instances: $this->provider([ServiceInstance::TYPE_RADARR]),
        );
        $this->assertTrue($svc->isHealthy('radarr'));
    }

    public function testIsConfiguredChecksRequiredKeysPerService(): void
    {
        $config = $this->createMock(ConfigService::class);
        // tmdb only needs the api key. radarr's check moved to the provider —
        // an empty provider means no radarr instance configured.
        $config->method('has')->willReturnCallback(fn (string $k) => match ($k) {
            'tmdb_api_key'    => true,
            default           => false,
        });

        $svc = $this->makeService(config: $config, instances: $this->provider([]));
        $this->assertTrue($svc->isConfigured('tmdb'));
        $this->assertFalse($svc->isConfigured('radarr'));
        $this->assertFalse($svc->isConfigured('jellyseerr'));
    }

    public function testIsConfiguredAlwaysTrueWhenNoConfigService(): void
    {
        // Legacy code path: no ConfigService injected → don't break the
        // existing test suite that pre-dates the unconfigured-skip feature.
        $svc = $this->makeService();
        $this->assertTrue($svc->isConfigured('radarr'));
        $this->assertTrue($svc->isConfigured('jellyseerr'));
    }

    /**
     * Issue #10 — qBittorrent only requires a URL. Empty user/password
     * means the user is on a reverse-proxy setup (qui, traefik forward
     * auth, …) where the proxy injects credentials transparently. We
     * MUST treat that as configured, otherwise the topbar/dashboard
     * marks it as unconfigured and hides the widgets.
     */
    public function testQbittorrentIsConfiguredWithUrlOnly(): void
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('has')->willReturnCallback(fn (string $k) => match ($k) {
            'qbittorrent_url'      => true,
            'qbittorrent_user'     => false,
            'qbittorrent_password' => false,
            default                => false,
        });

        $svc = $this->makeService(config: $config);
        $this->assertTrue($svc->isConfigured('qbittorrent'));
    }

    public function testQbittorrentIsNotConfiguredWithoutUrl(): void
    {
        // Even if user/password are filled in, no URL means we can't talk
        // to qBit at all → must be marked unconfigured.
        $config = $this->createMock(ConfigService::class);
        $config->method('has')->willReturnCallback(fn (string $k) => match ($k) {
            'qbittorrent_url'      => false,
            'qbittorrent_user'     => true,
            'qbittorrent_password' => true,
            default                => false,
        });

        $svc = $this->makeService(config: $config);
        $this->assertFalse($svc->isConfigured('qbittorrent'));
    }

    public function testExplicitlyDisabledServiceIsNotConfigured(): void
    {
        // Issue #15 — credentials are present, but the kill switch is off.
        $config = $this->createMock(ConfigService::class);
        $config->method('has')->willReturn(true);
        $config->method('get')->willReturnCallback(fn (string $k) => $k === 'prowlarr_enabled' ? '0' : null);

        $svc = $this->makeService(config: $config);
        $this->assertFalse($svc->isConfigured('prowlarr'));
    }

    public function testEnabledFlagAbsentOrOneFallsBackToCredentialCheck(): void
    {
        // Missing row (toggle never touched) → use the credential check.
        $absent = $this->createMock(ConfigService::class);
        $absent->method('has')->willReturn(true);
        $absent->method('get')->willReturn(null);
        $this->assertTrue($this->makeService(config: $absent)->isConfigured('jellyseerr'));

        // Explicit '1' → also enabled.
        $on = $this->createMock(ConfigService::class);
        $on->method('has')->willReturn(true);
        $on->method('get')->willReturnCallback(fn (string $k) => $k === 'jellyseerr_enabled' ? '1' : null);
        $this->assertTrue($this->makeService(config: $on)->isConfigured('jellyseerr'));
    }

    public function testDisabledServiceIsHealthyReturnsNullWithoutPinging(): void
    {
        $tmdb = $this->createMock(TmdbClient::class);
        $tmdb->expects($this->never())->method('ping');

        $config = $this->createMock(ConfigService::class);
        $config->method('has')->willReturn(true);
        $config->method('get')->willReturnCallback(fn (string $k) => $k === 'tmdb_enabled' ? '0' : null);

        $svc = $this->makeService(tmdb: $tmdb, config: $config);
        $this->assertNull($svc->isHealthy('tmdb'));
    }

    public function testUnconfiguredResultIsCachedSoSecondCallStillSkipsPing(): void
    {
        $jellyseerr = $this->createMock(JellyseerrClient::class);
        $jellyseerr->expects($this->never())->method('ping');

        $config = $this->createMock(ConfigService::class);
        $config->method('has')->willReturn(false);

        $svc = $this->makeService(jellyseerr: $jellyseerr, config: $config);
        $this->assertNull($svc->isHealthy('jellyseerr'));
        $this->assertNull($svc->isHealthy('jellyseerr'));
    }

    /**
     * @return array<string, array{0: array{http: ?int, body: ?string, err: string}, 1: string, 2: string, 3: bool}>
     */
    public static function diagnoseCases(): array
    {
        return [
            // [response tuple, service, expected category, expected ok]
            'curl error → network'         => [['http' => null, 'body' => null, 'err' => 'Could not resolve host'], 'radarr', 'network', false],
            'http 200 → ok'                => [['http' => 200,  'body' => '{}',  'err' => ''], 'radarr', 'ok',           true],
            'http 204 → ok'                => [['http' => 204,  'body' => '',    'err' => ''], 'sonarr', 'ok',           true],
            'http 401 → auth'              => [['http' => 401,  'body' => '',    'err' => ''], 'radarr', 'auth',         false],
            'http 403 → forbidden'         => [['http' => 403,  'body' => 'banned', 'err' => ''], 'qbittorrent', 'forbidden', false],
            'http 404 → not_found'         => [['http' => 404,  'body' => '',    'err' => ''], 'jellyseerr', 'not_found', false],
            'http 500 → server_error'      => [['http' => 500,  'body' => '',    'err' => ''], 'tmdb',  'server_error', false],
            'http 502 → server_error'      => [['http' => 502,  'body' => '',    'err' => ''], 'sonarr','server_error', false],
            'qbit 200 + Fails. → auth'     => [['http' => 200,  'body' => 'Fails.', 'err' => ''], 'qbittorrent', 'auth', false],
            'qbit 200 + Ok. → ok'          => [['http' => 200,  'body' => 'Ok.', 'err' => ''], 'qbittorrent', 'ok',     true],
            'http 418 → unknown'           => [['http' => 418,  'body' => '',    'err' => ''], 'radarr', 'unknown',      false],
        ];
    }

    /**
     * @param array{http: ?int, body: ?string, err: string} $resp
     */
    #[DataProvider('diagnoseCases')]
    public function testDiagnoseFromResponseMapsHttpCodesToCategories(array $resp, string $service, string $expectedCategory, bool $expectedOk): void
    {
        $svc = $this->makeService();
        $diag = $svc->diagnoseFromResponse($resp, $service);

        $this->assertSame($expectedCategory, $diag['category']);
        $this->assertSame($expectedOk, $diag['ok']);
        // The http code is preserved (or null on network failure).
        $this->assertSame($resp['err'] !== '' ? null : $resp['http'], $diag['http']);
    }

    public function testDiagnoseReturnsUnconfiguredWhenConfigMissing(): void
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturn(null);

        $svc = $this->makeService(config: $config);
        $diag = $svc->diagnose('radarr');

        $this->assertFalse($diag['ok']);
        $this->assertSame('unconfigured', $diag['category']);
    }

    public function testDiagnoseReturnsUnknownWhenServiceIdIsBogus(): void
    {
        $config = $this->createMock(ConfigService::class);
        $svc = $this->makeService(config: $config);
        $diag = $svc->diagnose('does-not-exist');

        $this->assertFalse($diag['ok']);
        $this->assertSame('unconfigured', $diag['category']);
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

    // ─── SSRF guard (urlBlockedReason) — added in v1.0.6 ───

    /**
     * Anything that isn't HTTP(S) must be rejected before cURL even runs.
     * Without this, an attacker submitting `radarr_url=file:///etc/passwd`
     * during the wizard window could probe the container filesystem.
     */
    #[DataProvider('blockedSchemeProvider')]
    public function testUrlBlockedReasonRejectsNonHttpSchemes(string $url): void
    {
        $this->assertSame('scheme', HealthService::urlBlockedReason($url));
    }

    public static function blockedSchemeProvider(): array
    {
        return [
            'file'   => ['file:///etc/passwd'],
            'gopher' => ['gopher://localhost:6379/_FLUSHALL'],
            'dict'   => ['dict://localhost:11211/stats'],
            'ftp'    => ['ftp://example.com/'],
            'data'   => ['data:text/html,<script>alert(1)</script>'],
            'no-scheme' => ['localhost:8080/api'],
        ];
    }

    public function testUrlBlockedReasonRejectsMalformedUrl(): void
    {
        // A port outside 0-65535 makes parse_url return false (PHP 7+);
        // surface that as "malformed" rather than the misleading "scheme".
        $this->assertSame('malformed', HealthService::urlBlockedReason('http://192.168.1.50:89000/'));
        $this->assertSame('malformed', HealthService::urlBlockedReason('http://host:99999'));
    }

    /**
     * AWS / GCP / Azure expose unauthenticated cloud-metadata endpoints on
     * 169.254.169.254. A SSRF that hits this IP can leak IAM credentials in
     * a few requests, so the IP literal is a hard-block.
     */
    public function testUrlBlockedReasonRejectsLinkLocalIpv4(): void
    {
        $this->assertSame('link-local', HealthService::urlBlockedReason('http://169.254.169.254/latest/meta-data/'));
        $this->assertSame('link-local', HealthService::urlBlockedReason('http://169.254.0.1/'));
    }

    public function testUrlBlockedReasonRejectsLinkLocalEvasions(): void
    {
        // IPv4-mapped IPv6 literal and a trailing-dot host both slipped past the
        // first guard yet still resolve to the metadata IP at curl time.
        $this->assertSame('link-local', HealthService::urlBlockedReason('http://[::ffff:169.254.169.254]/latest/meta-data/'));
        $this->assertSame('link-local', HealthService::urlBlockedReason('http://169.254.169.254./'));
    }

    public function testUrlBlockedReasonAllowsIpv6LoopbackLiteral(): void
    {
        // Stripping the [] brackets must not break a legitimate IPv6 literal
        // (loopback is allowed, same homelab stance as RFC1918).
        $this->assertNull(HealthService::urlBlockedReason('http://[::1]:8080/'));
    }

    public function testUrlBlockedReasonAllowsRfc1918LanIps(): void
    {
        // Critical: Prismarr MUST be able to reach Radarr on 192.168.x or
        // 10.x. We deliberately do NOT blacklist RFC1918 — only the
        // link-local /16 used by cloud metadata.
        $this->assertNull(HealthService::urlBlockedReason('http://192.168.1.50:7878/api/v3/system/status'));
        $this->assertNull(HealthService::urlBlockedReason('http://10.0.0.10:8989/'));
        $this->assertNull(HealthService::urlBlockedReason('http://172.16.5.5:9696/'));
    }

    public function testUrlBlockedReasonAllowsPublicHttps(): void
    {
        $this->assertNull(HealthService::urlBlockedReason('https://api.themoviedb.org/3/configuration'));
    }

    // ─── chips() — shared chip builder (dashboard + topbar single source) ───

    public function testChipsExpandsInstancesAddsColorsAndFiltersUnconfigured(): void
    {
        $svc = $this->chipsService(); // helper below

        $chips = $svc->chips();

        self::assertSame([
            ['id' => 'radarr',  'name' => 'Radarr 1080p', 'status' => 'up', 'latencyMs' => 12, 'color' => '#FFC230'],
            ['id' => 'sabnzbd', 'name' => 'SABnzbd',      'status' => 'up', 'latencyMs' => 30, 'color' => '#fbc531'],
        ], $chips);
    }

    public function testChipsIncludesUnraidOnlyWhenAsked(): void
    {
        $svc = $this->chipsService();

        $ids = array_column($svc->chips(true), 'id');
        self::assertContains('unraid', $ids);
        self::assertSame('#f15a2c', $svc->chips(true)[array_search('unraid', $ids, true)]['color']);

        self::assertNotContains('unraid', array_column($svc->chips(), 'id'));
    }

    /** HealthService with statusFor stubbed: radarr-1/sabnzbd/unraid up, rest unconfigured. */
    private function chipsService(): HealthService
    {
        $inst = $this->createMock(ServiceInstance::class);
        $inst->method('getSlug')->willReturn('radarr-1');
        $inst->method('getName')->willReturn('Radarr 1080p');
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getEnabled')->willReturnCallback(
            fn(string $t): array => $t === ServiceInstance::TYPE_RADARR ? [$inst] : []
        );

        return new class(
            $this->createMock(RadarrClient::class), $this->createMock(SonarrClient::class),
            $this->createMock(ProwlarrClient::class), $this->createMock(JellyseerrClient::class),
            $this->createMock(QBittorrentClient::class), $this->createMock(TmdbClient::class),
            null, null, $instances,
        ) extends HealthService {
            public function statusFor(string $service, ?string $instanceSlug = null): array
            {
                return match ($service) {
                    'radarr'  => ['status' => 'up', 'latencyMs' => 12],
                    'sabnzbd' => ['status' => 'up', 'latencyMs' => 30],
                    'unraid'  => ['status' => 'up', 'latencyMs' => 13],
                    default   => ['status' => null, 'latencyMs' => null],
                };
            }
        };
    }
}
