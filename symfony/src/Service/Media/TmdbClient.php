<?php

namespace App\Service\Media;

use App\Service\ConfigService;
use App\Service\DisplayPreferencesService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Service\ResetInterface;

class TmdbClient implements ResetInterface
{
    private const SERVICE    = 'TMDb';
    private const BASE_URL   = 'https://api.themoviedb.org/3';
    private const IMG_BASE   = 'https://image.tmdb.org/t/p';
    private const TTL_LIST   = 3600;   // 1h for lists (trending/popular/upcoming)
    private const TTL_DETAIL = 21600;  // 6h for detail pages
    private const TTL_SEARCH = 600;    // 10min for searches
    private const FALLBACK_LOCALE = 'fr-FR';

    private ?string $locale = null;
    private string $apiKey = '';

    public function __construct(
        private readonly ConfigService $config,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly DisplayPreferencesService $prefs,
    ) {}

    private function ensureConfig(): void
    {
        if ($this->apiKey === '') {
            $this->apiKey = $this->config->require('tmdb_api_key', self::SERVICE);
        }
    }

    /**
     * Read the admin-level metadata language once per request, falling back
     * to FR if the pref service is unreachable (shouldn't happen but
     * defensive — TMDb still needs a `language=` value to respond).
     */
    private function getLocale(): string
    {
        if ($this->locale === null) {
            try {
                $lang = $this->prefs->getMetadataLanguage();
                $this->locale = $lang !== '' ? $lang : self::FALLBACK_LOCALE;
            } catch (\Throwable) {
                $this->locale = self::FALLBACK_LOCALE;
            }
        }

        return $this->locale;
    }

    /**
     * Drop the cached API key + locale between FrankenPHP worker requests so
     * an admin-triggered config change (/admin/settings) is picked up
     * immediately — the next request rebuilds from the DB.
     */
    public function reset(): void
    {
        $this->apiKey = '';
        $this->locale = null;
    }

