<?php

namespace App\Tests\Service\Media;

use App\Service\Media\ServiceHealthCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Cross-request "service down" cache used by Media clients to short-circuit
 * upstream calls instantly while a service is unreachable, instead of paying
 * a 4 s connect timeout on every navigation in worker mode.
 *
 * Tests use Symfony's ArrayAdapter as the PSR-6 backend so we exercise the
 * real expiry semantics without touching the filesystem cache pool.
 */
class ServiceHealthCacheTest extends TestCase
{
    public function testIsDownReturnsFalseWhenNothingMarked(): void
    {
        $cache = new ServiceHealthCache(new ArrayAdapter());

        $this->assertFalse($cache->isDown('radarr'));
        $this->assertFalse($cache->isDown('sonarr'));
    }

    public function testMarkDownThenIsDownReturnsTrue(): void
    {
        $cache = new ServiceHealthCache(new ArrayAdapter());

        $cache->markDown('radarr');

        $this->assertTrue($cache->isDown('radarr'));
    }

    public function testClearRemovesTheDownFlag(): void
    {
        $cache = new ServiceHealthCache(new ArrayAdapter());

        $cache->markDown('radarr');
        $this->assertTrue($cache->isDown('radarr'));

        $cache->clear('radarr');

        $this->assertFalse($cache->isDown('radarr'));
    }

    public function testServicesAreIndependent(): void
    {
        $cache = new ServiceHealthCache(new ArrayAdapter());

        $cache->markDown('radarr');

        $this->assertTrue($cache->isDown('radarr'));
        $this->assertFalse($cache->isDown('sonarr'));
        $this->assertFalse($cache->isDown('prowlarr'));
        $this->assertFalse($cache->isDown('jellyseerr'));
        $this->assertFalse($cache->isDown('qbittorrent'));

        $cache->clear('radarr');
        $cache->markDown('sonarr');

        $this->assertFalse($cache->isDown('radarr'));
        $this->assertTrue($cache->isDown('sonarr'));
    }

    /**
     * The TTL is intentionally short (10 s) so recovery is detected without
     * manual intervention. Hard-coding the expectation here makes any future
     * change of TTL_DOWN a deliberate, visible decision rather than a silent
     * regression that bumps service-down windows in production.
     */
    public function testTtlConstantIsTenSeconds(): void
    {
        $this->assertSame(10, ServiceHealthCache::TTL_DOWN);
    }

    /**
     * Verify markDown() actually applies a finite TTL — without sleeping.
     * We seed the adapter, simulate elapsed time by rewriting the internal
     * `expiries` map to a past timestamp, and assert the entry has expired.
     */
    public function testMarkDownRespectsTtlExpiry(): void
    {
        $adapter = new ArrayAdapter();
        $cache   = new ServiceHealthCache($adapter);

        $cache->markDown('radarr');
        $this->assertTrue($cache->isDown('radarr'));

        // Force every adapter entry into the past so the TTL elapses without
        // the test having to sleep 11 s.
        $ref = new \ReflectionClass($adapter);
        $expiriesProp = $ref->getProperty('expiries');
        $expiriesProp->setAccessible(true);
        /** @var array<string, float> $expiries */
        $expiries = $expiriesProp->getValue($adapter);
        $this->assertNotEmpty($expiries, 'markDown() must register an expiry — a missed expiresAfter() would leave this empty');

        foreach ($expiries as $key => $_) {
            $expiries[$key] = microtime(true) - 1.0;
        }
        $expiriesProp->setValue($adapter, $expiries);

        $this->assertFalse(
            $cache->isDown('radarr'),
            'Entry should be expired once its TTL elapses'
        );
    }

    /**
     * v1.1.0 — each Radarr/Sonarr instance has its own circuit breaker.
     * Marking 'radarr-4k' down must NOT silence 'radarr-1080p' or any other
     * sibling, otherwise a single ailing instance would freeze the whole
     * media UI for TTL_DOWN seconds across instances.
     */
    public function testInstancesOfTheSameServiceAreIndependent(): void
    {
        $cache = new ServiceHealthCache(new ArrayAdapter());

        $cache->markDown('radarr', 'radarr-4k');

        $this->assertTrue($cache->isDown('radarr', 'radarr-4k'));
        $this->assertFalse($cache->isDown('radarr', 'radarr-1080p'));
        $this->assertFalse($cache->isDown('radarr'), 'unkeyed lookup must not pick up an instance-keyed mark');
    }

    public function testClearOnOneInstanceLeavesSiblingsAlone(): void
    {
        $cache = new ServiceHealthCache(new ArrayAdapter());

        $cache->markDown('radarr', 'radarr-4k');
        $cache->markDown('radarr', 'radarr-1080p');

        $cache->clear('radarr', 'radarr-4k');

        $this->assertFalse($cache->isDown('radarr', 'radarr-4k'));
        $this->assertTrue($cache->isDown('radarr', 'radarr-1080p'),
            'clearing one instance must not clear another');
    }

    public function testUnkeyedAndKeyedEntriesCoexist(): void
    {
        // Defensive: callers that don't pass a slug (legacy, jellyseerr,
        // prowlarr, qbittorrent — all single-instance services) keep using
        // the unkeyed form. That entry is independent of the per-instance
        // ones.
        $cache = new ServiceHealthCache(new ArrayAdapter());

        $cache->markDown('jellyseerr');
        $cache->markDown('radarr', 'radarr-1');

        $this->assertTrue($cache->isDown('jellyseerr'));
        $this->assertTrue($cache->isDown('radarr', 'radarr-1'));
        $this->assertFalse($cache->isDown('radarr'));
        $this->assertFalse($cache->isDown('jellyseerr', 'radarr-1'));
    }
}
