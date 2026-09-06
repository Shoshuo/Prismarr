<?php

namespace App\Service\Media;

use App\Entity\ServiceInstance;
use App\Service\Cache\StaleWhileRevalidateCache;
use App\Service\ServiceInstanceProvider;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * radarrId/sonarrSeriesId -> Bazarr subtitle status lookup, for badge rendering.
 *
 * The badge read path (movieStatus/movieLanguages/seriesStatus) NEVER fetches
 * from Bazarr: a full-library fetch (`/movies?length=-1`, ~7 s on a large
 * library) is far too expensive to run inline behind a decorative badge that
 * renders up to 588 times on the Films page. Instead these three methods only
 * ever read `cache.app` through `StaleWhileRevalidateCache`:
 *   - fresh or stale data is served immediately (bounded staleness), and
 *   - a hard miss answers the new `pending` state and asks the
 *     messenger-worker (BazarrIndexRefresher, via RefreshCacheKey) to rebuild
 *     the dataset off the request path.
 *
 * A request-scoped memo (cleared between requests via ResetInterface) sits in
 * front of the pool so a single request's many badge renders cost one pool
 * read per dataset, not one per badge.
 *
 * A subtitle status is always array{state: string, count: int}, where state is
 * one of 'complete', 'missing', 'hidden' or 'pending' and count is the number
 * of missing subtitle languages.
 */
class BazarrSubtitleIndex implements ResetInterface
{
    /** @var array{state: string, count: int} */
    private const HIDDEN = ['state' => 'hidden', 'count' => 0];

    /** @var array{state: string, count: int} A hard-miss answer: "we don't know yet", not "nothing missing". */
    private const PENDING = ['state' => 'pending', 'count' => 0];

    /** @var array{present: list<array{lang: string, hi: bool, forced: bool}>, missing: list<array{lang: string, hi: bool, forced: bool}>, tracked: bool} Fail-closed language shape: gated / untracked / absent. */
    private const UNTRACKED_LANGS = ['present' => [], 'missing' => [], 'tracked' => false];

    public const KEY_MOVIES      = 'bazarr_subtitle_index.movies';
    public const KEY_MOVIE_LANGS = 'bazarr_subtitle_index.movie_langs';
    public const KEY_SERIES      = 'bazarr_subtitle_index.series';

    /** Bazarr Movies-tab grid cards, derived at refresh time from the same getMovies() pass as KEY_MOVIES. */
    public const KEY_MOVIE_CARDS = 'bazarr_subtitle_index.movie_cards';

    /** Bazarr Series-tab grid cards, derived at refresh time from the same getSeries() pass as KEY_SERIES. */
    public const KEY_SERIES_CARDS = 'bazarr_subtitle_index.series_cards';

    /** Top-N (MOST_MISSING_CANDIDATES) movie candidates for the "most missing" strip. */
    public const KEY_MOST_MISSING_MOVIES = 'bazarr_subtitle_index.most_missing_movies';

    /** Top-N (MOST_MISSING_CANDIDATES) series candidates, ranked by aggregate episodeMissingCount. */
    public const KEY_MOST_MISSING_SERIES = 'bazarr_subtitle_index.most_missing_series';

    /** `/api/badges` counts, refreshed alongside the movie dataset (one cheap call, no dedicated message). */
    public const KEY_BADGES = 'bazarr_subtitle_index.badges';

    /**
     * A plain `cache.app` item (NOT an SWR envelope) journalling recent
     * per-id mutation patches, keyed "<kind>:<id>" so repeated mutations on
     * one item collapse to the newest. Read by applyPatchesNewerThan() so a
     * bulk refresh already in flight when a mutation lands can re-apply that
     * patch on top of its own (older) result before writing.
     *
     * @var string
     */
    public const KEY_PATCHES = 'bazarr_subtitle_index.patches';

    /** Seconds a journalled patch is kept — comfortably longer than a Bazarr full-list fetch can take. */
    public const PATCH_TTL = 120;

    /** Candidates kept per kind; the controller merges + re-sorts + slices to 16. */
    public const MOST_MISSING_CANDIDATES = 32;

    /** Every SWR-backed dataset key this class owns — used by invalidate()'s hard reset. */
    private const ALL_KEYS = [
        self::KEY_MOVIES, self::KEY_MOVIE_LANGS, self::KEY_SERIES,
        self::KEY_MOVIE_CARDS, self::KEY_SERIES_CARDS,
        self::KEY_MOST_MISSING_MOVIES, self::KEY_MOST_MISSING_SERIES,
        self::KEY_BADGES,
    ];

