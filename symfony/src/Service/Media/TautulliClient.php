<?php

namespace App\Service\Media;

use App\Service\ConfigService;
use App\Service\HealthService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Read-only client for the Tautulli API (current Plex activity).
 *
 * Optional service — Prismarr never talks to Plex directly; it consumes an
 * existing Tautulli instance's HTTP API. Only read-only commands are used
 * (`get_activity`, `get_metadata`, `get_history`); nothing mutating. The raw
 * response is reduced to a small, sanitized shape before it ever leaves the
 * server: IP addresses, Plex/server tokens, machine ids, file paths and the
 * raw payload are dropped (allow-list, not deny-list).
 *
 * Endpoint: GET {tautulli_url}/api/v2?apikey={key}&cmd=get_activity
 * Docs: https://github.com/Tautulli/Tautulli/blob/master/API.md
 *
 * Like the other flat-config clients (Gluetun, qBittorrent), config is read
 * lazily from the `setting` table via ConfigService and the client fails open:
 * a disabled / unconfigured / unreachable Tautulli yields a neutral shape with
 * an `error` code instead of throwing, so the dashboard never breaks.
 */
class TautulliClient implements ResetInterface
{
    /** Short slug — circuit-breaker key + HealthService service id. */
    public const SERVICE = 'tautulli';

    private bool $configLoaded = false;
    private bool $enabled = true;
    private string $baseUrl = '';
    private string $apiKey = '';

    /** @var array{code:int, method:string, path:string, message:string}|null */
    private ?array $lastError = null;

    public function __construct(
        private readonly ConfigService $config,
        private readonly LoggerInterface $logger,
        private readonly ?ServiceHealthCache $health = null,
    ) {}

    public function reset(): void
    {
        $this->configLoaded = false;
        $this->enabled      = true;
        $this->baseUrl      = '';
        $this->apiKey       = '';
        $this->lastError    = null;
    }

    /** @return array{code:int, method:string, path:string, message:string}|null */
    public function getLastError(): ?array
    {
        return $this->lastError;
    }

    private function ensureConfig(): void
    {
        if ($this->configLoaded) {
            return;
        }
        // Explicit kill switch (issue #15 pattern): only '0' disables; a
        // missing row means the toggle was never touched → stays enabled.
        $this->enabled = $this->config->get('tautulli_enabled') !== '0';
        $this->baseUrl = (string) ($this->config->get('tautulli_url') ?? '');
        $this->apiKey  = (string) ($this->config->get('tautulli_api_key') ?? '');
        $this->configLoaded = true;
    }

    /**
     * Lightweight reachability probe for HealthService. True when a fresh
     * get_activity call returns a successful Tautulli envelope.
     */
    public function ping(): bool
    {
        $this->ensureConfig();
        if (!$this->enabled || $this->baseUrl === '' || $this->apiKey === '') {
            return false;
        }
        $resp = $this->request();
        return $resp !== null && $resp['ok'] === true;
    }

    /**
     * Current Plex activity, normalized + sanitized for the frontend.
     *
     * Always returns the full shape — `enabled`, `configured`, `connected`
     * flags plus an `error` code (null | 'unconfigured' | 'unreachable' |
     * 'auth') so the widget can render the right empty/error state without
     * ever seeing a stack trace or a secret.
     *
     * @return array{
     *   enabled: bool, configured: bool, connected: bool, error: ?string,
     *   streamCount: int, directPlayCount: int, directStreamCount: int,
     *   transcodeCount: int,
     *   bandwidth: array{totalKbps:int, lanKbps:int, wanKbps:int, totalMbps:float, lanMbps:float, wanMbps:float},
     *   sessions: list<array<string, mixed>>
     * }
     */
    public function getActivity(): array
    {
        $this->ensureConfig();

        $configured = $this->baseUrl !== '' && $this->apiKey !== '';
        $base = self::emptyShape($this->enabled, $configured);

        if (!$this->enabled) {
            return $base; // error stays null — the widget is hidden upstream anyway
        }
        if (!$configured) {
            $base['error'] = 'unconfigured';
            return $base;
        }

        $resp = $this->request();
        if ($resp === null) {
            $base['error'] = 'unreachable';
            return $base;
        }
        if ($resp['ok'] !== true) {
            // Tautulli answers HTTP 200 with result:"error" on a bad apikey.
            $base['error'] = 'auth';
            return $base;
        }

        return [
            'enabled'    => true,
            'configured' => true,
            'connected'  => true,
            'error'      => null,
        ] + self::normalizeActivity($resp['data']);
    }

    /**
     * Full metadata for one Plex item, normalized + sanitized. Shaped like
     * getActivity(): always returns the flag/error envelope plus the data.
     *
     * @return array{
     *   enabled: bool, configured: bool, connected: bool, error: ?string,
     *   metadata: array<string, mixed>
     * }
     */
    public function getMetadata(string $ratingKey): array
    {
        $this->ensureConfig();
        $configured = $this->baseUrl !== '' && $this->apiKey !== '';

        $base = ['enabled' => $this->enabled, 'configured' => $configured, 'connected' => false, 'error' => null, 'metadata' => []];

        if (!$this->enabled)      { return $base; }
        if (!$configured)         { $base['error'] = 'unconfigured'; return $base; }
        if ($ratingKey === '' || !ctype_digit($ratingKey)) { $base['error'] = 'not_found'; return $base; }

        $resp = $this->request(['cmd' => 'get_metadata', 'rating_key' => $ratingKey]);
        if ($resp === null)        { $base['error'] = 'unreachable'; return $base; }
        if ($resp['ok'] !== true)  { $base['error'] = 'auth'; return $base; }
        if ($resp['data'] === [])  { $base['error'] = 'not_found'; return $base; }

        return ['enabled' => true, 'configured' => true, 'connected' => true, 'error' => null,
                'metadata' => self::normalizeMetadata($resp['data'])];
    }

