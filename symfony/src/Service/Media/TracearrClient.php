<?php

namespace App\Service\Media;

use App\Service\ConfigService;
use App\Service\HealthService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Read-only client for the Tracearr public API (Plex / Jellyfin / Emby
 * activity monitoring — a Tautulli alternative).
 *
 * Optional service — Prismarr never talks to the media servers directly; it
 * consumes an existing Tracearr instance's public REST API. Only GET endpoints
 * are used (`/health`, `/stats`, `/stats/today`, `/streams`, `/activity`,
 * `/history`, `/users`, `/media/:id`); `POST /streams/:id/terminate` is never
 * called. Raw
 * responses are reduced to a small, sanitized shape before they leave the
 * server: IPs, user ids, trust/violation payloads and raw codec details are
 * dropped (allow-list, not deny-list).
 *
 * Endpoint: GET {tracearr_url}/api/v2/public/{path} for streams + history
 *           (carries TMDb ids for the quick-look; falls back to v1 on a 404
 *           from older Tracearr), GET {tracearr_url}/api/v1/public/{path} for
 *           the rest.
 * Auth:     Authorization: Bearer trr_pub_<token>
 * Docs:     https://docs.tracearr.com/api (+ /api-docs on the instance)
 *
 * Same flat-config + fail-open stance as TautulliClient: a disabled /
 * unconfigured / unreachable Tracearr yields a neutral shape with an `error`
 * code instead of throwing, so the page never breaks.
 */
class TracearrClient implements ResetInterface
{
    /** Short slug — circuit-breaker key + HealthService service id. */
    public const SERVICE = 'tracearr';

    /** Public API base paths, relative to the configured instance URL. */
    public const API_BASE    = '/api/v1/public';
    public const API_BASE_V2 = '/api/v2/public';

    /** Periods accepted by GET /activity. */
    public const PERIODS = ['week', 'month', 'year'];

    private bool $configLoaded = false;
    private bool $enabled = true;
    private string $baseUrl = '';
    private string $apiKey = '';

    /** @var array{code:int, method:string, path:string, message:string}|null */
    private ?array $lastError = null;

    /** Whether this instance serves the v2 public API (null = not probed yet). */
    private ?bool $v2 = null;

    /** @var array<string, ?int> show media uuid → show TMDb id, per request */
    private array $showTmdbMemo = [];

    public function __construct(
        private readonly ConfigService $config,
        private readonly LoggerInterface $logger,
        private readonly ?ServiceHealthCache $health = null,
        // Long-lived show → TMDb id map (cache.app). Nullable for tests.
        private readonly ?CacheInterface $cache = null,
    ) {}

    public function reset(): void
    {
        $this->configLoaded = false;
        $this->enabled      = true;
        $this->baseUrl      = '';
        $this->apiKey       = '';
        $this->lastError    = null;
        $this->v2           = null;
        $this->showTmdbMemo = [];
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
        // Explicit kill switch (issue #15 pattern): only '0' disables.
        $this->enabled = $this->config->get('tracearr_enabled') !== '0';
        $this->baseUrl = (string) ($this->config->get('tracearr_url') ?? '');
        $this->apiKey  = (string) ($this->config->get('tracearr_api_key') ?? '');
        $this->configLoaded = true;
    }

    private function isUsable(): bool
    {
        $this->ensureConfig();
        return $this->enabled && $this->baseUrl !== '' && $this->apiKey !== '';
    }

    /** Lightweight reachability + key probe for HealthService (GET /health). */
    public function ping(): bool
    {
        if (!$this->isUsable()) {
            return false;
        }
        $resp = $this->request('/health');
        return $resp !== null && $resp['ok'] === true;
    }

    /**
     * Currently active streams, normalized + sanitized. Always returns the
     * full flag/error envelope (same contract as TautulliClient::getActivity).
     *
     * @return array{
     *   enabled: bool, configured: bool, connected: bool, error: ?string,
     *   streamCount: int, directPlayCount: int, directStreamCount: int,
     *   transcodeCount: int, totalBitrate: ?string,
     *   sessions: list<array<string, mixed>>
     * }
     */
    public function getActivity(): array
    {
        $this->ensureConfig();

        $configured = $this->baseUrl !== '' && $this->apiKey !== '';
        $base = self::emptyActivity($this->enabled, $configured);

        if (!$this->enabled) {
            return $base;
        }
        if (!$configured) {
            $base['error'] = 'unconfigured';
            return $base;
        }

        $resp = $this->requestV2('/streams');
        if ($resp === false) {
            $resp = $this->request('/streams');
        }
        if ($resp === null) {
            $base['error'] = 'unreachable';
            return $base;
        }
        if ($resp['ok'] !== true) {
            $base['error'] = 'auth';
            return $base;
        }

        return [
            'enabled'    => true,
            'configured' => true,
            'connected'  => true,
            'error'      => null,
        ] + self::normalizeStreams($resp['data'], $this->showTmdbId(...));
    }

