<?php

namespace App\Service\Media;

use App\Service\ConfigService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

class ProwlarrClient implements ResetInterface
{
    private const SERVICE = 'Prowlarr';
    private const SERVICE_KEY = 'prowlarr';

    private string $baseUrl = '';
    private string $apiKey = '';

    /** @var array{code:int, method:string, path:string, message:string}|null */
    private ?array $lastError = null;

    /**
     * Circuit breaker — once a network error / timeout occurs in this request,
     * short-circuit subsequent calls to avoid stacking 4s timeouts and tripping
     * PHP's max_execution_time. Reset between worker requests via reset().
     */
    private bool $serviceUnavailable = false;

    public function __construct(
        private readonly ConfigService $config,
        private readonly LoggerInterface $logger,
        private readonly ServiceHealthCache $health,
    ) {}

    private function ensureConfig(): void
    {
        if ($this->baseUrl === '') {
            $this->baseUrl = $this->config->require('prowlarr_url', self::SERVICE);
            $this->apiKey  = $this->config->require('prowlarr_api_key', self::SERVICE);
        }
    }

    public function reset(): void
    {
        $this->baseUrl = '';
        $this->apiKey  = '';
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

    private function extractApiErrorMessage(string $body, int $code, string $curlError): string
    {
        $body = trim($body);
        $decoded = $body !== '' ? json_decode($body, true) : null;

        if (is_array($decoded)) {
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

    private function recordError(string $method, string $path, int $code, string $body, string $curlError): void
    {
        $this->lastError = [
            'code'    => $code,
            'method'  => $method,
            'path'    => $path,
            'message' => $this->extractApiErrorMessage($body, $code, $curlError),
        ];
    }

    /** Light ping — true if the API responds and accepts the key. */
    public function ping(): bool
    {
        try {
            return $this->getSystemStatus() !== null;
        } catch (\Throwable $e) {
            $this->logger->warning('Prowlarr ping failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return false;
        }
    }

    // ── Indexers ──────────────────────────────────────────────────────────────

    public function getIndexers(): array
    {
        $data = $this->get('/api/v1/indexer');
        if ($data === null) return [];

        return array_map(fn($i) => $this->normalizeIndexer($i), $data);
    }

    public function getIndexer(int $id): ?array
    {
        $data = $this->get("/api/v1/indexer/{$id}");
        return $data ? $this->normalizeIndexer($data) : null;
    }

    public function getRawIndexer(int $id): ?array
    {
        return $this->get("/api/v1/indexer/{$id}");
    }

    public function addIndexer(array $data): ?array
    {
        return $this->request('POST', '/api/v1/indexer', [], $data);
    }

    public function addIndexerWithError(array $data): array
    {
        return $this->requestWithError('POST', '/api/v1/indexer', $data);
    }

    public function updateIndexer(int $id, array $data): ?array
    {
        return $this->request('PUT', "/api/v1/indexer/{$id}", [], $data);
    }

    public function updateIndexerWithError(int $id, array $data): array
    {
        return $this->requestWithError('PUT', "/api/v1/indexer/{$id}", $data);
    }

    public function deleteIndexer(int $id): bool
    {
        return $this->delete("/api/v1/indexer/{$id}");
    }

    public function testIndexer(array $data): array
    {
        return $this->requestWithError('POST', '/api/v1/indexer/test', $data);
    }

    public function getIndexerSchema(): array
    {
        return $this->get('/api/v1/indexer/schema') ?? [];
    }

    // ── Indexer health status ─────────────────────────────────────────────────

    public function getIndexerStatus(): array
    {
        $data = $this->get('/api/v1/indexerstatus');
        if ($data === null) return [];

        $byId = [];
        foreach ($data as $s) {
            $byId[$s['indexerId']] = [
                'disabled'       => $s['isDisabled'] ?? false,
                'lastRsssync'    => $s['lastRssSyncReason'] ?? null,
                'message'        => $s['disabledTill'] ?? null,
                'failures'       => $s['mostRecentFailure'] ?? null,
            ];
        }
        return $byId;
    }

    // ── Search ────────────────────────────────────────────────────────────────

    public function search(string $query, ?int $indexerId = null, string $type = 'search', int $limit = 100): array
    {
        $params = ['query' => $query, 'type' => $type, 'limit' => $limit];
        if ($indexerId !== null) $params['indexerIds'] = $indexerId;
        $data = $this->get('/api/v1/search', $params);
        if ($data === null) return [];

        return array_map(fn($r) => [
            'guid'        => $r['guid'] ?? null,
            'title'       => $r['title'] ?? '—',
            'size'        => $r['size'] ?? 0,
            'age'         => $r['age'] ?? 0,
            'ageHours'    => $r['ageHours'] ?? 0,
            'indexerId'   => $r['indexerId'] ?? null,
            'indexer'     => $r['indexer'] ?? '—',
            'seeders'     => $r['seeders'] ?? null,
            'leechers'    => $r['leechers'] ?? null,
            'protocol'    => $r['protocol'] ?? 'unknown',
            'imdbId'      => $r['imdbId'] ?? null,
            'tmdbId'      => $r['tmdbId'] ?? null,
            'tvdbId'      => $r['tvdbId'] ?? null,
            'categories'  => $r['categories'] ?? [],
            'downloadUrl' => $r['downloadUrl'] ?? null,
            'infoUrl'     => $r['infoUrl'] ?? null,
            'infoHash'    => $r['infoHash'] ?? null,
            'publishDate' => $r['publishDate'] ?? null,
            'indexerFlags' => $r['indexerFlags'] ?? [],
            'fileName'    => $r['fileName'] ?? null,
        ], $data);
    }

    // ── History ───────────────────────────────────────────────────────────────

    public function getRecentSearches(int $limit = 20): array
    {
        $data = $this->get('/api/v1/history', ['pageSize' => $limit, 'sortKey' => 'date', 'sortDirection' => 'descending']);
        if ($data === null || empty($data['records'])) return [];

        return array_map(fn($r) => [
            'id'          => $r['id'] ?? null,
            'date'        => $r['date'] ?? null,
            'indexerId'   => $r['indexerId'] ?? null,
            'indexer'     => $r['indexer'] ?? '—',
            'query'       => $r['data']['query'] ?? $r['sourceTitle'] ?? '—',
            'successful'  => (bool) ($r['successful'] ?? false),
            'categories'  => $r['data']['categories'] ?? [],
            'grabCount'   => (int) ($r['data']['grabCount'] ?? 0),
            'eventType'   => $r['eventType'] ?? null,
            'downloadUrl' => $r['data']['downloadUrl'] ?? null,
        ], $data['records']);
    }

    public function getIndexerHistory(int $indexerId, int $pageSize = 30): array
    {
        return $this->get('/api/v1/history', [
            'pageSize' => $pageSize, 'sortKey' => 'date', 'sortDirection' => 'descending',
            'indexerIds' => $indexerId,
        ]) ?? [];
    }

    public function getHistory(int $page = 1, int $pageSize = 50): array
    {
        return $this->get('/api/v1/history', [
            'page' => $page, 'pageSize' => $pageSize,
            'sortKey' => 'date', 'sortDirection' => 'descending',
        ]) ?? [];
    }

    // ── Applications (connected Radarr/Sonarr) ───────────────────────────────

    public function getApplications(): array
    {
        return $this->get('/api/v1/applications') ?? [];
    }

    public function getApplicationSchema(): array
    {
        return $this->get('/api/v1/applications/schema') ?? [];
    }

    public function testApplication(array $data): array
    {
        return $this->requestWithError('POST', '/api/v1/applications/test', $data);
    }

    // ── App Profiles ─────────────────────────────────────────────────────────

    public function getAppProfiles(): array
    {
        return $this->get('/api/v1/appprofile') ?? [];
    }

    public function getAppProfile(int $id): ?array
    {
        return $this->get("/api/v1/appprofile/{$id}");
    }

    public function addAppProfile(array $data): ?array
    {
        return $this->request('POST', '/api/v1/appprofile', [], $data);
    }

    public function updateAppProfile(int $id, array $data): ?array
    {
        return $this->request('PUT', "/api/v1/appprofile/{$id}", [], $data);
    }

    public function deleteAppProfile(int $id): bool
    {
        return $this->delete("/api/v1/appprofile/{$id}");
    }

    // ── Download Clients ─────────────────────────────────────────────────

    public function getDownloadClients(): array
    {
        return $this->get('/api/v1/downloadclient') ?? [];
    }

    public function getRawDownloadClient(int $id): ?array
    {
        return $this->get("/api/v1/downloadclient/{$id}");
    }

    public function getDownloadClientSchema(): array
    {
        return $this->get('/api/v1/downloadclient/schema') ?? [];
    }

    public function addDownloadClient(array $data): array
    {
        return $this->requestWithError('POST', '/api/v1/downloadclient', $data);
    }

    public function updateDownloadClient(int $id, array $data): array
    {
        return $this->requestWithError('PUT', "/api/v1/downloadclient/{$id}", $data);
    }

    public function deleteDownloadClient(int $id): bool
    {
        return $this->delete("/api/v1/downloadclient/{$id}");
    }

    public function testDownloadClient(array $data): array
    {
        return $this->requestWithError('POST', '/api/v1/downloadclient/test', $data);
    }

    // ── Notifications ────────────────────────────────────────────────────

    public function getNotifications(): array
    {
        return $this->get('/api/v1/notification') ?? [];
    }

    public function getRawNotification(int $id): ?array
    {
        return $this->get("/api/v1/notification/{$id}");
    }

    public function getNotificationSchema(): array
    {
        return $this->get('/api/v1/notification/schema') ?? [];
    }

    public function addNotification(array $data): array
    {
        return $this->requestWithError('POST', '/api/v1/notification', $data);
    }

    public function updateNotification(int $id, array $data): array
    {
        return $this->requestWithError('PUT', "/api/v1/notification/{$id}", $data);
    }

    public function deleteNotification(int $id): bool
    {
        return $this->delete("/api/v1/notification/{$id}");
    }

    public function testNotification(array $data): array
    {
        return $this->requestWithError('POST', '/api/v1/notification/test', $data);
    }

    // ── Applications CRUD ────────────────────────────────────────────────

    public function getRawApplication(int $id): ?array
    {
        return $this->get("/api/v1/applications/{$id}");
    }

    public function addApplication(array $data): array
    {
        return $this->requestWithError('POST', '/api/v1/applications', $data);
    }

    public function updateApplication(int $id, array $data): array
    {
        return $this->requestWithError('PUT', "/api/v1/applications/{$id}", $data);
    }

    public function deleteApplication(int $id): bool
    {
        return $this->delete("/api/v1/applications/{$id}");
    }

    // ── Config ───────────────────────────────────────────────────────────

    public function getGeneralConfig(): ?array
    {
        return $this->get('/api/v1/config/host');
    }

    public function updateGeneralConfig(array $data): ?array
    {
        return $this->request('PUT', '/api/v1/config/host/' . ($data['id'] ?? 1), [], $data);
    }

    public function getUiConfig(): ?array
    {
        return $this->get('/api/v1/config/ui');
    }

    public function updateUiConfig(array $data): ?array
    {
        return $this->request('PUT', '/api/v1/config/ui/' . ($data['id'] ?? 1), [], $data);
    }

    // ── System ───────────────────────────────────────────────────────────

    public function getTasks(): array
    {
        return $this->get('/api/v1/system/task') ?? [];
    }

    public function getBackups(): array
    {
        return $this->get('/api/v1/system/backup') ?? [];
    }

    public function deleteBackup(int $id): bool
    {
        return $this->delete("/api/v1/system/backup/{$id}");
    }

    public function getUpdates(): array
    {
        return $this->get('/api/v1/update') ?? [];
    }

    // ── Indexer Statistics ──────────────────────────────────────────────

    public function getIndexerStats(): array
    {
        return $this->get('/api/v1/indexerstats') ?? [];
    }

    // ── Indexer Categories (default) ─────────────────────────────────────

    public function getIndexerDefaultCategories(): array
    {
        return $this->get('/api/v1/indexer/categories') ?? [];
    }

    // ── Indexer Bulk ─────────────────────────────────────────────────────

    public function bulkUpdateIndexers(array $data): ?array
    {
        return $this->request('PUT', '/api/v1/indexer/bulk', [], $data);
    }

    public function bulkDeleteIndexers(array $ids): bool
    {
        return $this->deleteWithBody('/api/v1/indexer/bulk', ['ids' => $ids]);
    }

    public function testAllIndexers(): array
    {
        return $this->requestWithError('POST', '/api/v1/indexer/testall', []);
    }

    // ── Command Status ───────────────────────────────────────────────────

    public function getCommand(int $id): ?array
    {
        return $this->get("/api/v1/command/{$id}");
    }

    // ── Download Client Config ───────────────────────────────────────────

    public function getDownloadClientConfig(): ?array
    {
        return $this->get('/api/v1/config/downloadclient');
    }

    public function updateDownloadClientConfig(array $data): ?array
    {
        return $this->request('PUT', '/api/v1/config/downloadclient/' . ($data['id'] ?? 1), [], $data);
    }

    // ── History Since ────────────────────────────────────────────────────

    public function getHistorySince(string $date): array
    {
        return $this->get('/api/v1/history/since', ['date' => $date]) ?? [];
    }

    // ── Tag Detail ───────────────────────────────────────────────────────

    public function getTagsDetail(): array
    {
        return $this->get('/api/v1/tag/detail') ?? [];
    }

    public function getTagDetail(int $id): ?array
    {
        return $this->get("/api/v1/tag/detail/{$id}");
    }

    // ── Tags ─────────────────────────────────────────────────────────────────

    public function getTags(): array
    {
        return $this->get('/api/v1/tag') ?? [];
    }

    public function createTag(string $label): ?array
    {
        return $this->request('POST', '/api/v1/tag', [], ['label' => $label]);
    }

    public function deleteTag(int $id): bool
    {
        return $this->delete("/api/v1/tag/{$id}");
    }

    // ── Health & System ──────────────────────────────────────────────────────

    public function getHealth(): array
    {
        return $this->get('/api/v1/health') ?? [];
    }

    public function getSystemStatus(): ?array
    {
        return $this->get('/api/v1/system/status');
    }

    public function getLogs(int $page = 1, int $pageSize = 50): array
    {
        return $this->get('/api/v1/log', ['page' => $page, 'pageSize' => $pageSize, 'sortKey' => 'time', 'sortDirection' => 'descending']) ?? [];
    }

    public function getCommands(): array
    {
        return $this->get('/api/v1/command') ?? [];
    }

    public function sendCommand(string $name, array $extra = []): ?array
    {
        return $this->request('POST', '/api/v1/command', [], array_merge(['name' => $name], $extra));
    }

    // ── Stats ────────────────────────────────────────────────────────────────

    public function getStats(): array
    {
        $indexers = $this->getIndexers();
        $status   = $this->getIndexerStatus();

        $total    = count($indexers);
        $enabled  = count(array_filter($indexers, fn($i) => $i['enabled']));
        $failing  = count($status);
        $torrent  = count(array_filter($indexers, fn($i) => $i['protocol'] === 'torrent'));
        $usenet   = count(array_filter($indexers, fn($i) => $i['protocol'] === 'usenet'));

        return compact('total', 'enabled', 'failing', 'torrent', 'usenet');
    }

    // ── Normalization ────────────────────────────────────────────────────────

    private function normalizeIndexer(array $i): array
    {
        return [
            'id'             => $i['id'] ?? 0,
            'name'           => $i['name'] ?? '—',
            'definitionName' => $i['definitionName'] ?? null,
            'description'    => $i['description'] ?? null,
            'language'       => $i['language'] ?? null,
            'protocol'       => $i['protocol'] ?? 'unknown',
            'privacy'        => $i['privacy'] ?? null,
            'enabled'        => (bool) ($i['enable'] ?? false),
            'supportsRss'    => (bool) ($i['supportsRss'] ?? false),
            'supportsSearch' => (bool) ($i['supportsSearch'] ?? false),
            'appProfileId'   => $i['appProfileId'] ?? null,
            'priority'       => $i['priority'] ?? 25,
            'tags'           => $i['tags'] ?? [],
            'added'          => $i['added'] ?? null,
            'indexerUrls'    => $i['indexerUrls'] ?? [],
            'capabilities'   => $i['capabilities'] ?? [],
            'implementation' => $i['implementation'] ?? null,
            'configContract' => $i['configContract'] ?? null,
            'fields'         => $i['fields'] ?? [],
        ];
    }

    // ── HTTP ──────────────────────────────────────────────────────────────────

    private function get(string $path, array $params = []): ?array
    {
        if ($this->health->isDown(self::SERVICE_KEY)) {
            $this->serviceUnavailable = true;
            return null;
        }
        if ($this->serviceUnavailable) {
            return null;
        }
        $this->lastError = null;
        $this->ensureConfig();
        $url = rtrim($this->baseUrl, '/') . $path;
        if ($params) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
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

        if ($body === false || $code !== 200) {
            $this->logger->warning("ProwlarrClient GET {$path} → HTTP {$code} {$err}");
            $this->recordError('GET', $path, (int) $code, is_string($body) ? $body : '', $err);
            if ($err !== '' || (int) $code === 0) {
                $this->serviceUnavailable = true;
                $this->health->markDown(self::SERVICE_KEY);
            }
            return null;
        }

        $this->health->clear(self::SERVICE_KEY);
        return json_decode($body, true);
    }

    private function request(string $method, string $path, array $params = [], array $body = []): ?array
    {
        if ($this->health->isDown(self::SERVICE_KEY)) {
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
            $this->logger->warning("ProwlarrClient {$method} {$path} → HTTP {$code}");
            $this->recordError($method, $path, (int) $code, is_string($resp) ? $resp : '', $err);
            if ($err !== '' || (int) $code === 0) {
                $this->serviceUnavailable = true;
                $this->health->markDown(self::SERVICE_KEY);
            }
            return null;
        }

        $this->health->clear(self::SERVICE_KEY);
        return $resp ? json_decode($resp, true) : [];
    }

    private function requestWithError(string $method, string $path, array $body): array
    {
        if ($this->health->isDown(self::SERVICE_KEY)) {
            $this->serviceUnavailable = true;
            return ['ok' => false, 'error' => 'Service unavailable (circuit breaker open)', 'code' => 0];
        }
        if ($this->serviceUnavailable) {
            return ['ok' => false, 'error' => 'Service unavailable (circuit breaker open)', 'code' => 0];
        }
        $this->lastError = null;
        $this->ensureConfig();
        $url = rtrim($this->baseUrl, '/') . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS, // SSRF guard
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, // (block file:// gopher:// ...)
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => ["X-Api-Key: {$this->apiKey}", 'Content-Type: application/json', 'Accept: application/json'],
        ]);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $data = $resp ? json_decode($resp, true) : null;

        if ($code >= 200 && $code < 300) {
            $this->health->clear(self::SERVICE_KEY);
            return ['ok' => true, 'data' => $data];
        }

        $this->recordError($method, $path, (int) $code, is_string($resp) ? $resp : '', $err);
        if ($err !== '' || (int) $code === 0) {
            $this->serviceUnavailable = true;
            $this->health->markDown(self::SERVICE_KEY);
        }

        $error = '—';
        if (is_array($data)) {
            $error = $data['message'] ?? ($data[0]['errorMessage'] ?? json_encode($data));
        }

        return ['ok' => false, 'error' => $error, 'code' => $code];
    }

    private function deleteWithBody(string $path, array $body): bool
    {
        if ($this->health->isDown(self::SERVICE_KEY)) {
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
            $this->logger->warning("ProwlarrClient DELETE (body) {$path} → HTTP {$code}");
            $this->recordError('DELETE', $path, (int) $code, is_string($resp) ? $resp : '', $err);
            if ($err !== '' || (int) $code === 0) {
                $this->serviceUnavailable = true;
                $this->health->markDown(self::SERVICE_KEY);
            }
            return false;
        }
        $this->health->clear(self::SERVICE_KEY);
        return true;
    }

    private function delete(string $path): bool
    {
        if ($this->health->isDown(self::SERVICE_KEY)) {
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
            CURLOPT_HTTPHEADER     => ["X-Api-Key: {$this->apiKey}"],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($code < 200 || $code >= 300) {
            $this->logger->warning("ProwlarrClient DELETE {$path} → HTTP {$code}");
            $this->recordError('DELETE', $path, (int) $code, is_string($resp) ? $resp : '', $err);
            if ($err !== '' || (int) $code === 0) {
                $this->serviceUnavailable = true;
                $this->health->markDown(self::SERVICE_KEY);
            }
            return false;
        }
        $this->health->clear(self::SERVICE_KEY);
        return true;
    }
}
