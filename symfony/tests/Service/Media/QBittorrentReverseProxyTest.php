<?php

namespace App\Tests\Service\Media;

use App\Entity\ServiceInstance;
use App\Service\ConfigService;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\ServiceHealthCache;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Issue #10 — qBittorrent behind a reverse proxy (qui, traefik forward
 * auth, …) must work with EMPTY user/password. The proxy URL contains
 * a secret token and the proxy itself injects the credentials, so Prismarr
 * is supposed to leave the auth flow alone:
 *  - ensureConfig() must NOT throw when user/password are missing
 *  - login() must short-circuit to a sentinel SID without hitting the
 *    network (no /auth/login round-trip with an empty body, which qBit
 *    would answer "Fails." even though the proxy is fine)
 *
 * Issue #131 — the credentials live on each qBittorrent instance, so the
 * no-auth decision is taken per instance: one proxied instance next to one
 * with real credentials must each keep their own mode.
 */
#[AllowMockObjectsWithoutExpectations]
class QBittorrentReverseProxyTest extends TestCase
{
    private static function instance(string $slug, string $url, ?string $user = null, ?string $pass = null): ServiceInstance
    {
        $i = new ServiceInstance(ServiceInstance::TYPE_QBITTORRENT, $slug, ucfirst($slug), $url);
        $i->setUsername($user);
        $i->setPassword($pass);
        $i->setIsDefault(true);
        return $i;
    }

    private function makeClient(?ServiceInstance $default): QBittorrentClient
    {
        $provider = $this->createMock(ServiceInstanceProvider::class);
        $provider->method('getDefault')->willReturn($default);

        return new QBittorrentClient(
            $this->createMock(ConfigService::class),
            $this->createMock(LoggerInterface::class),
            new ServiceHealthCache(new ArrayAdapter()),
            $provider,
        );
    }

    private static function invoke(QBittorrentClient $client, string $method): mixed
    {
        $m = (new \ReflectionClass($client))->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($client);
    }

    private static function prop(QBittorrentClient $client, string $name): mixed
    {
        $p = (new \ReflectionClass($client))->getProperty($name);
        $p->setAccessible(true);
        return $p->getValue($client);
    }

    public function testEnsureConfigDoesNotThrowWhenUserAndPasswordAreEmpty(): void
    {
        $client = $this->makeClient(self::instance('qui', 'http://qui.lan:7476/proxy/abc123'));

        // Critical: this is what "qBit behind reverse-proxy" boils down to.
        // Pre-#10, the missing credentials threw ServiceNotConfiguredException
        // and crashed every page that touched QBittorrentClient.
        self::invoke($client, 'ensureConfig');

        $this->assertSame('http://qui.lan:7476/proxy/abc123', self::prop($client, 'baseUrl'));
        $this->assertSame('', self::prop($client, 'user'));
        $this->assertSame('', self::prop($client, 'password'));
    }

    public function testLoginShortCircuitsToSentinelWhenCredentialsAreEmpty(): void
    {
        $client = $this->makeClient(self::instance('qui', 'http://qui.lan:7476/proxy/abc123'));

        // If the short-circuit didn't trigger, login() would attempt a real
        // curl POST to /auth/login and either succeed (returning a real SID)
        // or fail (returning null + arming the circuit breaker). Either way,
        // we'd see a non-sentinel result here. The sentinel proves we
        // bypassed the network entirely.
        $sid = self::invoke($client, 'login');

        $this->assertIsString($sid);
        $this->assertSame('__noauth__', $sid);
    }

    public function testLoginShortCircuitsWhenOnlyOneCredentialIsEmpty(): void
    {
        // Defensive case: user typed a username but forgot the password.
        // We treat this as "reverse proxy mode" too — see
        // HealthService::qbittorrentProbe().
        $client = $this->makeClient(self::instance('qbit', 'http://qbit.lan:8080', 'admin', null));

        $this->assertSame('__noauth__', self::invoke($client, 'login'));
    }

    public function testNoAuthModeIsDecidedPerInstance(): void
    {
        // The autowired default has real credentials; a bound clone of a
        // proxied instance must still short-circuit, and must not inherit
        // the default instance's username/password.
        $withCreds = self::instance('tv', 'http://qbit-tv.lan:8080', 'admin', 'secret');
        $proxied   = self::instance('movies', 'http://qui.lan:7476/proxy/abc123');
        $proxied->setIsDefault(false);

        $client = $this->makeClient($withCreds)->withInstance($proxied);

        $this->assertSame('__noauth__', self::invoke($client, 'login'));
        $this->assertSame('http://qui.lan:7476/proxy/abc123', self::prop($client, 'baseUrl'));
        $this->assertSame('', self::prop($client, 'user'));
        $this->assertSame($proxied, $client->getInstance());
    }

    public function testBindInstanceSwitchesCredentialsAndDropsTheOldSession(): void
    {
        $client = $this->makeClient(self::instance('qui', 'http://qui.lan:7476/proxy/abc123'));
        $this->assertSame('__noauth__', self::invoke($client, 'login'));

        $client->bindInstance(self::instance('tv', 'http://qbit-tv.lan:8080', 'admin', 'secret'));

        // The sentinel SID of the previous instance must not leak into the
        // next one — otherwise authenticated calls would go out cookie-less.
        $this->assertNull(self::prop($client, 'sid'));
        self::invoke($client, 'ensureConfig');
        $this->assertSame('admin', self::prop($client, 'user'));
        $this->assertSame('secret', self::prop($client, 'password'));
    }

    public function testNoInstanceThrowsServiceNotConfigured(): void
    {
        $client = $this->makeClient(null);

        $this->expectException(\App\Exception\ServiceNotConfiguredException::class);
        self::invoke($client, 'ensureConfig');
    }

    public function testWithInstanceRejectsAnotherServiceType(): void
    {
        $client = $this->makeClient(null);

        $this->expectException(\InvalidArgumentException::class);
        $client->withInstance(new ServiceInstance(ServiceInstance::TYPE_RADARR, 'radarr-1', 'Radarr 1', 'http://radarr.lan:7878'));
    }
}
