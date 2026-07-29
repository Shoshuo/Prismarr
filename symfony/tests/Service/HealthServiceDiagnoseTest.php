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
use App\Service\Media\ServiceHealthCache;
use App\Service\Media\Usenet\SabnzbdClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pure-logic coverage of HealthService for the Usenet wiring (#20):
 *  - diagnoseFromResponse() maps an HTTP probe result to a category. SABnzbd
 *    answers 403 for BOTH a bad API key and a non-whitelisted host, so the
 *    body must disambiguate into `auth` vs `host_whitelist`.
 *  - isConfigured() requirements: SABnzbd needs URL + key, NZBGet only URL.
 * No network here — everything keys off injected ConfigService / response tuples.
 */
#[AllowMockObjectsWithoutExpectations]
class HealthServiceDiagnoseTest extends TestCase
{
    private function makeService(?ConfigService $config = null): HealthService
    {
        return new HealthService(
            $this->createMock(RadarrClient::class),
            $this->createMock(SonarrClient::class),
            $this->createMock(ProwlarrClient::class),
            $this->createMock(JellyseerrClient::class),
            $this->createMock(QBittorrentClient::class),
            $this->createMock(TmdbClient::class),
            $config,
        );
    }

    public function testSabnzbdHostWhitelistRefusal(): void
    {
        $resp = ['http' => 403, 'body' => 'Access denied - Hostname verification failed', 'err' => ''];
        self::assertSame('host_whitelist', $this->makeService()->diagnoseFromResponse($resp, 'sabnzbd')['category']);
    }

    public function testSabnzbdBadApiKeyIsAuth(): void
    {
        $resp = ['http' => 403, 'body' => 'API Key Incorrect', 'err' => ''];
        self::assertSame('auth', $this->makeService()->diagnoseFromResponse($resp, 'sabnzbd')['category']);
    }

    public function testSabnzbdHealthyVersion(): void
    {
        $resp = ['http' => 200, 'body' => '{"version":"5.0.3"}', 'err' => ''];
        self::assertSame('ok', $this->makeService()->diagnoseFromResponse($resp, 'sabnzbd')['category']);
    }

    public function testNzbgetNetworkError(): void
    {
        $resp = ['http' => null, 'body' => null, 'err' => 'Connection refused'];
        self::assertSame('network', $this->makeService()->diagnoseFromResponse($resp, 'nzbget')['category']);
    }

    /**
     * Transmission's session-id handshake is the one case in this codebase
     * where a non-2xx status means "reachable": the probe is deliberately
     * sent WITHOUT a session id, so a healthy daemon always answers 409
     * with the real token in a response header — that is 'ok', not a failure.
     */
    public function testTransmission409HandshakeIsOk(): void
    {
        $resp = ['http' => 409, 'body' => '{"result":"Conflict"}', 'err' => ''];
        self::assertSame('ok', $this->makeService()->diagnoseFromResponse($resp, 'transmission')['category']);
    }

    public function testTransmission401BadRpcCredentialsIsAuth(): void
    {
        $resp = ['http' => 401, 'body' => '', 'err' => ''];
        self::assertSame('auth', $this->makeService()->diagnoseFromResponse($resp, 'transmission')['category']);
    }

    public function testTransmission200SuccessIsOk(): void
    {
        $resp = ['http' => 200, 'body' => '{"result":"success","arguments":{"version":"4.0.5"}}', 'err' => ''];
        self::assertSame('ok', $this->makeService()->diagnoseFromResponse($resp, 'transmission')['category']);
    }

    public function testPassiveDiagnoseShortCircuitsWhenBreakerDown(): void
    {
        // #20 perf: a passive diagnosis (no overrides) must honour the circuit
        // breaker — returning 'network' immediately rather than re-probing a
        // known-down client and waiting out the connect timeout (which lagged
        // the page render). Proven by it NOT falling through to 'unconfigured'.
        $cache = $this->createMock(ServiceHealthCache::class);
        $cache->method('isDown')->willReturn(true);
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturn(null);
        $config->method('has')->willReturn(false); // would be 'unconfigured' without the breaker

        $health = new HealthService(
            $this->createMock(RadarrClient::class),
            $this->createMock(SonarrClient::class),
            $this->createMock(ProwlarrClient::class),
            $this->createMock(JellyseerrClient::class),
            $this->createMock(QBittorrentClient::class),
            $this->createMock(TmdbClient::class),
            $config,
            $cache,
        );

        self::assertSame('network', $health->diagnose('sabnzbd')['category']);
    }

    public function testTestConnectionIgnoresBreaker(): void
    {
        // With overrides (a "Test connection" click) the breaker is bypassed so
        // the admin always gets a fresh probe. No URL in the overrides → it
        // falls through to 'unconfigured' (no network), proving it didn't
        // short-circuit on the breaker.
        $cache = $this->createMock(ServiceHealthCache::class);
        $cache->method('isDown')->willReturn(true);

        $health = new HealthService(
            $this->createMock(RadarrClient::class),
            $this->createMock(SonarrClient::class),
            $this->createMock(ProwlarrClient::class),
            $this->createMock(JellyseerrClient::class),
            $this->createMock(QBittorrentClient::class),
            $this->createMock(TmdbClient::class),
            $this->createMock(ConfigService::class),
            $cache,
        );

        self::assertSame('unconfigured', $health->diagnose('sabnzbd', [])['category']);
    }

    public function testSabnzbdPillIsDownWhenClientPingFails(): void
    {
        // Regression (#20): the pill must reflect the client's key-aware ping
        // (mode=queue → false on a wrong key / down) so it stays consistent
        // with the circuit breaker, not green-on-broken-key.
        $health = $this->makeServiceWithSabPing(false);
        self::assertFalse($health->isHealthy('sabnzbd'));
    }

    public function testSabnzbdPillIsUpWhenClientPingOk(): void
    {
        $health = $this->makeServiceWithSabPing(true);
        self::assertTrue($health->isHealthy('sabnzbd'));
    }

    /**
     * A HealthService with a configured SABnzbd client whose ping() is stubbed,
     * so the real isHealthy() → pingFor() wiring is exercised without network.
     */
    private function makeServiceWithSabPing(bool $ping): HealthService
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturn(null);           // sabnzbd_enabled unset → not disabled
        $config->method('has')->willReturn(true);            // sabnzbd_url + sabnzbd_api_key present

        $sab = $this->createMock(SabnzbdClient::class);
        $sab->method('ping')->willReturn($ping);

        return new HealthService(
            $this->createMock(RadarrClient::class),
            $this->createMock(SonarrClient::class),
            $this->createMock(ProwlarrClient::class),
            $this->createMock(JellyseerrClient::class),
            $this->createMock(QBittorrentClient::class),
            $this->createMock(TmdbClient::class),
            $config,
            null,
            null,
            $sab,
        );
    }

    public function testSabnzbdNeedsUrlAndKey(): void
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturn(null);
        $config->method('has')->willReturnCallback(fn(string $k) => $k === 'sabnzbd_url');
        // URL only → not configured (key missing).
        self::assertFalse($this->makeService($config)->isConfigured('sabnzbd'));
    }

    public function testNzbgetNeedsOnlyUrl(): void
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturn(null);
        $config->method('has')->willReturnCallback(fn(string $k) => $k === 'nzbget_url');
        self::assertTrue($this->makeService($config)->isConfigured('nzbget'));
    }

    // The SABnzbd probe MUST hit an authenticated mode. mode=version answers
    // 200 for ANY key, so a broken key would test green; mode=queue actually
    // rejects a bad key with 403. Pin the endpoint so we can't regress to it.
    public function testSabnzbdProbeUsesAuthenticatedMode(): void
    {
        $probe = $this->probeFor('sabnzbd', ['sabnzbd_url' => 'http://sab:8080', 'sabnzbd_api_key' => 'k']);
        self::assertNotNull($probe);
        self::assertStringContainsString('mode=queue', $probe['url']);
        self::assertStringNotContainsString('mode=version', $probe['url']);
    }

    /**
     * @param array<string, ?string> $overrides
     * @return array{url: string, headers?: array<int,string>, method?: string, body?: string}|null
     */
    private function probeFor(string $service, array $overrides): ?array
    {
        $m = new ReflectionMethod(HealthService::class, 'probeFor');
        $m->setAccessible(true);
        return $m->invoke($this->makeService(), $service, $overrides);
    }

    // Deluge (#deluge-tab): deluge-web answers HTTP 200 for everything — the
    // real outcome lives in the JSON-RPC envelope, same shape problem as
    // Tautulli above but with a different success/failure encoding.
    public function testDelugeWrongPasswordIsAuthNotOk(): void
    {
        $health = $this->makeService();
        $r = $health->diagnoseFromResponse(
            ['http' => 200, 'body' => '{"result": false, "error": null, "id": 1}', 'err' => ''],
            'deluge'
        );
        $this->assertFalse($r['ok']);
        $this->assertSame('auth', $r['category']);
    }

    public function testDelugeRpcErrorEnvelopeIsAuth(): void
    {
        $health = $this->makeService();
        $r = $health->diagnoseFromResponse(
            ['http' => 200, 'body' => '{"result": null, "error": {"message": "Not authenticated", "code": 1}, "id": 1}', 'err' => ''],
            'deluge'
        );
        $this->assertFalse($r['ok']);
        $this->assertSame('auth', $r['category']);
    }

    public function testDelugeSuccessEnvelopeIsOk(): void
    {
        $health = $this->makeService();
        $r = $health->diagnoseFromResponse(
            ['http' => 200, 'body' => '{"result": true, "error": null, "id": 1}', 'err' => ''],
            'deluge'
        );
        $this->assertTrue($r['ok']);
        $this->assertSame('ok', $r['category']);
    }
}