    /**
     * Map a raw `get_metadata` payload to the {type, id} pair the global
     * quick-look modal consumes, or null when the item has no usable
     * show/movie-level tmdb guid.
     *
     * movie/show read the item's own guids; season reads parent_guids and
     * episode grandparent_guids (both = the show) — an episode-level tmdb
     * guid identifies the episode, which the quick-look can't render.
     * Only the numeric TMDb id survives; raw guids stay out of every
     * client payload, same allow-list stance as the normalizers.
     *
     * @param array<string, mixed> $data
     * @return ?array{type: string, id: int}
     */
    public static function tmdbIdFromMetadata(array $data): ?array
    {
        [$type, $guidField] = match (self::str($data['media_type'] ?? null)) {
            'movie'   => ['movie', 'guids'],
            'show'    => ['tv', 'guids'],
            'season'  => ['tv', 'parent_guids'],
            'episode' => ['tv', 'grandparent_guids'],
            default   => [null, null],
        };
        if ($type === null || !is_array($data[$guidField] ?? null)) {
            return null;
        }
        foreach ($data[$guidField] as $guid) {
            if (is_string($guid) && preg_match('#^tmdb://(\d+)$#', $guid, $m) === 1) {
                return ['type' => $type, 'id' => (int) $m[1]];
            }
        }
        return null;
    }

    /**
     * Resolve a Plex rating key to the TMDb {type, id} the global quick-look
     * opens with. Fails open to null (disabled / unconfigured / unreachable /
     * no tmdb guid) so the caller can fall back to the legacy Plex modal.
     * When an episode/season payload lacks show-level guids (older Tautulli),
     * one extra metadata hop to the show's own rating key covers it.
     *
     * @return ?array{type: string, id: int}
     */
    public function resolveTmdbId(string $ratingKey): ?array
    {
        $this->ensureConfig();
        if (!$this->enabled || $this->baseUrl === '' || $this->apiKey === '') {
            return null;
        }
        if ($ratingKey === '' || !ctype_digit($ratingKey)) {
            return null;
        }

        $resp = $this->request(['cmd' => 'get_metadata', 'rating_key' => $ratingKey]);
        if ($resp === null || $resp['ok'] !== true || $resp['data'] === []) {
            return null;
        }
        $data = $resp['data'];
        if (($found = self::tmdbIdFromMetadata($data)) !== null) {
            return $found;
        }

        $showKey = match (self::str($data['media_type'] ?? null)) {
            'episode' => self::str($data['grandparent_rating_key'] ?? null),
            'season'  => self::str($data['parent_rating_key'] ?? null),
            default   => '',
        };
        if ($showKey === '' || !ctype_digit($showKey)) {
            return null;
        }
        $resp = $this->request(['cmd' => 'get_metadata', 'rating_key' => $showKey]);
        if ($resp === null || $resp['ok'] !== true || $resp['data'] === []) {
            return null;
        }
        return self::tmdbIdFromMetadata($resp['data']);
    }

    /**
     * Recent watch history, normalized + sanitized. Returns a plain list (no
     * envelope) — an empty list covers disabled/unconfigured/unreachable so the
     * widget's "recently watched" pane just shows its empty state.
     *
     * @return list<array<string, mixed>>
     */
    public function getHistory(int $length = 8, int $start = 0, ?string $userId = null): array
    {
        $this->ensureConfig();
        if (!$this->enabled || $this->baseUrl === '' || $this->apiKey === '') {
            return [];
        }
        $params = [
            'cmd'          => 'get_history',
            'length'       => (string) max(1, min(50, $length)),
            'start'        => (string) max(0, $start),
            'order_column' => 'date',
            'order_dir'    => 'desc',
        ];
        if ($userId !== null && $userId !== '') {
            $params['user_id'] = $userId;
        }
        $resp = $this->request($params);
        if ($resp === null || $resp['ok'] !== true) {
            return [];
        }
        return self::normalizeHistory($resp['data']);
    }

    /** Clamp a stats window to the allowed presets; anything else → 30 days. */
    private static function clampRange(int $days): int
    {
        return in_array($days, [7, 30, 90], true) ? $days : 30;
    }

    /** Clamp the stats metric to the two Tautulli accepts. */
    private static function clampMetric(string $metric): string
    {
        return $metric === 'duration' ? 'duration' : 'plays';
    }

    /**
     * Watch statistics for the home page, normalized + sanitized. Returns the
     * seven lists the activity page renders; an all-empty shape covers
     * disabled/unconfigured/unreachable.
     *
     * @return array{topMovies:list<array<string,mixed>>, topShows:list<array<string,mixed>>, topUsers:list<array<string,mixed>>, topPlatforms:list<array<string,mixed>>, popularMovies:list<array<string,mixed>>, popularShows:list<array<string,mixed>>, mostConcurrent:list<array<string,mixed>>}
     */
    public function getHomeStats(int $days, string $metric = 'plays', ?string $userId = null): array
    {
        $this->ensureConfig();
        if (!$this->enabled || $this->baseUrl === '' || $this->apiKey === '') {
            return self::normalizeHomeStats([]);
        }
        $params = [
            'cmd'         => 'get_home_stats',
            'time_range'  => (string) self::clampRange($days),
            'stats_type'  => self::clampMetric($metric),
            'stats_count' => '5',
        ];
        if ($userId !== null && $userId !== '') {
            $params['user_id'] = $userId;
        }
        $resp = $this->request($params);
        if ($resp === null || $resp['ok'] !== true) {
            return self::normalizeHomeStats([]);
        }
        return self::normalizeHomeStats(is_array($resp['data']) ? $resp['data'] : []);
    }