    /** Light ping — true if TMDb responds with a valid key. Bypasses cache. */
    public function ping(): bool
    {
        try {
            return $this->request('/genre/movie/list') !== null;
        } catch (\Throwable $e) {
            $this->logger->warning('TMDb ping failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return false;
        }
    }

    public function getTrendingAll(string $window = 'week'): array
    {
        return $this->cachedGet("trending_all_{$window}", "/trending/all/{$window}", [], self::TTL_LIST);
    }

    public function getTrendingMovies(string $window = 'week'): array
    {
        return $this->cachedGet("trending_movie_{$window}", "/trending/movie/{$window}", [], self::TTL_LIST);
    }

    public function getTrendingTv(string $window = 'week'): array
    {
        return $this->cachedGet("trending_tv_{$window}", "/trending/tv/{$window}", [], self::TTL_LIST);
    }

    public function getPopularMovies(int $page = 1): array
    {
        return $this->cachedGet("popular_movie_{$page}", '/movie/popular', ['page' => $page], self::TTL_LIST);
    }

    public function getPopularTv(int $page = 1): array
    {
        return $this->cachedGet("popular_tv_{$page}", '/tv/popular', ['page' => $page], self::TTL_LIST);
    }

    public function getTopRatedMovies(int $page = 1): array
    {
        return $this->cachedGet("top_movie_{$page}", '/movie/top_rated', ['page' => $page], self::TTL_LIST);
    }

    public function getTopRatedTv(int $page = 1): array
    {
        return $this->cachedGet("top_tv_{$page}", '/tv/top_rated', ['page' => $page], self::TTL_LIST);
    }

    public function getUpcomingMovies(int $page = 1): array
    {
        return $this->cachedGet("upcoming_movie_{$page}", '/movie/upcoming', ['page' => $page], self::TTL_LIST);
    }

    public function getOnTheAirTv(int $page = 1): array
    {
        return $this->cachedGet("on_air_tv_{$page}", '/tv/on_the_air', ['page' => $page], self::TTL_LIST);
    }

    public function getNowPlayingMovies(int $page = 1): array
    {
        return $this->cachedGet("now_movie_{$page}", '/movie/now_playing', ['page' => $page], self::TTL_LIST);
    }

    public function getAiringTodayTv(int $page = 1): array
    {
        return $this->cachedGet("airing_tv_{$page}", '/tv/airing_today', ['page' => $page], self::TTL_LIST);
    }

    public function getMovie(int $id): ?array
    {
        return $this->cachedGet(
            "movie_{$id}",
            "/movie/{$id}",
            [
                'append_to_response'     => 'videos,credits,images,external_ids,watch/providers,keywords,release_dates,alternative_titles,reviews',
                'include_image_language'  => 'fr,en,null',
                'include_video_language'  => 'fr,en',
            ],
            self::TTL_DETAIL,
        );
    }

    public function getTv(int $id): ?array
    {
        return $this->cachedGet(
            "tv_{$id}",
            "/tv/{$id}",
            [
                'append_to_response'     => 'videos,credits,images,external_ids,watch/providers,keywords,content_ratings,alternative_titles,reviews,aggregate_credits',
                'include_image_language'  => 'fr,en,null',
                'include_video_language'  => 'fr,en',
            ],
            self::TTL_DETAIL,
        );
    }

    public function getMovieRecommendations(int $id, int $page = 1): array
    {
        return $this->cachedGet("movie_rec_{$id}_{$page}", "/movie/{$id}/recommendations", ['page' => $page], self::TTL_LIST);
    }

    public function getTvRecommendations(int $id, int $page = 1): array
    {
        return $this->cachedGet("tv_rec_{$id}_{$page}", "/tv/{$id}/recommendations", ['page' => $page], self::TTL_LIST);
    }

    public function getMovieSimilar(int $id, int $page = 1): array
    {
        return $this->cachedGet("movie_sim_{$id}_{$page}", "/movie/{$id}/similar", ['page' => $page], self::TTL_LIST);
    }

    public function getTvSimilar(int $id, int $page = 1): array
    {
        return $this->cachedGet("tv_sim_{$id}_{$page}", "/tv/{$id}/similar", ['page' => $page], self::TTL_LIST);
    }

    public function searchMulti(string $query, int $page = 1): array
    {
        $key = 'search_' . md5($query) . "_{$page}";
        return $this->cachedGet($key, '/search/multi', ['query' => $query, 'page' => $page], self::TTL_SEARCH);
    }

    public function discoverMovies(array $params = []): array
    {
        $key = 'discover_movie_' . md5(serialize($params));
        return $this->cachedGet($key, '/discover/movie', $params, self::TTL_LIST);
    }

    public function discoverTv(array $params = []): array
    {
        $key = 'discover_tv_' . md5(serialize($params));
        return $this->cachedGet($key, '/discover/tv', $params, self::TTL_LIST);
    }

    /** Resolve a series' tmdbId from a tvdbId (via /find). */
    public function findTmdbIdByTvdbId(int $tvdbId): ?int
    {
        $cacheKey = "find_tvdb_{$tvdbId}";
        $data = $this->cachedGet($cacheKey, "/find/{$tvdbId}", ['external_source' => 'tvdb_id'], 86400 * 30);
        $first = $data['tv_results'][0] ?? null;
        return $first && isset($first['id']) ? (int) $first['id'] : null;
    }

    /** Resolve a movie's tmdbId from an imdbId (via /find). */
    public function findTmdbIdByImdbId(string $imdbId): ?int
    {
        $cacheKey = "find_imdb_{$imdbId}";
        $data = $this->cachedGet($cacheKey, "/find/{$imdbId}", ['external_source' => 'imdb_id'], 86400 * 30);
        $first = $data['movie_results'][0] ?? null;
        return $first && isset($first['id']) ? (int) $first['id'] : null;
    }

    public function getCollection(int $id): ?array
    {
        return $this->cachedGet("collection_{$id}", "/collection/{$id}", [], self::TTL_DETAIL);
    }

    public function getPersonCombinedCredits(int $id): ?array
    {
        return $this->cachedGet("person_credits_{$id}", "/person/{$id}/combined_credits", [], self::TTL_LIST);
    }

    public function getPerson(int $id): ?array
    {
        return $this->cachedGet("person_{$id}", "/person/{$id}", [], self::TTL_DETAIL);
    }

    public function searchCollection(string $query): array
    {
        $key = 'search_collection_' . md5($query);
        return $this->cachedGet($key, '/search/collection', ['query' => $query], self::TTL_SEARCH);
    }

    public function searchPerson(string $query): array
    {
        $key = 'search_person_' . md5($query);
        return $this->cachedGet($key, '/search/person', ['query' => $query], self::TTL_SEARCH);
    }

    public function getGenresMovies(): array
    {
        return $this->cachedGet('genres_movie', '/genre/movie/list', [], self::TTL_DETAIL * 4);
    }

    public function getGenresTv(): array
    {
        return $this->cachedGet('genres_tv', '/genre/tv/list', [], self::TTL_DETAIL * 4);
    }

    public static function posterUrl(?string $path, string $size = 'w342'): ?string
    {
        return $path ? self::IMG_BASE . "/{$size}{$path}" : null;
    }

    public static function backdropUrl(?string $path, string $size = 'w1280'): ?string
    {
        return $path ? self::IMG_BASE . "/{$size}{$path}" : null;
    }

    private function cachedGet(string $cacheKey, string $path, array $params, int $ttl): array
    {
        // Cache keyed by locale so switching `display_metadata_language`
        // doesn't serve stale localized strings from the previous locale.
        $full = "prismarr_tmdb_{$this->getLocale()}_{$cacheKey}";

        return $this->cache->get($full, function (ItemInterface $item) use ($path, $params, $ttl) {
            $item->expiresAfter($ttl);
            return $this->request($path, $params) ?? [];
        });
    }

    private function request(string $path, array $params = []): ?array
    {
        $this->ensureConfig();
        $params['api_key']       = $this->apiKey;
        $params['language']      = $params['language']      ?? $this->getLocale();
        $params['include_adult'] = $params['include_adult'] ?? 'false';

        $url = self::BASE_URL . $path . '?' . http_build_query($params);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            // Generous timeouts: TMDb is on the open internet and the
            // Docker embedded DNS occasionally spikes > 4s on first hit.
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_NOSIGNAL       => 1,
            // SSRF guard: block file://, gopher://, dict:// and other non-http(s)
            // schemes both on the initial request and on redirects.
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $code >= 400) {
            $this->logger->warning("TMDB {$path} failed (HTTP {$code}) : {$err}");
            return null;
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

}
