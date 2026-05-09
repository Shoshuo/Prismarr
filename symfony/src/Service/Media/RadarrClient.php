<?php

namespace App\Service\Media;

use App\Entity\ServiceInstance;
use App\Exception\ServiceNotConfiguredException;
use App\Service\ServiceInstanceProvider;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

class RadarrClient implements ResetInterface
{
    private const SERVICE = 'Radarr';
    private const SERVICE_KEY = 'radarr';

    private string $baseUrl = '';
    private string $apiKey = '';
    /** Bound instance for this client, or null = serve the default. */
    private ?ServiceInstance $instance = null;

    /** @var array{code:int, method:string, path:string, message:string}|null */
    private ?array $lastError = null;

    /**
     * Circuit breaker — once a network error / timeout occurs in this request,
     * short-circuit subsequent calls to avoid stacking 4s timeouts and tripping
     * PHP's max_execution_time. Reset between worker requests via reset().
     */
    private bool $serviceUnavailable = false;

    public function __construct(
        private readonly ServiceInstanceProvider $instances,
        private readonly LoggerInterface $logger,
        private readonly ServiceHealthCache $health,
    ) {}

    /**
     * Returns a clone bound to a specific Radarr instance, used by routes
     * that carry an instance slug (e.g. /radarr/{slug}/films). The original
     * autowired client keeps serving the default instance.
     */
    public function withInstance(ServiceInstance $instance): self
    {
        if ($instance->getType() !== ServiceInstance::TYPE_RADARR) {
            throw new \InvalidArgumentException(sprintf(
                'RadarrClient::withInstance() expects a radarr instance, got "%s".',
                $instance->getType()
            ));
        }
        $clone = clone $this;
        $clone->instance = $instance;
        $clone->baseUrl  = '';
        $clone->apiKey   = '';
        $clone->lastError = null;
        $clone->serviceUnavailable = false;
        return $clone;
    }

    /** Currently bound instance (after the first call), or null. */
    public function getInstance(): ?ServiceInstance
    {
        return $this->instance;
    }

    /**
     * Mutate this client to point at $instance for the rest of the request.
     * Unlike withInstance() which clones, this is destructive: used by
     * MultiInstanceBinderSubscriber on the autowired client so the 98+
     * RadarrController methods don't have to care about the slug — they
     * just keep calling $this->radarr and hit the right upstream.
     *
     * Worker-mode safe: reset() clears the binding between requests.
     */
    public function bindInstance(?ServiceInstance $instance): void
    {
        if ($instance !== null && $instance->getType() !== ServiceInstance::TYPE_RADARR) {
            throw new \InvalidArgumentException(sprintf(
                'RadarrClient::bindInstance() expects a radarr instance, got "%s".',
                $instance->getType()
            ));
        }
        $this->instance = $instance;
        $this->baseUrl  = '';
        $this->apiKey   = '';
        $this->lastError = null;
        $this->serviceUnavailable = false;
    }

    private function ensureConfig(): void
    {
        if ($this->baseUrl !== '') {
            return;
        }
        $instance = $this->instance ?? $this->instances->getDefault(ServiceInstance::TYPE_RADARR);
        if ($instance === null || !$instance->isEnabled()) {
            throw new ServiceNotConfiguredException(self::SERVICE, 'service_instance:radarr');
        }
        $this->instance = $instance;
        $this->baseUrl  = $instance->getUrl();
        $this->apiKey   = $instance->getApiKey() ?? '';
    }

    public function reset(): void
    {
        $this->baseUrl  = '';
        $this->apiKey   = '';
        $this->instance = null;
        $this->lastError = null;
        $this->serviceUnavailable = false;
    }

    /**
     * Returns the last upstream error captured by an HTTP method on this client,
     * or null if the most recent call succeeded. Reset between worker requests.
     *
     * @return array{code:int, method:string, path:string, message:string}|null
     */
    public function getLastError(): ?array
    {
        return $this->lastError;
    }

    /**
     * Extracts a human-readable error message from an upstream API response body.
     * Handles JSON objects ({errorMessage}/{error}/{message}/{detail}), JSON arrays
     * of validation errors ([{propertyName, errorMessage}, ...]), short raw bodies,
     * and falls back to curl error or "HTTP {code}".
     */
    private function extractApiErrorMessage(string $body, int $code, string $curlError): string
    {
        $body = trim($body);
        $decoded = $body !== '' ? json_decode($body, true) : null;

        if (is_array($decoded)) {
            // Array of validation errors: [{propertyName, errorMessage}, ...]
            if (isset($decoded[0]) && is_array($decoded[0])) {
                $messages = [];
                foreach ($decoded as $entry) {
                    if (!is_array($entry)) continue;
                    $msg = $entry['errorMessage'] ?? $entry['error'] ?? $entry['message'] ?? $entry['detail'] ?? null;
                    if (is_string($msg) && $msg !== '') {
                        $prop = $entry['propertyName'] ?? '';
                        $messages[] = $prop !== '' ? "{$prop}: {$msg}" : $msg;
                    }
                }
                if (!empty($messages)) return implode('; ', $messages);
            }

            // Object with a known error key
            foreach (['errorMessage', 'error', 'message', 'detail'] as $key) {
                if (isset($decoded[$key]) && is_string($decoded[$key]) && $decoded[$key] !== '') {
                    return $decoded[$key];
                }
            }
        }

        if ($body !== '' && strlen($body) < 200) {
            return $body;
        }

        if ($curlError !== '') return $curlError;

        return "HTTP {$code}";
    }

    /**
     * @internal Stores last error metadata for getLastError() consumers.
     */
    private function recordError(string $method, string $path, int $code, string $body, string $curlError): void
    {
        $this->lastError = [
            'code'    => $code,
            'method'  => $method,
            'path'    => $path,
            'message' => $this->extractApiErrorMessage($body, $code, $curlError),
        ];
    }

    /**
     * Defense-in-depth: scrub magnet links and api keys before pushing the
     * upstream body into the warning log, then truncate to 200 chars. The
     * log destination is `docker logs prismarr`, accessible only to the
     * host admin, so the leak surface is small — but a screenshot, a bug
     * report copy-paste, or a future log-export feature would otherwise
     * carry indexer / tracker secrets verbatim.
     */
    private static function sanitizeLogBody(?string $body): string
    {
        if ($body === null || $body === '') return '';
        $body = preg_replace('/magnet:\?xt=urn:btih:[a-zA-Z0-9]+[^\s"\']*/', '[magnet]', $body) ?? $body;
        $body = preg_replace('/(api[_-]?key)=[^&"\'\s]+/i', '$1=[redacted]', $body) ?? $body;
        return mb_strlen($body) > 200 ? mb_substr($body, 0, 200) . '…' : $body;
    }

    /** Light ping — true if the API responds and accepts the key. */
    public function ping(): bool
    {
        try {
            return $this->getSystemStatus() !== null;
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr ping failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return false;
        }
    }

    // ── Movies ────────────────────────────────────────────────────────────────

    public function getMovies(): array
    {
        $data = $this->get('/api/v3/movie');
        if ($data === null) return [];

        return array_map(fn($m) => $this->normalizeMovie($m), $data);
    }

