<?php

namespace App\Service\Cache;

use App\Entity\ServiceInstance;
use App\Service\Media\LibraryIndexCache;
use App\Service\Media\ServiceHealthCache;
use App\Service\ServiceInstanceProvider;
use Psr\Log\LoggerInterface;

/**
 * Rebuilds `media.index.movies.<slug>` / `media.index.series.<slug>` inside
 * the messenger-worker process, so the full-library fetch behind the slim
 * index never runs on a web request once the index is warm.
 *
 * Disjoint from MediaLibraryRefresher's `media.movies.` / `media.series.`
 * prefixes, as CacheRefresherInterface requires.
 */
final class LibraryIndexRefresher implements CacheRefresherInterface
{
    public function __construct(
        private readonly ServiceInstanceProvider $instances,
        private readonly LibraryIndexCache $index,
        private readonly StaleWhileRevalidateCache $swr,
        private readonly ServiceHealthCache $health,
        private readonly LoggerInterface $logger,
    ) {}

    public function supports(string $key): bool
    {
        return LibraryIndexCache::parseKey($key) !== null;
    }

    public function refresh(string $key): void
    {
        $parsed = LibraryIndexCache::parseKey($key);
        if ($parsed === null) {
            return;
        }
        [$kind, $slug] = $parsed;

        // Idempotence: a duplicate message costs one cache read and stops here.
        $hit = $this->swr->read($key, LibraryIndexCache::SOFT_TTL);
        if ($hit !== null && $hit['state'] === 'fresh') {
            return;
        }

        $type     = $kind === 'movies' ? ServiceInstance::TYPE_RADARR : ServiceInstance::TYPE_SONARR;
        $instance = $this->instances->getBySlug($type, $slug);
        if ($instance === null || !$instance->isEnabled()) {
            return;
        }

        if ($this->health->isDown($type, $slug)) {
            return;
        }

        // Taken before the fetch so a mutation that invalidates this key
        // while the fetch is in flight wins over its result.
        $startedAt = microtime(true);
        try {
            $rows = $this->index->build($instance);
        } catch (\Throwable $e) {
            $this->logger->warning('Library index refresh failed', [
                'key' => $key, 'exception' => $e::class, 'message' => $e->getMessage(),
            ]);

            return;
        }

        // An empty list is a failed fetch — leave the previous good value alone.
        if ($rows === []) {
            return;
        }

        $this->swr->write($key, $rows, LibraryIndexCache::HARD_TTL, startedAt: $startedAt);
    }
}
