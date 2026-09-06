<?php

namespace App\Service\Cache;

use App\Message\RefreshCacheKey;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The one stale-while-revalidate primitive shared by every cached dataset.
 * Values live in cache.app (filesystem, atomic tmp+rename writes) as
 * {fetchedAt, value} envelopes with expiresAfter(hardTtl), so a hard miss ==
 * a pool miss.
 *
 * Two read paths on purpose:
 *   read()          — never fetches. For datasets a page can render without
 *                     (an optional badge, counter or side panel).
 *                     Structurally safe: any pool failure degrades to "no
 *                     cached data", never a 500, because this can sit
 *                     directly on the render path.
 *   getOrCompute()  — fetches inline on a hard miss, THROUGH the contracts
 *                     cache so Symfony's LockRegistry flock still gives
 *                     cross-worker single-flight, and with $beta = 0 so
 *                     probabilistic early expiration cannot fire a surprise
 *                     inline refill before the hard TTL. For datasets a page
 *                     cannot render without (the library lists).
 *
 * Refreshes never run in the web process: requestRefresh() sets a short
 * coalescing marker and dispatches RefreshCacheKey to the `async` transport,
 * consumed by the already-running messenger-worker s6 service.
 */
final class StaleWhileRevalidateCache
{
    /** Seconds a coalescing marker suppresses further dispatches for one key. */
    public const MARKER_TTL = 30;