    /**
     * Overview tiles: 30-day totals (GET /stats) + today's figures
     * (GET /stats/today). All-zero shape when disabled/unconfigured/unreachable.
     *
     * @return array<string, int|float>
     */
    public function getStats(): array
    {
        if (!$this->isUsable()) {
            return self::normalizeStats([], []);
        }
        $overview = $this->request('/stats');
        $today    = $this->request('/stats/today', ['timezone' => self::timezone()]);

        return self::normalizeStats(
            $overview !== null && $overview['ok'] ? $overview['data'] : [],
            $today !== null && $today['ok'] ? $today['data'] : [],
        );
    }

    /**
     * Every chart on the activity page, from a single GET /activity call.
     *
     * @return array<string, mixed>
     */
    public function getActivityCharts(string $period): array
    {
        $period = self::clampPeriod($period);
        if (!$this->isUsable()) {
            return self::normalizeActivityCharts([]);
        }
        $resp = $this->request('/activity', ['period' => $period, 'timezone' => self::timezone()]);
        if ($resp === null || $resp['ok'] !== true) {
            return self::normalizeActivityCharts([]);
        }
        return self::normalizeActivityCharts($resp['data']);
    }

    /**
     * One page of watch history (one row per play), newest first. `cursor` is
     * the opaque `nextCursor` of the previous page (v2), or a `p<N>` page token
     * minted here when this Tracearr only speaks v1. Empty rows + null cursor
     * cover disabled/unconfigured/unreachable.
     *
     * @return array{rows: list<array<string, mixed>>, nextCursor: ?string}
     */
    public function getHistory(?string $cursor = null, int $pageSize = 24): array
    {
        $empty = ['rows' => [], 'nextCursor' => null];
        if (!$this->isUsable() || ($cursor !== null && !self::isCursor($cursor))) {
            return $empty;
        }
        $pageSize = max(1, min(100, $pageSize));

        $page = $cursor !== null && preg_match('/^p(\d{1,6})$/', $cursor, $m) === 1 ? max(1, (int) $m[1]) : null;
        if ($page === null) {
            $query = ['pageSize' => (string) $pageSize] + ($cursor !== null ? ['cursor' => $cursor] : []);
            $resp  = $this->requestV2('/history', $query);
            if ($resp !== false) {
                if ($resp === null || $resp['ok'] !== true) {
                    return $empty;
                }
                $meta = is_array($resp['data']['meta'] ?? null) ? $resp['data']['meta'] : [];
                $next = self::str($meta['nextCursor'] ?? null);
                return [
                    'rows'       => self::normalizeHistory($resp['data'], $this->showTmdbId(...)),
                    'nextCursor' => $next !== null && self::isCursor($next) ? $next : null,
                ];
            }
            $page = 1;
        }

        // v1 fallback — page-numbered.
        $resp = $this->request('/history', ['page' => (string) $page, 'pageSize' => (string) $pageSize]);
        if ($resp === null || $resp['ok'] !== true) {
            return $empty;
        }
        $total = (int) ($resp['data']['meta']['total'] ?? 0);
        return [
            'rows'       => self::normalizeHistory($resp['data']),
            'nextCursor' => $page * $pageSize < $total ? 'p' . ($page + 1) : null,
        ];
    }

