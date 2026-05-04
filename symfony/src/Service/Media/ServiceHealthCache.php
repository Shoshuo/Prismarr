<?php

namespace App\Service\Media;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Cross-request "service down" cache.
 *
 * The in-process circuit breaker each Media client owns short-circuits calls
 * within a single HTTP request once a network error / timeout is observed.
 * That works inside one request — but FrankenPHP worker mode resets the
 * client state between requests via ResetInterface, so the very first call
 * on every navigation pays the full 4 s connect timeout when an upstream
 * service is unreachable. With several Media calls per page, an outage
 * makes the whole UI feel frozen.
 *
 * This service persists "service X is down" in the filesystem cache pool
 * (Symfony cache.app) with a short TTL. While the entry is hit, subsequent
 * page loads short-circuit instantly (0 ms) instead of paying another 4 s
 * timeout. After TTL_DOWN seconds the entry expires and the next call
 * tries once — if still down, it re-marks for another TTL window; if
 * recovered, the entry is cleared.
 *
 * The TTL is intentionally short (30 s): long enough to absorb an outage
 * across a flurry of page loads, short enough that recovery is detected
 * quickly without manual intervention.
 */
class ServiceHealthCache
{
    /** @internal Exposed only for tests. */
    public const TTL_DOWN = 10; // seconds — short window so recovery is detected quickly

    private const KEY_PREFIX = 'service_down.';

    public function __construct(private readonly CacheItemPoolInterface $cacheApp) {}

    /**
     * @param string  $service       short slug, e.g. 'radarr', 'sonarr',
     *                               'prowlarr', 'jellyseerr', 'qbittorrent'.
     * @param ?string $instanceSlug  v1.1.0 — optional instance slug for
     *                               radarr/sonarr so each instance has its
     *                               own circuit-breaker entry. Without it,
     *                               a single Radarr 4K outage would silence
     *                               every other Radarr instance until TTL.
     */
    public function isDown(string $service, ?string $instanceSlug = null): bool
    {
        return $this->cacheApp->getItem($this->key($service, $instanceSlug))->isHit();
    }

    public function markDown(string $service, ?string $instanceSlug = null): void
    {
        $item = $this->cacheApp->getItem($this->key($service, $instanceSlug));
        $item->set(true);
        $item->expiresAfter(self::TTL_DOWN);
        $this->cacheApp->save($item);
    }

    public function clear(string $service, ?string $instanceSlug = null): void
    {
        $this->cacheApp->deleteItem($this->key($service, $instanceSlug));
    }

    private function key(string $service, ?string $instanceSlug): string
    {
        return $instanceSlug !== null
            ? self::KEY_PREFIX . $service . '.' . $instanceSlug
            : self::KEY_PREFIX . $service;
    }
}
