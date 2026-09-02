<?php

namespace App\Service\Media;

use App\Service\Cache\StaleWhileRevalidateCache;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Short-TTL cache for the heavy Radarr/Sonarr library list.
 *
 * The library pages re-fetch and re-normalize the entire library on every
 * visit, which on a large homelab is the dominant page-load cost. The list
 * changes slowly (adds/deletes/monitor toggles), so a short window is safe
 * and write-through invalidation keeps user actions instantly visible.
 *
 * Keyed per instance slug so one Radarr instance's library never masks
 * another's. An empty result is NOT cached (expires immediately) so a
 * transient total failure isn't pinned for the whole window — mirrors
 * DashboardController's self-heal.
 *
 * This is a thin caller of StaleWhileRevalidateCache: TTL is the SOFT window
 * (a stale list is served immediately and a background refresh is requested),
 * HARD_TTL is the ceiling past which a read blocks and refetches inline — the
 * library pages cannot render without this list, so a hard miss must not be
 * served empty. An empty result is still never cached.
 */
class MediaLibraryCache
{
    /** @internal Exposed for tests; matches DashboardController::WIDGET_CACHE_TTL. Soft window. */
    public const TTL = 45; // seconds

    /** Ceiling: past this a read blocks and refetches inline. Bounded staleness, never permanent. */
    public const HARD_TTL = 600; // seconds

    /** Cross-request throttle for the "refresh overdue" log line. */
    private const OVERDUE_LOG_KEY = 'media_library.overdue_logged';

    /** Seconds. How long a requested refresh may go unanswered before it's worth an operator's attention. */
    private const OVERDUE_THRESHOLD = 180;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly StaleWhileRevalidateCache $swr,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param callable():array $fetch
     * @return array<mixed>
     */
    public function movies(string $slug, callable $fetch): array
    {
        return $this->fetchCached($this->key('movies', $slug), $fetch);
    }

    /**
     * @param callable():array $fetch
     * @return array<mixed>
     */
    public function series(string $slug, callable $fetch): array
    {
        return $this->fetchCached($this->key('series', $slug), $fetch);
    }

    /** Drop the cached list for an instance after a mutating action. Hard delete: the next read blocks. */
    public function invalidate(string $type, string $slug): void
    {
        $kind = $type === 'sonarr' ? 'series' : 'movies';
        $this->swr->delete($this->key($kind, $slug));
    }

    /**
     * @param callable():array $fetch
     * @return array<mixed>
     */
    private function fetchCached(string $key, callable $fetch): array
    {
        $hit = $this->swr->getOrCompute($key, self::TTL, self::HARD_TTL, $fetch);
        if ($hit['state'] === 'stale') {
            $this->swr->requestRefresh($key);
            $this->logOverdueOnce($key);
        }

        /** @var array<mixed> $value */
        $value = $hit['value'];

        return $value;
    }

    /**
     * Hard-miss/stale rebuild never answered within OVERDUE_THRESHOLD seconds
     * — rate-limited across requests, not just this one, via a short-lived
     * pool marker. Wrapped
     * in one try/catch: this sits on the same request path as the library
     * pages themselves, so a broken pool adapter here must degrade to "skip
     * the log line", never a 500.
     */
    private function logOverdueOnce(string $key): void
    {
        try {
            if (!$this->swr->refreshIsOverdue($key, self::OVERDUE_THRESHOLD)) {
                return;
            }

            $pool   = $this->swr->getPool();
            $marker = $pool->getItem(self::OVERDUE_LOG_KEY);
            if ($marker->isHit()) {
                return;
            }
            $marker->set(true);
            $marker->expiresAfter(self::OVERDUE_THRESHOLD);
            $pool->save($marker);

            $this->logger->error(sprintf(
                'Library cache refresh overdue for %s (requested >%d s ago) — is the messenger-worker service running?',
                $key,
                self::OVERDUE_THRESHOLD,
            ));
        } catch (\Throwable) {
            // Best-effort operational logging only; never let it affect the response.
        }
    }

    private function key(string $kind, string $slug): string
    {
        return 'media.' . $kind . '.' . $slug;
    }
}