    /** Returns raw movies without normalization (for lightweight cache) */
    public function getRawMovies(): array
    {
        return $this->get('/api/v3/movie') ?? [];
    }

    public function getMovie(int $id): ?array
    {
        $data = $this->get("/api/v3/movie/{$id}");
        return $data ? $this->normalizeMovie($data) : null;
    }

    public function getRawMovie(int $id): ?array
    {
        return $this->get("/api/v3/movie/{$id}");
    }

    public function lookupMovies(string $term): array
    {
        $data = $this->get('/api/v3/movie/lookup', ['term' => $term]);
        if ($data === null) return [];
        return array_map(fn($m) => $this->normalizeMovie($m), $data);
    }

    public function lookupByImdb(string $imdbId): ?array
    {
        $data = $this->get('/api/v3/movie/lookup', ['term' => 'imdb:' . $imdbId]);
        if (empty($data)) return null;
        return $this->normalizeMovie($data[0]);
    }

    public function lookupByTmdb(int $tmdbId): ?array
    {
        $data = $this->get('/api/v3/movie/lookup/tmdb', ['tmdbId' => $tmdbId]);
        return $data ? $this->normalizeMovie($data) : null;
    }

    public function addMovie(array $data): ?array
    {
        $result = $this->request('POST', '/api/v3/movie', [], $data);
        return $result ? $this->normalizeMovie($result) : null;
    }

    public function updateMovie(int $id, array $data): ?array
    {
        $result = $this->request('PUT', "/api/v3/movie/{$id}", [], $data);
        return $result ? $this->normalizeMovie($result) : null;
    }

    public function deleteMovie(int $id, bool $deleteFiles = false, bool $addExclusion = false): bool
    {
        return $this->delete("/api/v3/movie/{$id}", [
            'deleteFiles'  => $deleteFiles ? 'true' : 'false',
            'addExclusion' => $addExclusion ? 'true' : 'false',
        ]);
    }

    public function setMonitored(int $id, bool $monitored): bool
    {
        $movie = $this->get("/api/v3/movie/{$id}");
        if ($movie === null) return false;
        $movie['monitored'] = $monitored;
        return $this->put("/api/v3/movie/{$id}", $movie);
    }

    // ── Movie Files ───────────────────────────────────────────────────────────

    public function getMovieFiles(int $movieId): array
    {
        return $this->get('/api/v3/moviefile', ['movieId' => $movieId]) ?? [];
    }

    public function deleteMovieFile(int $fileId): bool
    {
        return $this->delete("/api/v3/moviefile/{$fileId}");
    }

    // ── History ───────────────────────────────────────────────────────────────

    public function getHistory(int $page = 1, int $pageSize = 50, string $eventType = '', int $movieId = 0): array
    {
        $params = [
            'page'         => $page,
            'pageSize'     => $pageSize,
            'includeMovie' => 'true',
        ];
        if ($eventType !== '') {
            $params['eventType'] = $eventType;
        }
        if ($movieId > 0) {
            $params['movieIds'] = $movieId;
        }
        return $this->get('/api/v3/history', $params) ?? [];
    }

    public function getMovieHistory(int $movieId): array
    {
        return $this->get('/api/v3/history/movie', ['movieId' => $movieId, 'includeMovie' => 'true']) ?? [];
    }

    // ── Queue ─────────────────────────────────────────────────────────────────

    public function getQueue(): array
    {
        $data = $this->get('/api/v3/queue', ['pageSize' => 50, 'includeMovie' => 'true']);
        if ($data === null || empty($data['records'])) return [];

        return array_map(fn($r) => [
            'id'             => $r['id'] ?? null,
            'movieId'        => $r['movieId'] ?? null,
            'title'          => $r['movie']['title'] ?? $r['title'] ?? '—',
            'year'           => $r['movie']['year'] ?? null,
            'size'           => $r['size'] ?? 0,
            'sizeleft'       => $r['sizeleft'] ?? 0,
            'status'         => $r['status'] ?? 'unknown',
            'trackedStatus'  => $r['trackedDownloadStatus'] ?? 'ok',
            'trackedState'   => $r['trackedDownloadState'] ?? '',
            'quality'        => $r['quality']['quality']['name'] ?? '—',
            'protocol'       => $r['protocol'] ?? '—',
            'eta'            => $r['estimatedCompletionTime'] ?? null,
            'indexer'        => $r['indexer'] ?? null,
            'downloadId'     => $r['downloadId'] ?? null,
            'outputPath'     => $r['outputPath'] ?? null,
            'downloadClient' => $r['downloadClient'] ?? null,
            'statusMessages' => array_map(fn($m) => [
                'title' => $m['title'] ?? '',
                'messages' => $m['messages'] ?? [],
            ], $r['statusMessages'] ?? []),
        ], $data['records']);
    }

    public function getRawQueue(): array
    {
        $data = $this->get('/api/v3/queue', ['pageSize' => 50, 'includeMovie' => 'true']);
        return $data['records'] ?? [];
    }

    public function getQueueStatus(): ?array
    {
        return $this->get('/api/v3/queue/status');
    }

    public function sendCommand(string $name, array $extra = []): ?array
    {
        return $this->request('POST', '/api/v3/command', [], array_merge(['name' => $name], $extra));
    }

    public function manualImport(array $files): array
    {
        $cmdData = $this->request('POST', '/api/v3/command', [], [
            'name' => 'ManualImport',
            'importMode' => 'move',
            'files' => $files,
        ]);
        if ($cmdData === null) {
            return ['ok' => false, 'error' => 'Cannot start manual import'];
        }
        return ['ok' => true, 'cmdId' => $cmdData['id'] ?? null];
    }

    public function deleteQueueItem(int $id, bool $removeFromClient = false, bool $blocklist = false, bool $skipReimport = false): bool
    {
        return $this->delete("/api/v3/queue/{$id}", [
            'removeFromClient' => $removeFromClient ? 'true' : 'false',
            'blocklist'        => $blocklist ? 'true' : 'false',
            'skipReimport'     => $skipReimport ? 'true' : 'false',
        ]);
    }

    public function grabQueueItem(int $id): array
    {
        return $this->requestWithError('POST', "/api/v3/queue/grab/{$id}", []);
    }

    public function bulkGrabQueue(array $ids): array
    {
        return $this->requestWithError('POST', '/api/v3/queue/grab/bulk', ['ids' => $ids]);
    }

    // ── Calendar ──────────────────────────────────────────────────────────────

    public function getCalendar(int $daysAhead = 90, int $daysBefore = 0): array
    {
        $start = (new \DateTimeImmutable("-{$daysBefore} days"))->format('Y-m-d');
        $end   = (new \DateTimeImmutable("+{$daysAhead} days"))->format('Y-m-d');
        $data  = $this->get('/api/v3/calendar', ['start' => $start, 'end' => $end]);
        if ($data === null) return [];

        return array_map(fn($m) => $this->normalizeMovie($m), $data);
    }

    // ── Commands ──────────────────────────────────────────────────────────────

