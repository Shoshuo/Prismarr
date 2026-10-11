<?php

namespace App\Service\Media;

use App\Service\ConfigService;
use App\Service\HealthService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Read-only client for the Emby Server REST API (current playback activity).
 *
 * Optional service — Prismarr talks to Emby directly (Emby has no Tautulli
 * equivalent). Only read-only endpoints are used: `GET /Sessions`,
 * `GET /System/Info`, `GET /Items` and the item image endpoint; nothing that
 * mutates state or controls playback. The raw session payload is reduced to
 * the same sanitized shape TautulliClient::getActivity() produces, so the
 * dashboard summary strip and the session card can be shared between the two
 * media servers. IP addresses, device ids, access tokens, file paths and the
 * raw payload never leave the server (allow-list, not deny-list).
 *
 * Endpoint: GET {emby_url}/emby/Sessions  (header X-Emby-Token: {key})
 * Docs: https://dev.emby.media/reference/RestAPI.html
 *
 * Like the other flat-config clients (Tautulli, Gluetun, qBittorrent), config
 * is read lazily from the `setting` table via ConfigService and the client
 * fails open: a disabled / unconfigured / unreachable Emby yields a neutral
 * shape with an `error` code instead of throwing, so the dashboard never
 * breaks.
 */
class EmbyClient implements ResetInterface
{
    /** Short slug — circuit-breaker key + HealthService service id. */
    public const SERVICE = 'emby';

