<?php

namespace App\Tests\Service\Media;

use App\Service\Media\RadarrClient;
use App\Service\Media\ServiceHealthCache;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Covers the in-process circuit breaker on Media clients.
 *
 * Two layers protect the UI from a 4 s connect timeout per call when an
 * upstream service is down:
 *  1. Cross-request: ServiceHealthCache::isDown() short-circuits at the
 *     start of every HTTP method.
 *  2. In-request: $serviceUnavailable flag avoids re-trying within a single
 *     PHP request once a network error is observed.
 *
 * This test verifies the cross-request short-circuit triggers the
 * in-request flag and returns instantly without hitting curl.
 */
#[AllowMockObjectsWithoutExpectations]
class ClientCircuitBreakerTest extends TestCase
{
    public function testGetMoviesShortCircuitsWhenHealthCacheMarksRadarrDown(): void
    {
        $cache = new ServiceHealthCache(new ArrayAdapter());
        $cache->markDown('radarr');

        // The breaker fires before ensureConfig() is reached, so the provider
        // mock can stay empty (no instances). If the breaker leaked, the
        // client would throw ServiceNotConfiguredException — easy to spot.
        $instances = $this->createMock(ServiceInstanceProvider::class);

        $client = new RadarrClient(
            $instances,
            $this->createMock(LoggerInterface::class),
            $cache,
        );

        $start  = microtime(true);
        $result = $client->getMovies();
        $duration = microtime(true) - $start;

        // getMovies() returns array; on short-circuit get() returns null and
        // getMovies() falls back to [].
        $this->assertSame([], $result);
        $this->assertLessThan(
            0.1,
            $duration,
            'Circuit breaker should short-circuit instantly (no curl attempt)'
        );
    }

    public function testShortCircuitFlipsInProcessFlagToTrue(): void
    {
        $cache = new ServiceHealthCache(new ArrayAdapter());
        $cache->markDown('radarr');

        $instances = $this->createMock(ServiceInstanceProvider::class);

        $client = new RadarrClient(
            $instances,
            $this->createMock(LoggerInterface::class),
            $cache,
        );

        $ref = new \ReflectionClass($client);
        $flag = $ref->getProperty('serviceUnavailable');
        $flag->setAccessible(true);

        $this->assertFalse(
            $flag->getValue($client),
            'Flag should start false on a fresh client'
        );

        $client->getMovies();

        $this->assertTrue(
            $flag->getValue($client),
            'Health cache hit should propagate to the in-request circuit-breaker flag'
        );
    }

    public function testResetClearsTheCircuitBreakerFlagBetweenRequests(): void
    {
        $cache = new ServiceHealthCache(new ArrayAdapter());
        $cache->markDown('radarr');

        $instances = $this->createMock(ServiceInstanceProvider::class);

        $client = new RadarrClient(
            $instances,
            $this->createMock(LoggerInterface::class),
            $cache,
        );

        // Trip the breaker.
        $client->getMovies();

        $ref = new \ReflectionClass($client);
        $flag = $ref->getProperty('serviceUnavailable');
        $flag->setAccessible(true);
        $this->assertTrue($flag->getValue($client));

        // Worker-mode reset() must clear it so the next request gets a fair
        // shot at the upstream.
        $client->reset();

        $this->assertFalse(
            $flag->getValue($client),
            'reset() must clear the circuit-breaker flag between worker requests'
        );
    }

    public function testWithoutDownMarkClientDoesNotShortCircuitImmediately(): void
    {
        // Sanity check: when the cache is empty, the client must NOT
        // short-circuit on its first call — otherwise the breaker would
        // permanently disable a healthy service.
        $cache = new ServiceHealthCache(new ArrayAdapter());

        $instances = $this->createMock(ServiceInstanceProvider::class);

        $client = new RadarrClient(
            $instances,
            $this->createMock(LoggerInterface::class),
            $cache,
        );

        $ref = new \ReflectionClass($client);
        $flag = $ref->getProperty('serviceUnavailable');
        $flag->setAccessible(true);

        // Before any call, the flag stays false — the breaker is opt-in,
        // never tripped without a prior failure observation.
        $this->assertFalse($flag->getValue($client));
    }
}