    public function searchMovie(int $id): ?int
    {
        $data = $this->request('POST', '/api/v3/command', [], ['name' => 'MoviesSearch', 'movieIds' => [$id]]);
        return $data['id'] ?? null;
    }

    public function searchAllMissing(): ?int
    {
        $data = $this->request('POST', '/api/v3/command', [], ['name' => 'MissingMoviesSearch']);
        return $data['id'] ?? null;
    }

    public function refreshMovie(int $id): ?int
    {
        $data = $this->request('POST', '/api/v3/command', [], ['name' => 'RefreshMovie', 'movieIds' => [$id]]);
        return $data['id'] ?? null;
    }

    public function rescanMovie(int $id): ?int
    {
        $data = $this->request('POST', '/api/v3/command', [], ['name' => 'RescanMovie', 'movieId' => $id]);
        return $data['id'] ?? null;
    }

    public function refreshAllMovies(): ?int
    {
        $data = $this->request('POST', '/api/v3/command', [], ['name' => 'RefreshMovie']);
        return $data['id'] ?? null;
    }

    public function rssSync(): ?int
    {
        $data = $this->request('POST', '/api/v3/command', [], ['name' => 'RssSync']);
        return $data['id'] ?? null;
    }

    public function getCommandStatus(int $cmdId): ?array
    {
        return $this->get("/api/v3/command/{$cmdId}");
    }

    public function getAllCommands(): array
    {
        return $this->get('/api/v3/command') ?? [];
    }

    // ── Releases ──────────────────────────────────────────────────────────────

    public function getReleasesForMovie(int $id): array
    {
        $this->ensureConfig();
        // Radarr queries all indexers in real-time — needs a longer timeout than the default 10s
        $url = rtrim($this->baseUrl, '/') . '/api/v3/release?' . http_build_query(['movieId' => $id]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS, // SSRF guard
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, // (block file:// gopher:// ...)
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => ["X-Api-Key: {$this->apiKey}", 'Accept: application/json'],
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err || $code !== 200) {
            $this->logger->warning("RadarrClient GET /api/v3/release → HTTP {$code} {$err}");
            return [];
        }

        return json_decode($body, true) ?? [];
    }

