<?php

namespace App\Service\Media\Usenet;

use App\Exception\ServiceNotConfiguredException;
use App\Service\ConfigService;
use App\Service\Media\ServiceHealthCache;
use Psr\Log\LoggerInterface;

/**
 * SABnzbd download client. Talks the JSON API at `{url}/api?mode=…&apikey=…`.
 *
 * Mirrors QBittorrentClient's resilience model: an in-process + cross-request
 * circuit breaker (ServiceHealthCache), SSRF-guarded curl locked to HTTP(S),
 * NOSIGNAL + explicit timeouts (critical in FrankenPHP worker mode on Alpine),
 * and a recorded lastError for the admin "Test connection" hint.
 *
 * Sizes come back from SABnzbd as MB-in-strings ("0.48") in the queue and as
 * raw bytes in history; both are normalized to bytes in {@see UsenetDownload}.
 */
class SabnzbdClient implements UsenetClientInterface
{
    private const SERVICE     = 'SABnzbd';
    private const SERVICE_KEY = 'sabnzbd';

    private string $baseUrl = '';
    private string $apiKey  = '';

    /** @var array{code:int, method:string, path:string, message:string}|null */
    private ?array $lastError = null;

    private bool $serviceUnavailable = false;

    public function __construct(
        private readonly ConfigService $config,
        private readonly LoggerInterface $logger,
        private readonly ServiceHealthCache $health,
    ) {}

    public function getKind(): string
    {
        return self::SERVICE_KEY;
    }

    private function ensureConfig(): void
    {
        if ($this->config->get(self::SERVICE_KEY . '_enabled') === '0') {
            throw new ServiceNotConfiguredException(self::SERVICE, self::SERVICE_KEY . '_enabled');
        }
        if ($this->baseUrl === '') {
            $this->baseUrl = $this->config->require('sabnzbd_url', self::SERVICE);
            $this->apiKey  = $this->config->require('sabnzbd_api_key', self::SERVICE);
        }
    }

    public function reset(): void
    {
        $this->baseUrl = '';
        $this->apiKey  = '';
        $this->lastError = null;
        $this->serviceUnavailable = false;
    }

    public function getLastError(): ?array
    {
        return $this->lastError;
    }

