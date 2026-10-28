<?php

namespace App\Tests\Service\Media;

use App\Entity\ServiceInstance;
use App\Service\Media\GluetunClient;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Regression suite for the Gluetun control-server integration:
 *
 *  1. The client used to authenticate with `Authorization: Bearer <key>`, but
 *     Gluetun reads the key from the `X-API-Key` header, so any configured key
 *     would not work and the integration only worked if the authentication was
 *     set to "none".
 *  2. The endpoint fallbacks `/v1/wireguard/status` and
 *     `/v1/wireguard/portforwarded` don't exist.
 */
#[AllowMockObjectsWithoutExpectations]
class GluetunClientTest extends TestCase
{
    private static function gluetun(string $slug, string $url, ?string $apiKey = null): ServiceInstance
    {
        return new ServiceInstance(ServiceInstance::TYPE_GLUETUN, $slug, ucfirst($slug), $url, $apiKey);
    }

    /**
     * Client whose unbound fallback is the Gluetun linked to the default
     * qBittorrent instance ($linked; null = no qBittorrent or no link).
     */
    private function clientWithDefault(?ServiceInstance $linked, bool $hasQbittorrent = true): GluetunClient
    {
        $qbt = new ServiceInstance(ServiceInstance::TYPE_QBITTORRENT, 'qbittorrent-1', 'qBittorrent 1', 'http://qbit.lan:8080');
        $provider = $this->createMock(ServiceInstanceProvider::class);
        $provider->method('getDefault')->willReturn($hasQbittorrent ? $qbt : null);
        $provider->method('getGluetunFor')->willReturnCallback(fn (ServiceInstance $i) => $i === $qbt ? $linked : null);

        return new GluetunClient($provider, $this->createMock(LoggerInterface::class));
    }

    private function makeClient(?string $apiKey): GluetunClient
    {
        return $this->clientWithDefault(self::gluetun('gluetun-1', 'http://gluetun:8000', $apiKey));
    }

    private function invokePrivate(GluetunClient $client, string $method): mixed
    {
        return (new \ReflectionMethod($client, $method))->invoke($client);
    }

    public function testAuthHeaderUsesXApiKeyNotBearer(): void
    {
        $headers = $this->invokePrivate($this->makeClient('s3cret-key'), 'authHeaders');

        $this->assertSame(['X-API-Key: s3cret-key'], $headers);
        $this->assertStringNotContainsStringIgnoringCase(
            'authorization',
            implode("\n", $headers),
            'Gluetun does not accept Authorization: Bearer'
        );
    }

    public function testNoAuthHeaderWhenKeyIsBlank(): void
    {
        foreach (['', null] as $blank) {
            $headers = $this->invokePrivate($this->makeClient($blank), 'authHeaders');
            $this->assertSame([], $headers);
        }
    }

    public function testStatusPathsAreUnifiedFirstWithNoWireguardEndpoint(): void
    {
        $paths = $this->invokePrivate($this->makeClient(null), 'statusPaths');

        $this->assertSame(['/v1/vpn/status', '/v1/openvpn/status'], $paths);
        $this->assertNoWireguardPath($paths);
    }

    public function testPortPathsAreUnifiedFirstWithNoWireguardEndpoint(): void
    {
        $paths = $this->invokePrivate($this->makeClient(null), 'portPaths');

        $this->assertSame(['/v1/portforward', '/v1/openvpn/portforwarded'], $paths);
        $this->assertNoWireguardPath($paths);
    }

    private function assertNoWireguardPath(array $paths): void
    {
        foreach ($paths as $path) {
            $this->assertStringNotContainsString(
                '/wireguard/',
                $path,
                'Gluetun does not have /v1/wireguard/* control endpoints.'
            );
        }
    }

    /**
     * Issue #131 — Gluetun is a multi-instance service; the URL / key come
     * from the Gluetun instance the client is bound to (the one linked to
     * the qBittorrent instance of the page), not from a global setting.
     */
    public function testBoundInstanceWinsOverTheDefault(): void
    {
        $client = $this->clientWithDefault(self::gluetun('tv', 'http://gluetun-tv:8000', 'tv-key'));
        $client->bindInstance(self::gluetun('movies', 'http://gluetun-movies:8000', 'movies-key'));

        $this->assertTrue($client->isConfigured());
        $this->assertSame('movies', $client->getInstance()?->getSlug());
        $this->assertSame(['X-API-Key: movies-key'], $this->invokePrivate($client, 'authHeaders'));
        $this->assertSame('http://gluetun-movies:8000', (new \ReflectionProperty($client, 'baseUrl'))->getValue($client));
    }

    public function testUnboundFollowsTheGluetunLinkedToTheDefaultQbittorrent(): void
    {
        $client = $this->clientWithDefault(self::gluetun('tv', 'http://gluetun-tv:8000', 'tv-key'));

        $this->assertSame('tv', $client->getInstance()?->getSlug());
        $this->assertSame(['X-API-Key: tv-key'], $this->invokePrivate($client, 'authHeaders'));
    }

    public function testBoundToNoneIsNotConfiguredEvenWhenTheDefaultHasAGluetun(): void
    {
        // A qBittorrent instance without a linked Gluetun gets the 1.3.0
        // empty VPN card — it must NOT borrow the default instance's Gluetun.
        $client = $this->clientWithDefault(self::gluetun('tv', 'http://gluetun-tv:8000', 'tv-key'));
        $client->bindInstance(null);

        $this->assertNull($client->getInstance());
        $this->assertFalse($client->isConfigured());
        // No URL → no HTTP call at all, every getter degrades to null.
        $this->assertNull($client->getPublicIp());
        $this->assertNull($client->getForwardedPort());
        $this->assertFalse($client->getSummary()['ok']);
    }

    public function testResetDropsTheBinding(): void
    {
        $client = $this->clientWithDefault(self::gluetun('tv', 'http://gluetun-tv:8000'));
        $client->bindInstance(null);
        $client->reset();

        $this->assertSame('tv', $client->getInstance()?->getSlug());
    }

    public function testWithInstanceReturnsAnIndependentClone(): void
    {
        $client = $this->clientWithDefault(self::gluetun('tv', 'http://gluetun-tv:8000', 'tv-key'));
        $clone  = $client->withInstance(self::gluetun('movies', 'http://gluetun-movies:8000'));

        $this->assertSame([], $this->invokePrivate($clone, 'authHeaders'));
        // The original keeps following the default instance.
        $this->assertSame(['X-API-Key: tv-key'], $this->invokePrivate($client, 'authHeaders'));
    }

    public function testNoLinkedGluetunIsNotConfigured(): void
    {
        $this->assertFalse($this->clientWithDefault(null)->isConfigured());
    }

    public function testNoQbittorrentInstanceAtAllIsNotConfigured(): void
    {
        $this->assertFalse($this->clientWithDefault(self::gluetun('tv', 'http://gluetun-tv:8000'), hasQbittorrent: false)->isConfigured());
    }

    public function testRejectsANonGluetunInstance(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->clientWithDefault(null)->bindInstance(
            new ServiceInstance(ServiceInstance::TYPE_QBITTORRENT, 'qbittorrent-1', 'qBittorrent 1', 'http://qbit.lan:8080')
        );
    }
}