    /**
     * TMDb id of a show, from its Tracearr media uuid (GET v2 /media/{uuid}).
     * Episodes only carry their own episode-level TMDb id, which the
     * quick-look can't render. Cached a day (5 min on a miss) — a show's TMDb
     * id never changes.
     */
    public function showTmdbId(string $mediaId): ?int
    {
        if (!self::isUuid($mediaId) || !$this->isUsable()) {
            return null;
        }
        if (array_key_exists($mediaId, $this->showTmdbMemo)) {
            return $this->showTmdbMemo[$mediaId];
        }

        $fetch = function () use ($mediaId): ?int {
            $resp = $this->request('/media/' . $mediaId, [], self::API_BASE_V2);
            if ($resp === null || $resp['ok'] !== true || ($resp['data']['media_type'] ?? null) !== 'show') {
                return null;
            }
            $id = $resp['data']['tmdb_id'] ?? null;
            return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
        };
        $id = $this->cache === null ? $fetch() : $this->cache->get(
            'tracearr.show_tmdb.' . $mediaId,
            function (ItemInterface $item) use ($fetch): ?int {
                $id = $fetch();
                $item->expiresAfter($id === null ? 300 : 86400);
                return $id;
            },
        );

        return $this->showTmdbMemo[$mediaId] = $id;
    }

    /**
     * User/server pairs ordered by last activity. Plain list (first 100).
     *
     * @return list<array<string, mixed>>
     */
    public function getUsers(): array
    {
        if (!$this->isUsable()) {
            return [];
        }
        $resp = $this->request('/users', ['page' => '1', 'pageSize' => '100']);
        if ($resp === null || $resp['ok'] !== true) {
            return [];
        }

        // Tracearr often leaves lastActivityAt null; derive it from the 100
        // most recent plays instead (users absent from those stay unknown).
        $lastSeen = [];
        foreach (self::rows($resp['data']['data'] ?? null) as $u) {
            if (self::str($u['lastActivityAt'] ?? null) === null) {
                $history  = $this->request('/history', ['page' => '1', 'pageSize' => '100']);
                $lastSeen = $history !== null && $history['ok'] ? self::lastSeenByUser($history['data']) : [];
                break;
            }
        }
        return self::normalizeUsers($resp['data'], $lastSeen);
    }

    /**
     * Fetch a poster through Tracearr's image proxy (GET /api/v1/images/proxy),
     * which resolves the media-server path against the pinned server origin.
     * Both inputs are allow-listed first so this can't be used as an open relay.
     *
     * @return array{body: string, contentType: string}|null
     */
    public function fetchImage(string $serverId, string $img, int $width = 300, int $height = 450): ?array
    {
        if (!$this->isUsable()) {
            return null;
        }
        if (!self::isUuid($serverId) || !self::isProxyableImagePath($img)) {
            return null;
        }
        if ($this->health?->isDown(self::SERVICE)) {
            return null;
        }

        $endpoint = rtrim($this->baseUrl, '/') . '/api/v1/images/proxy';
        if (HealthService::urlBlockedReason($endpoint) !== null) {
            return null;
        }

        $url = $endpoint . '?' . http_build_query([
            'server'   => $serverId,
            'url'      => $img,
            'width'    => (string) $width,
            'height'   => (string) $height,
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
            CURLOPT_HTTPHEADER      => ['Accept: image/*'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $err !== '' || $code === 0) {
            $this->health?->markDown(self::SERVICE);
            return null;
        }
        if ($code !== 200) {
            return null;
        }

        // Only ever hand back a genuine image — never an error envelope or HTML.
        $contentType = strtolower(trim(explode(';', $type)[0]));
        if (!str_starts_with($contentType, 'image/') || !is_string($body) || $body === '') {
            return null;
        }

        return ['body' => $body, 'contentType' => $contentType];
    }

    /**
     * True only for media-server image paths: Plex (/library/metadata/1/thumb/2)
     * and Jellyfin/Emby (/Items/{id}/Images/Primary, optional ?tag=). Rejects
     * absolute URLs, protocol-relative refs, traversal, backslashes and any
     * other query string. Public + static for testing.
     */
    public static function isProxyableImagePath(string $img): bool
    {
        if ($img === '' || strlen($img) > 512) {
            return false;
        }
        if (str_contains($img, '..') || str_contains($img, '\\') || str_starts_with($img, '//')) {
            return false;
        }
        return preg_match('#^/[A-Za-z0-9/_.\-]+(\?tag=[A-Za-z0-9]+)?$#', $img) === 1;
    }

    public static function isUuid(string $v): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) === 1;
    }

    public static function clampPeriod(string $period): string
    {
        return in_array($period, self::PERIODS, true) ? $period : 'month';
    }

