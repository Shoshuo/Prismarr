<?php

namespace App\Service\Media;

use App\Service\ConfigService;
use App\Service\HealthService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Read/write client for the Bazarr subtitle-management API.
 *
 * Single-instance, flat-config service (like Tautulli / Deluge): config is
 * read lazily from the `setting` table via ConfigService, and the client
 * fails closed — an unconfigured / disabled / unreachable Bazarr yields safe
 * empty shapes (`[]`, zero counts, `ping() === false`) instead of throwing,
 * so a badge or a widget never breaks page render.
 *
 * Auth is an `X-API-KEY` request header (NOT a `?apikey=` query param, unlike
 * Tautulli). Endpoint base: {bazarr_url}/api{path}.
 *
 * Docs: https://wiki.bazarr.media/Additional-Configuration/API/
 */
class BazarrClient implements ResetInterface
{
    /** Short slug — circuit-breaker key + HealthService service id. */
    public const SERVICE = 'bazarr';

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
        $this->enabled = $this->config->get('bazarr_enabled') !== '0';
        $this->baseUrl = (string) ($this->config->get('bazarr_url') ?? '');
        $this->apiKey  = (string) ($this->config->get('bazarr_api_key') ?? '');
        $this->configLoaded = true;
    }

    private function ready(): bool
    {
        $this->ensureConfig();
        return $this->enabled && $this->baseUrl !== '' && $this->apiKey !== '';
    }

    /**
     * Lightweight reachability probe for HealthService. True when
     * /system/status answers without a transport/JSON error.
     */
    public function ping(): bool
    {
        if (!$this->ready()) {
            return false;
        }
        return $this->request('GET', '/system/status') !== null;
    }

    /**
     * Mutates $this->lastError (via request()) on failure —
     * BazarrIndexRefresher checks getLastError() both before and after this
     * call in the same scope to tell a real zero from a failed fetch.
     * @return array{movies: int, episodes: int, providers: int} Zeros on failure.
     */
    public function getBadgeCounts(): array
    {
        $zero = ['movies' => 0, 'episodes' => 0, 'providers' => 0];
        if (!$this->ready()) {
            return $zero;
        }
        $r = $this->request('GET', '/badges');
        if ($r === null) {
            return $zero;
        }
        return [
            'movies'    => (int) ($r['movies'] ?? 0),
            'episodes'  => (int) ($r['episodes'] ?? 0),
            'providers' => (int) ($r['providers'] ?? 0),
        ];
    }

    /** @return list<array<string, mixed>> Wanted-movie dicts (missing subtitles); [] on failure. */
    public function getWantedMovies(): array
    {
        if (!$this->ready()) {
            return [];
        }
        $r = $this->request('GET', '/movies/wanted');
        return array_values(is_array($r['data'] ?? null) ? $r['data'] : []);
    }

    /** @return list<array<string, mixed>> Wanted-episode dicts (missing subtitles); [] on failure. */
    public function getWantedEpisodes(): array
    {
        if (!$this->ready()) {
            return [];
        }
        $r = $this->request('GET', '/episodes/wanted');
        return array_values(is_array($r['data'] ?? null) ? $r['data'] : []);
    }

    /**
     * @param list<int> $radarrIds Optional per-id filter. Empty = the whole
     *        list (start=0&length=-1), byte-for-byte the previous query.
     * @return list<array<string, mixed>> Raw movie dicts; [] on failure.
     */
    public function getMovies(array $radarrIds = []): array
    {
        if (!$this->ready()) {
            return [];
        }
        $r = $this->request('GET', '/movies', ['start' => 0, 'length' => -1], [], self::repeatedIds('radarrid', $radarrIds));

        return array_values(is_array($r['data'] ?? null) ? $r['data'] : []);
    }

    /**
     * @param list<int> $sonarrSeriesIds Optional per-id filter; empty = the whole list.
     * @return list<array<string, mixed>> Raw series dicts; [] on failure.
     */
    public function getSeries(array $sonarrSeriesIds = []): array
    {
        if (!$this->ready()) {
            return [];
        }
        $r = $this->request('GET', '/series', ['start' => 0, 'length' => -1], [], self::repeatedIds('seriesid', $sonarrSeriesIds));

        return array_values(is_array($r['data'] ?? null) ? $r['data'] : []);
    }

    /**
     * Bazarr's list endpoints take a REPEATED `name[]=` parameter
     * (flask-restx reqparse, action='append'). http_build_query() emits the
     * PHP-indexed `name[0]=` form instead, which request.args.getlist('name[]')
     * never sees — so this fragment is built by hand and appended after the
     * encoded query. Ids are cast to int, so nothing unsanitized reaches the URL.
     *
     * @param list<mixed> $ids
     */
    private static function repeatedIds(string $name, array $ids): string
    {
        if ($ids === []) {
            return '';
        }

        return implode('&', array_map(
            static fn ($id): string => rawurlencode($name . '[]') . '=' . ((int) $id),
            $ids,
        ));
    }

    /** @return list<array<string, mixed>> Raw movie subtitle-history dicts; [] on failure. */
    public function getHistoryMovies(): array
    {
        if (!$this->ready()) {
            return [];
        }
        $r = $this->request('GET', '/movies/history');
        return array_values(is_array($r['data'] ?? null) ? $r['data'] : []);
    }

    /** @return list<array<string, mixed>> Raw episode subtitle-history dicts; [] on failure. */
    public function getHistoryEpisodes(): array
    {
        if (!$this->ready()) {
            return [];
        }
        $r = $this->request('GET', '/episodes/history');
        return array_values(is_array($r['data'] ?? null) ? $r['data'] : []);
    }

    /** @return list<array<string, mixed>> Raw episode dicts for one Sonarr series; [] on failure. */
    public function getEpisodes(int $sonarrSeriesId): array
    {
        if (!$this->ready()) {
            return [];
        }
        $r = $this->request('GET', '/episodes', ['seriesid[]' => $sonarrSeriesId]);
        return array_values(is_array($r['data'] ?? null) ? $r['data'] : []);
    }

    /** @return array<string, mixed>|null Provider search results; null on failure. */
    public function searchMovie(int $radarrId): ?array
    {
        if (!$this->ready()) {
            return null;
        }
        return $this->request('GET', '/providers/movies', ['radarrid' => $radarrId]);
    }

    /** @return array<string, mixed>|null Provider search results; null on failure. */
    public function searchEpisode(int $episodeId): ?array
    {
        if (!$this->ready()) {
            return null;
        }
        return $this->request('GET', '/providers/episodes', ['episodeid' => $episodeId]);
    }

    /** @param array{radarrid?: mixed, hi?: mixed, forced?: mixed, original_format?: mixed, provider?: mixed, subtitle?: mixed} $p */
    public function downloadMovie(array $p): bool
    {
        if (!$this->ready()) {
            return false;
        }
        return $this->request('POST', '/providers/movies', [], $this->downloadBody($p, 'radarrid')) !== null;
    }

    /** @param array{episodeid?: mixed, hi?: mixed, forced?: mixed, original_format?: mixed, provider?: mixed, subtitle?: mixed} $p */
    public function downloadEpisode(array $p): bool
    {
        if (!$this->ready()) {
            return false;
        }
        return $this->request('POST', '/providers/episodes', [], $this->downloadBody($p, 'episodeid')) !== null;
    }

    public function searchMissingMovie(int $radarrId): bool
    {
        if (!$this->ready()) {
            return false;
        }
        return $this->request('PATCH', '/movies', [], ['radarrid' => $radarrId, 'action' => 'search-missing']) !== null;
    }

    public function searchMissingSeries(int $sonarrSeriesId): bool
    {
        if (!$this->ready()) {
            return false;
        }
        return $this->request('PATCH', '/series', [], ['seriesid' => $sonarrSeriesId, 'action' => 'search-missing']) !== null;
    }

    /**
     * Normalizes a subtitle-download request body for Bazarr's
     * `/providers/{movies,episodes}` POST endpoints: the id is cast to a
     * string under the caller-supplied key ('radarrid' or 'episodeid'), and
     * the three boolean-ish flags are coerced to Bazarr's expected literal
     * strings "True"/"False" (truthy-ish inputs: `true`, `"True"`, `"1"`, `1`).
     *
     * @param array<string, mixed> $p
     * @return array<string, string>
     */
    private function downloadBody(array $p, string $idKey): array
    {
        $b = static fn($v): string => (($v === true || $v === 'True' || $v === '1' || $v === 1) ? 'True' : 'False');
        return [
            $idKey            => (string) ($p[$idKey] ?? ''),
            'hi'              => $b($p['hi'] ?? false),
            'forced'          => $b($p['forced'] ?? false),
            'original_format' => $b($p['original_format'] ?? false),
            'provider'        => (string) ($p['provider'] ?? ''),
            'subtitle'        => (string) ($p['subtitle'] ?? ''),
        ];
    }

    /**
     * Issue a Bazarr API call. Returns the decoded top-level response array
     * (empty array on a 204 / empty-body 2xx — Bazarr's download/PATCH
     * endpoints answer no-content on success), or null when the host is
     * unreachable / blocked / returns a non-2xx / returns invalid JSON.
     * Honors + feeds the cross-request circuit breaker so a downed Bazarr
     * doesn't cost an 8 s timeout on every poll.
     *
     * @param array<string, mixed> $query    Appended as a query string.
     * @param array<string, mixed> $body     Form-encoded body for POST/PATCH.
     * @param string               $rawQuery Pre-encoded extra query fragment for
     *                                       parameters http_build_query() cannot
     *                                       express (Bazarr's repeated `name[]=`).
     * @return array<string, mixed>|null
     */
    private function request(string $method, string $path, array $query = [], array $body = [], string $rawQuery = ''): ?array
    {
        // Circuit breaker: skip the call entirely if Bazarr was just seen
        // down — a widget poll would otherwise stack connect timeouts.
        if ($this->health?->isDown(self::SERVICE)) {
            // Record a structured error so callers can tell "skipped, the host
            // was just down" apart from "never called" (getLastError() === null
            // is what jsonClientError() and BazarrSubtitleIndex's cache-on-
            // success check both key on). Deliberately NOT logged: badge
            // rendering can enter this branch dozens of times per page during
            // a down window, and the failure that opened the breaker was
            // already logged once by recordError().
            $this->lastError = [
                'code'    => 0,
                'method'  => $method,
                'path'    => $path,
                'message' => 'circuit open — Bazarr was unreachable moments ago, retrying shortly',
            ];
            return null;
        }

        // SSRF guard #1 — reuse the shared validator (blocks non-http(s)
        // schemes + link-local / cloud-metadata IPs) before opening a socket.
        $endpoint = rtrim($this->baseUrl, '/') . '/api' . $path;
        if (($reason = HealthService::urlBlockedReason($endpoint)) !== null) {
            $this->recordError(0, 'blocked: ' . $reason, $method, $path);
            $this->logger->warning('Bazarr URL blocked', ['reason' => $reason]);
            return null;
        }

        $qs = http_build_query($query);
        if ($rawQuery !== '') {
            $qs = $qs === '' ? $rawQuery : $qs . '&' . $rawQuery;
        }
        $url = $endpoint . ($qs !== '' ? '?' . $qs : '');

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        $opts = [
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
            CURLOPT_HTTPHEADER     => ['X-API-KEY: ' . $this->apiKey, 'Accept: application/json'],
        ];

        if ($method === 'POST' || $method === 'PATCH') {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($body);
        }

        curl_setopt_array($ch, $opts);
        [$rawBody, $code, $err] = $this->exec($ch);

        // Transport failure (unreachable / DNS / TLS / timeout) — the only
        // class of failure that may trip the breaker.
        if ($rawBody === false || $err !== '' || $code === 0) {
            $this->recordError($code, $err !== '' ? $err : 'connection failed', $method, $path);
            $this->health?->markDown(self::SERVICE);
            return null;
        }

        if ($code < 200 || $code >= 300) {
            // A reachable host clears the breaker even on an auth error (or a
            // 404 from an endpoint this Bazarr version doesn't expose) — the
            // box is up, only this one call failed. Mirrors TautulliClient;
            // marking down here would blind every OTHER Bazarr call for the
            // full breaker TTL because of one bad request.
            $this->recordError($code, 'unexpected HTTP status', $method, $path);
            $this->health?->clear(self::SERVICE);
            return null;
        }

        // 204 / empty-body 2xx counts as success (Bazarr's download/PATCH
        // endpoints answer no-content) — don't treat it as a JSON failure.
        if ($code === 204 || trim((string) $rawBody) === '') {
            $this->health?->clear(self::SERVICE);
            $this->lastError = null;
            return [];
        }

        $json = json_decode((string) $rawBody, true);
        if (!is_array($json)) {
            $this->recordError($code, 'invalid JSON response', $method, $path);
            $this->health?->markDown(self::SERVICE);
            return null;
        }

        $this->health?->clear(self::SERVICE);
        $this->lastError = null;
        return $json;
    }

    /**
     * cURL execution seam: performs the transfer and returns the three raw
     * facts request() classifies on. Split out (and protected) so unit tests
     * can feed fabricated responses through the classification branches —
     * transport failure vs non-2xx vs invalid JSON drive different circuit-
     * breaker decisions, and there is no live Bazarr in the test suite.
     *
     * @param \CurlHandle $ch
     * @return array{0: string|false, 1: int, 2: string} [body, http code, curl error]
     */
    protected function exec(\CurlHandle $ch): array
    {
        /** @var string|false $body */
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        return [$body, $code, $err];
    }

    private function recordError(int $code, string $message, string $method, string $path): void
    {
        $this->lastError = [
            'code'    => $code,
            'method'  => $method,
            'path'    => $path,
            'message' => $message,
        ];
        $this->logger->warning('Bazarr request failed', [
            'method'  => $method,
            'path'    => $path,
            'code'    => $code,
            'message' => $message,
        ]);
    }
}