    /**
     * Pure transform: get_home_stats `data` (list of stat groups) → sanitized
     * lists. Allow-list only: usernames/emails/ids/avatars/file paths/guids are
     * never copied out. Only the groups we render are kept.
     *
     * @param array<int, mixed> $data
     * @return array{topMovies:list<array<string,mixed>>, topShows:list<array<string,mixed>>, topUsers:list<array<string,mixed>>, topPlatforms:list<array<string,mixed>>, popularMovies:list<array<string,mixed>>, popularShows:list<array<string,mixed>>, mostConcurrent:list<array<string,mixed>>}
     */
    public static function normalizeHomeStats(array $data): array
    {
        $out = [
            'topMovies' => [], 'topShows' => [], 'topUsers' => [], 'topPlatforms' => [],
            'popularMovies' => [], 'popularShows' => [], 'mostConcurrent' => [],
        ];
        foreach ($data as $group) {
            if (!is_array($group)) { continue; }
            $rows = is_array($group['rows'] ?? null) ? $group['rows'] : [];
            switch (self::str($group['stat_id'] ?? null)) {
                case 'top_movies':
                    foreach ($rows as $r) {
                        if (!is_array($r)) { continue; }
                        $dur = (int) ($r['total_duration'] ?? 0);
                        $out['topMovies'][] = [
                            'ratingKey'     => self::str($r['rating_key'] ?? null),
                            'title'         => self::str($r['title'] ?? null),
                            'year'          => self::str($r['year'] ?? null),
                            'posterPath'    => self::str($r['thumb'] ?? ($r['grandparent_thumb'] ?? null)),
                            'plays'         => (int) ($r['total_plays'] ?? 0),
                            'duration'      => $dur,
                            'durationLabel' => self::secondsLabel($dur),
                        ];
                    }
                    break;
                case 'top_tv':
                    foreach ($rows as $r) {
                        if (!is_array($r)) { continue; }
                        $dur = (int) ($r['total_duration'] ?? 0);
                        $out['topShows'][] = [
                            'ratingKey'     => self::str($r['rating_key'] ?? null),
                            'title'         => self::str($r['title'] ?? null),
                            'posterPath'    => self::str($r['grandparent_thumb'] ?? ($r['thumb'] ?? null)),
                            'plays'         => (int) ($r['total_plays'] ?? 0),
                            'duration'      => $dur,
                            'durationLabel' => self::secondsLabel($dur),
                        ];
                    }
                    break;
                case 'popular_movies':
                    foreach ($rows as $r) {
                        if (!is_array($r)) { continue; }
                        $out['popularMovies'][] = [
                            'ratingKey'    => self::str($r['rating_key'] ?? null),
                            'title'        => self::str($r['title'] ?? null),
                            'year'         => self::str($r['year'] ?? null),
                            'posterPath'   => self::str($r['thumb'] ?? ($r['grandparent_thumb'] ?? null)),
                            'usersWatched' => (int) ($r['users_watched'] ?? 0),
                        ];
                    }
                    break;
                case 'popular_tv':
                    foreach ($rows as $r) {
                        if (!is_array($r)) { continue; }
                        $out['popularShows'][] = [
                            'ratingKey'    => self::str($r['rating_key'] ?? null),
                            'title'        => self::str($r['title'] ?? null),
                            'posterPath'   => self::str($r['grandparent_thumb'] ?? ($r['thumb'] ?? null)),
                            'usersWatched' => (int) ($r['users_watched'] ?? 0),
                        ];
                    }
                    break;
                case 'top_users':
                    foreach ($rows as $r) {
                        if (!is_array($r)) { continue; }
                        $dur = (int) ($r['total_duration'] ?? 0);
                        $out['topUsers'][] = [
                            'userDisplayName' => self::str($r['friendly_name'] ?? null),
                            'plays'           => (int) ($r['total_plays'] ?? 0),
                            'duration'        => $dur,
                            'durationLabel'   => self::secondsLabel($dur),
                        ];
                    }
                    break;
                case 'top_platforms':
                    foreach ($rows as $r) {
                        if (!is_array($r)) { continue; }
                        $dur = (int) ($r['total_duration'] ?? 0);
                        $out['topPlatforms'][] = [
                            'platform'      => self::str($r['platform_name'] ?? ($r['platform'] ?? null)),
                            'plays'         => (int) ($r['total_plays'] ?? 0),
                            'duration'      => $dur,
                            'durationLabel' => self::secondsLabel($dur),
                        ];
                    }
                    break;
                case 'most_concurrent':
                    foreach ($rows as $r) {
                        if (!is_array($r)) { continue; }
                        $out['mostConcurrent'][] = [
                            'title' => self::str($r['title'] ?? null),
                            'count' => (int) ($r['count'] ?? 0),
                        ];
                    }
                    break;
            }
        }
        return $out;
    }