    /**
     * Pure transform: GET /streams payload (v1 camelCase or v2 snake_case)
     * → sanitized activity shape. `$showTmdb` maps a show media uuid to its
     * TMDb id for the episode quick-look (null = no lookup).
     *
     * @param array<string, mixed> $data
     * @param (callable(string): ?int)|null $showTmdb
     * @return array{
     *   streamCount:int, directPlayCount:int, directStreamCount:int, transcodeCount:int,
     *   totalBitrate: ?string, sessions: list<array<string, mixed>>
     * }
     */
    public static function normalizeStreams(array $data, ?callable $showTmdb = null): array
    {
        $sessions = [];
        foreach (self::rows($data['data'] ?? null) as $s) {
            $sessions[] = self::normalizeStream(self::camelize($s), $showTmdb);
        }
        $summary = self::camelize(is_array($data['summary'] ?? null) ? $data['summary'] : []);

        return [
            'streamCount'       => (int) ($summary['total'] ?? count($sessions)),
            'directPlayCount'   => (int) ($summary['directPlays'] ?? 0),
            'directStreamCount' => (int) ($summary['directStreams'] ?? 0),
            'transcodeCount'    => (int) ($summary['transcodes'] ?? 0),
            // Tracearr pre-formats this ("12.3 Mbps").
            'totalBitrate'      => self::str($summary['totalBitrate'] ?? null),
            'sessions'          => $sessions,
        ];
    }

    /**
     * One stream, mapped onto the same keys TautulliClient uses so the shared
     * dashboard/_plex_session_card.html.twig renders it unchanged.
     *
     * @param array<string, mixed> $s
     * @param (callable(string): ?int)|null $showTmdb
     * @return array<string, mixed>
     */
    private static function normalizeStream(array $s, ?callable $showTmdb = null): array
    {
        $isTranscode = ($s['isTranscode'] ?? false) === true;
        $video = self::str($s['videoDecision'] ?? null);
        $audio = self::str($s['audioDecision'] ?? null);
        $decision = match (true) {
            $isTranscode                       => 'transcode',
            $video === 'copy' || $audio === 'copy' => 'copy',
            default                            => 'direct play',
        };
        $bitrate  = (int) ($s['bitrate'] ?? 0);
        $duration = (int) ($s['durationMs'] ?? 0);
        $progress = (int) ($s['progressMs'] ?? 0);
        $sourceVideo = is_array($s['sourceVideoDetails'] ?? null) ? $s['sourceVideoDetails'] : [];
        // Plex desktop clients report the same label as product and device
        // ("Plex for Mac" twice); keep it once on the card meta line.
        $product = self::str($s['product'] ?? null);
        $device  = self::str($s['device'] ?? null);
        if ($device !== null && $device === $product) {
            $device = null;
        }

        return [
            // No ratingKey: the Plex info modal stays off (it goes through
            // Tautulli). The app-global TMDb quick-look opens instead.
            'ratingKey'        => null,
            'quickLook'        => self::quickLook($s, $showTmdb),
            // plex | jellyfin | emby (v2 only) — source badge on the card.
            'serverType'       => self::str($s['serverType'] ?? null),
            'state'            => self::str($s['state'] ?? null),
            'title'            => self::fullTitle($s),
            'year'             => self::str($s['year'] ?? null),
            'mediaType'        => self::str($s['mediaType'] ?? null),
            // Media-server image path + owning server, streamed through
            // TracearrController::apiImage — never the raw Tracearr URL.
            'posterPath'       => self::str($s['thumbPath'] ?? null),
            'posterServerId'   => self::str($s['serverId'] ?? null),
            'serverName'       => self::str($s['serverName'] ?? null),
            // Display name only — no user id / avatar URL / IP.
            'userDisplayName'  => self::str($s['username'] ?? null),
            'product'          => $product,
            'player'           => self::str($s['player'] ?? null),
            'device'           => $device,
            'platform'         => self::str($s['platform'] ?? null),
            'quality'          => self::str($s['resolution'] ?? null),
            'videoDecision'    => $video,
            'audioDecision'    => $audio,
            'subtitleDecision' => null,
            'dynamicRange'     => self::str($sourceVideo['dynamicRange'] ?? null),
            'videoCodec'       => self::str($s['sourceVideoCodecDisplay'] ?? ($s['sourceVideoCodec'] ?? null)),
            'streamVideoCodec' => self::str($s['streamVideoCodecDisplay'] ?? ($s['streamVideoCodec'] ?? null)),
            'audioCodec'       => self::str($s['sourceAudioCodecDisplay'] ?? ($s['sourceAudioCodec'] ?? null)),
            'streamAudioCodec' => self::str($s['streamAudioCodecDisplay'] ?? ($s['streamAudioCodec'] ?? null)),
            'transcodeDecision'=> $decision,
            'location'         => null,
            'bandwidthKbps'    => $bitrate,
            'bandwidthMbps'    => $bitrate > 0 ? round($bitrate / 1000, 1) : 0.0,
            'progressPercent'  => $duration > 0 ? max(0.0, min(100.0, round($progress / $duration * 100, 1))) : 0.0,
        ];
    }

