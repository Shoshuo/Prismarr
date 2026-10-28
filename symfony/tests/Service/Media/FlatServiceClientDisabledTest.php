<?php

namespace App\Tests\Service\Media;

use App\Exception\ServiceNotConfiguredException;
use App\Service\ConfigService;
use App\Service\DisplayPreferencesService;
use App\Service\Media\JellyseerrClient;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\ServiceHealthCache;
use App\Service\Media\TmdbClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Issue #15 — when the per-service kill switch is on, the client behaves
 * as if it weren't configured: any method that needs the upstream throws
 * ServiceNotConfiguredException so dashboard widgets, the qBit poll, and
 * every other direct caller stop talking to the "disabled" service.
 */
#[AllowMockObjectsWithoutExpectations]
class FlatServiceClientDisabledTest extends TestCase
{
    /**
     * @param array<string,?string> $config  Mapping of setting keys → values.
     */
    private function configReturning(array $values): ConfigService
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturnCallback(
            fn(string $k) => $values[$k] ?? null
        );
        // require() shouldn't be reached when the disabled-check fires first;
        // returning the value if present keeps the helper symmetric.
        $config->method('require')->willReturnCallback(
            fn(string $k) => (string) ($values[$k] ?? '')
        );
        return $config;
    }

    private static function ensureConfigOf(object $client): void
    {
        $m = (new \ReflectionClass($client))->getMethod('ensureConfig');
        $m->setAccessible(true);
        $m->invoke($client);
    }

    public function testProwlarrClientThrowsWhenDisabled(): void
    {
        $client = new ProwlarrClient(
            $this->configReturning(['prowlarr_enabled' => '0']),
            $this->createMock(\Psr\Log\LoggerInterface::class),
            new ServiceHealthCache(new ArrayAdapter()),
        );

        $this->expectException(ServiceNotConfiguredException::class);
        $this->expectExceptionMessageMatches('/prowlarr_enabled/');
        self::ensureConfigOf($client);
    }

    public function testJellyseerrClientThrowsWhenDisabled(): void
    {
        $client = new JellyseerrClient(
            $this->configReturning(['jellyseerr_enabled' => '0']),
            $this->createMock(\Psr\Log\LoggerInterface::class),
            new ServiceHealthCache(new ArrayAdapter()),
        );

        $this->expectException(ServiceNotConfiguredException::class);
        $this->expectExceptionMessageMatches('/jellyseerr_enabled/');
        self::ensureConfigOf($client);
    }

    public function testQBittorrentClientThrowsWhenDisabled(): void
    {
        $client = new QBittorrentClient(
            $this->configReturning(['qbittorrent_enabled' => '0']),
            new NullLogger(),
            new ServiceHealthCache(new ArrayAdapter()),
            $this->createMock(\App\Service\ServiceInstanceProvider::class),
        );

        $this->expectException(ServiceNotConfiguredException::class);
        $this->expectExceptionMessageMatches('/qbittorrent_enabled/');
        self::ensureConfigOf($client);
    }

    public function testTmdbClientThrowsWhenDisabled(): void
    {
        $client = new TmdbClient(
            $this->configReturning(['tmdb_enabled' => '0']),
            new \Symfony\Component\Cache\Adapter\NullAdapter(),
            new NullLogger(),
            $this->createMock(DisplayPreferencesService::class),
        );

        $this->expectException(ServiceNotConfiguredException::class);
        $this->expectExceptionMessageMatches('/tmdb_enabled/');
        self::ensureConfigOf($client);
    }

    public function testAbsentFlagFallsThroughToTheNormalConfigCheck(): void
    {
        // An empty-but-present URL means the credential-check path runs and
        // throws on its own — the point of this test is that the disabled
        // branch did NOT trip. `require()` would normally throw too; here
        // require() returns '' from configReturning so we just check that
        // we reach the URL load (baseUrl ends up '' since require returns '').
        $client = new ProwlarrClient(
            $this->configReturning([]), // no *_enabled row, no credentials
            $this->createMock(\Psr\Log\LoggerInterface::class),
            new ServiceHealthCache(new ArrayAdapter()),
        );

        // No exception expected from the disabled branch — the credential
        // path runs (and the empty values are stored). The test passes if
        // ensureConfig returns without throwing the disabled exception.
        self::ensureConfigOf($client);
        $this->expectNotToPerformAssertions();
    }
}