    /** Seconds -> "3h 12m" / "45m" / "0m". For watch-duration tile labels. */
    private static function secondsLabel(int $s): string
    {
        if ($s <= 0) { return '0m'; }
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        return $h > 0 ? sprintf('%dh %dm', $h, $m) : sprintf('%dm', $m);
    }

    /**
     * Plays-per-day series for the activity graph, ready for Chart.js.
     *
     * @return array{categories:list<string>, series:list<array{name:string,data:list<int>}>}
     */
    public function getPlaysByDate(int $days, string $metric = 'plays', ?string $userId = null): array
    {
        return $this->playsChart('get_plays_by_date', $days, $metric, $userId);
    }

    /** Plays per day by transcode decision (Direct Play / Direct Stream / Transcode). */
    public function getPlaysByStreamType(int $days, string $metric = 'plays', ?string $userId = null): array
    {
        return $this->playsChart('get_plays_by_stream_type', $days, $metric, $userId);
    }

    /** Plays aggregated by hour of day (0-23). */
    public function getPlaysByHourOfDay(int $days, string $metric = 'plays', ?string $userId = null): array
    {
        return $this->playsChart('get_plays_by_hourofday', $days, $metric, $userId);
    }

    /** Plays aggregated by day of week. */
    public function getPlaysByDayOfWeek(int $days, string $metric = 'plays', ?string $userId = null): array
    {
        return $this->playsChart('get_plays_by_dayofweek', $days, $metric, $userId);
    }

    /** Plays by top-10 platform, split by transcode decision (problem clients). */
    public function getStreamTypeByPlatform(int $days, string $metric = 'plays', ?string $userId = null): array
    {
        return $this->playsChart('get_stream_type_by_top_10_platforms', $days, $metric, $userId);
    }

    /** Plays by source resolution (pre-transcode), split by transcode decision. */
    public function getPlaysBySourceResolution(int $days, string $metric = 'plays', ?string $userId = null): array
    {
        return $this->playsChart('get_plays_by_source_resolution', $days, $metric, $userId);
    }

    /** Plays by stream resolution (post-transcode), split by transcode decision. */
    public function getPlaysByStreamResolution(int $days, string $metric = 'plays', ?string $userId = null): array
    {
        return $this->playsChart('get_plays_by_stream_resolution', $days, $metric, $userId);
    }

    /** Plays by top-10 user, split by transcode decision. */
    public function getStreamTypeByUser(int $days, string $metric = 'plays', ?string $userId = null): array
    {
        return $this->playsChart('get_stream_type_by_top_10_users', $days, $metric, $userId);
    }

    /**
     * Shared fetch for the read-only Tautulli chart commands that all return the
     * `{categories, series}` envelope. Clamps the range and fails open to the
     * neutral shape (disabled/unconfigured/unreachable).
     *
     * @return array{categories:list<string>, series:list<array{name:string,data:list<int>}>}
     */
    private function playsChart(string $cmd, int $days, string $metric = 'plays', ?string $userId = null): array
    {
        $this->ensureConfig();
        if (!$this->enabled || $this->baseUrl === '' || $this->apiKey === '') {
            return self::normalizePlaysByDate([]);
        }
        $params = [
            'cmd'        => $cmd,
            'time_range' => (string) self::clampRange($days),
            'y_axis'     => self::clampMetric($metric),
        ];
        if ($userId !== null && $userId !== '') {
            $params['user_id'] = $userId;
        }
        $resp = $this->request($params);
        if ($resp === null || $resp['ok'] !== true) {
            return self::normalizePlaysByDate([]);
        }
        return self::normalizePlaysByDate(is_array($resp['data']) ? $resp['data'] : []);
    }

    /**
     * Concurrent stream count over time by stream type. Unlike the other charts
     * this command takes no y_axis (it is always a count), only time_range + user.
     *
     * @return array{categories:list<string>, series:list<array{name:string,data:list<int>}>}
     */
    public function getConcurrentStreams(int $days, ?string $userId = null): array
    {
        $this->ensureConfig();
        if (!$this->enabled || $this->baseUrl === '' || $this->apiKey === '') {
            return self::normalizePlaysByDate([]);
        }
        $params = ['cmd' => 'get_concurrent_streams_by_stream_type', 'time_range' => (string) self::clampRange($days)];
        if ($userId !== null && $userId !== '') {
            $params['user_id'] = $userId;
        }
        $resp = $this->request($params);
        if ($resp === null || $resp['ok'] !== true) {
            return self::normalizePlaysByDate([]);
        }
        return self::normalizePlaysByDate(is_array($resp['data']) ? $resp['data'] : []);
    }

    /**
     * Pure transform: a chart command's `data` envelope → {categories, series}. Series
     * data is coerced to ints; only name + data survive.
     *
     * @param array<string, mixed> $data
     * @return array{categories:list<string>, series:list<array{name:string,data:list<int>}>}
     */
    public static function normalizePlaysByDate(array $data): array
    {
        $series = [];
        $rawSeries = is_array($data['series'] ?? null) ? $data['series'] : [];
        foreach ($rawSeries as $s) {
            if (!is_array($s)) {
                continue;
            }
            $sname = self::str($s['name'] ?? null) ?? '';
            if (strtolower($sname) === 'total') {
                continue; // aggregate line — charts show the per-type breakdown only
            }
            $vals = is_array($s['data'] ?? null) ? $s['data'] : [];
            $series[] = [
                'name' => $sname,
                'data' => array_map(static fn ($v) => (int) $v, array_values($vals)),
            ];
        }
        return ['categories' => self::strList($data['categories'] ?? []), 'series' => $series];
    }