    /**
     * @param array<string, mixed> $overview GET /stats payload
     * @param array<string, mixed> $today    GET /stats/today payload
     * @return array<string, int|float>
     */
    public static function normalizeStats(array $overview, array $today): array
    {
        return [
            'activeStreams'    => (int) ($overview['activeStreams'] ?? ($today['activeStreams'] ?? 0)),
            'totalUsers'       => (int) ($overview['totalUsers'] ?? 0),
            'plays30d'         => (int) ($overview['totalSessions'] ?? 0),
            'violations7d'     => (int) ($overview['recentViolations'] ?? 0),
            'todayPlays'       => (int) ($today['todayPlays'] ?? 0),
            'watchTimeHours'   => round((float) ($today['watchTimeHours'] ?? 0), 1),
            'activeUsersToday' => (int) ($today['activeUsersToday'] ?? 0),
            'alertsLast24h'    => (int) ($today['alertsLast24h'] ?? 0),
        ];
    }

    /**
     * Pure transform: GET /activity payload → Chart.js-ready
     * {categories, series:[{name, data}]} blocks plus the quality split.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function normalizeActivityCharts(array $data): array
    {
        $empty = ['categories' => [], 'series' => []];

        // Plays over time come back per (bucket, server) — sum the servers.
        // Keyed and sorted by timestamp: the labels mix two formats (day vs
        // hour buckets), so sorting on them would misplace midnight points.
        $byTs = [];
        foreach (self::rows($data['plays'] ?? null) as $r) {
            $ts = self::bucketTimestamp(self::str($r['date'] ?? null));
            if ($ts !== null) {
                $byTs[$ts] = ($byTs[$ts] ?? 0) + (int) ($r['count'] ?? 0);
            }
        }
        ksort($byTs);
        $plays = [];
        foreach ($byTs as $ts => $count) {
            $plays[self::formatBucket($ts)] = $count;
        }

        $concurrent = ['categories' => [], 'direct' => [], 'directStream' => [], 'transcode' => []];
        foreach (self::rows($data['concurrent'] ?? null) as $r) {
            $date = self::bucketLabel(self::str($r['date'] ?? null));
            if ($date === null) {
                continue;
            }
            $concurrent['categories'][]   = $date;
            $concurrent['direct'][]       = (int) ($r['direct'] ?? 0);
            $concurrent['directStream'][] = (int) ($r['directStream'] ?? 0);
            $concurrent['transcode'][]    = (int) ($r['transcode'] ?? 0);
        }

        $hour = ['categories' => [], 'data' => []];
        foreach (self::rows($data['byHourOfDay'] ?? null) as $r) {
            $hour['categories'][] = sprintf('%02d', (int) ($r['hour'] ?? 0));
            $hour['data'][]       = (int) ($r['count'] ?? 0);
        }

        $dow = ['categories' => [], 'data' => []];
        foreach (self::rows($data['byDayOfWeek'] ?? null) as $r) {
            $dow['categories'][] = self::str($r['name'] ?? null) ?? (string) ($r['day'] ?? '');
            $dow['data'][]       = (int) ($r['count'] ?? 0);
        }

        $platforms = ['categories' => [], 'data' => []];
        foreach (array_slice(self::rows($data['platforms'] ?? null), 0, 10) as $r) {
            $platforms['categories'][] = self::str($r['platform'] ?? null) ?? '—';
            $platforms['data'][]       = (int) ($r['count'] ?? 0);
        }

        $q = is_array($data['quality'] ?? null) ? $data['quality'] : [];

        return [
            'plays' => $plays === [] ? $empty : [
                'categories' => array_keys($plays),
                'series'     => [['name' => 'Plays', 'data' => array_values($plays)]],
            ],
            'concurrent' => $concurrent['categories'] === [] ? $empty : [
                'categories' => $concurrent['categories'],
                'series'     => [
                    ['name' => 'Direct Play',   'data' => $concurrent['direct']],
                    ['name' => 'Direct Stream', 'data' => $concurrent['directStream']],
                    ['name' => 'Transcode',     'data' => $concurrent['transcode']],
                ],
            ],
            'hour'      => array_sum($hour['data']) === 0 ? $empty : [
                'categories' => $hour['categories'],
                'series'     => [['name' => 'Plays', 'data' => $hour['data']]],
            ],
            'dow'       => array_sum($dow['data']) === 0 ? $empty : [
                'categories' => $dow['categories'],
                'series'     => [['name' => 'Plays', 'data' => $dow['data']]],
            ],
            'platforms' => $platforms['categories'] === [] ? $empty : [
                'categories' => $platforms['categories'],
                'series'     => [['name' => 'Plays', 'data' => $platforms['data']]],
            ],
            'quality'   => [
                'directPlay'          => (int) ($q['directPlay'] ?? 0),
                'directStream'        => (int) ($q['directStream'] ?? 0),
                'transcode'           => (int) ($q['transcode'] ?? 0),
                'total'               => (int) ($q['total'] ?? 0),
                'directPlayPercent'   => (int) ($q['directPlayPercent'] ?? 0),
                'directStreamPercent' => (int) ($q['directStreamPercent'] ?? 0),
                'transcodePercent'    => (int) ($q['transcodePercent'] ?? 0),
            ],
        ];
    }

    /**
     * Pure transform: GET /history payload (v1 or v2) → sanitized rows.
     *
     * @param array<string, mixed> $data
     * @param (callable(string): ?int)|null $showTmdb
     * @return list<array<string, mixed>>
     */
    public static function normalizeHistory(array $data, ?callable $showTmdb = null): array
    {
        $out = [];
        foreach (self::rows($data['data'] ?? null) as $r) {
            $r        = self::camelize($r);
            $total    = (int) ($r['totalDurationMs'] ?? 0);
            $progress = (int) ($r['progressMs'] ?? 0);
            $user     = is_array($r['user'] ?? null) ? $r['user'] : [];
            $started  = self::str($r['startedAt'] ?? null);
            $out[] = [
                // Shared dashboard/_plex_history_row keys: no Plex rating key
                // (no info modal), and the show name already lives in `title`.
                'ratingKey'        => null,
                'grandparentTitle' => null,
                'quickLook'        => self::quickLook($r, $showTmdb),
                'title'           => self::fullTitle($r),
                'year'            => self::str($r['year'] ?? null),
                'mediaType'       => self::str($r['mediaType'] ?? null),
                'posterPath'      => self::str($r['thumbPath'] ?? null),
                'posterServerId'  => self::str($r['serverId'] ?? null),
                'serverName'      => self::str($r['serverName'] ?? null),
                'userDisplayName' => self::str($user['username'] ?? null),
                'platform'        => self::str($r['platform'] ?? null),
                'watchedAt'       => $started !== null ? (int) (strtotime($started) ?: 0) : 0,
                'durationMs'      => (int) ($r['durationMs'] ?? 0),
                'watched'         => ($r['watched'] ?? false) === true,
                'percentComplete' => $total > 0 ? max(0.0, min(100.0, round($progress / $total * 100, 1))) : 0.0,
            ];
        }
        return $out;
    }

