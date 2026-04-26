<?php

namespace App\Service\Media;

use App\Service\ConfigService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

class JellyseerrClient implements ResetInterface
{
    private const SERVICE = 'Jellyseerr';
    private const SERVICE_KEY = 'jellyseerr';

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
            $this->baseUrl = $this->config->require('jellyseerr_url', self::SERVICE);
            $this->apiKey  = $this->config->require('jellyseerr_api_key', self::SERVICE);
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

    /**
     * Light ping — true if the API responds and accepts the key.
     * /api/v1/settings/about is guarded by admin auth (unlike /status).
     */
    public function ping(): bool
    {
        try {
            return $this->getAbout() !== null;
        } catch (\Throwable $e) {
            $this->logger->warning('Jellyseerr ping failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return false;
        }
    }

    // ── Requests ─────────────────────────────────────────────────────────────

    public function getRequests(int $take = 20, int $skip = 0, ?string $filter = null, ?string $sort = null, ?string $requestedBy = null): array
    {
        $params = ['take' => $take, 'skip' => $skip];
        if ($filter)      $params['filter'] = $filter;
        if ($sort)        $params['sort']   = $sort;
        if ($requestedBy) $params['requestedBy'] = $requestedBy;

        return $this->get('/api/v1/request', $params) ?? ['pageInfo' => [], 'results' => []];
    }

    public function getRequest(int $id): ?array
    {
        return $this->get("/api/v1/request/{$id}");
    }

    public function getRequestCount(): array
    {
        return $this->get('/api/v1/request/count') ?? [];
    }

    public function approveRequest(int $id): bool
    {
        return $this->request('POST', "/api/v1/request/{$id}/approve", [], []) !== null;
    }

    public function declineRequest(int $id): bool
    {
        return $this->request('POST', "/api/v1/request/{$id}/decline", [], []) !== null;
    }

    public function deleteRequest(int $id): bool
    {
        return $this->delete("/api/v1/request/{$id}");
    }

    public function retryRequest(int $id): bool
    {
        return $this->request('POST', "/api/v1/request/{$id}/retry", [], []) !== null;
    }

    public function updateRequest(int $id, array $data): ?array
    {
        return $this->request('PUT', "/api/v1/request/{$id}", [], $data);
    }

    // ── Services (quality profiles, root folders) ────────────────────────────

    public function getServiceRadarr(int $serverId = 0): ?array
    {
        return $this->get("/api/v1/service/radarr/{$serverId}");
    }

    public function getServiceSonarr(int $serverId = 0): ?array
    {
        return $this->get("/api/v1/service/sonarr/{$serverId}");
    }

    // ── Users ────────────────────────────────────────────────────────────────

    public function getUsers(int $take = 20, int $skip = 0, ?string $sort = null): array
    {
        $params = ['take' => $take, 'skip' => $skip];
        if ($sort) $params['sort'] = $sort;

        return $this->get('/api/v1/user', $params) ?? ['pageInfo' => [], 'results' => []];
    }

    public function getUser(int $id): ?array
    {
        return $this->get("/api/v1/user/{$id}");
    }

    public function getUserRequests(int $userId, int $take = 20, int $skip = 0): array
    {
        return $this->get("/api/v1/user/{$userId}/requests", ['take' => $take, 'skip' => $skip]) ?? ['pageInfo' => [], 'results' => []];
    }

    public function getUserQuota(int $userId): ?array
    {
        return $this->get("/api/v1/user/{$userId}/quota");
    }

    public function updateUser(int $id, array $data): ?array
    {
        return $this->request('PUT', "/api/v1/user/{$id}", [], $data);
    }

    public function deleteUser(int $id): bool
    {
        return $this->delete("/api/v1/user/{$id}");
    }

    public function updateUserPermissions(int $userId, int $permissions): ?array
    {
        return $this->request('POST', "/api/v1/user/{$userId}/settings/permissions", [], ['permissions' => $permissions]);
    }

    public function getUserSettingsMain(int $userId): ?array
    {
        return $this->get("/api/v1/user/{$userId}/settings/main");
    }

    public function updateUserSettings(int $userId, array $data): ?array
    {
        return $this->request('POST', "/api/v1/user/{$userId}/settings/main", [], $data);
    }

    public function getUserSettingsNotifications(int $userId): ?array
    {
        return $this->get("/api/v1/user/{$userId}/settings/notifications");
    }

    public function updateUserPassword(int $userId, string $newPassword): bool
    {
        return $this->request('POST', "/api/v1/user/{$userId}/settings/password", [], ['newPassword' => $newPassword]) !== null;
    }

    public function updateUserNotifications(int $userId, array $data): ?array
    {
        return $this->request('POST', "/api/v1/user/{$userId}/settings/notifications", [], $data);
    }

    public function createLocalUser(string $email, string $password): ?array
    {
        return $this->request('POST', '/api/v1/user', [], ['email' => $email, 'password' => $password]);
    }

    public function getJellyfinUsers(): array
    {
        return $this->get('/api/v1/settings/jellyfin/users') ?? [];
    }

    public function importJellyfinUsers(array $jellyfinUserIds): ?array
    {
        return $this->request('POST', '/api/v1/user/import-from-jellyfin', [], ['jellyfinUserIds' => $jellyfinUserIds]);
    }

    // ── Media ────────────────────────────────────────────────────────────────

    public function getMedia(int $take = 20, int $skip = 0, ?string $filter = null, ?string $sort = null): array
    {
        $params = ['take' => $take, 'skip' => $skip];
        if ($filter) $params['filter'] = $filter;
        if ($sort)   $params['sort']   = $sort;

        return $this->get('/api/v1/media', $params) ?? ['pageInfo' => [], 'results' => []];
    }

    public function deleteMedia(int $id): bool
    {
        return $this->delete("/api/v1/media/{$id}");
    }

    // ── Issues ───────────────────────────────────────────────────────────────

    public function getIssues(int $take = 20, int $skip = 0, ?string $filter = null, ?string $sort = null): array
    {
        $params = ['take' => $take, 'skip' => $skip];
        if ($filter) $params['filter'] = $filter;
        if ($sort)   $params['sort']   = $sort;

        return $this->get('/api/v1/issue', $params) ?? ['pageInfo' => [], 'results' => []];
    }

    public function getIssue(int $id): ?array
    {
        return $this->get("/api/v1/issue/{$id}");
    }

    public function getIssueCount(): array
    {
        return $this->get('/api/v1/issue/count') ?? [];
    }

    public function createIssueComment(int $issueId, string $message): ?array
    {
        return $this->request('POST', "/api/v1/issue/{$issueId}/comment", [], ['message' => $message]);
    }

    public function updateIssueStatus(int $issueId, string $status): ?array
    {
        if (!in_array($status, ['open', 'resolved'], true)) {
            $this->logger->warning("JellyseerrClient updateIssueStatus — invalid status: {$status}");
            return null;
        }
        return $this->request('POST', "/api/v1/issue/{$issueId}/{$status}", [], []);
    }

    public function deleteIssue(int $issueId): bool
    {
        return $this->delete("/api/v1/issue/{$issueId}");
    }

    // ── Blocklist ────────────────────────────────────────────────────────────

    public function getBlocklist(int $take = 20, int $skip = 0): array
    {
        return $this->get('/api/v1/blocklist', ['take' => $take, 'skip' => $skip]) ?? ['pageInfo' => [], 'results' => []];
    }

    // ── Status & Info ────────────────────────────────────────────────────────

    public function getStatus(): ?array
    {
        return $this->get('/api/v1/status');
    }

    public function getAbout(): ?array
    {
        return $this->get('/api/v1/settings/about');
    }

    // ── Settings ─────────────────────────────────────────────────────────────

    public function getMainSettings(): ?array
    {
        return $this->get('/api/v1/settings/main');
    }

    public function updateMainSettings(array $data): ?array
    {
        return $this->request('POST', '/api/v1/settings/main', [], $data);
    }

    public function getJellyfinSettings(): ?array
    {
        return $this->get('/api/v1/settings/jellyfin');
    }

    public function updateJellyfinSettings(array $data): ?array
    {
        return $this->request('POST', '/api/v1/settings/jellyfin', [], $data);
    }

    public function getJellyfinLibraries(): array
    {
        return $this->get('/api/v1/settings/jellyfin/library') ?? [];
    }

    public function syncJellyfinLibraries(): bool
    {
        return $this->request('POST', '/api/v1/settings/jellyfin/sync', [], []) !== null;
    }

    public function getRadarrSettings(): array
    {
        return $this->get('/api/v1/settings/radarr') ?? [];
    }

    public function getSonarrSettings(): array
    {
        return $this->get('/api/v1/settings/sonarr') ?? [];
    }

    public function createRadarrServer(array $data): ?array
    {
        return $this->request('POST', '/api/v1/settings/radarr', [], $data);
    }

    public function createSonarrServer(array $data): ?array
    {
        return $this->request('POST', '/api/v1/settings/sonarr', [], $data);
    }

    public function deleteRadarrServer(int $id): bool
    {
        return $this->delete("/api/v1/settings/radarr/{$id}");
    }

    public function deleteSonarrServer(int $id): bool
    {
        return $this->delete("/api/v1/settings/sonarr/{$id}");
    }

    public function updateRadarrSettings(int $id, array $data): ?array
    {
        return $this->request('PUT', "/api/v1/settings/radarr/{$id}", [], $data);
    }

    public function updateSonarrSettings(int $id, array $data): ?array
    {
        return $this->request('PUT', "/api/v1/settings/sonarr/{$id}", [], $data);
    }

    public function testRadarrConnection(array $data): ?array
    {
        return $this->request('POST', '/api/v1/settings/radarr/test', [], $data);
    }

    public function testSonarrConnection(array $data): ?array
    {
        return $this->request('POST', '/api/v1/settings/sonarr/test', [], $data);
    }

    public function getNotificationSettings(string $type): ?array
    {
        return $this->get("/api/v1/settings/notifications/{$type}");
    }

    public function updateNotificationSettings(string $type, array $data): ?array
    {
        return $this->request('POST', "/api/v1/settings/notifications/{$type}", [], $data);
    }

    public function testNotification(string $type, array $data): ?array
    {
        return $this->request('POST', "/api/v1/settings/notifications/{$type}/test", [], $data);
    }

    // ── Logs ─────────────────────────────────────────────────────────────────

    public function getLogs(int $take = 50, int $skip = 0, ?string $filter = null): array
    {
        $params = ['take' => $take, 'skip' => $skip];
        if ($filter) $params['filter'] = $filter;

        return $this->get('/api/v1/settings/logs', $params) ?? ['pageInfo' => [], 'results' => []];
    }

    // ── Jobs ─────────────────────────────────────────────────────────────────

    public function getJobs(): array
    {
        return $this->get('/api/v1/settings/jobs') ?? [];
    }

    public function runJob(string $jobId): ?array
    {
        return $this->request('POST', "/api/v1/settings/jobs/{$jobId}/run", [], []);
    }

    public function cancelJob(string $jobId): ?array
    {
        return $this->request('POST', "/api/v1/settings/jobs/{$jobId}/cancel", [], []);
    }

    public function updateJobSchedule(string $jobId, string $schedule): ?array
    {
        return $this->request('POST', "/api/v1/settings/jobs/{$jobId}/schedule", [], ['schedule' => $schedule]);
    }

    // ── Cache ────────────────────────────────────────────────────────────────

    public function getCacheStats(): ?array
    {
        return $this->get('/api/v1/settings/cache');
    }

    public function flushCache(): bool
    {
        return $this->request('POST', '/api/v1/settings/cache/flush', [], []) !== null;
    }

    public function flushCacheById(string $cacheId): bool
    {
        return $this->request('POST', "/api/v1/settings/cache/{$cacheId}/flush", [], []) !== null;
    }

    // ── Override rules ──────────────────────────────────────────────────────

    public function getOverrideRules(): array
    {
        return $this->get('/api/v1/overrideRule') ?? [];
    }

    public function createOverrideRule(array $data): ?array
    {
        return $this->request('POST', '/api/v1/overrideRule', [], $data);
    }

    public function updateOverrideRule(int $id, array $data): ?array
    {
        return $this->request('PUT', "/api/v1/overrideRule/{$id}", [], $data);
    }

    public function deleteOverrideRule(int $id): bool
    {
        return $this->delete("/api/v1/overrideRule/{$id}");
    }

    // ── Metadata (genres, languages, regions) ──────────────────────────────

    public function getGenresMovie(): array
    {
        return $this->get('/api/v1/genres/movie') ?? [];
    }

    public function getGenresTv(): array
    {
        return $this->get('/api/v1/genres/tv') ?? [];
    }

    public function getLanguages(): array
    {
        return $this->get('/api/v1/languages') ?? [];
    }

    public function getRegions(): array
    {
        return $this->get('/api/v1/regions') ?? [];
    }

    // ── Search & TMDb details ───────────────────────────────────────────────

    public function searchMovie(int $tmdbId): ?array
    {
        return $this->get("/api/v1/movie/{$tmdbId}");
    }

    public function searchTv(int $tmdbId): ?array
    {
        return $this->get("/api/v1/tv/{$tmdbId}");
    }

    public function getTvSeason(int $tmdbId, int $seasonNumber): ?array
    {
        return $this->get("/api/v1/tv/{$tmdbId}/season/{$seasonNumber}");
    }

    // ── Media — actions ──────────────────────────────────────────────────────

    public function updateMediaStatus(int $mediaId, string $status): ?array
    {
        return $this->request('POST', "/api/v1/media/{$mediaId}/{$status}", [], []);
    }

    // ── GitHub Releases ───────────────────────────────────────────────────────

    public function getGitHubReleases(int $perPage = 10): array
    {
        $url = "https://api.github.com/repos/Fallenbagel/jellyseerr/releases?per_page={$perPage}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            // SSRF guard: block file://, gopher://, dict:// and other non-http(s)
            // schemes both on the initial request and on redirects.
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github+json', 'User-Agent: IH-ARGOS'],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err || $code !== 200) {
            $this->logger->warning("JellyseerrClient GitHub releases → HTTP {$code} {$err}");
            return [];
        }

        return json_decode($body, true) ?? [];
    }

    // ── HTTP ─────────────────────────────────────────────────────────────────

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
            $this->logger->warning("JellyseerrClient GET {$path} → HTTP {$code} {$err}");
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

    private function delete(string $path, array $params = []): bool
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

        if ($err || $code < 200 || $code >= 300) {
            $this->logger->warning("JellyseerrClient DELETE {$path} → HTTP {$code} {$err}");
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

    private function request(string $method, string $path, array $params, array $body): ?array
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

        if ($err || $code < 200 || $code >= 300) {
            $this->logger->warning("JellyseerrClient {$method} {$path} → HTTP {$code} {$err}");
            $this->recordError($method, $path, (int) $code, is_string($resp) ? $resp : '', $err);
            if ($err !== '' || (int) $code === 0) {
                $this->serviceUnavailable = true;
                $this->health->markDown(self::SERVICE_KEY);
            }
            return null;
        }

        $this->health->clear(self::SERVICE_KEY);
        return json_decode($resp ?: '{}', true) ?? [];
    }
}