    /**
     * Library sections with item counts, normalized + sanitized.
     *
     * @return list<array{name:?string,type:?string,count:int,childCount:?int}>
     */
    public function getLibraries(): array
    {
        $this->ensureConfig();
        if (!$this->enabled || $this->baseUrl === '' || $this->apiKey === '') {
            return [];
        }
        $resp = $this->request(['cmd' => 'get_libraries']);
        if ($resp === null || $resp['ok'] !== true) {
            return [];
        }
        return self::normalizeLibraries(is_array($resp['data']) ? $resp['data'] : []);
    }

    /**
     * Per-user totals for the Users overview table, normalized + sanitized.
     * Empty list covers disabled/unconfigured/unreachable.
     *
     * @return list<array{friendlyName:?string,lastSeen:int,lastPlayed:?string,plays:int,durationSeconds:int}>
     */
    public function getUsersTable(int $length = 25): array
    {
        $this->ensureConfig();
        if (!$this->enabled || $this->baseUrl === '' || $this->apiKey === '') {
            return [];
        }
        $resp = $this->request([
            'cmd'          => 'get_users_table',
            'order_column' => 'plays',
            'order_dir'    => 'desc',
            'length'       => (string) max(1, min(100, $length)),
        ]);
        if ($resp === null || $resp['ok'] !== true) {
            return [];
        }
        return self::normalizeUsersTable(is_array($resp['data']) ? $resp['data'] : []);
    }

    /**
     * Friendly-name + opaque user_id pairs for the page's user filter dropdown.
     *
     * @return list<array{name:string,id:string}>
     */
    public function getUserNames(): array
    {
        $this->ensureConfig();
        if (!$this->enabled || $this->baseUrl === '' || $this->apiKey === '') {
            return [];
        }
        $resp = $this->request(['cmd' => 'get_user_names']);
        if ($resp === null || $resp['ok'] !== true) {
            return [];
        }
        // get_user_names returns a bare list as `data`, not a {data:...} envelope.
        return self::normalizeUserNames(is_array($resp['data']) ? $resp['data'] : []);
    }

    /**
     * Pure transform: get_libraries `data` → sanitized rows. Names + counts
     * only; section ids, thumbs and paths are dropped.
     *
     * @param array<int, mixed> $data
     * @return list<array{name:?string,type:?string,count:int,childCount:?int}>
     */
    public static function normalizeLibraries(array $data): array
    {
        $out = [];
        foreach ($data as $lib) {
            if (!is_array($lib)) {
                continue;
            }
            $out[] = [
                'name'       => self::str($lib['section_name'] ?? null),
                'type'       => self::str($lib['section_type'] ?? null),
                'count'      => (int) ($lib['count'] ?? 0),
                'childCount' => isset($lib['child_count']) ? (int) $lib['child_count'] : null,
            ];
        }
        return $out;
    }