    /**
     * Keys a caller may hand to requestRefresh() — exactly the keys
     * BazarrIndexRefresher::supports() claims. The derived datasets
     * (movie/series cards, most-missing, movie langs) are written as a SIDE
     * EFFECT of one of these three fetches; there is no refresher to route a
     * request for one of THOSE keys to, so queuing one would silently do
     * nothing forever.
     */
    private const REQUESTABLE_KEYS = [self::KEY_MOVIES, self::KEY_SERIES, self::KEY_BADGES];

    /** Seconds. Short enough that a stale badge self-heals without any action. */
    public const SOFT_TTL = 60;

    /** Seconds. Outer bound on how long a value may be served stale. */
    public const HARD_TTL = 600;

    /** Cross-request throttle for the "refresh overdue" log line (see requestRefreshOnce()). */
    private const OVERDUE_LOG_KEY = 'bazarr_subtitle_index.overdue_logged';

    /** Seconds. How long a refresh may go unanswered before it's worth an operator's attention. */
    private const OVERDUE_THRESHOLD = 180;

    /** @var array<int, array{state: string, count: int}>|null null = hard miss (index not warmed) */
    private ?array $movies = null;

    /** @var array<int, array{present: list<array{lang: string, hi: bool, forced: bool}>, missing: list<array{lang: string, hi: bool, forced: bool}>, tracked: bool}>|null Filled by the SAME dataset as $movies. */
    private ?array $movieLangs = null;

    /** @var array<int, array{state: string, count: int}>|null null = hard miss (index not warmed) */
    private ?array $series = null;

    /** Distinguishes "not loaded yet" from "loaded, and it's a hard miss (null)". */
    private bool $moviesLoaded = false;
    private bool $seriesLoaded = false;

    /** Per-request memo of the multi-instance gate (see gate()). */
    private ?bool $radarrGate = null;
    private ?bool $sonarrGate = null;

    /**
     * Per-request memo for the single-item fallback (movieStatusSingle() /
     * movieLanguagesSingle()): a quick-look renders the badge and then fetches
     * the chips, and both must collapse to the SAME one per-id Bazarr call.
     *
     * @var array<int, array{status: array{state: string, count: int}, langs: array{present: list<array{lang: string, hi: bool, forced: bool}>, missing: list<array{lang: string, hi: bool, forced: bool}>, tracked: bool}}>
     */
    private array $singles = [];

