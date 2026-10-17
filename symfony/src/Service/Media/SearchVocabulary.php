<?php

namespace App\Service\Media;

use App\Service\Cache\StaleWhileRevalidateCache;
use Psr\Log\LoggerInterface;

/**
 * A few hundred well-known titles and people (TMDb popular, top-rated and
 * popular-people lists), with their popularity, used to correct mistyped
 * searches. TMDb's own search has no typo tolerance, and a typo early in a
 * word ("pokumon") defeats every prefix search, so the suggestion needs a
 * vocabulary of its own.
 *
 * Read on the request path without ever fetching (stale-while-revalidate):
 * a missing or stale entry queues a rebuild for the messenger worker
 * (SearchVocabularyRefresher) and the search goes on with what it has.
 */
final class SearchVocabulary
{
    public const KEY_PREFIX = 'tmdb.search_vocab.';
    public const SOFT_TTL = 86400;        // rebuild daily
    public const HARD_TTL = 86400 * 14;   // keep serving a stale copy two weeks
    private const MOVIE_PAGES = 10;
    private const TV_PAGES = 10;
    private const TOP_PAGES = 5;
    private const PEOPLE_PAGES = 5;

    public function __construct(
        private readonly StaleWhileRevalidateCache $swr,
        private readonly TmdbClient $tmdb,
        private readonly LoggerInterface $logger,
    ) {}

    public function key(): string
    {
        return self::KEY_PREFIX . ($this->tmdb->getLocale() ?: 'default');
    }

    /** Pages allowed to come back empty before the whole build counts as failed. */
    private const MAX_FAILED_PAGES = 2;

    /** @return list<array{label: string, popularity: float}> never fetches */
    public function read(): array
    {
        $key = $this->key();
        $hit = $this->swr->read($key, self::SOFT_TTL);
        if ($hit === null || $hit['state'] === 'stale') {
            $this->swr->requestRefresh($key);
        }

        return is_array($hit['value'] ?? null) ? $hit['value'] : [];
    }

    /**
     * Fetches the lists from TMDb (cached per call by TmdbClient). Labels are
     * the display and original titles, deduplicated on normalized text with
     * the highest popularity kept. An empty result means the fetch failed.
     *
     * @return list<array{label: string, popularity: float}>
     */
    public function build(): array
    {
        $lists = [];
        try {
            for ($p = 1; $p <= self::MOVIE_PAGES; $p++) {
                $lists[] = $this->tmdb->getPopularMovies($p);
            }
            for ($p = 1; $p <= self::TV_PAGES; $p++) {
                $lists[] = $this->tmdb->getPopularTv($p);
            }
            for ($p = 1; $p <= self::TOP_PAGES; $p++) {
                $lists[] = $this->tmdb->getTopRatedMovies($p);
                $lists[] = $this->tmdb->getTopRatedTv($p);
            }
            for ($p = 1; $p <= self::PEOPLE_PAGES; $p++) {
                $lists[] = $this->tmdb->getPopularPeople($p);
            }
            $lists[] = $this->tmdb->getTrendingAll('week');
        } catch (\Throwable $e) {
            $this->logger->warning('Search vocabulary build failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return [];
        }

        // TmdbClient answers [] (not an exception) for a failed call; a vocabulary
        // missing more than a couple of pages would be cached for 24 h, so treat
        // it as a failed build and let the refresher keep the previous value.
        $failed = count(array_filter($lists, static fn ($d) => !is_array($d['results'] ?? null)));
        if ($failed > self::MAX_FAILED_PAGES) {
            $this->logger->warning('Search vocabulary build incomplete', ['failed_pages' => $failed, 'pages' => count($lists)]);
            return [];
        }

        $byNorm = [];
        foreach ($lists as $data) {
            foreach (($data ?? [])['results'] ?? [] as $r) {
                $popularity = (float) ($r['popularity'] ?? 0);
                $labels = array_unique(array_filter([
                    $r['title'] ?? $r['name'] ?? null,
                    $r['original_title'] ?? $r['original_name'] ?? null,
                ], 'is_string'));
                foreach ($labels as $label) {
                    $norm = SearchRanker::normalize($label);
                    if ($norm === '') {
                        continue;
                    }
                    if (!isset($byNorm[$norm]) || $byNorm[$norm]['popularity'] < $popularity) {
                        $byNorm[$norm] = ['label' => $label, 'popularity' => $popularity];
                    }
                }
            }
        }

        return array_values($byNorm);
    }
}