    /**
     * Pure transform: GET /users paginated payload → sanitized rows.
     * `$lastSeenByUser` (user id → epoch, see lastSeenByUser()) fills in a
     * missing lastActivityAt. The user id itself is never output.
     *
     * @param array<string, mixed> $data
     * @param array<string, int> $lastSeenByUser
     * @return list<array<string, mixed>>
     */
    public static function normalizeUsers(array $data, array $lastSeenByUser = []): array
    {
        $out = [];
        foreach (self::rows($data['data'] ?? null) as $r) {
            $last = self::str($r['lastActivityAt'] ?? null);
            $seen = $last !== null ? (int) (strtotime($last) ?: 0) : ($lastSeenByUser[(string) ($r['id'] ?? '')] ?? 0);
            $out[] = [
                'displayName' => self::str($r['displayName'] ?? ($r['username'] ?? null)),
                'serverName'  => self::str($r['serverName'] ?? null),
                'lastSeen'    => $seen,
                'plays'       => (int) ($r['sessionCount'] ?? 0),
                'trustScore'  => isset($r['trustScore']) && is_numeric($r['trustScore']) ? (int) $r['trustScore'] : null,
            ];
        }
        return $out;
    }

    /**
     * Most recent play start per user id, from a GET v1 /history payload.
     *
     * @param array<string, mixed> $data
     * @return array<string, int>
     */
    public static function lastSeenByUser(array $data): array
    {
        $out = [];
        foreach (self::rows($data['data'] ?? null) as $r) {
            $user = is_array($r['user'] ?? null) ? $r['user'] : [];
            $id   = self::str($user['id'] ?? null);
            $ts   = strtotime((string) ($r['startedAt'] ?? ($r['started_at'] ?? ''))) ?: 0;
            if ($id !== null && $ts > ($out[$id] ?? 0)) {
                $out[$id] = $ts;
            }
        }
        return $out;
    }

