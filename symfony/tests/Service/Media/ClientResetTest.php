<?php

namespace App\Tests\Service\Media;

use App\Entity\ServiceInstance;
use App\Service\ConfigService;
use App\Service\Media\GluetunClient;
use App\Service\Media\JellyseerrClient;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\ServiceHealthCache;
use App\Service\Media\SonarrClient;
use App\Service\Media\TmdbClient;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Regression suite for the FrankenPHP worker-mode bug: Media clients cached
 * their `$apiKey` / `$baseUrl` in instance properties and kept them alive
 * across requests, so an admin updating a service key via /admin/settings
 * got the stale value until the worker recycled (10–30 min). Fixed by
 * making each client implement ResetInterface — Symfony calls reset()
 * between requests in worker mode.
 */
#[AllowMockObjectsWithoutExpectations]
class ClientResetTest extends TestCase
{
    /**
     * @return list<array{0: string}>
     */
    public static function clientClassesProvider(): array
    {
        return [
            [TmdbClient::class],
            [RadarrClient::class],
            [SonarrClient::class],
            [ProwlarrClient::class],
            [JellyseerrClient::class],
            [GluetunClient::class],
            [QBittorrentClient::class],
        ];
    }

    #[DataProvider('clientClassesProvider')]
    public function testEachMediaClientImplementsResetInterface(string $class): void
    {
        $this->assertTrue(
            is_subclass_of($class, ResetInterface::class),
            "$class must implement ResetInterface so the worker reloads config"
        );
    }

    public function testTmdbClientResetClearsCachedApiKey(): void
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('require')
            ->willReturnOnConsecutiveCalls('old-key', 'new-key');

        $client = new TmdbClient(
            $config,
            $this->createMock(CacheInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(\App\Service\DisplayPreferencesService::class),
        );

        // Force first config load.
        $ref = new \ReflectionClass($client);
        $load = $ref->getMethod('ensureConfig');
        $load->setAccessible(true);
        $apiKeyProp = $ref->getProperty('apiKey');
        $apiKeyProp->setAccessible(true);

        $load->invoke($client);
        $this->assertSame('old-key', $apiKeyProp->getValue($client));

        // Admin updated the key via /admin/settings → Symfony calls reset()
        $client->reset();
        $this->assertSame('', $apiKeyProp->getValue($client));

        // Next request → client reloads, picks up the new key.
        $load->invoke($client);
        $this->assertSame('new-key', $apiKeyProp->getValue($client));
    }

    public function testRadarrClientResetClearsBothBaseUrlAndApiKey(): void
    {
        // v1.1.0 — clients pull from ServiceInstanceProvider instead of
        // ConfigService. Stub a default instance so ensureConfig() succeeds.
        $instance = new ServiceInstance(
            ServiceInstance::TYPE_RADARR,
            'radarr-1',
            'Radarr 1',
            'http://radarr.test:7878',
            'dummy-api-key',
        );
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getDefault')->with(ServiceInstance::TYPE_RADARR)->willReturn($instance);

        $client = new RadarrClient(
            $instances,
            $this->createMock(LoggerInterface::class),
            new ServiceHealthCache(new ArrayAdapter()),
        );

        $ref = new \ReflectionClass($client);
        $load = $ref->getMethod('ensureConfig');
        $load->setAccessible(true);
        $baseUrl = $ref->getProperty('baseUrl');
        $baseUrl->setAccessible(true);
        $apiKey = $ref->getProperty('apiKey');
        $apiKey->setAccessible(true);

        $load->invoke($client);
        $this->assertNotSame('', $baseUrl->getValue($client));
        $this->assertNotSame('', $apiKey->getValue($client));

        $client->reset();
        $this->assertSame('', $baseUrl->getValue($client));
        $this->assertSame('', $apiKey->getValue($client));
    }

    public function testQBittorrentClientResetClearsSessionAndCaches(): void
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('require')->willReturn('dummy');

        $client = new QBittorrentClient(
            $config,
            $this->createMock(LoggerInterface::class),
            new ServiceHealthCache(new ArrayAdapter()),
        );

        $ref = new \ReflectionClass($client);
        $load = $ref->getMethod('ensureConfig');
        $load->setAccessible(true);
        $load->invoke($client);

        // Prime session + server state cache to simulate a worker that
        // already served several requests.
        $sid = $ref->getProperty('sid');
        $sid->setAccessible(true);
        $sid->setValue($client, 'SID=abc123');

        $stateCache = $ref->getProperty('serverStateCache');
        $stateCache->setAccessible(true);
        $stateCache->setValue($client, ['alltime_dl' => 123]);

        $stateCacheAt = $ref->getProperty('serverStateCacheAt');
        $stateCacheAt->setAccessible(true);
        $stateCacheAt->setValue($client, microtime(true));

        $baseUrl = $ref->getProperty('baseUrl');
        $baseUrl->setAccessible(true);
        $this->assertNotSame('', $baseUrl->getValue($client));

        $client->reset();

        $this->assertSame('', $baseUrl->getValue($client));
        $this->assertNull($sid->getValue($client));
        $this->assertNull($stateCache->getValue($client));
        $this->assertSame(0.0, $stateCacheAt->getValue($client));
    }

    public function testGluetunClientResetDropsAllCaches(): void
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturn(null);

        $client = new GluetunClient(
            $config,
            $this->createMock(LoggerInterface::class),
        );

        $ref = new \ReflectionClass($client);
        $configLoaded = $ref->getProperty('configLoaded');
        $configLoaded->setAccessible(true);
        $statusCache = $ref->getProperty('statusCache');
        $statusCache->setAccessible(true);

        // Force first config load + prime a cache.
        $load = $ref->getMethod('ensureConfig');
        $load->setAccessible(true);
        $load->invoke($client);
        $statusCache->setValue($client, 'running');
        $this->assertTrue($configLoaded->getValue($client));

        $client->reset();

        $this->assertFalse($configLoaded->getValue($client));
        $this->assertNull($statusCache->getValue($client));
    }
}