    /**
     * Pure transform: get_users_table `data` envelope -> privacy-safe rows.
     * Allow-list only — ip_address, email, user_id, avatar/thumb never copied out.
     *
     * @param array<string, mixed> $data
     * @return list<array{friendlyName:?string,lastSeen:int,lastPlayed:?string,plays:int,durationSeconds:int}>
     */
    public static function normalizeUsersTable(array $data): array
    {
        $rows = is_array($data['data'] ?? null) ? $data['data'] : [];
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) { continue; }
            $out[] = [
                'friendlyName'    => self::str($r['friendly_name'] ?? ($r['user'] ?? null)),
                'lastSeen'        => (int) ($r['last_seen'] ?? 0),
                'lastPlayed'      => self::str($r['last_played'] ?? null),
                'plays'           => (int) ($r['plays'] ?? 0),
                'durationSeconds' => (int) ($r['duration'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Pure transform: get_user_names `data` (bare list) -> {name, id} pairs for the
     * filter dropdown. `id` is the opaque Tautulli user_id (filter token only).
     * Rows missing either field are dropped.
     *
     * @param array<int, mixed> $data
     * @return list<array{name:string,id:string}>
     */
    public static function normalizeUserNames(array $data): array
    {
        $out = [];
        foreach ($data as $u) {
            if (!is_array($u)) { continue; }
            $name = self::str($u['friendly_name'] ?? null);
            $id   = self::str($u['user_id'] ?? null);
            if ($name !== null && $id !== null) {
                $out[] = ['name' => $name, 'id' => $id];
            }
        }
        return $out;
    }

    /**
     * Pure transform: get_history `data` envelope -> sanitized rows. Allow-list
     * only; usernames/emails/IPs/file paths are never copied out.
     *
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    public static function normalizeHistory(array $data): array
    {
        $rows = is_array($data['data'] ?? null) ? $data['data'] : [];
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $mediaType = self::str($r['media_type'] ?? null);
            $out[] = [
                'ratingKey'        => self::str($r['rating_key'] ?? null),
                'mediaType'        => $mediaType,
                'title'            => self::str($r['title'] ?? ($r['full_title'] ?? null)),
                'grandparentTitle' => self::str($r['grandparent_title'] ?? null),
                'year'             => self::str($r['year'] ?? null),
                'posterPath'       => self::pickPoster($r, $mediaType),
                // Display name only — never username (Plex login) or email.
                'userDisplayName'  => self::str($r['friendly_name'] ?? ($r['user'] ?? null)),
                'watchedAt'        => (int) ($r['date'] ?? 0),
                'percentComplete'  => (int) ($r['percent_complete'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Pure transform: get_metadata `data` -> sanitized shape. Allow-list only;
     * file paths, section ids, guids, raw media_info and the raw payload are
     * dropped by construction.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function normalizeMetadata(array $data): array
    {
        $mediaType = self::str($data['media_type'] ?? null);
        $mi = (is_array($data['media_info'] ?? null) && isset($data['media_info'][0]) && is_array($data['media_info'][0]))
            ? $data['media_info'][0] : [];

        return [
            'mediaType'        => $mediaType,
            'title'            => self::str($data['title'] ?? null),
            'grandparentTitle' => self::str($data['grandparent_title'] ?? null),
            'year'             => self::str($data['year'] ?? null),
            'season'           => isset($data['parent_media_index']) ? (int) $data['parent_media_index'] : null,
            'episode'          => isset($data['media_index']) ? (int) $data['media_index'] : null,
            'summary'          => self::str($data['summary'] ?? null),
            'tagline'          => self::str($data['tagline'] ?? null),
            'contentRating'    => self::str($data['content_rating'] ?? null),
            'durationMs'       => (int) ($data['duration'] ?? 0),
            'durationLabel'    => self::durationLabel((int) ($data['duration'] ?? 0)),
            'genres'           => self::strList($data['genres'] ?? []),
            'ratings'          => [
                'critic'   => is_numeric($data['rating'] ?? null) ? (float) $data['rating'] : null,
                'audience' => is_numeric($data['audience_rating'] ?? null) ? (float) $data['audience_rating'] : null,
            ],
            'directors'        => self::strList($data['directors'] ?? []),
            'writers'          => self::strList($data['writers'] ?? []),
            'cast'             => array_slice(self::strList($data['actors'] ?? []), 0, 8),
            'studio'           => self::str($data['studio'] ?? null),
            'releaseDate'      => self::str($data['originally_available_at'] ?? null),
            'media'            => [
                'resolution'  => self::str($mi['video_full_resolution'] ?? ($mi['video_resolution'] ?? null)),
                'videoCodec'  => self::str($mi['video_codec'] ?? null),
                'audioCodec'  => self::str($mi['audio_codec'] ?? null),
                'container'   => self::str($mi['container'] ?? null),
                'bitrateKbps' => (int) ($mi['bitrate'] ?? 0),
            ],
        ];
    }

    /** ms -> "1 h 37 min" / "45 min" / null when zero. */
    private static function durationLabel(int $ms): ?string
    {
        if ($ms <= 0) {
            return null;
        }
        $totalMin = (int) round($ms / 60000);
        $h = intdiv($totalMin, 60);
        $m = $totalMin % 60;
        return $h > 0 ? sprintf('%d h %02d min', $h, $m) : sprintf('%d min', $m);
    }

    /**
     * Coerce a Tautulli list (array of scalars) to a clean list<string>,
     * dropping empties. Non-arrays yield [].
     *
     * @return list<string>
     */
    private static function strList(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $item) {
            $s = self::str($item);
            if ($s !== null) {
                $out[] = $s;
            }
        }
        return $out;
    }

    /**
     * Server-side fetch of a Plex poster via Tautulli's `pms_image_proxy`
     * command. The API key never leaves the server — the browser only ever
     * receives the image bytes (streamed by TautulliController::apiImage).
     *
     * `fallback=poster` makes Tautulli return its own neutral poster when the
     * art can't be retrieved, so the widget shows a grey poster rather than a
     * broken image. Returns null only when Tautulli is disabled / unconfigured
     * / unreachable, the path isn't a proxyable Plex image path, or the
     * response wasn't actually an image.
     *
     * @return array{body: string, contentType: string}|null
     */
    public function fetchImage(string $img, int $width = 300, int $height = 450): ?array
    {
        $this->ensureConfig();
        if (!$this->enabled || $this->baseUrl === '' || $this->apiKey === '') {
            return null;
        }
        // Allow-list the path before opening any socket — never proxy an
        // arbitrary URL/route through our authenticated server.
        if (!self::isProxyableImagePath($img)) {
            return null;
        }
        if ($this->health?->isDown(self::SERVICE)) {
            return null;
        }

        $endpoint = rtrim($this->baseUrl, '/') . '/api/v2';
        if (HealthService::urlBlockedReason($endpoint) !== null) {
            return null;
        }

        $url = $endpoint . '?' . http_build_query([
            'apikey'   => $this->apiKey,
            'cmd'      => 'pms_image_proxy',
            'img'      => $img,
            'width'    => $width,
            'height'   => $height,
            'fallback' => 'poster',
        ]);

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_CONNECTTIMEOUT  => 3,
            CURLOPT_TIMEOUT         => 10,
            CURLOPT_NOSIGNAL        => true,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $err !== '' || $code !== 200) {
            $this->health?->markDown(self::SERVICE);
            return null;
        }
        $this->health?->clear(self::SERVICE);

        // Only ever hand back a genuine image — never an error envelope or HTML.
        $contentType = strtolower(trim(explode(';', $type)[0]));
        if (!str_starts_with($contentType, 'image/') || !is_string($body) || $body === '') {
            return null;
        }

        return ['body' => $body, 'contentType' => $contentType];
    }

    /**
     * True only for Plex library image paths (e.g. /library/metadata/12/thumb/3).
     * Rejects absolute URLs, schemes, protocol-relative refs, path traversal,
     * query strings and backslashes — so the image proxy can't be steered at an
     * arbitrary target (SSRF / open-relay guard). Public + static for testing.
     */
    public static function isProxyableImagePath(string $img): bool
    {
        if ($img === '' || strlen($img) > 512) {
            return false;
        }
        if (str_contains($img, '..') || str_contains($img, '\\')) {
            return false;
        }
        return preg_match('#^/library/[A-Za-z0-9/_.\-]+$#', $img) === 1;
    }

    /**
     * Issue a Tautulli API call. Returns the decoded Tautulli envelope split
     * into a tiny result tuple, or null when the host is unreachable / the
     * response isn't valid JSON. Honors + feeds the cross-request circuit
     * breaker so a downed Tautulli doesn't cost an 8 s timeout on every poll.
     *
     * @param array<string, string> $params Tautulli command + args. Must not contain
     *                                       'apikey' (reserved; added automatically).
     * @return array{ok: bool, data: array<string, mixed>}|null
     */
    private function request(array $params = ['cmd' => 'get_activity']): ?array
    {
        $cmd = $params['cmd'] ?? 'unknown';

        // Circuit breaker: skip the call entirely if Tautulli was just seen
        // down — the 10 s widget poll would otherwise stack connect timeouts.
        if ($this->health?->isDown(self::SERVICE)) {
            return null;
        }

        // SSRF guard #1 — reuse the shared validator (blocks non-http(s)
        // schemes + link-local / cloud-metadata IPs) before opening a socket.
        $endpoint = rtrim($this->baseUrl, '/') . '/api/v2';
        if (($reason = HealthService::urlBlockedReason($endpoint)) !== null) {
            $this->recordError(0, 'blocked: ' . $reason, $cmd);
            $this->logger->warning('Tautulli URL blocked', ['reason' => $reason]);
            return null;
        }

        $url = $endpoint . '?' . http_build_query(['apikey' => $this->apiKey] + $params);

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_NOSIGNAL       => true, // critical under FrankenPHP/Alpine
            CURLOPT_FOLLOWLOCATION => false,
            // SSRF guard #2 — lock the protocol even across any redirect.
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $err !== '' || $code === 0) {
            $this->recordError($code, $err !== '' ? $err : 'connection failed', $cmd);
            $this->health?->markDown(self::SERVICE);
            return null;
        }

        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            $this->recordError($code, 'invalid JSON response', $cmd);
            $this->health?->markDown(self::SERVICE);
            return null;
        }