    /**
     * Quick-look target {type, id} for the app-global TMDb modal: movies use
     * their own TMDb id; episodes resolve the show's (an episode-level TMDb id
     * can't be rendered). Null when unknown — the tile then stays inert.
     *
     * @param array<string, mixed> $r camelized row
     * @param (callable(string): ?int)|null $showTmdb
     * @return array{type: string, id: int}|null
     */
    private static function quickLook(array $r, ?callable $showTmdb): ?array
    {
        $type = self::str($r['mediaType'] ?? null);
        if ($type === 'movie') {
            $id = $r['tmdbId'] ?? null;
            return is_numeric($id) && (int) $id > 0 ? ['type' => 'movie', 'id' => (int) $id] : null;
        }
        if ($type === 'episode' && $showTmdb !== null) {
            $show = self::str($r['showMediaId'] ?? null);
            $id   = $show !== null && self::isUuid($show) ? $showTmdb($show) : null;
            return $id !== null ? ['type' => 'tv', 'id' => $id] : null;
        }
        return null;
    }

    /**
     * v2 → v1 key shape: snake_case top-level keys become camelCase so one
     * set of normalizers serves both API versions (camelCase keys pass
     * through untouched).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function camelize(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $key = is_string($key) ? lcfirst(str_replace('_', '', ucwords($key, '_'))) : $key;
            $out[$key] = $value;
        }
        return $out;
    }

    /** True for a v2 history cursor (base64url) or a v1 `p<N>` page token. */
    public static function isCursor(string $cursor): bool
    {
        return preg_match('/^(p\d{1,6}|[A-Za-z0-9_\-]{1,512})$/', $cursor) === 1;
    }

    /**
     * Human label: "Show — S01E02 · Episode", "Artist — Track", or the bare
     * title (+ nothing) for movies.
     *
     * @param array<string, mixed> $r
     */
    private static function fullTitle(array $r): ?string
    {
        $title = self::str($r['mediaTitle'] ?? null);
        $type  = self::str($r['mediaType'] ?? null);
        if ($type === 'episode' && ($show = self::str($r['showTitle'] ?? null)) !== null) {
            $code = '';
            if (is_numeric($r['seasonNumber'] ?? null) && is_numeric($r['episodeNumber'] ?? null)) {
                $code = sprintf('S%02dE%02d', (int) $r['seasonNumber'], (int) $r['episodeNumber']);
            }
            return trim($show . ' — ' . trim($code . ($title !== null ? ' · ' . $title : ''), ' ·'), ' —');
        }
        if ($type === 'track' && ($artist = self::str($r['artistName'] ?? null)) !== null) {
            return $artist . ($title !== null ? ' — ' . $title : '');
        }
        return $title;
    }

    /** "2026-09-30 06:00:00" (time_bucket text) → "2026-09-30" or "09-30 06h". */
    private static function bucketLabel(?string $raw): ?string
    {
        $ts = self::bucketTimestamp($raw);
        return $ts !== null ? self::formatBucket($ts) : null;
    }

    private static function bucketTimestamp(?string $raw): ?int
    {
        if ($raw === null) {
            return null;
        }
        $ts = strtotime($raw);
        return $ts === false ? null : $ts;
    }