    /** Emby reports playback positions/durations in 100 ns ticks. */
    private const TICKS_PER_SECOND = 10_000_000;

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
        $this->enabled = $this->config->get('emby_enabled') !== '0';
        $this->baseUrl = (string) ($this->config->get('emby_url') ?? '');
        $this->apiKey  = (string) ($this->config->get('emby_api_key') ?? '');
        $this->configLoaded = true;
    }

    private function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    /**
     * Lightweight reachability probe for HealthService. True when
     * `GET /System/Info` answers with a JSON body (a bad key is a 401, which
     * request() reports as an auth failure, not as "up").
     */
    public function ping(): bool
    {
        $this->ensureConfig();
        if (!$this->enabled || !$this->isConfigured()) {
            return false;
        }
        $resp = $this->request('/System/Info');
        return $resp !== null && $resp['ok'] === true;
    }

    /**
     * Current Emby playback activity, normalized + sanitized for the frontend.
     *
     * Same envelope as TautulliClient::getActivity(): `enabled`, `configured`,
     * `connected` flags plus an `error` code (null | 'unconfigured' |
     * 'unreachable' | 'auth') so the widget can render the right empty/error
     * state without ever seeing a stack trace or a secret.
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

        $configured = $this->isConfigured();
        $base = self::emptyShape($this->enabled, $configured);

        if (!$this->enabled) {
            return $base; // error stays null — the widget is hidden upstream anyway
        }
        if (!$configured) {
            $base['error'] = 'unconfigured';
            return $base;
        }

        $resp = $this->request('/Sessions');
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
        ] + self::normalizeSessions($resp['data']);
    }

    /**
     * Resolve an Emby item id to the TMDb {type, id} the global quick-look
     * modal opens with. Movies carry their own TMDb provider id; episodes and
     * seasons resolve through their series (an episode-level TMDb id would
     * identify the episode, which the quick-look can't render). Fails open to
     * null (disabled / unconfigured / unreachable / no TMDb id).
     *
     * @return ?array{type: string, id: int}
     */
    public function resolveTmdbId(string $itemId): ?array
    {
        $this->ensureConfig();
        if (!$this->enabled || !$this->isConfigured() || !self::isItemId($itemId)) {
            return null;
        }

        $item = $this->fetchItem($itemId);
        if ($item === null) {
            return null;
        }
        if (($found = self::tmdbIdFromItem($item)) !== null) {
            return $found;
        }

        $seriesId = self::str($item['SeriesId'] ?? null);
        if ($seriesId === null || !self::isItemId($seriesId)) {
            return null;
        }
        $series = $this->fetchItem($seriesId);
        return $series === null ? null : self::tmdbIdFromItem($series);
    }

    /**
     * Map an Emby item to the {type, id} pair the quick-look consumes, or null
     * when the item has no TMDb provider id of its own. Movies → 'movie';
     * series → 'tv'; episodes/seasons return null here (the caller hops to
     * the series). Only the numeric TMDb id survives; the rest of ProviderIds
     * stays server-side.
     *
     * @param array<string, mixed> $item
     * @return ?array{type: string, id: int}
     */
    public static function tmdbIdFromItem(array $item): ?array
    {
        $type = match (self::str($item['Type'] ?? null)) {
            'Movie'  => 'movie',
            'Series' => 'tv',
            default  => null,
        };
        if ($type === null) {
            return null;
        }
        $providers = is_array($item['ProviderIds'] ?? null) ? $item['ProviderIds'] : [];
        foreach ($providers as $name => $value) {
            if (is_string($name) && strtolower($name) === 'tmdb' && is_scalar($value) && ctype_digit((string) $value)) {
                return ['type' => $type, 'id' => (int) $value];
            }
        }
        return null;
    }

    /**
     * Server-side fetch of an item's primary image (poster). The API key never
     * leaves the server — the browser only ever receives the image bytes
     * (streamed by EmbyController::apiImage). Returns null when Emby is
     * disabled / unconfigured / unreachable, the id isn't an Emby item id, the
     * item has no primary image (Emby answers 404) or the response wasn't an
     * image.
     *
     * @return array{body: string, contentType: string}|null
     */
    public function fetchImage(string $itemId, int $width = 300, int $height = 450): ?array
    {
        $this->ensureConfig();
        if (!$this->enabled || !$this->isConfigured()) {
            return null;
        }
        // Allow-list the id before opening any socket — never proxy an
        // arbitrary path through our authenticated server.
        if (!self::isItemId($itemId)) {
            return null;
        }
        if ($this->health?->isDown(self::SERVICE)) {
            return null;
        }

        $url = $this->endpoint('/Items/' . $itemId . '/Images/Primary');
        if ($url === null) {
            return null;
        }
        $url .= '?' . http_build_query(['maxWidth' => $width, 'maxHeight' => $height, 'quality' => 90]);

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
            CURLOPT_HTTPHEADER      => ['X-Emby-Token: ' . $this->apiKey],
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
        $this->health?->clear(self::SERVICE);
        if ($code !== 200) {
            return null; // 404 = item has no poster; 401 = key revoked
        }

        // Only ever hand back a genuine image — never an error envelope or HTML.
        $contentType = strtolower(trim(explode(';', $type)[0]));
        if (!str_starts_with($contentType, 'image/') || !is_string($body) || $body === '') {
            return null;
        }

        return ['body' => $body, 'contentType' => $contentType];
    }

    /**
     * True only for Emby item ids: digits (Emby's own ids) or a 32-char hex
     * GUID (Emby's ids for users/servers, and Jellyfin-style item ids). Rejects
     * anything that could steer the image proxy at another path (SSRF /
     * open-relay guard). Public + static for testing.
     */
    public static function isItemId(string $id): bool
    {
        return preg_match('/^(?:[0-9]{1,20}|[0-9a-fA-F]{32})$/', $id) === 1;
    }

    /**
     * One item with its provider ids, or null. Uses `GET /Items?Ids=` rather
     * than `/Users/{id}/Items/{id}` so no user context is needed.
     *
     * @return array<string, mixed>|null
     */
    private function fetchItem(string $itemId): ?array
    {
        $resp = $this->request('/Items', ['Ids' => $itemId, 'Fields' => 'ProviderIds']);
        if ($resp === null || $resp['ok'] !== true) {
            return null;
        }
        $items = is_array($resp['data']['Items'] ?? null) ? $resp['data']['Items'] : [];
        $first = $items[0] ?? null;
        return is_array($first) ? $first : null;
    }

    /** Full API URL for a path, or null when the configured base URL is blocked (SSRF guard #1). */
    private function endpoint(string $path): ?string
    {
        // Accept a base URL with or without the /emby prefix: both
        // "http://host:8096" and "http://host:8096/emby" are common.
        $base = rtrim($this->baseUrl, '/');
        if (!str_ends_with(strtolower($base), '/emby')) {
            $base .= '/emby';
        }
        $url = $base . $path;
        if (($reason = HealthService::urlBlockedReason($url)) !== null) {
            $this->recordError(0, 'blocked: ' . $reason, $path);
            $this->logger->warning('Emby URL blocked', ['reason' => $reason]);
            return null;
        }
        return $url;
    }

    /**
     * Issue an Emby API call. Returns the decoded JSON split into a tiny
     * result tuple, or null when the host is unreachable / the response isn't
     * valid JSON. `ok` is false on 401/403 (bad or revoked API key). Honors +
     * feeds the cross-request circuit breaker so a downed Emby doesn't cost a
     * connect timeout on every 10 s widget poll.
     *
     * @param array<string, string> $query
     * @return array{ok: bool, data: array<string, mixed>}|null
     */
    private function request(string $path, array $query = []): ?array
    {
        if ($this->health?->isDown(self::SERVICE)) {
            return null;
        }

        $url = $this->endpoint($path);
        if ($url === null) {
            return null;
        }
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

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
            // The key goes in a header, never in the URL, so it can't end up
            // in Emby's access log or a proxy log.
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'X-Emby-Token: ' . $this->apiKey],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $err !== '' || $code === 0) {
            $this->recordError($code, $err !== '' ? $err : 'connection failed', $path);
            $this->health?->markDown(self::SERVICE);
            return null;
        }

        // A reachable host clears the breaker even on an auth error — the box
        // is up, only the key is wrong.
        $this->health?->clear(self::SERVICE);

        if ($code === 401 || $code === 403) {
            $this->recordError($code, 'unauthorized', $path);
            return ['ok' => false, 'data' => []];
        }

        $json = json_decode((string) $body, true);
        if ($code !== 200 || !is_array($json)) {
            $this->recordError($code, $code !== 200 ? 'http ' . $code : 'invalid JSON response', $path);
            return ['ok' => false, 'data' => []];
        }

        $this->lastError = null;
        return ['ok' => true, 'data' => $json];
    }

    /**
     * Pure transform: Emby `GET /Sessions` payload (list of sessions, idle ones
     * included) → sanitized activity shape. Public + static so it can be
     * unit-tested against a captured fixture without any network. Only
     * allow-listed fields are copied out — anything sensitive (RemoteEndPoint,
     * DeviceId, AccessToken, Path, …) is dropped by construction because it is
     * never read here (RemoteEndPoint is inspected for a LAN/WAN label only).
     *
     * @param array<int|string, mixed> $data
     * @return array{
     *   streamCount:int, directPlayCount:int, directStreamCount:int, transcodeCount:int,
     *   bandwidth: array{totalKbps:int, lanKbps:int, wanKbps:int, totalMbps:float, lanMbps:float, wanMbps:float},
     *   sessions: list<array<string, mixed>>
     * }
     */
    public static function normalizeSessions(array $data): array
    {
        $sessions = [];
        $direct = $stream = $transcode = 0;
        $lanKbps = $wanKbps = 0;

        foreach ($data as $s) {
            if (!is_array($s) || !is_array($s['NowPlayingItem'] ?? null)) {
                continue; // idle session (app open, nothing playing)
            }
            $n = self::normalizeSession($s);
            $sessions[] = $n;
            match ($n['transcodeDecision']) {
                'direct play' => $direct++,
                'copy'        => $stream++,
                'transcode'   => $transcode++,
                default       => null,
            };
            if ($n['location'] === 'lan') {
                $lanKbps += $n['bandwidthKbps'];
            } else {
                $wanKbps += $n['bandwidthKbps'];
            }
        }

        $total = $lanKbps + $wanKbps;

        return [
            'streamCount'       => count($sessions),
            'directPlayCount'   => $direct,
            'directStreamCount' => $stream,
            'transcodeCount'    => $transcode,
            'bandwidth'         => [
                'totalKbps' => $total,
                'lanKbps'   => $lanKbps,
                'wanKbps'   => $wanKbps,
                'totalMbps' => self::toMbps($total),
                'lanMbps'   => self::toMbps($lanKbps),
                'wanMbps'   => self::toMbps($wanKbps),
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
        $item  = is_array($s['NowPlayingItem'] ?? null) ? $s['NowPlayingItem'] : [];
        $play  = is_array($s['PlayState'] ?? null) ? $s['PlayState'] : [];
        $trans = is_array($s['TranscodingInfo'] ?? null) ? $s['TranscodingInfo'] : [];

        $type      = strtolower(self::str($item['Type'] ?? null) ?? '');
        $mediaType = match ($type) {
            'movie'   => 'movie',
            'episode' => 'episode',
            'audio'   => 'track',
            default   => $type !== '' ? $type : null,
        };

        [$video, $audio] = self::pickStreams($item);
        $sourceBitrate = (int) ($item['Bitrate'] ?? 0);
        if ($sourceBitrate <= 0 && is_array($item['MediaSources'][0] ?? null)) {
            $sourceBitrate = (int) ($item['MediaSources'][0]['Bitrate'] ?? 0);
        }

        $method = self::str($play['PlayMethod'] ?? null);
        $decision = match ($method) {
            'DirectPlay'   => 'direct play',
            'DirectStream' => 'copy',
            'Transcode'    => 'transcode',
            default        => null,
        };
        // While transcoding, the stream bitrate is the transcoder's target;
        // otherwise the file's own bitrate is what crosses the wire.
        $bitrateBps = $decision === 'transcode' && (int) ($trans['Bitrate'] ?? 0) > 0
            ? (int) $trans['Bitrate']
            : $sourceBitrate;
        $kbps = (int) round($bitrateBps / 1000);

        $videoDirect = $decision !== 'transcode' || (bool) ($trans['IsVideoDirect'] ?? false);
        $audioDirect = $decision !== 'transcode' || (bool) ($trans['IsAudioDirect'] ?? false);

        $runtime  = (int) ($item['RunTimeTicks'] ?? 0);
        $position = (int) ($play['PositionTicks'] ?? 0);

        return [
            'sessionKey'       => self::str($s['Id'] ?? null),
            'sessionId'        => self::str($s['Id'] ?? null),
            // Emby item id — NOT a filesystem path. Streamed to the browser via
            // the server-side image proxy (EmbyController::apiImage); the API
            // key is never exposed. Episodes use the series poster (portrait).
            'ratingKey'        => self::str($item['Id'] ?? null),
            'state'            => (bool) ($play['IsPaused'] ?? false) ? 'paused' : 'playing',
            'title'            => self::fullTitle($item, $mediaType),
            'grandparentTitle' => self::str($item['SeriesName'] ?? null),
            'year'             => self::str($item['ProductionYear'] ?? null),
            'mediaType'        => $mediaType,
            'posterPath'       => self::pickPoster($item, $mediaType),
            // Display name only. We deliberately never expose the user id,
            // device id, access token or remote address.
            'userDisplayName'  => self::str($s['UserName'] ?? null),
            'product'          => self::str($s['Client'] ?? null),
            'player'           => self::str($s['DeviceName'] ?? null),
            'device'           => null,
            'platform'         => null,
            'quality'          => self::qualityLabel($video),
            'containerDecision'=> $decision === 'direct play' ? 'direct play' : ($decision === null ? null : 'transcode'),
            'videoDecision'    => $decision === null ? null : ($videoDirect ? 'copy' : 'transcode'),
            'audioDecision'    => $decision === null ? null : ($audioDirect ? 'copy' : 'transcode'),
            'subtitleDecision' => null,
            'dynamicRange'     => self::dynamicRange($video),
            'videoCodec'       => self::str($video['Codec'] ?? null),
            'streamVideoCodec' => $videoDirect ? self::str($video['Codec'] ?? null) : self::str($trans['VideoCodec'] ?? null),
            'audioCodec'       => self::str($audio['Codec'] ?? null),
            'streamAudioCodec' => $audioDirect ? self::str($audio['Codec'] ?? null) : self::str($trans['AudioCodec'] ?? null),
            'transcodeDecision'=> $decision,
            'location'         => self::location(self::str($s['RemoteEndPoint'] ?? null)),
            'bandwidthKbps'    => $kbps,
            'bandwidthMbps'    => self::toMbps($kbps),
            'progressPercent'  => $runtime > 0 ? max(0.0, min(100.0, round($position * 100 / $runtime, 1))) : 0.0,
            'durationSeconds'  => intdiv($runtime, self::TICKS_PER_SECOND),
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
     * "Show - S01E05 - Title" for episodes (Tautulli's full_title style),
     * "Title" for everything else. Season/episode numbers are zero-padded
     * when present; a missing number is skipped rather than rendered as 00.
     *
     * @param array<string, mixed> $item
     */
    private static function fullTitle(array $item, ?string $mediaType): ?string
    {
        $name = self::str($item['Name'] ?? null);
        if ($mediaType !== 'episode') {
            return $name;
        }
        $parts = [];
        if (($series = self::str($item['SeriesName'] ?? null)) !== null) {
            $parts[] = $series;
        }
        $season  = $item['ParentIndexNumber'] ?? null;
        $episode = $item['IndexNumber'] ?? null;
        if (is_numeric($season) && is_numeric($episode)) {
            $parts[] = sprintf('S%02dE%02d', (int) $season, (int) $episode);
        }
        if ($name !== null) {
            $parts[] = $name;
        }
        return $parts === [] ? null : implode(' - ', $parts);
    }

    /**
     * Item id whose primary image is the portrait poster: the series for an
     * episode, the album for a track, the item itself otherwise.
     *
     * @param array<string, mixed> $item
     */
    private static function pickPoster(array $item, ?string $mediaType): ?string
    {
        $own = self::str($item['Id'] ?? null);
        if ($mediaType === 'episode') {
            return self::str($item['SeriesId'] ?? null) ?? $own;
        }
        if ($mediaType === 'track') {
            return self::str($item['AlbumId'] ?? null) ?? $own;
        }
        return $own;
    }

    /**
     * The default (or first) video and audio streams of the playing item.
     *
     * @param array<string, mixed> $item
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private static function pickStreams(array $item): array
    {
        $streams = is_array($item['MediaStreams'] ?? null) ? $item['MediaStreams'] : [];
        $video = $audio = [];
        foreach ($streams as $st) {
            if (!is_array($st)) {
                continue;
            }
            $kind = self::str($st['Type'] ?? null);
            if ($kind === 'Video' && ($video === [] || (bool) ($st['IsDefault'] ?? false))) {
                $video = $st;
            } elseif ($kind === 'Audio' && ($audio === [] || (bool) ($st['IsDefault'] ?? false))) {
                $audio = $st;
            }
        }
        return [$video, $audio];
    }

    /**
     * Resolution badge from the video stream's frame size: 4K / 1080p / 720p /
     * SD. Null when the item has no video stream (audio, or streams not
     * included in the payload).
     *
     * @param array<string, mixed> $video
     */
    private static function qualityLabel(array $video): ?string
    {
        $w = (int) ($video['Width'] ?? 0);
        $h = (int) ($video['Height'] ?? 0);
        if ($w <= 0 && $h <= 0) {
            return null;
        }
        return match (true) {
            $w >= 3200 || $h >= 2000 => '4K',
            $w >= 1800 || $h >= 1000 => '1080p',
            $w >= 1200 || $h >= 700  => '720p',
            default                  => 'SD',
        };
    }

    /**
     * Dynamic-range badge (Dolby Vision / HDR10+ / HDR / SDR). Emby exposes
     * `VideoRange` on the stream and, on newer servers, an `ExtendedVideoType`
     * that tells Dolby Vision and HDR10+ apart from plain HDR.
     *
     * @param array<string, mixed> $video
     */
    private static function dynamicRange(array $video): ?string
    {
        $ext = strtolower(self::str($video['ExtendedVideoType'] ?? null) ?? '');
        if (str_contains($ext, 'dolby')) {
            return 'Dolby Vision';
        }
        if (str_contains($ext, 'plus')) {
            return 'HDR10+';
        }
        $range = self::str($video['VideoRange'] ?? null);
        if ($range === null) {
            return null;
        }
        return match (strtolower($range)) {
            'dolbyvision'  => 'Dolby Vision',
            'hdr', 'hdr10' => 'HDR',
            'sdr'          => 'SDR',
            default        => $range,
        };
    }

    /**
     * 'lan' for private / loopback addresses, 'wan' otherwise, null when Emby
     * didn't report an endpoint. The address itself is never copied out.
     */
    private static function location(?string $endpoint): ?string
    {
        if ($endpoint === null) {
            return null;
        }
        $host = $endpoint;
        if (str_starts_with($host, '::ffff:')) {
            $host = substr($host, 7);
        }
        // Strip a trailing ":port" from IPv4 endpoints; bracketed IPv6 keeps its form.
        if (substr_count($host, ':') === 1) {
            $host = explode(':', $host)[0];
        }
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $public = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        return $public ? 'wan' : 'lan';
    }

    /** Bandwidth is tracked in kbps; the UI shows Mbps (1 decimal). */
    private static function toMbps(int $kbps): float
    {
        return $kbps > 0 ? round($kbps / 1000, 1) : 0.0;
    }

    /** Coerce an Emby scalar to a trimmed string, or null when absent/empty. */
    private static function str(mixed $v): ?string
    {
        if ($v === null || is_array($v) || is_bool($v)) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
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