    /**
     * True only when SABnzbd answers AND accepts the API key. Probes mode=queue
     * — NOT mode=version, which returns 200 for any key, so a wrong key would
     * read as healthy. Goes through call(), so it honours the circuit breaker:
     * a downed SABnzbd short-circuits instead of timing out on every health
     * poll (a wrong key or a non-whitelisted host both 403 → false).
     */
    public function ping(): bool
    {
        try {
            $data = $this->call(['mode' => 'queue']);
            return is_array($data['queue'] ?? null);
        } catch (\Throwable $e) {
            $this->logger->warning('SABnzbd ping failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return false;
        }
    }

    public function getVersion(): ?string
    {
        $data = $this->call(['mode' => 'version']);
        $v = $data['version'] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    // ── Read ──────────────────────────────────────────────────────────────────

    public function getQueue(): UsenetQueueSnapshot
    {
        $data = $this->call(['mode' => 'queue']);
        $q = $data['queue'] ?? null;
        if (!is_array($q)) {
            return UsenetQueueSnapshot::empty();
        }

        $items = [];
        $active = 0;
        $queued = 0;
        foreach (($q['slots'] ?? []) as $slot) {
            if (!is_array($slot)) continue;
            $item = $this->normalizeQueueSlot($slot);
            $items[] = $item;
            if ($item->status === UsenetStatus::DOWNLOADING) $active++;
            elseif ($item->status === UsenetStatus::QUEUED)  $queued++;
        }

        return new UsenetQueueSnapshot(
            paused:          (bool) ($q['paused'] ?? false),
            speedBytes:      (int) round(((float) ($q['kbpersec'] ?? 0)) * 1024),
            speedLimitBytes: (int) ($q['speedlimit_abs'] ?? 0),
            remainingBytes:  (int) round(((float) ($q['mbleft'] ?? 0)) * 1024 * 1024),
            activeCount:     $active,
            queuedCount:     $queued,
            etaSeconds:      $this->parseClock((string) ($q['timeleft'] ?? '')),
            freeSpaceBytes:  (int) round(((float) ($q['diskspace1'] ?? 0)) * 1024 * 1024 * 1024),
            items:           $items,
        );
    }

    public function getCategories(): array
    {
        $data = $this->call(['mode' => 'get_cats']);
        $cats = $data['categories'] ?? [];
        if (!is_array($cats)) return [];
        // SABnzbd lists "*" (the default catch-all) first — drop it.
        return array_values(array_filter(
            array_map('strval', $cats),
            static fn(string $c): bool => $c !== '' && $c !== '*',
        ));
    }

    public function getHistoryPage(int $offset, int $limit): array
    {
        // SABnzbd paginates natively via start/limit and reports the grand
        // total in `noofslots`.
        $data = $this->call(['mode' => 'history', 'start' => (string) max(0, $offset), 'limit' => (string) max(1, $limit)]);
        $h = $data['history'] ?? null;
        if (!is_array($h)) return ['items' => [], 'total' => 0];

        $out = [];
        foreach (($h['slots'] ?? []) as $slot) {
            if (!is_array($slot)) continue;
            $out[] = $this->normalizeHistorySlot($slot);
        }
        return ['items' => $out, 'total' => (int) ($h['noofslots'] ?? count($out))];
    }

    // ── Actions ─────────────────────────────────────────────────────────────────

    public function pauseAll(): bool
    {
        return $this->action(['mode' => 'pause']);
    }

    public function resumeAll(): bool
    {
        return $this->action(['mode' => 'resume']);
    }

    public function pauseItem(string $id): bool
    {
        return $this->action(['mode' => 'queue', 'name' => 'pause', 'value' => $id]);
    }

    public function resumeItem(string $id): bool
    {
        return $this->action(['mode' => 'queue', 'name' => 'resume', 'value' => $id]);
    }

    /**
     * Deletes a queue job. `deleteFiles` removes the partially-downloaded
     * data too. History entries use a different mode; the Downloads page acts
     * on the live queue, so this targets the queue.
     */
    public function deleteItem(string $id, bool $deleteFiles = false): bool
    {
        return $this->action([
            'mode'      => 'queue',
            'name'      => 'delete',
            'value'     => $id,
            'del_files' => $deleteFiles ? '1' : '0',
        ]);
    }

    public function setSpeedLimitBytes(int $bytesPerSec): bool
    {
        // SABnzbd takes the limit in KB/s with a `K` suffix; 0 = unlimited.
        $kb = $bytesPerSec > 0 ? (int) round($bytesPerSec / 1024) : 0;
        $value = $kb > 0 ? $kb . 'K' : '0';
        return $this->action(['mode' => 'config', 'name' => 'speedlimit', 'value' => $value]);
    }

    public function addNzbFromUrl(string $url, ?string $category = null): bool
    {
        $params = ['mode' => 'addurl', 'name' => $url];
        if ($category !== null && $category !== '') $params['cat'] = $category;
        return $this->action($params);
    }

    public function addNzbFromFiles(array $files, ?string $category = null): bool
    {
        if ($files === []) return false;
        if ($this->isBrokenOrDown()) return false;
        $this->ensureConfig();

        $ok = true;
        foreach ($files as $file) {
            if (!isset($file['content'], $file['name'])) { $ok = false; continue; }
            $ok = $this->uploadNzb($file['content'], $file['name'], $category) && $ok;
        }
        return $ok;
    }

    // ── Normalization ────────────────────────────────────────────────────────────

    private function normalizeQueueSlot(array $s): UsenetDownload
    {
        $raw = (string) ($s['status'] ?? '');
        return new UsenetDownload(
            id:             (string) ($s['nzo_id'] ?? ''),
            name:           (string) ($s['filename'] ?? '—'),
            status:         UsenetStatus::fromSabnzbd($raw),
            rawStatus:      $raw,
            sizeBytes:      (int) round(((float) ($s['mb'] ?? 0)) * 1024 * 1024),
            remainingBytes: (int) round(((float) ($s['mbleft'] ?? 0)) * 1024 * 1024),
            percentage:     round((float) ($s['percentage'] ?? 0), 1),
            category:       (string) ($s['cat'] ?? ''),
            etaSeconds:     $this->parseClock((string) ($s['timeleft'] ?? '')),
            speedBytes:     0, // SABnzbd reports no per-item rate
            failMessage:    null,
            isHistory:      false,
            waitSeconds:    $this->parseWaitSeconds($s['labels'] ?? null),
        );
    }

    /**
     * While grabbing an NZB from a URL, SABnzbd parks a retry countdown in the
     * slot `labels` as a localized string ("WAIT 58 sec" / "PATIENTER 58 sec").
     * Pull the first integer out so the UI can show a "wait Xs" badge.
     *
     * @param mixed $labels
     */
    private function parseWaitSeconds(mixed $labels): ?int
    {
        if (!is_array($labels)) return null;
        foreach ($labels as $label) {
            // Only the retry-countdown label carries a "sec" unit ("WAIT 58 sec",
            // "PATIENTER 58 sec"). Other labels ("3 RETRIES", "DUPLICATE", …)
            // must NOT be mistaken for a wait countdown.
            if (is_string($label) && stripos($label, 'sec') !== false && preg_match('/(\d+)/', $label, $m)) {
                return (int) $m[1];
            }
        }
        return null;
    }

    private function normalizeHistorySlot(array $s): UsenetDownload
    {
        $raw    = (string) ($s['status'] ?? '');
        $status = UsenetStatus::fromSabnzbd($raw);
        $fail   = (string) ($s['fail_message'] ?? '');
        return new UsenetDownload(
            id:             (string) ($s['nzo_id'] ?? ''),
            name:           (string) ($s['name'] ?? $s['nzb_name'] ?? '—'),
            status:         $status,
            rawStatus:      $raw,
            sizeBytes:      (int) ($s['bytes'] ?? 0),
            remainingBytes: 0,
            percentage:     $status === UsenetStatus::COMPLETED ? 100.0 : 0.0,
            category:       (string) ($s['category'] ?? ''),
            etaSeconds:     null,
            speedBytes:     0,
            failMessage:    $fail !== '' ? $fail : null,
            isHistory:      true,
        );
    }

    /** "H:MM:SS" / "MM:SS" → seconds; "" or "unknown" → null. */
    private function parseClock(string $clock): ?int
    {
        $clock = trim($clock);
        if ($clock === '' || strtolower($clock) === 'unknown' || $clock === '0:00:00') {
            return null;
        }
        $parts = array_map('intval', explode(':', $clock));
        $secs = 0;
        foreach ($parts as $p) {
            $secs = $secs * 60 + $p;
        }
        return $secs > 0 ? $secs : null;
    }

    // ── HTTP ──────────────────────────────────────────────────────────────────

    private function isBrokenOrDown(): bool
    {
        if ($this->health->isDown(self::SERVICE_KEY)) {
            $this->serviceUnavailable = true;
            return true;
        }
        return $this->serviceUnavailable;
    }

    private static function isOkStatus(int $code): bool
    {
        return $code >= 200 && $code < 300;
    }

    /**
     * GET an API mode and return the decoded JSON object, or [] on failure.
     *
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    protected function call(array $params): array
    {
        if ($this->isBrokenOrDown()) return [];
        $this->lastError = null;
        $this->ensureConfig();

        $query = array_merge($params, ['output' => 'json', 'apikey' => $this->apiKey]);
        $url = rtrim($this->baseUrl, '/') . '/api?' . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS, // SSRF guard
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT  => 4,
            CURLOPT_TIMEOUT         => 8,
            CURLOPT_NOSIGNAL        => 1,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        $mode = $params['mode'] ?? '';
        if ($body === false || !self::isOkStatus($code)) {
            $this->logger->warning('SABnzbd GET error', ['mode' => $mode, 'code' => $code, 'error' => $curlErr]);
            $this->recordError('GET', $mode, $code, is_string($body) ? $body : '', $curlErr);
            if ($curlErr !== '' || $code === 0) {
                $this->serviceUnavailable = true;
                $this->health->markDown(self::SERVICE_KEY);
            }
            return [];
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            $this->recordError('GET', $mode, $code, (string) $body, '');
            return [];
        }

        // SABnzbd reports logical failures as {"status": false, "error": "..."}.
        if (($decoded['status'] ?? null) === false) {
            $this->recordError('GET', $mode, $code, (string) ($decoded['error'] ?? 'unknown error'), '');
            return [];
        }

        $this->health->clear(self::SERVICE_KEY);
        return $decoded;
    }

    /**
     * GET an action mode. SABnzbd answers {"status": true} on success.
     *
     * @param array<string, string> $params
     */
    private function action(array $params): bool
    {
        $data = $this->call($params);
        if ($data === []) {
            return false;
        }

        // Les actions pures renvoient toujours `status`. Son absence signale
        // une réponse inattendue (proxy, version différente) : on échoue
        // FERMÉ plutôt que d'annoncer un succès non vérifié à l'utilisateur.
        if (!array_key_exists('status', $data)) {
            $this->logger->warning('SABnzbd action response carried no status flag', [
                'mode' => $params['mode'] ?? '?',
                'keys' => array_keys($data),
            ]);

            return false;
        }

        return $data['status'] !== false;
    }

    private function uploadNzb(string $content, string $name, ?string $category): bool
    {
        $query = ['mode' => 'addfile', 'output' => 'json', 'apikey' => $this->apiKey];
        if ($category !== null && $category !== '') $query['cat'] = $category;
        $url = rtrim($this->baseUrl, '/') . '/api?' . http_build_query($query);

        $tmp = tempnam(sys_get_temp_dir(), 'nzb_');
        if ($tmp === false) {
            $this->logger->error('SABnzbdClient uploadNzb: tempnam failed');
            return false;
        }
        try {
            file_put_contents($tmp, $content);
            $this->lastError = null;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER  => true,
                CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT  => 4,
                CURLOPT_TIMEOUT         => 20,
                CURLOPT_NOSIGNAL        => 1,
                CURLOPT_POST            => true,
                CURLOPT_POSTFIELDS      => ['name' => new \CURLFile($tmp, 'application/x-nzb', $name)],
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($body === false || !self::isOkStatus($code)) {
                $this->logger->warning('SABnzbd addfile failed', ['code' => $code, 'error' => $curlErr]);
                $this->recordError('POST', 'addfile', $code, is_string($body) ? $body : '', $curlErr);
                if ($curlErr !== '' || $code === 0) {
                    $this->serviceUnavailable = true;
                    $this->health->markDown(self::SERVICE_KEY);
                }
                return false;
            }
            $decoded = json_decode((string) $body, true);
            $ok = is_array($decoded) && ($decoded['status'] ?? false) === true;
            if (!$ok) {
                $this->recordError('POST', 'addfile', $code, (string) $body, '');
            } else {
                $this->health->clear(self::SERVICE_KEY);
            }
            return $ok;
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }
    }

    private function recordError(string $method, string $path, int $code, string $body, string $curlError): void
    {
        $body = trim($body);
        $message = $curlError !== '' ? $curlError
            : ($body !== '' && strlen($body) < 200 ? $body : "HTTP {$code}");
        $this->lastError = [
            'code'    => $code,
            'method'  => $method,
            'path'    => $path,
            'message' => $message,
        ];
    }
}
