<?php

namespace App\Service\Cache;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Owns the "how do I rebuild this cache entry" half of the SWR contract.
 * Implementations are collected by RefreshCacheKeyHandler through the
 * app.cache_refresher tag; EVERY refresher whose supports() answers true for
 * a key is called (see the handler's docblock for why "first match wins"
 * was rejected).
 *
 * Implementer obligations:
 *  - supports() domains MUST be mutually exclusive across all refreshers.
 *    Tagged-iterator order is container registration order, not a stable
 *    API, so relying on prefix shadowing (one refresher's prefix being a
 *    superset of another's) is not safe at any ordering. The handler logs a
 *    warning if two refreshers ever claim the same key, but that is a safety
 *    net for a misconfiguration, not something to design around.
 *  - Before doing any refresh work, check ServiceHealthCache::isDown() for
 *    the service you own and return immediately if it is down: never hammer
 *    a service its own circuit breaker just marked down.
 *  - Never persist (e.g. via StaleWhileRevalidateCache::write()) on a
 *    failed/empty fetch — leave the previous good value untouched.
 *  - Be idempotent: re-read the current cache state first and return early
 *    if it is already fresh. A duplicate message can arrive from at-least-
 *    once transport delivery, and — see above — from an accidental
 *    supports() overlap.
 */
#[AutoconfigureTag('app.cache_refresher')]
interface CacheRefresherInterface
{
    public function supports(string $key): bool;

    /** Must be idempotent: a duplicate message may arrive. */
    public function refresh(string $key): void;
}