    /** Seconds an unanswered refresh request is remembered (dead-consumer probe). */
    public const REQUEST_TTL = 900;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly CacheItemPoolInterface $cacheApp,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return array{value: mixed, state: 'fresh'|'stale'}|null null = hard miss */
    public function read(string $key, int $softTtl): ?array
    {
        try {
            $item = $this->cacheApp->getItem($key);
            if (!$item->isHit()) {
                return null;
            }

            $env = $item->get();
            if (!is_array($env) || !isset($env['fetchedAt']) || !array_key_exists('value', $env)) {
                // Shape from an older build (or a corrupt entry): treat as a
                // miss and drop it so getOrCompute() cannot hand it back.
                $this->cacheApp->deleteItem($key);

                return null;
            }

            return [
                'value' => $env['value'],
                'state' => (time() - (int) $env['fetchedAt']) < $softTtl ? 'fresh' : 'stale',
            ];
        } catch (\Throwable $e) {
            // This sits directly on the page/badge-render path: a broken
            // pool adapter or a reserved-character key must degrade to "no
            // cached data" here, never surface as a 500.
            $this->logger->warning('SWR read failed', [
                'key'       => $key,
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param callable():mixed $fetch
     * @return array{value: mixed, state: 'fresh'|'stale'}
     */
    public function getOrCompute(string $key, int $softTtl, int $hardTtl, callable $fetch): array
    {
        $hit = $this->read($key, $softTtl);
        if ($hit !== null) {
            return $hit;
        }

        $env = $this->cache->get(
            $key,
            static function (ItemInterface $item) use ($fetch, $hardTtl): array {
                $value = $fetch();
                // An empty/failed fetch is never cached as success (never
                // "effectively permanent" stale data): expiresAfter(0) means
                // the entry is gone the instant this callback returns, so the
                // very next read is a hard miss again. Concurrent
                // LockRegistry waiters queued behind this same key may each
                // land here and re-run $fetch once the lock is released —
                // that is exactly pre-SWR behaviour (no caching at all on a
                // failure), and it is ServiceHealthCache's own circuit
                // breaker that bounds the resulting call volume once a
                // service is marked down, not this cache.
                $item->expiresAfter($value === [] ? 0 : $hardTtl);

                return ['fetchedAt' => time(), 'value' => $value];
            },
            0.0, // disable probabilistic early expiration
        );

        // The callback above only runs on a MISS: a cache hit hands back
        // whatever was already stored under this key (an older build's
        // envelope shape, or a stand-in CacheInterface in a test), so the
        // callback's return type is not a runtime guarantee here.
        if (!is_array($env) || !isset($env['fetchedAt']) || !array_key_exists('value', $env)) {
            // Never trust the contracts cache blindly — the return type
            // above is a hint, not a guarantee. A malformed/foreign-shaped
            // result is the same corrupt-entry case read() guards against:
            // treat it as a miss, drop whatever is in the pool under this
            // key, and recompute directly so the caller still gets an answer.
            $this->cacheApp->deleteItem($key);
            $value = $fetch();
            if ($value !== []) {
                $this->cacheApp->deleteItem($key . '.requested_at');
            }

            return ['value' => $value, 'state' => 'fresh'];
        }

        // A LockRegistry waiter can be released after the entry it was
        // waiting on has already crossed the soft TTL (e.g. a slow fetch
        // plus a short soft window): derive state from fetchedAt exactly
        // like read() does, never hardcode 'fresh'.
        $fetchedAt = (int) $env['fetchedAt'];
        if ($env['value'] !== []) {
            // The demand was answered inline with real data; stop counting
            // it as overdue — mirrors write(), which by convention (see
            // MediaLibraryRefresher) is likewise only ever called on a
            // non-empty fetch. See refreshIsOverdue().
            $this->cacheApp->deleteItem($key . '.requested_at');
        }

        return [
            'value' => $env['value'],
            'state' => (time() - $fetchedAt) < $softTtl ? 'fresh' : 'stale',
        ];
    }

    public function write(string $key, mixed $value, int $hardTtl, ?int $fetchedAt = null): void
    {
        if ($hardTtl <= 0) {
            throw new \InvalidArgumentException(sprintf('StaleWhileRevalidateCache::write(): $hardTtl must be > 0, got %d.', $hardTtl));
        }

        $fetchedAt = $fetchedAt ?? time();
        $remaining = $hardTtl - (time() - $fetchedAt);
        if ($remaining <= 0) {
            // A back-dated $fetchedAt whose hard window has already elapsed
            // must not create a live entry: writing it anyway (even with a
            // clamped near-zero TTL) risks the pool briefly serving an
            // envelope that is already past its own hard life, breaking
            // "hard miss == pool miss". The demand this write would have
            // answered is moot for the same reason — the data it carries is
            // already too old to serve — so drop the requested-at stamp too;
            // otherwise it lingers and can trip refreshIsOverdue() for a
            // request nothing will ever answer.
            $this->cacheApp->deleteItem($key . '.requested_at');

            return;
        }

        $item = $this->cacheApp->getItem($key);
        $item->set(['fetchedAt' => $fetchedAt, 'value' => $value]);
        // Clamp to what is actually left of the hard window, not the full
        // $hardTtl, so a back-dated write can never outlive an on-time one.
        $item->expiresAfter($remaining);
        $this->cacheApp->save($item);

        // The request was answered; stop counting it as overdue. This never
        // touches the .refreshing coalescing marker — that marker exists to
        // suppress duplicate DISPATCHES within its own short window and
        // expires on its own; a refresh completing early does not change
        // that.
        $this->cacheApp->deleteItem($key . '.requested_at');
    }

    public function delete(string $key): void
    {
        $this->cacheApp->deleteItems([$key, $key . '.refreshing', $key . '.requested_at']);
    }

    /**
     * Exposes the underlying pool so a caller can co-locate its OWN small
     * throttle markers (e.g. MediaLibraryCache's overdue-log marker) next to
     * the ones this class already keeps, without wiring a second
     * CacheItemPoolInterface dependency through everything that already holds
     * a StaleWhileRevalidateCache.
     */
    public function getPool(): CacheItemPoolInterface
    {
        return $this->cacheApp;
    }

    /**
     * Best-effort single flight: PSR-6 has no atomic "add if absent", so the
     * isHit() check and the save() below are not atomic against a concurrent
     * caller. A rare duplicate dispatch is bounded — CacheRefresherInterface
     * requires refreshers to be idempotent — and far cheaper than a
     * distributed lock would be for a 30 s coalescing window.
     *
     * Never throws: the whole body is one try/catch because ANY step here
     * (getItem/save on either key, or the dispatch itself) can throw — a
     * PSR-6 reserved-character key, a broken pool, or a dead transport — and
     * this is called from the main request path, where the page must
     * degrade (serve the stale/cached value) rather than 500. On failure the
     * marker is dropped in its OWN nested try/catch so a failing cleanup can
     * never mask (and replace) the original error in the log below.
     */
    public function requestRefresh(string $key): void
    {
        try {
            $marker = $this->cacheApp->getItem($key . '.refreshing');
            if ($marker->isHit()) {
                return;
            }

            $marker->set(true);
            $marker->expiresAfter(self::MARKER_TTL);
            $this->cacheApp->save($marker);

            $stamp = $this->cacheApp->getItem($key . '.requested_at');
            if (!$stamp->isHit()) {
                $stamp->set(time());
                $stamp->expiresAfter(self::REQUEST_TTL);
                $this->cacheApp->save($stamp);
            }

            $this->bus->dispatch(new RefreshCacheKey($key));
        } catch (\Throwable $e) {
            try {
                $this->cacheApp->deleteItem($key . '.refreshing');
            } catch (\Throwable) {
                // Cleanup is itself best-effort: if the pool is broken enough
                // that even a delete fails, the marker will simply expire on
                // its own after MARKER_TTL seconds. Swallow so this cannot
                // replace the original error logged below.
            }

            // Log only the exception class/message, never the message bus
            // envelope or transport DSN: a credentialed transport DSN can
            // appear in stamps/metadata a bus implementation attaches, and
            // RefreshCacheKey itself carries only the (app-constructed,
            // never user-supplied) cache key.
            $this->logger->error('SWR refresh dispatch failed', [
                'key'       => $key,
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * True when a refresh was asked for at least $seconds ago and never
     * answered. The stamp is cleared by both write() and a successful
     * (non-empty) getOrCompute() inline compute — either one means the
     * demand was answered, whether that happened in the background
     * refresher or inline in a request that hit a hard miss.
     */
    public function refreshIsOverdue(string $key, int $seconds): bool
    {
        $stamp = $this->cacheApp->getItem($key . '.requested_at');
        if (!$stamp->isHit()) {
            return false;
        }

        return (time() - (int) $stamp->get()) >= $seconds;
    }
}
