<?php

namespace App\Tests\Service\Media;

use App\Service\ConfigService;
use App\Service\Media\GluetunClient;
use App\Service\Media\JellyseerrClient;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use App\Service\Media\TmdbClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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
        $config = $this->createMock(ConfigService::class);
        $config->method('require')->willReturn('dummy'); // baseUrl or apiKey

        $client = new RadarrClient(
            $config,
            $this->createMock(LoggerInterface::class),
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