    private static function formatBucket(int $ts): string
    {
        return date('H:i', $ts) === '00:00' ? date('Y-m-d', $ts) : date('m-d H\h', $ts);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }
        return array_values(array_filter($v, 'is_array'));
    }

    /** IANA timezone Tracearr buckets dates in — the container's TZ. */
    private static function timezone(): string
    {
        $tz = date_default_timezone_get();
        return $tz !== '' ? $tz : 'UTC';
    }

    /**
     * @return array{
     *   enabled: bool, configured: bool, connected: bool, error: ?string,
     *   streamCount: int, directPlayCount: int, directStreamCount: int,
     *   transcodeCount: int, totalBitrate: ?string, sessions: list<array<string, mixed>>
     * }
     */
    private static function emptyActivity(bool $enabled, bool $configured): array
    {
        return [
            'enabled'           => $enabled,
            'configured'        => $configured,
            'connected'         => false,
            'error'             => null,
            'streamCount'       => 0,
            'directPlayCount'   => 0,
            'directStreamCount' => 0,
            'transcodeCount'    => 0,
            'totalBitrate'      => null,
            'sessions'          => [],
        ];
    }

    /** Coerce a scalar to a trimmed string, or null when absent/empty. */
    private static function str(mixed $v): ?string
    {
        if ($v === null || is_array($v) || is_bool($v)) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    /**
     * Issue a GET against the public API. Returns {ok, data} (ok=false on
     * 401/403 = bad key), or null when unreachable / non-JSON / other HTTP
     * error. Honors + feeds the cross-request circuit breaker.
     *
     * @param array<string, string> $query
     * @return array{ok: bool, data: array<string, mixed>}|null
     */
    private function request(string $path, array $query = [], string $apiBase = self::API_BASE): ?array
    {
        if ($this->health?->isDown(self::SERVICE)) {
            return null;
        }

        // SSRF guard #1 — shared validator (non-http(s) schemes, link-local
        // and cloud-metadata IPs) before opening a socket.
        $endpoint = rtrim($this->baseUrl, '/') . $apiBase . $path;
        if (($reason = HealthService::urlBlockedReason($endpoint)) !== null) {
            $this->recordError(0, 'blocked: ' . $reason, $apiBase . $path);
            $this->logger->warning('Tracearr URL blocked', ['reason' => $reason]);
            return null;
        }
        $url = $query === [] ? $endpoint : $endpoint . '?' . http_build_query($query);

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_CONNECTTIMEOUT  => 3,
            CURLOPT_TIMEOUT         => 8,
            CURLOPT_NOSIGNAL        => true, // critical under FrankenPHP/Alpine
            CURLOPT_FOLLOWLOCATION  => false,
            // SSRF guard #2 — lock the protocol even across any redirect.
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_HTTPHEADER      => [
                'Accept: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $err !== '' || $code === 0) {
            $this->recordError($code, $err !== '' ? $err : 'connection failed', $apiBase . $path);
            $this->health?->markDown(self::SERVICE);
            return null;
        }

        // The host answered — clear the breaker even on an auth error.
        $this->health?->clear(self::SERVICE);

        if ($code === 401 || $code === 403) {
            $this->recordError($code, 'unauthorized', $apiBase . $path);
            return ['ok' => false, 'data' => []];
        }
        if ($code < 200 || $code >= 300) {
            $this->recordError($code, 'http ' . $code, $apiBase . $path);
            return null;
        }

        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            $this->recordError($code, 'invalid JSON response', $apiBase . $path);
            return null;
        }

        $this->lastError = null;
        return ['ok' => true, 'data' => $json];
    }

    /**
     * GET against the v2 public API, for the endpoints that have a v1 twin.
     * Returns false (not null) when this Tracearr predates v2 — a 404 on the
     * route — so the caller falls back to v1; remembered per request.
     *
     * @param array<string, string> $query
     * @return array{ok: bool, data: array<string, mixed>}|null|false
     */
    private function requestV2(string $path, array $query = []): array|null|false
    {
        if ($this->v2 === false) {
            return false;
        }
        $resp = $this->request($path, $query, self::API_BASE_V2);
        if ($resp === null && ($this->lastError['code'] ?? 0) === 404) {
            $this->v2 = false;
            return false;
        }
        if ($resp !== null) {
            $this->v2 = true;
        }
        return $resp;
    }

    private function recordError(int $code, string $message, string $path): void
    {
        $this->lastError = [
            'code'    => $code,
            'method'  => 'GET',
            'path'    => $path,
            'message' => $message,
        ];
    }
}
