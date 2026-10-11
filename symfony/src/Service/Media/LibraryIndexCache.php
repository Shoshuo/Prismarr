<?php

namespace App\Service\Media;

use App\Entity\ServiceInstance;
use App\Service\Cache\StaleWhileRevalidateCache;

/**
 * One shared, slim "library index" per Radarr / Sonarr instance.
 *
 * Most callers that used to fetch the whole library only need to know
 * whether a tmdbId / tvdbId is in it, with which status, under which title,
 * or how many items there are. On a large library the full fetch is the
 * dominant cost of the page (20k movies: an ~80 MB /api/v3/movie that takes
 * Radarr 4–5 s to serialize and PHP ~350 MB to decode), and the Discover
 * page alone fires it from every TMDb rail request in parallel — enough
 * overlapping fetches to push Radarr past the client timeout.
 *
 * This keeps the projection from RadarrClient/SonarrClient::toLibraryIndexRow()
 * (~10 MB serialized for 20k movies, ~25 ms to read back) under
 * `media.index.{movies|series}.<slug>`, stale-while-revalidate:
 *   - fresh  (< SOFT_TTL): served from cache;
 *   - stale  (< HARD_TTL): served from cache, a background refresh is
 *     requested from the messenger worker (LibraryIndexRefresher);
 *   - missing:             fetched inline once — concurrent requests wait on
 *     the contracts-cache lock instead of each starting their own fetch.
 *
 * Mutations go through MediaLibraryCache::invalidate(), which marks the
 * index stale (soft) rather than deleting it, so a quick-add from Discover
 * does not make the next rail block on a full refetch.
 *
 * An empty fetch is never cached (see StaleWhileRevalidateCache), so a
 * failed fetch is retried on the next read instead of pinning "nothing in
 * the library" for the whole window.
 */
class LibraryIndexCache
{
    /** Seconds a built index is served without asking for a refresh. */
    public const SOFT_TTL = 300;

    /** Seconds past which a read blocks and refetches inline. */
    public const HARD_TTL = 3600;

    private const PREFIX = 'media.index.';

    public function __construct(
        private readonly StaleWhileRevalidateCache $swr,
        private readonly RadarrClient $radarr,
        private readonly SonarrClient $sonarr,
    ) {}

    /** @return list<array<string, mixed>> slim movie rows, see RadarrClient::toLibraryIndexRow() */
    public function movies(ServiceInstance $instance): array
    {
        return $this->read('movies', $instance);
    }

    /** @return list<array<string, mixed>> slim series rows, see SonarrClient::toLibraryIndexRow() */
    public function series(ServiceInstance $instance): array
    {
        return $this->read('series', $instance);
    }

    /**
     * Fetch the full library from the instance and project it. Used for the
     * inline hard-miss path and by LibraryIndexRefresher. Returns [] when
     * the instance is unreachable (the clients swallow transport errors).
     *
     * @return list<array<string, mixed>>
     */
    public function build(ServiceInstance $instance): array
    {
        return self::fetchRows($instance->getType() === ServiceInstance::TYPE_SONARR
            ? $this->sonarr->withInstance($instance)
            : $this->radarr->withInstance($instance));
    }

    /**
     * Uncached build from an already-bound client. Also the fallback for
     * callers constructed without a LibraryIndexCache (unit tests), so both
     * paths see exactly the same row shape.
     *
     * @return list<array<string, mixed>>
     */
    public static function fetchRows(RadarrClient|SonarrClient $client): array
    {
        return $client instanceof SonarrClient
            ? self::project($client->getRawAllSeries(SonarrClient::LIBRARY_TIMEOUT), SonarrClient::toLibraryIndexRow(...))
            : self::project($client->getRawMovies(RadarrClient::LIBRARY_TIMEOUT), RadarrClient::toLibraryIndexRow(...));
    }

    /**
     * Store an index built from a raw list someone already fetched (the
     * full-library refresher), so one Radarr/Sonarr round-trip feeds both
     * caches. Skips empty lists, like every other SWR writer.
     *
     * @param array<int, array<string, mixed>> $raw
     */
    public function writeFromRaw(ServiceInstance $instance, array $raw, float $startedAt): void
    {
        if ($raw === []) {
            return;
        }
        $rows = $instance->getType() === ServiceInstance::TYPE_SONARR
            ? self::project($raw, SonarrClient::toLibraryIndexRow(...))
            : self::project($raw, RadarrClient::toLibraryIndexRow(...));

        $this->swr->write(self::keyFor($instance), $rows, self::HARD_TTL, startedAt: $startedAt);
    }

    public static function key(string $kind, string $slug): string
    {
        return self::PREFIX . $kind . '.' . $slug;
    }

    public static function keyFor(ServiceInstance $instance): string
    {
        return self::key($instance->getType() === ServiceInstance::TYPE_SONARR ? 'series' : 'movies', $instance->getSlug());
    }

    /**
     * Inverse of key(): ['movies'|'series', slug], or null for a key this
     * class does not own.
     *
     * @return array{0: 'movies'|'series', 1: string}|null
     */
    public static function parseKey(string $key): ?array
    {
        foreach (['movies', 'series'] as $kind) {
            $prefix = self::PREFIX . $kind . '.';
            if (str_starts_with($key, $prefix) && strlen($key) > strlen($prefix)) {
                return [$kind, substr($key, strlen($prefix))];
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function read(string $kind, ServiceInstance $instance): array
    {
        $key = self::key($kind, $instance->getSlug());
        $hit = $this->swr->getOrCompute($key, self::SOFT_TTL, self::HARD_TTL, fn() => $this->build($instance));
        if ($hit['state'] === 'stale') {
            $this->swr->requestRefresh($key);
        }

        return is_array($hit['value']) ? array_values($hit['value']) : [];
    }

    /**
     * @param array<int, mixed> $raw
     * @param callable(array<string, mixed>): array<string, mixed> $toRow
     * @return list<array<string, mixed>>
     */
    private static function project(array $raw, callable $toRow): array
    {
        $rows = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $rows[] = $toRow($item);
            }
        }

        return $rows;
    }
}