    /**
     * $client is used by TWO call paths: refreshItem() — the post-mutation
     * per-id repair path — and loadSingle() — the single-item quick-look
     * fallback behind movieStatusSingle()/movieLanguagesSingle(). The GRID
     * badge read path (movieStatus/movieLanguages/seriesStatus) never calls
     * it directly; those go through $swr only. $cacheApp is used both for
     * the patch journal (KEY_PATCHES) and — see requestRefreshOnce() — the
     * cross-request overdue-log throttle and the breaker check.
     */
    public function __construct(
        private readonly BazarrClient $client,
        private readonly CacheItemPoolInterface $cacheApp,
        private readonly ServiceInstanceProvider $instances,
        private readonly StaleWhileRevalidateCache $swr,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function reset(): void
    {
        $this->movies       = null;
        $this->movieLangs   = null;
        $this->series       = null;
        $this->moviesLoaded = false;
        $this->seriesLoaded = false;
        $this->radarrGate   = null;
        $this->sonarrGate   = null;
        $this->singles      = [];
    }

    /**
     * Drop the memo AND the cross-request pool entries (markers/stamps
     * included, via the SWR primitive). This is the hard-reset hook, NOT the
     * mutation-endpoint invalidation path: a subtitle download / auto-search
     * goes through refreshItem()/requestRefresh() instead (see those
     * docblocks), which patch the acted-on item in place rather than
     * blanking every badge on the page for a whole hard window. invalidate()
     * is for the case where WHICH Bazarr instance is being read has changed
     * — AdminSettingsController calls it when bazarr_url/bazarr_api_key
     * actually change, so a stale badge from the old instance cannot live on
     * for up to HARD_TTL.
     */
    public function invalidate(): void
    {
        $this->reset();
        foreach (self::ALL_KEYS as $key) {
            $this->swr->delete($key);
        }
    }

    /**
     * Queue a bulk rebuild of $key without waiting for the next reader to hit
     * a hard miss or a stale soft window. Used by mutation endpoints that
     * cannot patch a specific id in place — e.g. apiDownloadEpisode, which
     * only knows an episode id, not the series id it belongs to — so the
     * queue happens synchronously in the request that performed the mutation
     * instead of the fix waiting for someone else's page view.
     *
     * $key is restricted to REQUESTABLE_KEYS (
     * narrowed from ALL_KEYS in a later fix wave to exactly what
     * BazarrIndexRefresher::supports() claims) so a caller cannot
     * accidentally queue an unsupported/mistyped key — including KEY_PATCHES
     * itself (not an SWR-backed dataset) or one of the derived-dataset keys
     * (cards/most-missing/langs), which have no refresher of their own to
     * route a request to.
     *
     * @throws \InvalidArgumentException if $key is not one of REQUESTABLE_KEYS
     */
    public function requestRefresh(string $key): void
    {
        if (!in_array($key, self::REQUESTABLE_KEYS, true)) {
            throw new \InvalidArgumentException(sprintf('BazarrSubtitleIndex::requestRefresh(): unsupported key "%s".', $key));
        }

        $this->swr->requestRefresh($key);
    }

    /**
     * Freshness of one dataset key WITHOUT triggering a refresh request, a
     * client call, or touching the per-request memo — a genuinely
     * non-blocking read used only to report truthfully on what an inline
     * rebuild actually achieved (BazarrController::apiRefresh()). Contrast
     * with the private readDataset()/loadMovies()
     * helpers, which DO request a refresh on a miss/stale read — that
     * behaviour is exactly what apiRefresh() must NOT trigger a second time
     * on top of its own explicit inline refresh() calls.
     *
     * @return 'fresh'|'stale'|'pending'
     */
    public function datasetState(string $key): string
    {
        $hit = $this->swr->read($key, self::SOFT_TTL);

        return $hit === null ? 'pending' : $hit['state'];
    }

    /**
     * Post-mutation repair for ONE item. The user who just downloaded a
     * subtitle must see the new count immediately; everyone else catches up
     * within one consumer cycle.
     *
     * Order of operations matters. The patch is journalled BEFORE the pooled
     * maps are touched, so a bulk refresh already in flight (which will
     * overwrite those maps when it lands) can re-apply it — the ordering rule
     * is "the bulk result is authoritative except for patches recorded at or
     * after its fetch start".
     *
     * A failed or empty per-id fetch — or one whose response doesn't actually
     * contain the requested id (never trust $rows[0] blindly; see
     * findRowById()) — leaves the pooled maps and the journal untouched
     * the previous good value is never overwritten by a
     * hard-missing or misattributed per-id lookup, and a queued bulk rebuild
     * is still requested so the item self-heals on the next cycle.
     *
     * The pool writes (journalPatch/patchPooledMap) are wrapped in their own
     * try/catch: this runs on the request that just
     * performed a successful Bazarr mutation, so a broken pool adapter here
     * must degrade to "the bulk rebuild will fix it eventually", never turn
     * a successful download into a 500.
     *
     * @param 'movie'|'series' $kind
     */
    public function refreshItem(string $kind, int $id): void
    {
        $statusKey = $kind === 'movie' ? self::KEY_MOVIES : self::KEY_SERIES;
        $rows      = $kind === 'movie' ? $this->client->getMovies([$id]) : $this->client->getSeries([$id]);

        if ($this->client->getLastError() !== null || $rows === []) {
            $this->swr->requestRefresh($statusKey);

            return;
        }

        $row = $this->findRowById($kind, $rows, $id);
        if ($row === null) {
            // The per-id filter was ignored (or Bazarr answered with a
            // differently-shaped list) and the requested id isn't actually in
            // the response — writing $rows[0] under $id would patch the WRONG
            // item. Treat exactly like a failed fetch.
            $this->logger->warning('Bazarr per-id refresh: requested id not found in response', ['kind' => $kind, 'id' => $id]);
            $this->swr->requestRefresh($statusKey);

            return;
        }

        $status = $kind === 'movie' ? self::computeMovieStatus($row) : self::computeSeriesStatus($row);
        $langs  = $kind === 'movie' ? self::extractMovieLangs($row) : null;

        try {
            $this->journalPatch($kind, $id, $status, $langs);
            $this->patchPooledMap($statusKey, $id, $status);
            if ($kind === 'movie') {
                $this->patchPooledMap(self::KEY_MOVIE_LANGS, $id, $langs);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Bazarr per-id patch failed', [
                'kind'      => $kind,
                'id'        => $id,
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
        }

        // Drop the per-request memo so this request re-reads the patched maps.
        $this->reset();

        // Everyone else's view, plus the cards/most-missing/badge datasets
        // derived from the same full-list fetch.
        $this->swr->requestRefresh($statusKey);
    }

    /**
     * Find the row matching $id in a per-id Bazarr response by its own id
     * field, rather than trusting $rows[0] — a filter param Bazarr ignored,
     * or a client mock/stub in a test, can hand back a list that doesn't
     * start with (or doesn't contain) the requested id at all.
     *
     * @param 'movie'|'series' $kind
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function findRowById(string $kind, array $rows, int $id): ?array
    {
        $idField = $kind === 'movie' ? 'radarrId' : 'sonarrSeriesId';

        foreach ($rows as $row) {
            if (isset($row[$idField]) && (int) $row[$idField] === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Merge one id into a pooled map WITHOUT touching its fetchedAt — a
     * one-row patch must not make the whole map look freshly fetched (the
     * soft-TTL self-heal / stale-request logic must still see the map's real
     * age).
     *
     * Read-then-write race: the read of $key above and
     * the write below are two separate, non-atomic pool operations (PSR-6 has
     * no compare-and-swap). If a bulk refresh's own write for the same $key
     * lands in that window, this patch's write — carrying the OLD map plus
     * one merged row, stamped with the OLD fetchedAt — overwrites the bulk
     * result. This is self-healing, not silently wrong: the back-dated
     * fetchedAt means the map reads 'stale' on its very next read, which asks
     * for another bulk refresh; and applyPatchesNewerThan() is what actually
     * guarantees the mutation itself is never lost across a concurrent bulk
     * write (see refreshItem()/applyPatchesNewerThan() docblocks) — this
     * method only has to avoid CORRUPTING the map, not win every race.
     */
    private function patchPooledMap(string $key, int $id, mixed $value): void
    {
        $hit = $this->swr->read($key, self::SOFT_TTL);
        if ($hit === null || !is_array($hit['value'])) {
            return; // hard miss: the journal + the requested rebuild cover it
        }

        $item = $this->cacheApp->getItem($key);
        $env  = $item->isHit() ? $item->get() : null;
        if (!is_array($env) || !isset($env['fetchedAt'])) {
            // An envelope whose fetchedAt cannot be
            // read must not be defaulted to time() — that would fabricate
            // freshness for a map this method never actually re-fetched. Bail
            // on the pool patch; the memo reset + requestRefresh() the caller
            // still performs cover it.
            return;
        }

        $map      = $hit['value'];
        $map[$id] = $value;
        $at       = (int) $env['fetchedAt'];

        $this->swr->write($key, $map, self::HARD_TTL, $at);
    }

    /**
     * Journal one patch under KEY_PATCHES, keyed "<kind>:<id>" so repeated
     * mutations on the same item collapse to the newest. Entries older than
     * PATCH_TTL are dropped opportunistically so the journal never grows
     * unbounded even though the whole item also carries its own
     * expiresAfter(PATCH_TTL).
     *
     * Read-modify-write race: the whole journal is one
     * PSR-6 item, read here, mutated in memory, and saved back — PSR-6 has no
     * compare-and-swap, so two concurrent mutations (e.g. two subtitle
     * downloads landing in the same instant) can both read the same journal,
     * and whichever saves last wins, silently dropping the other's entry.
     * This is bounded and self-healing, not a correctness hole: the loser's
     * OWN pooled-map patch (patchPooledMap(), a separate write) still lands
     * regardless, so that mutation's badge is still correct immediately; only
     * its protection against being clobbered by a same-second in-flight bulk
     * refresh (the fetch-start ordering rule) is what can be lost, and that is bounded
     * by SOFT_TTL — the map self-heals to fresh well within the next minute.
     *
     * @param 'movie'|'series' $kind
     * @param array{state: string, count: int} $status
     * @param array{present: list<array{lang: string, hi: bool, forced: bool}>, missing: list<array{lang: string, hi: bool, forced: bool}>, tracked: bool}|null $langs
     */
    private function journalPatch(string $kind, int $id, array $status, ?array $langs): void
    {
        $item    = $this->cacheApp->getItem(self::KEY_PATCHES);
        $journal = $item->isHit() && is_array($item->get()) ? $item->get() : [];

        $now = time();
        foreach ($journal as $patchKey => $entry) {
            if (!is_array($entry) || !isset($entry['at']) || ($now - (int) $entry['at']) >= self::PATCH_TTL) {
                unset($journal[$patchKey]);
            }
        }

        $journal["$kind:$id"] = [
            'at'     => $now,
            'kind'   => $kind,
            'id'     => $id,
            'status' => $status,
            'langs'  => $langs,
        ];

        $item->set($journal);
        $item->expiresAfter(self::PATCH_TTL);
        $this->cacheApp->save($item);
    }

    /**
     * Apply every journalled patch of $kind recorded at or after
     * $fetchStartedAt on top of $statusMap/$langsMap. Called by
     * BazarrIndexRefresher immediately before it writes a freshly fetched
     * bulk result, so a mutation that landed WHILE that fetch was in flight
     * is not silently reverted by it. A patch
     * older than $fetchStartedAt is ignored: that fetch's own result already
     * reflects it.
     *
     * @param 'movie'|'series' $kind
     * @param array<int, mixed> $statusMap
     * @param array<int, mixed> $langsMap
     * @return array{0: array<int, mixed>, 1: array<int, mixed>}
     */
    public function applyPatchesNewerThan(string $kind, int $fetchStartedAt, array $statusMap, array $langsMap): array
    {
        $item    = $this->cacheApp->getItem(self::KEY_PATCHES);
        $journal = $item->isHit() && is_array($item->get()) ? $item->get() : [];

        foreach ($journal as $entry) {
            if (!is_array($entry) || ($entry['kind'] ?? null) !== $kind) {
                continue;
            }
            if ((int) ($entry['at'] ?? 0) < $fetchStartedAt) {
                continue;
            }
            // A malformed entry (a foreign cache.app
            // write reusing this key, a future/older build's shape) must
            // never null out an otherwise-good map row — skip it entirely
            // rather than assign a missing/non-array 'status'.
            if (!isset($entry['status']) || !is_array($entry['status'])) {
                continue;
            }

            $id             = (int) ($entry['id'] ?? 0);
            $statusMap[$id] = $entry['status'];
            if ($kind === 'movie' && isset($entry['langs']) && is_array($entry['langs'])) {
                $langsMap[$id] = $entry['langs'];
            }
        }

        return [$statusMap, $langsMap];
    }

    /** @return array{state: string, count: int} */
    public function movieStatus(int $radarrId): array
    {
        // Gate first: on a multi-instance install nothing below may run — not
        // even a pool read — because the id is meaningless (see gate()), and
        // spending a Bazarr fetch on it would be pointless.
        if (!$this->gate(ServiceInstance::TYPE_RADARR)) {
            return self::HIDDEN;
        }

        $map = $this->movieMap();

        // A hard-missing map (null memo sentinel) means "we don't know yet",
        // which is NOT the same as "nothing missing" — see PENDING.
        return $map === null ? self::PENDING : ($map[$radarrId] ?? self::HIDDEN);
    }

    /**
     * Per-movie present/missing subtitle languages for the film-detail modal.
     * Same multi-instance gate and fail-closed rule as the badge: a gated,
     * untracked (no subtitle profile), absent, or not-yet-warmed movie
     * answers the empty `tracked:false` shape, never an error.
     *
     * @return array{present: list<array{lang: string, hi: bool, forced: bool}>, missing: list<array{lang: string, hi: bool, forced: bool}>, tracked: bool}
     */
    public function movieLanguages(int $radarrId): array
    {
        if (!$this->gate(ServiceInstance::TYPE_RADARR)) {
            return self::UNTRACKED_LANGS;
        }

        $map = $this->movieLangMap();

        return $map === null ? self::UNTRACKED_LANGS : ($map[$radarrId] ?? self::UNTRACKED_LANGS);
    }

    /**
     * Badge/chips lookup for a surface that renders exactly ONE item — the
     * dashboard quick-look. DO NOT call this from a grid, a list or a search
     * loop: on a cold index it issues one Bazarr call per invocation.
     * movieStatus() is the method for those.
     * BazarrTemplateGuardTest enforces the split on the template side.
     *
     * @return array{state: string, count: int}
     */
    public function movieStatusSingle(int $radarrId): array
    {
        if (!$this->gate(ServiceInstance::TYPE_RADARR)) {
            return self::HIDDEN;
        }

        $status = $this->movieStatus($radarrId);
        if ($status['state'] !== 'pending') {
            return $status;
        }

        return $this->loadSingle($radarrId)['status'];
    }

    /**
     * Same contract as movieStatusSingle(), for the quick-look's chips fetch.
     *
     * @return array{present: list<array{lang: string, hi: bool, forced: bool}>, missing: list<array{lang: string, hi: bool, forced: bool}>, tracked: bool}
     */
    public function movieLanguagesSingle(int $radarrId): array
    {
        if (!$this->gate(ServiceInstance::TYPE_RADARR)) {
            return self::UNTRACKED_LANGS;
        }

        // A hard-missing map is exactly the case movieStatus() reports as
        // 'pending' (both maps are filled by the SAME loadMovies() pass); reuse
        // that probe rather than duplicating the branch.
        if ($this->movieStatus($radarrId)['state'] !== 'pending') {
            return $this->movieLanguages($radarrId);
        }

        return $this->loadSingle($radarrId)['langs'];
    }

    /**
     * ONE `radarrid[]`-filtered call, memoized per request so a quick-look
     * (badge + chips) costs one call, not two. A failure answers the
     * fail-closed shapes — never 'pending', because the fallback already ran
     * and there is nothing left to wait for.
     *
     * Identity-verified via findRowById() — the
     * same guard refreshItem() uses: a filter param Bazarr ignored, or a
     * mock/stub in a test, can hand back a list that doesn't contain the
     * requested id at all, and blindly trusting $rows[0] would then compute
     * and memoize the WRONG movie's status/langs under this id. A row that
     * doesn't verify is treated exactly like an empty/error response — the
     * fail-closed shape is memoized (so a repeated badge+chips lookup in the
     * same request doesn't retry), and nothing is ever written to the pool
     * from this method to begin with.
     *
     * @return array{status: array{state: string, count: int}, langs: array{present: list<array{lang: string, hi: bool, forced: bool}>, missing: list<array{lang: string, hi: bool, forced: bool}>, tracked: bool}}
     */
    private function loadSingle(int $radarrId): array
    {
        if (isset($this->singles[$radarrId])) {
            return $this->singles[$radarrId];
        }

        $rows = $this->client->getMovies([$radarrId]);
        if ($this->client->getLastError() !== null || $rows === []) {
            return $this->singles[$radarrId] = ['status' => self::HIDDEN, 'langs' => self::UNTRACKED_LANGS];
        }

        $row = $this->findRowById('movie', $rows, $radarrId);
        if ($row === null) {
            return $this->singles[$radarrId] = ['status' => self::HIDDEN, 'langs' => self::UNTRACKED_LANGS];
        }

        return $this->singles[$radarrId] = [
            'status' => self::computeMovieStatus($row),
            'langs'  => self::extractMovieLangs($row),
        ];
    }

    /** @return array{state: string, count: int} */
    public function seriesStatus(int $sonarrSeriesId): array
    {
        if (!$this->gate(ServiceInstance::TYPE_SONARR)) {
            return self::HIDDEN;
        }

        $map = $this->seriesMap();

        return $map === null ? self::PENDING : ($map[$sonarrSeriesId] ?? self::HIDDEN);
    }

    /**
     * Grid cards for the Bazarr Movies tab, derived at refresh time from the
     * SAME getMovies() pass that fills the badge maps — the tab used to run
     * its own full-list fetch on every view (4-8 s).
     *
     * Posters are deliberately absent: BazarrController joins
     * BazarrPosterResolver::postersFor('movie') at render time so poster
     * freshness follows MediaLibraryCache, not Bazarr's soft window.
     *
     * Not multi-instance gated: unlike the per-id badge lookups, the tab is
     * its own page (not overlaid on Radarr/Sonarr grids) and the underlying
     * fetch is already scoped to Bazarr's one paired Radarr/Sonarr instance.
     *
     * @return array{state: 'ready'|'warming', cards: list<array{title: string, year: int|string|null, substate: string, count: int, missingLangs: list<string>, seriesId: int|null, movieId: int|null}>, languages: list<string>}
     */
    public function movieCards(): array
    {
        $hit = $this->readDataset(self::KEY_MOVIE_CARDS, self::KEY_MOVIES);
        if ($hit === null || !is_array($hit['cards'] ?? null) || !is_array($hit['languages'] ?? null)) {
            return ['state' => 'warming', 'cards' => [], 'languages' => []];
        }

        return ['state' => 'ready', 'cards' => array_values($hit['cards']), 'languages' => array_values($hit['languages'])];
    }

    /**
     * Grid cards for the Bazarr Series tab. Same shape and contract as
     * movieCards(); see that method's docblock.
     *
     * @return array{state: 'ready'|'warming', cards: list<array{title: string, year: int|string|null, substate: string, count: int, missingLangs: list<string>, seriesId: int|null, movieId: int|null}>, languages: list<string>}
     */
    public function seriesCards(): array
    {
        $hit = $this->readDataset(self::KEY_SERIES_CARDS, self::KEY_SERIES);
        if ($hit === null || !is_array($hit['cards'] ?? null) || !is_array($hit['languages'] ?? null)) {
            return ['state' => 'warming', 'cards' => [], 'languages' => []];
        }

        return ['state' => 'ready', 'cards' => array_values($hit['cards']), 'languages' => array_values($hit['languages'])];
    }

    /**
     * The Bazarr landing page's "most missing" candidates: BOTH kinds,
     * concatenated and unsorted across kinds (the controller merges + sorts
     * by missingCount desc with a title tie-break, then slices to
     * BazarrController::MOST_MISSING_LIMIT). 'warming' is returned when
     * EITHER half is a hard miss, since the strip needs both to be meaningful.
     *
     * @return array{state: 'ready'|'warming', items: list<array{kind: 'movie'|'series', id: int|null, title: string, year: int|string|null, missingCount: int}>}
     */
    public function mostMissing(): array
    {
        $movies = $this->readDataset(self::KEY_MOST_MISSING_MOVIES, self::KEY_MOVIES);
        $series = $this->readDataset(self::KEY_MOST_MISSING_SERIES, self::KEY_SERIES);

        if ($movies === null || $series === null) {
            return ['state' => 'warming', 'items' => []];
        }

        /** @var list<array{kind: 'movie'|'series', id: int|null, title: string, year: int|string|null, missingCount: int}> $items */
        $items = array_merge(array_values($movies), array_values($series));

        return ['state' => 'ready', 'items' => $items];
    }

    /**
     * `/api/badges` counts for the Bazarr topbar/tab chips. Refreshed
     * alongside the movie dataset (see BazarrIndexRefresher) since it is one
     * cheap call — giving it its own refresh key would double queue traffic
     * for no benefit.
     *
     * @return array{state: 'ready'|'warming', counts: array{movies: int, episodes: int, providers: int}}
     */
    public function badgeCounts(): array
    {
        $hit = $this->readDataset(self::KEY_BADGES, self::KEY_MOVIES);
        if (!is_array($hit)) {
            return ['state' => 'warming', 'counts' => ['movies' => 0, 'episodes' => 0, 'providers' => 0]];
        }

        return [
            'state'  => 'ready',
            'counts' => [
                'movies'    => (int) ($hit['movies'] ?? 0),
                'episodes'  => (int) ($hit['episodes'] ?? 0),
                'providers' => (int) ($hit['providers'] ?? 0),
            ],
        ];
    }

    /**
     * Shared read for the tab datasets: serve fresh or stale, ask the
     * consumer for a rebuild of $refreshKey when stale or missing, and never
     * fetch. Deliberately not memoized per-request (unlike movieMap() /
     * seriesMap()): each of the four dataset reads is called at most once per
     * page render, and memoizing a large card list on the service would work
     * against the memo's own bound (no unbounded cross-request retention).
     *
     * @return array<mixed>|null null = hard miss (caller renders "warming")
     */
    private function readDataset(string $key, string $refreshKey): ?array
    {
        $hit = $this->swr->read($key, self::SOFT_TTL);
        if ($hit === null) {
            $this->requestRefreshOnce($refreshKey);

            return null;
        }

        if ($hit['state'] === 'stale') {
            $this->swr->requestRefresh($refreshKey);
        }

        return is_array($hit['value']) ? $hit['value'] : null;
    }

    /**
     * Multi-instance safety gate. Bazarr pairs with exactly ONE Radarr and one
     * Sonarr, but the badges key on bare per-instance ids (`radarrId` /
     * `sonarrSeriesId`) and render on EVERY instance's pages. With two enabled
     * Radarr instances, "movie 42" means a different film in each, so a badge
     * would show the wrong subtitle state — and its download button would
     * fetch subtitles for the wrong film. Fail closed: hide every badge unless
     * exactly one instance of that type is enabled.
     *
     * Single-instance installs — the common case — are unaffected. Matching on
     * tmdbId instead of the *arr id is the future enhancement if multi-instance
     * badges are ever wanted.
     *
     * Memoized per request: the answer costs one already-request-cached
     * ServiceInstanceProvider read, but this is called once per rendered badge.
     */
    private function gate(string $type): bool
    {
        return match ($type) {
            ServiceInstance::TYPE_RADARR => $this->radarrGate ??= $this->exactlyOneEnabled($type),
            ServiceInstance::TYPE_SONARR => $this->sonarrGate ??= $this->exactlyOneEnabled($type),
            default => false, // unknown type — fail closed, same as multi-instance
        };
    }

    private function exactlyOneEnabled(string $type): bool
    {
        return $this->instances->hasExactlyOneEnabled($type);
    }

    /** @return array<int, array{state: string, count: int}>|null */
    private function movieMap(): ?array
    {
        $this->loadMovies();

        return $this->movies;
    }

    /** @return array<int, array{present: list<array{lang: string, hi: bool, forced: bool}>, missing: list<array{lang: string, hi: bool, forced: bool}>, tracked: bool}>|null */
    private function movieLangMap(): ?array
    {
        $this->loadMovies();

        return $this->movieLangs;
    }

    /**
     * ONE cached dataset fills BOTH maps. This method NEVER fetches: a hard
     * miss asks the messenger-worker for a rebuild and leaves both maps null,
     * so a 588-badge Films page costs zero Bazarr calls no matter how cold the
     * cache is.
     */
    private function loadMovies(): void
    {
        if ($this->moviesLoaded) {
            return;
        }
        $this->moviesLoaded = true;

        $status = $this->swr->read(self::KEY_MOVIES, self::SOFT_TTL);
        $langs  = $this->swr->read(self::KEY_MOVIE_LANGS, self::SOFT_TTL);

        if ($status === null || $langs === null) {
            $this->requestRefreshOnce(self::KEY_MOVIES);
            $this->movies     = null;
            $this->movieLangs = null;

            return;
        }

        if ($status['state'] === 'stale' || $langs['state'] === 'stale') {
            $this->swr->requestRefresh(self::KEY_MOVIES);
        }

        /** @var array<int, array{state: string, count: int}> $s */
        $s = is_array($status['value']) ? $status['value'] : [];
        /** @var array<int, array{present: list<array{lang: string, hi: bool, forced: bool}>, missing: list<array{lang: string, hi: bool, forced: bool}>, tracked: bool}> $l */
        $l = is_array($langs['value']) ? $langs['value'] : [];
        $this->movies     = $s;
        $this->movieLangs = $l;
    }

    /** @return array<int, array{state: string, count: int}>|null */
    private function seriesMap(): ?array
    {
        if ($this->seriesLoaded) {
            return $this->series;
        }
        $this->seriesLoaded = true;

        $hit = $this->swr->read(self::KEY_SERIES, self::SOFT_TTL);
        if ($hit === null) {
            $this->requestRefreshOnce(self::KEY_SERIES);
            $this->series = null;

            return null;
        }

        if ($hit['state'] === 'stale') {
            $this->swr->requestRefresh(self::KEY_SERIES);
        }

        /** @var array<int, array{state: string, count: int}> $map */
        $map = is_array($hit['value']) ? $hit['value'] : [];

        return $this->series = $map;
    }

    /**
     * Hard miss: ask for a rebuild, and — rate-limited across requests, not
     * just this one — shout if the consumer never answers.
     *
     * Everything below the dispatch is wrapped in one try/catch: this runs on
     * the badge render path (up to 588 times per Films-page load), so a
     * broken pool adapter here must degrade to "skip the log line", never a
     * 500. requestRefresh() above already has its own internal try/catch
     * (StaleWhileRevalidateCache's own contract), so it's excluded from this
     * one on purpose — a dispatch failure there is already handled/logged.
     */
    private function requestRefreshOnce(string $key): void
    {
        $this->swr->requestRefresh($key);

        try {
            if (!$this->swr->refreshIsOverdue($key, self::OVERDUE_THRESHOLD)) {
                return;
            }

            // A cold cache means every rendered badge (and every concurrent
            // request) reaches this branch — throttle the log line itself
            // across requests via a short-lived pool marker, the same shape
            // StaleWhileRevalidateCache's own coalescing marker uses.
            $marker = $this->cacheApp->getItem(self::OVERDUE_LOG_KEY);
            if ($marker->isHit()) {
                return;
            }
            $marker->set(true);
            $marker->expiresAfter(self::OVERDUE_THRESHOLD);
            $this->cacheApp->save($marker);

            if ((new ServiceHealthCache($this->cacheApp))->isDown(BazarrClient::SERVICE)) {
                // The open breaker is already the actionable signal — don't
                // also point at the messenger worker, which is likely fine.
                $this->logger->warning(
                    'Bazarr subtitle index refresh overdue — Bazarr appears to be down (circuit breaker open)',
                    ['key' => $key],
                );

                return;
            }

            $this->logger->error(
                'Bazarr subtitle index refresh overdue — is the messenger-worker service running?',
                ['key' => $key],
            );
        } catch (\Throwable) {
            // Best-effort operational logging only; never let it affect the
            // response.
        }
    }

    /**
     * @param array<string, mixed> $movie
     * @return array{state: string, count: int}
     */
    public static function computeMovieStatus(array $movie): array
    {
        if (($movie['profileId'] ?? null) === null) {
            return self::HIDDEN;
        }

        $missing = is_countable($movie['missing_subtitles'] ?? null) ? count($movie['missing_subtitles']) : 0;

        return $missing > 0
            ? ['state' => 'missing', 'count' => $missing]
            : ['state' => 'complete', 'count' => 0];
    }

    /**
     * Map one movie dict to its present/missing subtitle languages. The
     * untracked rule (no subtitle profile → tracked:false, empty lists) lives
     * HERE, not in BazarrLangs, which is a pure array mapper shared with the
     * episode drill-down.
     *
     * Public: BazarrIndexRefresher (the messenger-worker consumer) calls this
     * directly to build the language map from the same getMovies() pass that
     * feeds the status tuples.
     *
     * @param array<string, mixed> $movie
     * @return array{present: list<array{lang: string, hi: bool, forced: bool}>, missing: list<array{lang: string, hi: bool, forced: bool}>, tracked: bool}
     */
    public static function extractMovieLangs(array $movie): array
    {
        if (($movie['profileId'] ?? null) === null) {
            return self::UNTRACKED_LANGS;
        }

        return BazarrLangs::extract($movie) + ['tracked' => true];
    }

    /**
     * @param array<string, mixed> $series
     * @return array{state: string, count: int}
     */
    public static function computeSeriesStatus(array $series): array
    {
        if (($series['profileId'] ?? null) === null) {
            return self::HIDDEN;
        }

        $files = (int) ($series['episodeFileCount'] ?? 0);
        if ($files === 0) {
            return self::HIDDEN;
        }

        $missing = (int) ($series['episodeMissingCount'] ?? 0);

        return $missing > 0
            ? ['state' => 'missing', 'count' => $missing]
            : ['state' => 'complete', 'count' => 0];
    }
}