    public function grabRelease(string $guid, int $indexerId): array
    {
        $this->ensureConfig();
        $url = rtrim($this->baseUrl, '/') . '/api/v3/release';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS, // SSRF guard
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, // (block file:// gopher:// ...)
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode(['guid' => $guid, 'indexerId' => $indexerId]),
            CURLOPT_HTTPHEADER     => ["X-Api-Key: {$this->apiKey}", 'Content-Type: application/json', 'Accept: application/json'],
        ]);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($code < 200 || $code >= 300) {
            $this->logger->warning("RadarrClient POST /api/v3/release → HTTP {$code} {$err}", ['response' => self::sanitizeLogBody($resp)]);
            $decoded = json_decode($resp ?: '{}', true);
            $data    = is_array($decoded) ? $decoded : [];
            $msg = $data['message'] ?? ($err !== '' ? $err : "HTTP error {$code}");
            return ['ok' => false, 'error' => $msg];
        }

        return ['ok' => true];
    }

    // ── Wanted ────────────────────────────────────────────────────────────────

    public function getMissing(int $page = 1, int $pageSize = 50): array
    {
        return $this->get('/api/v3/wanted/missing', [
            'page'          => $page,
            'pageSize'      => $pageSize,
            'sortKey'       => 'inCinemas',
            'sortDirection' => 'descending',
            'monitored'     => 'true',
            'includeMovie'  => 'true',
        ]) ?? [];
    }

    public function getCutoff(int $page = 1, int $pageSize = 50): array
    {
        return $this->get('/api/v3/wanted/cutoff', [
            'page'          => $page,
            'pageSize'      => $pageSize,
            'sortKey'       => 'inCinemas',
            'sortDirection' => 'descending',
            'monitored'     => 'true',
            'includeMovie'  => 'true',
        ]) ?? [];
    }

    // ── Collections ───────────────────────────────────────────────────────────

    public function getCollections(): array
    {
        return $this->get('/api/v3/collection') ?? [];
    }

    public function getCollection(int $id): ?array
    {
        return $this->get("/api/v3/collection/{$id}");
    }

    public function updateCollection(int $id, bool $monitored, int $qualityProfileId = 0): bool
    {
        $body = ['monitored' => $monitored];
        if ($qualityProfileId > 0) {
            $body['qualityProfileId'] = $qualityProfileId;
        }
        return $this->request('PUT', "/api/v3/collection/{$id}", [], $body) !== null;
    }

    // ── Blocklist ─────────────────────────────────────────────────────────────

    public function getBlocklist(int $page = 1, int $pageSize = 50): array
    {
        return $this->get('/api/v3/blocklist', [
            'page'          => $page,
            'pageSize'      => $pageSize,
            'sortKey'       => 'date',
            'sortDirection' => 'descending',
            'includeMovie'  => 'true',
        ]) ?? [];
    }

    public function deleteBlocklistItem(int $id): bool
    {
        return $this->delete("/api/v3/blocklist/{$id}");
    }

    public function bulkDeleteBlocklist(array $ids): bool
    {
        return $this->deleteWithBody('/api/v3/blocklist/bulk', ['ids' => $ids]);
    }

    public function getMovieBlocklist(int $movieId): array
    {
        return $this->get('/api/v3/blocklist/movie', ['movieId' => $movieId]) ?? [];
    }

    // ── Quality Profiles ──────────────────────────────────────────────────────

    public function getQualityProfiles(): array
    {
        return $this->get('/api/v3/qualityprofile') ?? [];
    }

    // ── Root Folders ──────────────────────────────────────────────────────────

    public function getRootFolders(): array
    {
        return $this->get('/api/v3/rootfolder') ?? [];
    }

    // ── Tags ──────────────────────────────────────────────────────────────────

    public function getTags(): array
    {
        return $this->get('/api/v3/tag') ?? [];
    }

    public function createTag(string $label): ?array
    {
        return $this->request('POST', '/api/v3/tag', [], ['label' => $label]);
    }

    public function deleteTag(int $id): bool
    {
        return $this->delete("/api/v3/tag/{$id}");
    }

    // ── System ────────────────────────────────────────────────────────────────

    public function getSystemStatus(): ?array
    {
        return $this->get('/api/v3/system/status');
    }

    public function getSystemHealth(): array
    {
        return $this->get('/api/v3/health') ?? [];
    }

    public function getDiskSpace(): array
    {
        return $this->get('/api/v3/diskspace') ?? [];
    }

    // ── Indexers ──────────────────────────────────────────────────────────────

    public function getRadarrIndexers(): array
    {
        return $this->get('/api/v3/indexer') ?? [];
    }

    public function testAllIndexers(): bool
    {
        return $this->request('POST', '/api/v3/indexer/testall', [], []) !== null;
    }

    // ── Download Clients ──────────────────────────────────────────────────────

    public function getDownloadClients(): array
    {
        return $this->get('/api/v3/downloadclient') ?? [];
    }

    public function testAllDownloadClients(): bool
    {
        return $this->request('POST', '/api/v3/downloadclient/testall', [], []) !== null;
    }

    public function getDownloadClientSchema(): array
    {
        return $this->get('/api/v3/downloadclient/schema') ?? [];
    }

    // ── Import Lists ──────────────────────────────────────────────────────────

    public function getImportLists(): array
    {
        return $this->get('/api/v3/importlist') ?? [];
    }

    public function getImportListSchema(): array
    {
        return $this->get('/api/v3/importlist/schema') ?? [];
    }

    public function getImportListMovies(): array
    {
        return $this->get('/api/v3/importlist/movie') ?? [];
    }

    // ── Logs ──────────────────────────────────────────────────────────────────

    public function getLogs(int $page = 1, int $pageSize = 100, string $level = ''): array
    {
        $params = [
            'page'          => $page,
            'pageSize'      => $pageSize,
            'sortKey'       => 'time',
            'sortDirection' => 'descending',
        ];
        if ($level !== '') {
            $params['level'] = $level;
        }
        return $this->get('/api/v3/log', $params) ?? [];
    }

    // ── Notifications ─────────────────────────────────────────────────────────

    public function getNotifications(): array
    {
        return $this->get('/api/v3/notification') ?? [];
    }

    public function createNotification(array $d): ?array
    {
        return $this->request('POST', '/api/v3/notification', [], $d);
    }

    public function updateNotification(int $id, array $d): ?array
    {
        return $this->request('PUT', "/api/v3/notification/{$id}", [], $d);
    }

    public function deleteNotification(int $id): bool
    {
        return $this->delete("/api/v3/notification/{$id}");
    }

    public function testNotification(int $id): bool
    {
        $this->ensureConfig();
        $url = rtrim($this->baseUrl, '/') . '/api/v3/notification/test';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS, // SSRF guard
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, // (block file:// gopher:// ...)
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode(['id' => $id]),
            CURLOPT_HTTPHEADER     => ["X-Api-Key: {$this->apiKey}", 'Content-Type: application/json'],
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }

    // ── Updates ────────────────────────────────────────────────────────────────

    public function getUpdates(): array
    {
        return $this->get('/api/v3/update') ?? [];
    }

    public function installUpdate(): ?int
    {
        $data = $this->request('POST', '/api/v3/command', [], ['name' => 'ApplicationUpdate']);
        return $data['id'] ?? null;
    }

    // ── Import List Exclusions ──────────────────────────────────────────────────

    public function getImportListExclusions(int $page = 1, int $pageSize = 50): array
    {
        return $this->get('/api/v3/exclusions/paged', [
            'page'          => $page,
            'pageSize'      => $pageSize,
            'sortKey'       => 'movieTitle',
            'sortDirection' => 'ascending',
        ]) ?? [];
    }

    public function addImportListExclusion(int $tmdbId, string $movieTitle, int $year): ?array
    {
        return $this->request('POST', '/api/v3/exclusions', [], [
            'tmdbId'     => $tmdbId,
            'movieTitle' => $movieTitle,
            'year'       => $year,
        ]);
    }

    public function deleteImportListExclusion(int $id): bool
    {
        return $this->delete("/api/v3/exclusions/{$id}");
    }

    public function bulkDeleteImportListExclusions(array $ids): bool
    {
        return $this->deleteWithBody('/api/v3/exclusions/bulk', ['ids' => $ids]);
    }

    // ── Import List Movies (suggestions) ───────────────────────────────────────

    public function getImportListMoviesWithRecommendations(): array
    {
        return $this->get('/api/v3/importlist/movie', ['includeRecommendations' => 'true']) ?? [];
    }

    // ── Extra Files ────────────────────────────────────────────────────────────

    public function getExtraFiles(int $movieId): array
    {
        return $this->get('/api/v3/extrafile', ['movieId' => $movieId]) ?? [];
    }

    // ── Credits ────────────────────────────────────────────────────────────────

    public function getCredits(int $movieId): array
    {
        return $this->get('/api/v3/credit', ['movieId' => $movieId]) ?? [];
    }

    // ── Rename ─────────────────────────────────────────────────────────────────

    public function getRenameProposals(int $movieId): array
    {
        return $this->get('/api/v3/rename', ['movieId' => $movieId]) ?? [];
    }

    public function executeRename(int $movieId): ?int
    {
        $data = $this->request('POST', '/api/v3/command', [], ['name' => 'RenameMovie', 'movieIds' => [$movieId]]);
        return $data['id'] ?? null;
    }

    // ── Delay Profiles ─────────────────────────────────────────────────────────

    public function getDelayProfiles(): array
    {
        return $this->get('/api/v3/delayprofile') ?? [];
    }

    public function createDelayProfile(array $d): ?array
    {
        return $this->request('POST', '/api/v3/delayprofile', [], $d);
    }

    public function updateDelayProfile(int $id, array $d): ?array
    {
        return $this->request('PUT', "/api/v3/delayprofile/{$id}", [], $d);
    }

    public function deleteDelayProfile(int $id): bool
    {
        return $this->delete("/api/v3/delayprofile/{$id}");
    }

    // ── Custom Formats ─────────────────────────────────────────────────────────

    public function getCustomFormats(): array
    {
        return $this->get('/api/v3/customformat') ?? [];
    }

    public function getCustomFormatSchema(): array
    {
        return $this->get('/api/v3/customformat/schema') ?? [];
    }

    public function createCustomFormat(array $d): ?array
    {
        return $this->request('POST', '/api/v3/customformat', [], $d);
    }

    public function updateCustomFormat(int $id, array $d): ?array
    {
        return $this->request('PUT', "/api/v3/customformat/{$id}", [], $d);
    }

    public function deleteCustomFormat(int $id): bool
    {
        return $this->delete("/api/v3/customformat/{$id}");
    }

    // ── Auto-Tagging ───────────────────────────────────────────────────────────

    public function getAutoTags(): array
    {
        return $this->get('/api/v3/autotagging') ?? [];
    }

    public function createAutoTag(array $d): ?array
    {
        return $this->request('POST', '/api/v3/autotagging', [], $d);
    }

    public function updateAutoTag(int $id, array $d): ?array
    {
        return $this->request('PUT', "/api/v3/autotagging/{$id}", [], $d);
    }

    public function deleteAutoTag(int $id): bool
    {
        return $this->delete("/api/v3/autotagging/{$id}");
    }

    // ── Quality Profiles CRUD ──────────────────────────────────────────────────

    public function createQualityProfile(array $d): ?array
    {
        return $this->request('POST', '/api/v3/qualityprofile', [], $d);
    }

    public function createQualityProfileWithError(array $d): array
    {
        return $this->requestWithError('POST', '/api/v3/qualityprofile', $d);
    }

    public function updateQualityProfile(int $id, array $d): ?array
    {
        return $this->request('PUT', "/api/v3/qualityprofile/{$id}", [], $d);
    }

    public function updateQualityProfileWithError(int $id, array $d): array
    {
        return $this->requestWithError('PUT', "/api/v3/qualityprofile/{$id}", $d);
    }

    public function deleteQualityProfile(int $id): bool
    {
        return $this->delete("/api/v3/qualityprofile/{$id}");
    }

    // ── Quality Definitions ────────────────────────────────────────────────────

    public function getQualityDefinitions(): array
    {
        return $this->get('/api/v3/qualitydefinition') ?? [];
    }

    public function getQualityDefinitionLimits(): ?array
    {
        return $this->get('/api/v3/qualitydefinition/limits');
    }

    // ── Backup / Restore ───────────────────────────────────────────────────────

    public function getBackups(): array
    {
        return $this->get('/api/v3/system/backup') ?? [];
    }

    public function deleteBackup(int $id): bool
    {
        return $this->delete("/api/v3/system/backup/{$id}");
    }

    public function restoreBackup(int $id): bool
    {
        $this->ensureConfig();
        $url = rtrim($this->baseUrl, '/') . "/api/v3/system/backup/restore/{$id}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS, // SSRF guard
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, // (block file:// gopher:// ...)
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => '{}',
            CURLOPT_HTTPHEADER     => ["X-Api-Key: {$this->apiKey}", 'Content-Type: application/json'],
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }

    public function createBackup(): ?int
    {
        $data = $this->request('POST', '/api/v3/command', [], ['name' => 'Backup']);
        return $data['id'] ?? null;
    }

    // ── Host Configuration ─────────────────────────────────────────────────────

    public function getHostConfig(): ?array
    {
        return $this->get('/api/v3/config/host');
    }

    public function updateHostConfig(array $d): ?array
    {
        return $this->request('PUT', "/api/v3/config/host/{$d['id']}", [], $d);
    }

    // ── UI Configuration ──────────────────────────────────────────────────────

    public function getUiConfig(): ?array
    {
        return $this->get('/api/v3/config/ui');
    }

    public function updateUiConfig(array $d): array
    {
        return $this->requestWithError('PUT', "/api/v3/config/ui/{$d['id']}", $d);
    }

    // ── Indexer Configuration ──────────────────────────────────────────────────

    public function getIndexerConfig(): ?array
    {
        return $this->get('/api/v3/config/indexer');
    }

    public function updateIndexerConfig(array $d): ?array
    {
        return $this->request('PUT', "/api/v3/config/indexer/{$d['id']}", [], $d);
    }

    // ── Download Client Configuration ──────────────────────────────────────────

    public function getDownloadClientConfig(): ?array
    {
        return $this->get('/api/v3/config/downloadclient');
    }

    public function updateDownloadClientConfig(array $d): ?array
    {
        return $this->request('PUT', "/api/v3/config/downloadclient/{$d['id']}", [], $d);
    }

    // ── Import List Configuration ──────────────────────────────────────────────

    public function getImportListConfig(): ?array
    {
        return $this->get('/api/v3/config/importlist');
    }

    public function updateImportListConfig(array $d): ?array
    {
        return $this->request('PUT', "/api/v3/config/importlist/{$d['id']}", [], $d);
    }

    // ── Download Clients CRUD ─────────────────────────────────────────────────

    public function createDownloadClient(array $d): array
    {
        return $this->requestWithError('POST', '/api/v3/downloadclient', $d);
    }

    public function updateDownloadClient(int $id, array $d): array
    {
        return $this->requestWithError('PUT', "/api/v3/downloadclient/{$id}", $d);
    }

    public function deleteDownloadClient(int $id): bool
    {
        return $this->delete("/api/v3/downloadclient/{$id}");
    }

    public function testDownloadClient(array $d): array
    {
        return $this->requestWithError('POST', '/api/v3/downloadclient/test', $d);
    }

    // ── Indexers CRUD ─────────────────────────────────────────────────────────

    public function createIndexer(array $d): array
    {
        return $this->requestWithError('POST', '/api/v3/indexer', $d);
    }

    public function updateIndexer(int $id, array $d): array
    {
        return $this->requestWithError('PUT', "/api/v3/indexer/{$id}", $d);
    }

    public function deleteIndexer(int $id): bool
    {
        return $this->delete("/api/v3/indexer/{$id}");
    }

    public function testIndexer(array $d): array
    {
        return $this->requestWithError('POST', '/api/v3/indexer/test', $d);
    }

    public function getIndexerSchema(): array
    {
        return $this->get('/api/v3/indexer/schema') ?? [];
    }

    // ── Import Lists CRUD ─────────────────────────────────────────────────────

    public function createImportList(array $d): array
    {
        return $this->requestWithError('POST', '/api/v3/importlist', $d);
    }

    public function updateImportList(int $id, array $d): array
    {
        return $this->requestWithError('PUT', "/api/v3/importlist/{$id}", $d);
    }

    public function deleteImportList(int $id): bool
    {
        return $this->delete("/api/v3/importlist/{$id}");
    }

    // ── Tags CRUD ─────────────────────────────────────────────────────────────

    public function updateTag(int $id, string $label): ?array
    {
        return $this->request('PUT', "/api/v3/tag/{$id}", [], ['id' => $id, 'label' => $label]);
    }

    // ── Languages ─────────────────────────────────────────────────────────────

    public function getLanguages(): array
    {
        return $this->get('/api/v3/language') ?? [];
    }

    // ── Movie Bulk / Editor ───────────────────────────────────────────────────

    public function bulkUpdateMovies(array $movieIds, array $changes): bool
    {
        return $this->request('PUT', '/api/v3/movie/editor', [], array_merge(['movieIds' => $movieIds], $changes)) !== null;
    }

    public function bulkDeleteMovies(array $ids, bool $deleteFiles = false, bool $addExclusion = false): bool
    {
        return $this->deleteWithBody('/api/v3/movie/bulk', ['movieIds' => $ids, 'deleteFiles' => $deleteFiles, 'addExclusion' => $addExclusion]);
    }

    // ── MovieFile Update ──────────────────────────────────────────────────────

    public function getMovieFile(int $fileId): ?array
    {
        return $this->get("/api/v3/moviefile/{$fileId}");
    }

    public function updateMovieFile(int $fileId, array $data): ?array
    {
        return $this->request('PUT', "/api/v3/moviefile/{$fileId}", [], $data);
    }

    public function bulkDeleteMovieFiles(array $ids): bool
    {
        return $this->deleteWithBody('/api/v3/moviefile/bulk', ['ids' => $ids]);
    }

    // ── History since / failed ────────────────────────────────────────────────

    public function getHistorySince(\DateTimeInterface $date, string $eventType = ''): array
    {
        $params = ['date' => $date->format('Y-m-d\TH:i:s\Z')];
        if ($eventType !== '') $params['eventType'] = $eventType;
        return $this->get('/api/v3/history/since', $params) ?? [];
    }

    public function markHistoryAsFailed(int $historyId): bool
    {
        return $this->request('POST', "/api/v3/history/failed/{$historyId}", [], []) !== null;
    }

    // ── Collection Bulk ───────────────────────────────────────────────────────

    public function bulkUpdateCollections(array $collectionIds, bool $monitored): bool
    {
        return $this->request('PUT', '/api/v3/collection', [], ['collectionIds' => $collectionIds, 'monitored' => $monitored]) !== null;
    }

    // ── Command Cancel ────────────────────────────────────────────────────────

    public function cancelCommand(int $cmdId): bool
    {
        return $this->delete("/api/v3/command/{$cmdId}");
    }

    // ── CustomFilter ──────────────────────────────────────────────────────────

    public function getCustomFilters(): array
    {
        return $this->get('/api/v3/customfilter') ?? [];
    }

    public function createCustomFilter(array $d): ?array
    {
        return $this->request('POST', '/api/v3/customfilter', [], $d);
    }

    public function updateCustomFilter(int $id, array $d): ?array
    {
        return $this->request('PUT', "/api/v3/customfilter/{$id}", [], $d);
    }

    public function deleteCustomFilter(int $id): bool
    {
        return $this->delete("/api/v3/customfilter/{$id}");
    }

    // ── FileSystem ────────────────────────────────────────────────────────────

    public function browseFileSystem(string $path = ''): ?array
    {
        $params = $path !== '' ? ['path' => $path] : [];
        return $this->get('/api/v3/filesystem', $params);
    }

    public function getFilesystem(string $path, bool $includeFiles = false): array
    {
        return $this->get('/api/v3/filesystem', [
            'path'         => $path,
            'includeFiles' => $includeFiles ? 'true' : 'false',
        ]) ?? [];
    }

    public function getFileSystemType(string $path): ?array
    {
        return $this->get('/api/v3/filesystem/type', ['path' => $path]);
    }

    // ── IndexerFlag ───────────────────────────────────────────────────────────

    public function getIndexerFlags(): array
    {
        return $this->get('/api/v3/indexerflag') ?? [];
    }

    // ── Localization ──────────────────────────────────────────────────────────

    public function getLocalization(): ?array
    {
        return $this->get('/api/v3/localization');
    }

    // ── LogFile ───────────────────────────────────────────────────────────────

    public function getLogFiles(): array
    {
        return $this->get('/api/v3/log/file') ?? [];
    }

    // ── Parse ─────────────────────────────────────────────────────────────────

    public function parseTitle(string $title): ?array
    {
        return $this->get('/api/v3/parse', ['title' => $title]);
    }

    // ── QualityDefinition Update ──────────────────────────────────────────────

    public function updateQualityDefinition(int $id, array $d): ?array
    {
        return $this->request('PUT', "/api/v3/qualitydefinition/{$id}", [], $d);
    }

    public function bulkUpdateQualityDefinitions(array $definitions): bool
    {
        return $this->request('PUT', '/api/v3/qualitydefinition/update', [], $definitions) !== null;
    }

    // ── RootFolder CRUD ───────────────────────────────────────────────────────

    public function addRootFolder(string $path): ?array
    {
        return $this->request('POST', '/api/v3/rootfolder', [], ['path' => $path]);
    }

    public function deleteRootFolder(int $id): bool
    {
        return $this->delete("/api/v3/rootfolder/{$id}");
    }

    // ── Release Push ──────────────────────────────────────────────────────────

    public function pushRelease(array $data): ?array
    {
        return $this->request('POST', '/api/v3/release/push', [], $data);
    }

    // ── System Task ───────────────────────────────────────────────────────────

    public function getSystemTasks(): array
    {
        return $this->get('/api/v3/system/task') ?? [];
    }

    // ── ImportListExclusion Update ────────────────────────────────────────────

    public function updateImportListExclusion(int $id, int $tmdbId, string $movieTitle, int $year): ?array
    {
        return $this->request('PUT', "/api/v3/exclusions/{$id}", [], [
            'id'         => $id,
            'tmdbId'     => $tmdbId,
            'movieTitle' => $movieTitle,
            'year'       => $year,
        ]);
    }

    // ── ImportList Movies Add ─────────────────────────────────────────────────

    public function addImportListMovies(array $movies): bool
    {
        return $this->request('POST', '/api/v3/importlist/movie', [], $movies) !== null;
    }

    // ── DelayProfile Reorder ──────────────────────────────────────────────────

    public function reorderDelayProfile(int $id, int $afterId): bool
    {
        return $this->request('PUT', "/api/v3/delayprofile/reorder/{$id}", ['afterId' => $afterId], []) !== null;
    }

    // ── Notification Schema / TestAll ─────────────────────────────────────────

    public function getNotificationSchema(): array
    {
        return $this->get('/api/v3/notification/schema') ?? [];
    }

    public function createNotificationWithError(array $d): array
    {
        return $this->requestWithError('POST', '/api/v3/notification', $d);
    }

    public function updateNotificationWithError(int $id, array $d): array
    {
        return $this->requestWithError('PUT', "/api/v3/notification/{$id}", $d);
    }

    public function testNotificationPayload(array $d): array
    {
        return $this->requestWithError('POST', '/api/v3/notification/test', $d);
    }

    public function testAllNotifications(): bool
    {
        return $this->request('POST', '/api/v3/notification/testall', [], []) !== null;
    }

    // ── Naming / Media Management Config ─────────────────────────────────

    public function getNamingConfig(): ?array
    {
        return $this->get('/api/v3/config/naming');
    }

    public function getNamingExamples(): ?array
    {
        return $this->get('/api/v3/config/naming/examples');
    }

    public function updateNamingConfig(array $d): array
    {
        return $this->requestWithError('PUT', '/api/v3/config/naming', $d);
    }

    public function getMediaManagementConfig(): ?array
    {
        return $this->get('/api/v3/config/mediamanagement');
    }

    public function updateMediaManagementConfig(array $d): array
    {
        return $this->requestWithError('PUT', '/api/v3/config/mediamanagement', $d);
    }

    // ── Queue Details ─────────────────────────────────────────────────────────

    public function getQueueDetails(): array
    {
        return $this->get('/api/v3/queue/details', ['includeMovie' => 'true']) ?? [];
    }

    public function bulkDeleteQueue(array $ids, bool $removeFromClient = false, bool $blocklist = false): bool
    {
        return $this->deleteWithBody('/api/v3/queue/bulk', [
            'ids'              => $ids,
            'removeFromClient' => $removeFromClient,
            'blocklist'        => $blocklist,
        ]);
    }

    // ── Tag Detail ────────────────────────────────────────────────────────────

    public function getTagsDetail(): array
    {
        return $this->get('/api/v3/tag/detail') ?? [];
    }

    // ── Manual Import (preview) ───────────────────────────────────────────────

    public function getManualImportPreview(string $folder, bool $filterExistingFiles = true): array
    {
        return $this->get('/api/v3/manualimport', [
            'folder'              => $folder,
            'filterExistingFiles' => $filterExistingFiles ? 'true' : 'false',
        ]) ?? [];
    }

    // ── Remote Path Mappings ──────────────────────────────────────────────────

    public function getRemotePathMappings(): array
    {
        return $this->get('/api/v3/remotepathmapping') ?? [];
    }

    public function createRemotePathMapping(array $d): ?array
    {
        return $this->request('POST', '/api/v3/remotepathmapping', [], $d);
    }

    public function updateRemotePathMapping(int $id, array $d): ?array
    {
        return $this->request('PUT', "/api/v3/remotepathmapping/{$id}", [], $d);
    }

    public function deleteRemotePathMapping(int $id): bool
    {
        return $this->delete("/api/v3/remotepathmapping/{$id}");
    }

    // ── Metadata ──────────────────────────────────────────────────────────────

    public function getMetadata(): array
    {
        return $this->get('/api/v3/metadata') ?? [];
    }

    public function createMetadata(array $d): ?array
    {
        return $this->request('POST', '/api/v3/metadata', [], $d);
    }

    public function updateMetadata(int $id, array $d): ?array
    {
        return $this->request('PUT', "/api/v3/metadata/{$id}", [], $d);
    }

    public function deleteMetadata(int $id): bool
    {
        return $this->delete("/api/v3/metadata/{$id}");
    }

    public function getMetadataSchema(): array
    {
        return $this->get('/api/v3/metadata/schema') ?? [];
    }

    // ── Metadata Config ──────────────────────────────────────────────────────

    public function getMetadataConfig(): ?array
    {
        return $this->get('/api/v3/config/metadata');
    }

    public function updateMetadataConfig(array $d): array
    {
        return $this->requestWithError('PUT', "/api/v3/config/metadata/{$d['id']}", $d);
    }

    // ── Normalization ─────────────────────────────────────────────────────────

    private function normalizeMovie(array $m): array
    {
        $sizeBytes = (float) ($m['sizeOnDisk'] ?? 0);
        $quality   = $m['movieFile']['quality']['quality']['name'] ?? null;
        $mf        = $m['movieFile'] ?? null;

        return [
            'id'               => $m['id'] ?? null,
            'tmdbId'           => $m['tmdbId'] ?? null,
            'imdbId'           => $m['imdbId'] ?? null,
            'title'            => $m['title'] ?? '—',
            'originalTitle'    => $m['originalTitle'] ?? null,
            'sortTitle'        => strtolower($m['sortTitle'] ?? $m['title'] ?? ''),
            'year'             => $m['year'] ?? null,
            'overview'         => $m['overview'] ?? null,
            'poster'           => $this->imageUrl($m, 'poster'),
            'fanart'           => $this->imageUrl($m, 'fanart'),
            'certification'    => $m['certification'] ?? null,
            'studio'           => $m['studio'] ?? null,
            'path'             => $m['path'] ?? null,
            'status'           => $m['status'] ?? 'unknown',
            'hasFile'          => (bool) ($m['hasFile'] ?? false),
            'monitored'        => (bool) ($m['monitored'] ?? false),
            'quality'          => $quality,
            'qualityProfileId'    => $m['qualityProfileId'] ?? null,
            'minimumAvailability' => $m['minimumAvailability'] ?? 'released',
            'alternateTitles'     => array_map(fn($t) => [
                'title'      => $t['title'] ?? '',
                'sourceType' => $t['sourceType'] ?? '',
                'sourceId'   => $t['sourceId'] ?? null,
            ], $m['alternateTitles'] ?? []),
            'rootFolderPath'      => $m['rootFolderPath'] ?? null,
            'tags'             => $m['tags'] ?? [],
            'added'            => $m['added'] ?? null,
            'language'         => isset($mf['languages'][0]) ? $mf['languages'][0]['name'] ?? null : null,
            'languages'        => array_map(fn($l) => $l['name'] ?? '—', $mf['languages'] ?? []),
            'collection'       => isset($m['collection']) ? [
                'id'    => $m['collection']['tmdbId'] ?? null,
                'title' => $m['collection']['title'] ?? null,
            ] : null,
            'sizeOnDisk'  => (int) $sizeBytes,
            'sizeGb'      => $sizeBytes > 0 ? round($sizeBytes / 1073741824, 2) : null,
            'addedAt'     => isset($m['added']) ? new \DateTimeImmutable($m['added']) : null,
            'inCinemasAt' => isset($m['inCinemas']) ? new \DateTimeImmutable($m['inCinemas']) : null,
            'digitalAt'   => isset($m['digitalRelease']) ? new \DateTimeImmutable($m['digitalRelease']) : null,
            'physicalAt'  => isset($m['physicalRelease']) ? new \DateTimeImmutable($m['physicalRelease']) : null,
            'genres'      => $m['genres'] ?? [],
            'runtime'     => $m['runtime'] ?? null,
            'ratings'     => $m['ratings']['tmdb']['value'] ?? null,
            'ratingsImdb' => $m['ratings']['imdb']['value'] ?? null,
            'ratingsImdbVotes' => $m['ratings']['imdb']['votes'] ?? null,
            'ratingsTmdb' => $m['ratings']['tmdb']['value'] ?? null,
            'ratingsTmdbVotes' => $m['ratings']['tmdb']['votes'] ?? null,
            'movieFile'   => $mf ? [
                'size'              => $mf['size'] ?? null,
                'relativePath'      => $mf['relativePath'] ?? null,
                'quality'           => $mf['quality']['quality']['name'] ?? null,
                'releaseGroup'      => $mf['releaseGroup'] ?? null,
                'customFormats'     => array_map(fn($cf) => $cf['name'] ?? '?', $mf['customFormats'] ?? []),
                'customFormatScore' => $mf['customFormatScore'] ?? 0,
                'languages'         => array_map(fn($l) => $l['name'] ?? '—', $mf['languages'] ?? []),
                'mediaInfo'    => isset($mf['mediaInfo']) ? [
                    'videoCodec'        => $mf['mediaInfo']['videoCodec'] ?? null,
                    'videoBitrate'      => $mf['mediaInfo']['videoBitrate'] ?? null,
                    'videoBitDepth'     => $mf['mediaInfo']['videoBitDepth'] ?? null,
                    'videoFps'          => $mf['mediaInfo']['videoFps'] ?? null,
                    'videoDynamicRange' => $mf['mediaInfo']['videoDynamicRange'] ?? null,
                    'resolution'        => $mf['mediaInfo']['resolution'] ?? null,
                    'runTime'           => $mf['mediaInfo']['runTime'] ?? null,
                    'audioCodec'        => $mf['mediaInfo']['audioCodec'] ?? null,
                    'audioBitrate'      => $mf['mediaInfo']['audioBitrate'] ?? null,
                    'audioChannels'     => $mf['mediaInfo']['audioChannels'] ?? null,
                    'subtitles'         => $mf['mediaInfo']['subtitles'] ?? null,
                ] : null,
            ] : null,
        ];
    }

    private function imageUrl(array $m, string $coverType): ?string
    {
        foreach ($m['images'] ?? [] as $img) {
            if ($img['coverType'] === $coverType) {
                return $img['remoteUrl'] ?? null;
            }
        }
        return null;
    }

    // ── HTTP ──────────────────────────────────────────────────────────────────

    private function get(string $path, array $params = []): ?array
    {
        if ($this->health->isDown(self::SERVICE_KEY, $this->instance?->getSlug())) {
            $this->serviceUnavailable = true;
            return null;
        }
        if ($this->serviceUnavailable) {
            return null;
        }
        $this->lastError = null;
        $this->ensureConfig();
        $url = rtrim($this->baseUrl, '/') . $path;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS, // SSRF guard
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, // (block file:// gopher:// ...)
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_NOSIGNAL       => 1,
            CURLOPT_HTTPHEADER     => ["X-Api-Key: {$this->apiKey}", 'Accept: application/json'],
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err || $code !== 200) {
            $this->logger->warning("RadarrClient GET {$path} → HTTP {$code} {$err}");
            $this->recordError('GET', $path, (int) $code, is_string($body) ? $body : '', $err);
            if ($err !== '' || (int) $code === 0) {
                $this->serviceUnavailable = true;
                $this->health->markDown(self::SERVICE_KEY, $this->instance?->getSlug());
            }
            return null;
        }

        $this->health->clear(self::SERVICE_KEY, $this->instance?->getSlug());
        return json_decode($body, true);
    }

    private function put(string $path, array $body): bool
    {
        return $this->request('PUT', $path, [], $body) !== null;
    }

    private function delete(string $path, array $params = []): bool
    {
        if ($this->health->isDown(self::SERVICE_KEY, $this->instance?->getSlug())) {
            $this->serviceUnavailable = true;
            return false;
        }
        if ($this->serviceUnavailable) {
            return false;
        }
        $this->lastError = null;
        $this->ensureConfig();
        $url = rtrim($this->baseUrl, '/') . $path;
        if ($params) $url .= '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS, // SSRF guard
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, // (block file:// gopher:// ...)
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_NOSIGNAL       => 1,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_HTTPHEADER     => ["X-Api-Key: {$this->apiKey}"],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($code < 200 || $code >= 300) {
            $this->logger->warning("RadarrClient DELETE {$path} → HTTP {$code}");
            $this->recordError('DELETE', $path, (int) $code, is_string($resp) ? $resp : '', $err);
            if ($err !== '' || (int) $code === 0) {
                $this->serviceUnavailable = true;
                $this->health->markDown(self::SERVICE_KEY, $this->instance?->getSlug());
            }
            return false;
        }
        $this->health->clear(self::SERVICE_KEY, $this->instance?->getSlug());
        return true;
    }

    private function deleteWithBody(string $path, array $body): bool
    {
        if ($this->health->isDown(self::SERVICE_KEY, $this->instance?->getSlug())) {
            $this->serviceUnavailable = true;
            return false;
        }
        if ($this->serviceUnavailable) {
            return false;
        }
        $this->lastError = null;
        $this->ensureConfig();
        $url = rtrim($this->baseUrl, '/') . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS, // SSRF guard
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, // (block file:// gopher:// ...)
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_NOSIGNAL       => 1,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => ["X-Api-Key: {$this->apiKey}", 'Content-Type: application/json'],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($code < 200 || $code >= 300) {
            $this->logger->warning("RadarrClient DELETE (body) {$path} → HTTP {$code}");
            $this->recordError('DELETE', $path, (int) $code, is_string($resp) ? $resp : '', $err);
            if ($err !== '' || (int) $code === 0) {
                $this->serviceUnavailable = true;
                $this->health->markDown(self::SERVICE_KEY, $this->instance?->getSlug());
            }
            return false;
        }
        $this->health->clear(self::SERVICE_KEY, $this->instance?->getSlug());
        return true;
    }

    private function request(string $method, string $path, array $params, array $body): ?array
    {
        if ($this->health->isDown(self::SERVICE_KEY, $this->instance?->getSlug())) {
            $this->serviceUnavailable = true;
            return null;
        }
        if ($this->serviceUnavailable) {
            return null;
        }
        $this->lastError = null;
        $this->ensureConfig();
        $url = rtrim($this->baseUrl, '/') . $path;
        if ($params) $url .= '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS, // SSRF guard
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, // (block file:// gopher:// ...)
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_NOSIGNAL       => 1,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => ["X-Api-Key: {$this->apiKey}", 'Content-Type: application/json', 'Accept: application/json'],
        ]);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($code < 200 || $code >= 300) {
            $this->logger->warning("RadarrClient {$method} {$path} → HTTP {$code}");
            $this->recordError($method, $path, (int) $code, is_string($resp) ? $resp : '', $err);
            if ($err !== '' || (int) $code === 0) {
                $this->serviceUnavailable = true;
                $this->health->markDown(self::SERVICE_KEY, $this->instance?->getSlug());
            }
            return null;
        }

        $this->health->clear(self::SERVICE_KEY, $this->instance?->getSlug());
        // Some Radarr endpoints return bare JSON strings (e.g. "OK") which
        // would violate the ?array return contract. Coerce non-array decode
        // results to [] so the caller's behaviour stays consistent.
        $decoded = json_decode($resp ?: '{}', true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Like request() but always returns an array with 'ok' + Radarr error details.
     */
    private function requestWithError(string $method, string $path, array $body): array
    {
        if ($this->health->isDown(self::SERVICE_KEY, $this->instance?->getSlug())) {
            $this->serviceUnavailable = true;
            return ['ok' => false, 'error' => 'Service unavailable (circuit breaker open)'];
        }
        if ($this->serviceUnavailable) {
            return ['ok' => false, 'error' => 'Service unavailable (circuit breaker open)'];
        }
        $this->lastError = null;
        $this->ensureConfig();
        $url = rtrim($this->baseUrl, '/') . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS, // SSRF guard
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, // (block file:// gopher:// ...)
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => ["X-Api-Key: {$this->apiKey}", 'Content-Type: application/json', 'Accept: application/json'],
        ]);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            $this->recordError($method, $path, (int) $code, is_string($resp) ? $resp : '', $err);
            $this->serviceUnavailable = true;
            $this->health->markDown(self::SERVICE_KEY, $this->instance?->getSlug());
            return ['ok' => false, 'error' => "Network error: {$err}"];
        }
        if ((int) $code === 0) {
            $this->recordError($method, $path, 0, is_string($resp) ? $resp : '', $err);
            $this->serviceUnavailable = true;
            $this->health->markDown(self::SERVICE_KEY, $this->instance?->getSlug());
            return ['ok' => false, 'error' => 'Network error: no HTTP response'];
        }

        $decoded = json_decode($resp ?: '{}', true);
        $data    = is_array($decoded) ? $decoded : [];

        if ($code < 200 || $code >= 300) {
            $this->logger->warning("RadarrClient {$method} {$path} → HTTP {$code}", ['response' => self::sanitizeLogBody($resp)]);
            $this->recordError($method, $path, (int) $code, is_string($resp) ? $resp : '', $err);
            // Radarr may return either a {message} object or an array [{propertyName, errorMessage}]
            if (isset($data[0]['errorMessage'])) {
                $messages = array_map(fn($e) => ($e['propertyName'] ?? '') . ' : ' . ($e['errorMessage'] ?? '?'), $data);
                $msg = implode(' | ', $messages);
            } else {
                $msg = $data['message'] ?? "Radarr error (HTTP {$code})";
            }
            return ['ok' => false, 'error' => $msg, 'details' => $data];
        }

        $this->health->clear(self::SERVICE_KEY, $this->instance?->getSlug());
        return ['ok' => true, 'data' => $data];
    }
}
