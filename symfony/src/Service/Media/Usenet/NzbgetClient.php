<?php

namespace App\Service\Media\Usenet;

use App\Exception\ServiceNotConfiguredException;
use App\Service\ConfigService;
use App\Service\Media\ServiceHealthCache;
use Psr\Log\LoggerInterface;

/**
 * NZBGet download client. Talks JSON-RPC at `{url}/jsonrpc` over HTTP Basic
 * auth (NZBGet's ControlUsername / ControlPassword).
 *
 * Same resilience model as {@see SabnzbdClient} / QBittorrentClient: circuit
 * breaker (ServiceHealthCache), SSRF-guarded curl locked to HTTP(S), NOSIGNAL
 * + explicit timeouts, recorded lastError. Where SABnzbd hands back MB-in-
 * strings, NZBGet splits every 64-bit byte count into a Lo/Hi 32-bit pair
 * ({@see combineBytes}); both downloaders normalize to bytes in the DTOs.
 */
class NzbgetClient implements UsenetClientInterface
{
    private const SERVICE     = 'NZBGet';
    private const SERVICE_KEY = 'nzbget';

    private string $baseUrl  = '';
    private string $user     = '';
    private string $password = '';

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
            $this->baseUrl  = $this->config->require('nzbget_url', self::SERVICE);
            // user/password optional: a reverse proxy may inject auth, or
            // NZBGet may run with authentication disabled on the LAN.
            $this->user     = (string) ($this->config->get('nzbget_user') ?? '');
            $this->password = (string) ($this->config->get('nzbget_password') ?? '');
        }
    }

    public function reset(): void
    {
        $this->baseUrl  = '';
        $this->user     = '';
        $this->password = '';
        $this->lastError = null;
        $this->serviceUnavailable = false;
    }

    public function getLastError(): ?array
    {
        return $this->lastError;
    }

    public function ping(): bool
    {
        try {
            return $this->getVersion() !== null;
        } catch (\Throwable $e) {
            $this->logger->warning('NZBGet ping failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return false;
        }
    }

    public function getVersion(): ?string
    {
        $v = $this->rpc('version');
        return is_string($v) && $v !== '' ? $v : null;
    }

    // ── Read ──────────────────────────────────────────────────────────────────

    public function getQueue(): UsenetQueueSnapshot
    {
        $groups = $this->rpc('listgroups', [0]);
        $status = $this->rpc('status');
        if (!is_array($groups) || !is_array($status)) {
            return UsenetQueueSnapshot::empty();
        }

        $items = [];
        $active = 0;
        $queued = 0;
        foreach ($groups as $g) {
            if (!is_array($g)) continue;
            $item = $this->normalizeGroup($g);
            $items[] = $item;
            if ($item->status === UsenetStatus::DOWNLOADING) $active++;
            elseif ($item->status === UsenetStatus::QUEUED)  $queued++;
        }

        $rate      = (int) ($status['DownloadRate'] ?? 0); // bytes/s
        $remaining = $this->combineBytes($status, 'RemainingSize');
        $limit     = (int) ($status['DownloadLimit'] ?? 0); // bytes/s, 0 = unlimited

        return new UsenetQueueSnapshot(
            paused:          (bool) ($status['DownloadPaused'] ?? false),
            speedBytes:      $rate,
            speedLimitBytes: $limit,
            remainingBytes:  $remaining,
            activeCount:     $active,
            queuedCount:     $queued,
            etaSeconds:      $rate > 0 && $remaining > 0 ? (int) ceil($remaining / $rate) : null,
            freeSpaceBytes:  $this->combineBytes($status, 'FreeDiskSpace'),
            items:           $items,
        );
    }

    public function getCategories(): array
    {
        // NZBGet has no category list RPC; they live in the config as
        // CategoryN.Name entries returned by the `config` method.
        $config = $this->rpc('config');
        if (!is_array($config)) return [];

        $out = [];
        foreach ($config as $entry) {
            if (is_array($entry)
                && isset($entry['Name'], $entry['Value'])
                && preg_match('/^Category\d+\.Name$/', (string) $entry['Name'])
                && (string) $entry['Value'] !== '') {
                $out[] = (string) $entry['Value'];
            }
        }
        return $out;
    }

    public function getHistoryPage(int $offset, int $limit): array
    {
        // NZBGet's history RPC returns the whole list (most recent first), with
        // no native paging — slice it server-side.
        $hist = $this->rpc('history', [false]);
        if (!is_array($hist)) return ['items' => [], 'total' => 0];

        $total = count($hist);
        $out = [];
        foreach (array_slice($hist, max(0, $offset), max(1, $limit)) as $h) {
            if (!is_array($h)) continue;
            $out[] = $this->normalizeHistory($h);
        }
        return ['items' => $out, 'total' => $total];
    }

    // ── Actions ─────────────────────────────────────────────────────────────────

    public function pauseAll(): bool
    {
        return $this->rpc('pausedownload') === true;
    }

    public function resumeAll(): bool
    {
        return $this->rpc('resumedownload') === true;
    }

    public function pauseItem(string $id): bool
    {
        return $this->editQueue('GroupPause', $id);
    }

    public function resumeItem(string $id): bool
    {
        return $this->editQueue('GroupResume', $id);
    }

    /**
     * Removes a queue group. NZBGet's GroupFinalDelete drops the group and its
     * partial files without parking it in history; GroupDelete keeps a
     * "DELETED" history entry. `deleteFiles` picks the harder removal.
     */
    public function deleteItem(string $id, bool $deleteFiles = false): bool
    {
        return $this->editQueue($deleteFiles ? 'GroupFinalDelete' : 'GroupDelete', $id);
    }

    public function setSpeedLimitBytes(int $bytesPerSec): bool
    {
        // NZBGet's rate() takes KB/s; 0 = unlimited.
        $kb = $bytesPerSec > 0 ? (int) round($bytesPerSec / 1024) : 0;
        return $this->rpc('rate', [$kb]) === true;
    }

    public function addNzbFromUrl(string $url, ?string $category = null): bool
    {
        // NZBGet's append() fetches the NZB itself when the content is a URL.
        return $this->append('', $url, $category);
    }

    public function addNzbFromFiles(array $files, ?string $category = null): bool
    {
        if ($files === []) return false;
        $ok = true;
        foreach ($files as $file) {
            if (!isset($file['content'], $file['name'])) { $ok = false; continue; }
            $ok = $this->append($file['name'], base64_encode($file['content']), $category) && $ok;
        }
        return $ok;
    }

    /** @param string $content base64 NZB content, or a raw http(s) URL. */
    private function append(string $filename, string $content, ?string $category): bool
    {
        // append(NZBFilename, NZBContent, Category, Priority, AddToTop,
        //        AddPaused, DupeKey, DupeScore, DupeMode)
        $result = $this->rpc('append', [
            $filename,
            $content,
            $category ?? '',
            0,      // priority
            false,  // addToTop
            false,  // addPaused
            '',     // dupeKey
            0,      // dupeScore
            'SCORE',// dupeMode
        ]);
        // Returns the new NZBID (int > 0) on success, 0 on failure.
        return is_int($result) ? $result > 0 : ($result === true);
    }

    private function editQueue(string $command, string $id): bool
    {
        // editqueue(Command, Offset, Text, IDs[])
        $result = $this->rpc('editqueue', [$command, 0, '', [(int) $id]]);
        return $result === true;
    }

    // ── Normalization ────────────────────────────────────────────────────────────

    private function normalizeGroup(array $g): UsenetDownload
    {
        $raw       = (string) ($g['Status'] ?? '');
        $total     = $this->combineBytes($g, 'FileSize');
        $remaining = $this->combineBytes($g, 'RemainingSize');
        $done      = $total - $remaining;
        return new UsenetDownload(
            id:             (string) ($g['NZBID'] ?? ''),
            name:           (string) ($g['NZBName'] ?? '—'),
            status:         UsenetStatus::fromNzbgetQueue($raw),
            rawStatus:      $raw,
            sizeBytes:      $total,
            remainingBytes: $remaining,
            // Clamp to 0-100: NZBGet can report RemainingSize > FileSize during
            // post-processing, which would otherwise yield a negative percentage.
            percentage:     $total > 0 ? max(0.0, min(100.0, round($done / $total * 100, 1))) : 0.0,
            category:       (string) ($g['Category'] ?? ''),
            etaSeconds:     null, // NZBGet has no reliable per-group ETA
            speedBytes:     (int) ($g['DownloadRate'] ?? 0),
            failMessage:    null,
            isHistory:      false,
        );
    }

    private function normalizeHistory(array $h): UsenetDownload
    {
        $raw    = (string) ($h['Status'] ?? '');
        $status = UsenetStatus::fromNzbgetHistory($raw);
        return new UsenetDownload(
            id:             (string) ($h['NZBID'] ?? ''),
            name:           (string) ($h['Name'] ?? '—'),
            status:         $status,
            rawStatus:      $raw,
            sizeBytes:      $this->combineBytes($h, 'FileSize'),
            remainingBytes: 0,
            percentage:     $status === UsenetStatus::COMPLETED ? 100.0 : 0.0,
            category:       (string) ($h['Category'] ?? ''),
            etaSeconds:     null,
            speedBytes:     0,
            failMessage:    $status === UsenetStatus::FAILED && $raw !== '' ? $raw : null,
            isHistory:      true,
            // NZBGet's history entries carry HistoryTime — the unix epoch the
            // job landed in history, i.e. when it finished. Absent on some entry
            // kinds (DUP/URL stubs), so 0 / missing stays null.
            completedAt:    ((int) ($h['HistoryTime'] ?? 0)) ?: null,
        );
    }

    /**
     * NZBGet splits each 64-bit byte count into 32-bit Lo/Hi halves
     * (`<field>Lo`, `<field>Hi`). Recombine to a single byte count; fall back
     * to the deprecated `<field>MB` int when the pair is absent.
     *
     * @param array<string, mixed> $data
     */
    private function combineBytes(array $data, string $field): int
    {
        if (isset($data[$field . 'Lo']) || isset($data[$field . 'Hi'])) {
            // NZBGet emits the low half as a SIGNED 32-bit int, so any value
            // with bit 31 set arrives negative (e.g. -1 for 0xFFFFFFFF). Mask it
            // back to unsigned or the recombination undercounts by up to ~4 GB.
            $lo = ((int) ($data[$field . 'Lo'] ?? 0)) & 0xFFFFFFFF;
            $hi = (int) ($data[$field . 'Hi'] ?? 0);
            return $hi * 4294967296 + $lo;
        }
        return (int) ($data[$field . 'MB'] ?? 0) * 1024 * 1024;
    }

    // ── JSON-RPC ──────────────────────────────────────────────────────────────────

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
     * Issue a JSON-RPC call and return the decoded `result` (mixed), or null
     * on transport/RPC failure.
     *
     * @param array<int, mixed> $params
     */
    private function rpc(string $method, array $params = []): mixed
    {
        if ($this->isBrokenOrDown()) return null;
        $this->lastError = null;
        $this->ensureConfig();

        $url = rtrim($this->baseUrl, '/') . '/jsonrpc';
        $payload = json_encode(['version' => '1.1', 'id' => 1, 'method' => $method, 'params' => $params]);

        $headers = ['Content-Type: application/json'];
        if ($this->user !== '' || $this->password !== '') {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->user . ':' . $this->password);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS, // SSRF guard
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT  => 4,
            CURLOPT_TIMEOUT         => 20, // append() can fetch a URL server-side
            CURLOPT_NOSIGNAL        => 1,
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $payload,
            CURLOPT_HTTPHEADER      => $headers,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($body === false || !self::isOkStatus($code)) {
            $this->logger->warning('NZBGet RPC error', ['method' => $method, 'code' => $code, 'error' => $curlErr]);
            $this->recordError('POST', $method, $code, is_string($body) ? $body : '', $curlErr);
            if ($curlErr !== '' || $code === 0) {
                $this->serviceUnavailable = true;
                $this->health->markDown(self::SERVICE_KEY);
            }
            return null;
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            $this->recordError('POST', $method, $code, (string) $body, '');
            return null;
        }
        if (isset($decoded['error'])) {
            $msg = is_array($decoded['error']) ? (string) ($decoded['error']['message'] ?? 'RPC error') : (string) $decoded['error'];
            $this->recordError('POST', $method, $code, $msg, '');
            return null;
        }

        $this->health->clear(self::SERVICE_KEY);
        return $decoded['result'] ?? null;
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