        // A reachable host clears the breaker even on an auth error — the box
        // is up, only the key is wrong.
        $this->health?->clear(self::SERVICE);

        $resp   = is_array($json['response'] ?? null) ? $json['response'] : [];
        $result = $resp['result'] ?? null;
        if ($result !== 'success') {
            $this->recordError($code, 'tautulli result: ' . (is_string($result) ? $result : 'error'), $cmd);
            return ['ok' => false, 'data' => []];
        }

        $this->lastError = null;
        $data = is_array($resp['data'] ?? null) ? $resp['data'] : [];
        return ['ok' => true, 'data' => $data];
    }

    /**
     * Pure transform: Tautulli `get_activity` `data` object → sanitized shape.
     * Public + static so it can be unit-tested against a captured fixture
     * without any network. Only allow-listed fields are copied out — anything
     * sensitive (ip_address[_public], machine_id, *_token, file, …) is dropped
     * by construction because it is never read here.
     *
     * @param array<string, mixed> $data
     * @return array{
     *   streamCount:int, directPlayCount:int, directStreamCount:int, transcodeCount:int,
     *   bandwidth: array{totalKbps:int, lanKbps:int, wanKbps:int, totalMbps:float, lanMbps:float, wanMbps:float},
     *   sessions: list<array<string, mixed>>
     * }
     */
    public static function normalizeActivity(array $data): array
    {
        $sessions = [];
        $rawSessions = $data['sessions'] ?? [];
        if (is_array($rawSessions)) {
            foreach ($rawSessions as $s) {
                if (is_array($s)) {
                    $sessions[] = self::normalizeSession($s);
                }
            }
        }

        $total = (int) ($data['total_bandwidth'] ?? 0);
        $lan   = (int) ($data['lan_bandwidth'] ?? 0);
        $wan   = (int) ($data['wan_bandwidth'] ?? 0);

        return [
            'streamCount'       => (int) ($data['stream_count'] ?? 0),
            'directPlayCount'   => (int) ($data['stream_count_direct_play'] ?? 0),
            'directStreamCount' => (int) ($data['stream_count_direct_stream'] ?? 0),
            'transcodeCount'    => (int) ($data['stream_count_transcode'] ?? 0),
            'bandwidth'         => [
                'totalKbps' => $total,
                'lanKbps'   => $lan,
                'wanKbps'   => $wan,
                'totalMbps' => self::toMbps($total),
                'lanMbps'   => self::toMbps($lan),
                'wanMbps'   => self::toMbps($wan),
            ],
            'sessions'          => $sessions,
        ];
    }

    /**
     * @param array<string, mixed> $s
     * @return array<string, mixed>
     */
    private static function normalizeSession(array $s): array
    {
        $bw = (int) ($s['bandwidth'] ?? 0);

        return [
            'sessionKey'       => self::str($s['session_key'] ?? null),
            'sessionId'        => self::str($s['session_id'] ?? null),
            'ratingKey'        => self::str($s['rating_key'] ?? null),
            'state'            => self::str($s['state'] ?? null),
            // full_title is the human label Tautulli builds ("Show - SxxEyy" /
            // "Movie (year)"); fall back to the bare title if it's missing.
            'title'            => self::str($s['full_title'] ?? ($s['title'] ?? null)),
            'grandparentTitle' => self::str($s['grandparent_title'] ?? null),
            'year'             => self::str($s['year'] ?? null),
            'mediaType'        => self::str($s['media_type'] ?? null),
            // Plex metadata path (e.g. /library/metadata/123/thumb/456) — NOT a
            // server filesystem path. Streamed to the browser via the
            // server-side image proxy (TautulliController::apiImage); the API
            // key is never exposed. pickPoster() chooses the portrait art.
            'posterPath'       => self::pickPoster($s, self::str($s['media_type'] ?? null)),
            // Display name only. We deliberately never expose `username` (the
            // Plex login) or any email/IP.
            'userDisplayName'  => self::str($s['friendly_name'] ?? ($s['user'] ?? null)),
            'product'          => self::str($s['product'] ?? null),
            'player'           => self::str($s['player'] ?? null),
            'device'           => self::str($s['device'] ?? null),
            'platform'         => self::str($s['platform'] ?? null),
            'quality'          => self::str($s['quality_profile'] ?? null),
            'containerDecision'=> self::str($s['container_decision'] ?? null),
            'videoDecision'    => self::str($s['video_decision'] ?? null),
            'audioDecision'    => self::str($s['audio_decision'] ?? null),
            'subtitleDecision' => self::str($s['subtitle_decision'] ?? null),
            // Dynamic range badge (HDR/SDR/Dolby Vision). Prefer the actual
            // stream value over the source so a transcoded-to-SDR stream reads
            // SDR. Display-only label — no sensitive surface.
            'dynamicRange'     => self::str($s['stream_video_dynamic_range'] ?? ($s['video_dynamic_range'] ?? null)),
            // Source vs stream codecs, for the "HEVC → H264" transcode detail.
            'videoCodec'       => self::str($s['video_codec'] ?? null),
            'streamVideoCodec' => self::str($s['stream_video_codec'] ?? null),
            'audioCodec'       => self::str($s['audio_codec'] ?? null),
            'streamAudioCodec' => self::str($s['stream_audio_codec'] ?? null),
            'transcodeDecision'=> self::str($s['transcode_decision'] ?? null),
            'location'         => self::str($s['location'] ?? null),
            'bandwidthKbps'    => $bw,
            'bandwidthMbps'    => self::toMbps($bw),
            'progressPercent'  => self::pct($s['progress_percent'] ?? null),
        ];
    }

    /**
     * @return array{
     *   enabled: bool, configured: bool, connected: bool, error: ?string,
     *   streamCount: int, directPlayCount: int, directStreamCount: int, transcodeCount: int,
     *   bandwidth: array{totalKbps:int, lanKbps:int, wanKbps:int, totalMbps:float, lanMbps:float, wanMbps:float},
     *   sessions: list<array<string, mixed>>
     * }
     */
    private static function emptyShape(bool $enabled, bool $configured): array
    {
        return [
            'enabled'    => $enabled,
            'configured' => $configured,
            'connected'  => false,
            'error'      => null,
            'streamCount'       => 0,
            'directPlayCount'   => 0,
            'directStreamCount' => 0,
            'transcodeCount'    => 0,
            'bandwidth'         => [
                'totalKbps' => 0, 'lanKbps' => 0, 'wanKbps' => 0,
                'totalMbps' => 0.0, 'lanMbps' => 0.0, 'wanMbps' => 0.0,
            ],
            'sessions'          => [],
        ];
    }

    /**
     * Pick the portrait poster path for a session. Episodes (and music tracks)
     * carry a landscape still in `thumb`; the portrait series/album art lives
     * in `grandparent_thumb`, which suits the poster tile. Movies use `thumb`
     * directly (already the portrait poster).
     *
     * @param array<string, mixed> $s
     */
    private static function pickPoster(array $s, ?string $mediaType): ?string
    {
        if ($mediaType === 'episode' || $mediaType === 'track') {
            return self::str($s['grandparent_thumb'] ?? ($s['parent_thumb'] ?? ($s['thumb'] ?? null)));
        }
        return self::str($s['thumb'] ?? ($s['grandparent_thumb'] ?? null));
    }

    /** Tautulli reports bandwidth in kbps; the UI shows Mbps (1 decimal). */
    private static function toMbps(int $kbps): float
    {
        return $kbps > 0 ? round($kbps / 1000, 1) : 0.0;
    }

    /** Coerce a Tautulli scalar to a trimmed string, or null when absent/empty. */
    private static function str(mixed $v): ?string
    {
        if ($v === null || is_array($v)) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    /** progress_percent comes back as a numeric string; clamp to 0-100. */
    private static function pct(mixed $v): float
    {
        if (!is_numeric($v)) {
            return 0.0;
        }
        return max(0.0, min(100.0, round((float) $v, 1)));
    }

    private function recordError(int $code, string $message, string $cmd = 'get_activity'): void
    {
        $this->lastError = [
            'code'    => $code,
            'method'  => 'GET',
            'path'    => '/api/v2?cmd=' . $cmd,
            'message' => $message,
        ];
    }
}
