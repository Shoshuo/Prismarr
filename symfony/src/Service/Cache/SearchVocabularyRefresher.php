<?php

namespace App\Service\Cache;

use App\Service\Media\SearchVocabulary;

/**
 * Rebuilds the search vocabulary (SearchVocabulary) in the messenger worker.
 * Owns every "tmdb.search_vocab.<locale>" key; the locale part only keys the
 * entry, the build uses TmdbClient's current locale.
 */
final class SearchVocabularyRefresher implements CacheRefresherInterface
{
    public function __construct(
        private readonly SearchVocabulary $vocabulary,
        private readonly StaleWhileRevalidateCache $swr,
    ) {}

    public function supports(string $key): bool
    {
        return str_starts_with($key, SearchVocabulary::KEY_PREFIX);
    }

    public function refresh(string $key): void
    {
        // Idempotence: a duplicate message costs one cache read and stops here.
        $hit = $this->swr->read($key, SearchVocabulary::SOFT_TTL);
        if ($hit !== null && $hit['state'] === 'fresh') {
            return;
        }
        // A message queued under another locale than the current one.
        if ($key !== $this->vocabulary->key()) {
            return;
        }

        $words = $this->vocabulary->build();
        if ($words === []) {
            return; // failed fetch: keep the previous good value
        }

        $this->swr->write($key, $words, SearchVocabulary::HARD_TTL);
    }
}
