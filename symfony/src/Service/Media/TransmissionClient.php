<?php

namespace App\Service\Media;

use App\Exception\ServiceNotConfiguredException;
use App\Service\ConfigService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Transmission RPC client.
 *
 * Every call is `POST {transmission_url}/transmission/rpc` with the
 * envelope `{method, arguments, tag}`; the response's `result` field is
 * the literal string "success" on success, or the error message otherwise
 * — the HTTP status is not the success signal for that distinction.
 *
 * The HTTP status DOES matter for the session handshake: a client with no
 * (or an expired) `X-Transmission-Session-Id` gets back HTTP 409 with the
 * correct id in the response header — this is the expected first round
 * trip, not a failure, and is retried once transparently. A 401 means the
 * RPC password (HTTP Basic, checked independently of the session id) was
 * rejected — the host is reachable, so this does not trip the breaker.
 *
 * Mirrors DelugeClient's conventions: circuit breaker (in-process +
 * cross-request via ServiceHealthCache), SSRF-guarded curl, lastError for
 * ApiClientErrorTrait, ResetInterface for FrankenPHP worker mode.
 */
class TransmissionClient implements ResetInterface
{
    private const SERVICE = 'Transmission';
    private const SERVICE_KEY = 'transmission';

    /** Shared with qBit/Deluge so the copied template formats ETA identically. */
    private const ETA_NONE = 8640000;

    private string $baseUrl = '';
    private string $user = '';
    private string $password = '';

    /** Cached X-Transmission-Session-Id, refreshed whenever the server 409s. */
    private ?string $sessionId = null;

    private int $tag = 0;

    /** @var array{code:int, method:string, path:string, message:string}|null */
    private ?array $lastError = null;

    /** Circuit breaker — see QBittorrentClient::$serviceUnavailable. */
    private bool $serviceUnavailable = false;

    public function __construct(
        private readonly ConfigService $config,
        private readonly LoggerInterface $logger,
        private readonly ServiceHealthCache $health,
    ) {}

    private function ensureConfig(): void
    {
        if ($this->config->get(self::SERVICE_KEY . '_enabled') === '0') {
            throw new ServiceNotConfiguredException(self::SERVICE, self::SERVICE_KEY . '_enabled');
        }
        if ($this->baseUrl === '') {
            $this->baseUrl  = self::normalizeBaseUrl($this->config->require('transmission_url', self::SERVICE));
            $this->user     = (string) ($this->config->get('transmission_user') ?? '');
            $this->password = (string) ($this->config->get('transmission_password') ?? '');
        }
    }

    /**
     * Strip a redundant trailing `/transmission`, `/transmission/rpc`, or
     * `/transmission/web` path segment some users add by analogy to
     * Transmission's own web-UI URL convention — otherwise it doubles up
     * with the `/transmission/rpc` suffix httpPost() always appends,
     * producing an invalid `/transmission/transmission/rpc` path.
     */
    private static function normalizeBaseUrl(string $url): string
    {
        $url = rtrim($url, '/');
        return preg_replace('#/transmission(?:/(?:rpc|web))?$#i', '', $url) ?? $url;
    }

    public function reset(): void
    {
        $this->baseUrl = '';
        $this->user = '';
        $this->password = '';
        $this->sessionId = null;
        $this->tag = 0;
        $this->lastError = null;
        $this->serviceUnavailable = false;
    }

    /** @return array{code:int, method:string, path:string, message:string}|null */
    public function getLastError(): ?array
    {
        return $this->lastError;
    }

    private function recordError(string $method, int $code, string $message): void
    {
        $this->lastError = ['code' => $code, 'method' => $method, 'path' => '/transmission/rpc', 'message' => $message];
    }

    /** Light ping — true if Transmission answers and (if configured) accepts the credentials. */
    public function ping(): bool
    {
        try {
            return $this->getVersion() !== null;
        } catch (\Throwable $e) {
            $this->logger->warning('Transmission ping failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return false;
        }
    }

    public function getVersion(): ?string
    {
        $r = $this->result('session-get', ['fields' => ['version']]);
        return is_array($r) && isset($r['version']) ? (string) $r['version'] : null;
    }

    /** Fields requested for the torrent table. */
    private const TORRENT_FIELDS = [
        'hashString', 'name', 'status', 'error', 'totalSize', 'sizeWhenDone',
        'downloadedEver', 'uploadedEver', 'percentDone', 'rateDownload', 'rateUpload',
        'eta', 'uploadRatio', 'peersSendingToUs', 'peersGettingFromUs', 'addedDate', 'doneDate',
        'downloadDir', 'labels', 'trackers', 'downloadLimit', 'downloadLimited',
        'uploadLimit', 'uploadLimited', 'queuePosition', 'secondsSeeding',
    ];

    /** Extra fields for the detail panel. */
    private const DETAIL_FIELDS = [
        'hashString', 'name', 'status', 'error', 'totalSize', 'downloadDir',
        'pieceSize', 'pieceCount', 'comment', 'uploadedEver', 'downloadedEver', 'uploadRatio',
        'addedDate', 'doneDate', 'secondsDownloading', 'secondsSeeding', 'eta',
        'peersSendingToUs', 'peersGettingFromUs', 'files', 'fileStats', 'trackerStats', 'peers',
    ];

    // ══════════════════════════════════════════════════════════════════════════
    //  Torrents — Read
    // ══════════════════════════════════════════════════════════════════════════

    /** All torrents, normalized to the qBit-compatible shape, newest first. */
    public function getTorrents(): array
    {
        $r = $this->result('torrent-get', ['fields' => self::TORRENT_FIELDS]);
        $list = is_array($r['torrents'] ?? null) ? $r['torrents'] : [];
        $out = [];
        foreach ($list as $t) {
            if (is_array($t) && isset($t['hashString'])) {
                $out[] = $this->normalizeTorrent($t);
            }
        }
        usort($out, fn($a, $b) => ($b['added_on'] ?? 0) <=> ($a['added_on'] ?? 0));
        return $out;
    }

    /** Detail panel payload: {properties, files, trackers, peers} — qBit-detail-compatible field names. */
    public function getTorrentDetail(string $hash): ?array
    {
        $r = $this->result('torrent-get', ['ids' => [$hash], 'fields' => self::DETAIL_FIELDS]);
        $list = is_array($r['torrents'] ?? null) ? $r['torrents'] : [];
        $t = $list[0] ?? null;
        if (!is_array($t)) {
            return null;
        }

        $files = [];
        $fileStats = $t['fileStats'] ?? [];
        foreach (($t['files'] ?? []) as $i => $f) {
            $stat = is_array($fileStats[$i] ?? null) ? $fileStats[$i] : [];
            $length = (int) ($f['length'] ?? 0);
            $files[] = [
                'name'     => $f['name'] ?? '—',
                'size'     => $length,
                'progress' => $length > 0 ? round(((int) ($f['bytesCompleted'] ?? 0)) / $length, 3) : 0,
                'priority' => (int) ($stat['priority'] ?? 0),
            ];
        }

        $trackers = [];
        $seedsTotal = 0;
        $peersTotal = 0;
        foreach (($t['trackerStats'] ?? []) as $tr) {
            $trackers[] = [
                'url'    => $tr['announce'] ?? '',
                'tier'   => (int) ($tr['tier'] ?? 0),
                'status' => self::trackerStatusLabel((int) ($tr['announceState'] ?? 0)),
                'msg'    => (string) ($tr['lastAnnounceResult'] ?? ''),
            ];
            $seedsTotal += max(0, (int) ($tr['seederCount'] ?? -1));
            $peersTotal += max(0, (int) ($tr['leecherCount'] ?? -1));
        }

        $peers = [];
        foreach (($t['peers'] ?? []) as $p) {
            $peers[] = [
                'ip'         => $p['address'] ?? '',
                'client'     => $p['clientName'] ?? '',
                'country'    => '',
                'progress'   => round((float) ($p['progress'] ?? 0) * 100, 1),
                'dl_speed'   => (int) ($p['rateToClient'] ?? 0),
                'up_speed'   => (int) ($p['rateToPeer'] ?? 0),
                'flags'      => ($p['isUploadingTo'] ?? false) ? 'seed' : '',
                'connection' => '',
            ];
        }

        $eta = (int) ($t['eta'] ?? -1);
        return [
            'properties' => [
                'name'             => (string) ($t['name'] ?? ''),
                'save_path'        => (string) ($t['downloadDir'] ?? ''),
                'total_size'       => (int) ($t['totalSize'] ?? 0),
                'piece_size'       => (int) ($t['pieceSize'] ?? 0),
                'pieces_num'       => (int) ($t['pieceCount'] ?? 0),
                'comment'          => (string) ($t['comment'] ?? ''),
                'total_uploaded'   => (int) ($t['uploadedEver'] ?? 0),
                'total_downloaded' => (int) ($t['downloadedEver'] ?? 0),
                'share_ratio'      => round((float) ($t['uploadRatio'] ?? 0), 2),
                'addition_date'    => (int) ($t['addedDate'] ?? 0),
                'completion_date'  => (int) ($t['doneDate'] ?? 0),
                'time_elapsed'     => (int) ($t['secondsDownloading'] ?? 0),
                'seeding_time'     => (int) ($t['secondsSeeding'] ?? 0),
                'next_announce'    => 0,
                'eta'              => $eta > 0 ? $eta : self::ETA_NONE,
                'seeds'            => (int) ($t['peersSendingToUs'] ?? 0),
                'seeds_total'      => $seedsTotal,
                'peers'            => (int) ($t['peersGettingFromUs'] ?? 0),
                'peers_total'      => $peersTotal,
                'dl_speed'         => (int) ($t['rateDownload'] ?? 0),
                'up_speed'         => (int) ($t['rateUpload'] ?? 0),
            ],
            'files'    => $files,
            'trackers' => $trackers,
            'peers'    => $peers,
        ];
    }

    private static function trackerStatusLabel(int $announceState): string
    {
        return match ($announceState) {
            1       => 'announcing',
            2       => 'queued',
            3       => 'active',
            default => 'idle',
        };
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Torrents — Actions
    // ══════════════════════════════════════════════════════════════════════════

    public function pauseTorrents(array $hashes): bool
    {
        return $this->ok('torrent-stop', ['ids' => $hashes]);
    }

    public function resumeTorrents(array $hashes): bool
    {
        return $this->ok('torrent-start', ['ids' => $hashes]);
    }

    /** Omitting `ids` applies the action to every torrent — Transmission's native "all" mode. */
    public function pauseAll(): bool
    {
        return $this->ok('torrent-stop');
    }

    public function resumeAll(): bool
    {
        return $this->ok('torrent-start');
    }

    public function deleteTorrents(array $hashes, bool $deleteFiles = false): bool
    {
        return $this->ok('torrent-remove', ['ids' => $hashes, 'delete-local-data' => $deleteFiles]);
    }

    public function recheckTorrents(array $hashes): bool
    {
        return $this->ok('torrent-verify', ['ids' => $hashes]);
    }

    public function reannounceTorrents(array $hashes): bool
    {
        return $this->ok('torrent-reannounce', ['ids' => $hashes]);
    }

    public function setTorrentLocation(string $hash, string $location): bool
    {
        return $this->ok('torrent-set-location', ['ids' => [$hash], 'location' => $location, 'move' => true]);
    }

    public function setTorrentDownloadLimit(array $hashes, int $bytes): bool
    {
        return $this->ok('torrent-set', [
            'ids'              => $hashes,
            'downloadLimit'    => max(0, (int) self::bytesToKib($bytes)),
            'downloadLimited'  => $bytes > 0,
        ]);
    }

    public function setTorrentUploadLimit(array $hashes, int $bytes): bool
    {
        return $this->ok('torrent-set', [
            'ids'            => $hashes,
            'uploadLimit'    => max(0, (int) self::bytesToKib($bytes)),
            'uploadLimited'  => $bytes > 0,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Add torrent
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Split a multi-line/pipe-separated add box into magnets vs http(s) URLs.
     *
     * @return array{magnets: list<string>, urls: list<string>}
     */
    private static function splitAddUrls(string $raw): array
    {
        $magnets = [];
        $urls = [];
        foreach (preg_split('/[\r\n|]+/', trim($raw)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (stripos($line, 'magnet:') === 0) {
                $magnets[] = $line;
            } else {
                $urls[] = $line;
            }
        }
        return ['magnets' => $magnets, 'urls' => $urls];
    }

    /** torrent-add has no batch mode — one RPC call per URL/magnet. */
    public function addTorrentFromUrl(string $urls, ?string $savepath = null, bool $paused = false): bool
    {
        $split = self::splitAddUrls($urls);
        $any = false;
        $allOk = true;
        foreach (array_merge($split['magnets'], $split['urls']) as $line) {
            $any = true;
            $args = ['filename' => $line];
            if ($savepath !== null && $savepath !== '') {
                $args['download-dir'] = $savepath;
            }
            if ($paused) {
                $args['paused'] = true;
            }
            $allOk = $this->ok('torrent-add', $args) && $allOk;
        }
        return $any && $allOk;
    }

    /**
     * @param array<array{content: string, name: string}> $files
     */
    public function addTorrentFromFiles(array $files, ?string $savepath = null, bool $paused = false): bool
    {
        if ($files === []) {
            return false;
        }
        $allOk = true;
        foreach ($files as $file) {
            $args = ['metainfo' => base64_encode($file['content'])];
            if ($savepath !== null && $savepath !== '') {
                $args['download-dir'] = $savepath;
            }
            if ($paused) {
                $args['paused'] = true;
            }
            $allOk = $this->ok('torrent-add', $args) && $allOk;
        }
        return $allOk;
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Global transfer limits
    // ══════════════════════════════════════════════════════════════════════════

    public function getGlobalDownloadLimit(): int
    {
        $r = $this->result('session-get', ['fields' => ['speed-limit-down', 'speed-limit-down-enabled']]);
        if (!is_array($r) || !($r['speed-limit-down-enabled'] ?? false)) {
            return 0;
        }
        return (int) round(((float) ($r['speed-limit-down'] ?? 0)) * 1024);
    }

    public function getGlobalUploadLimit(): int
    {
        $r = $this->result('session-get', ['fields' => ['speed-limit-up', 'speed-limit-up-enabled']]);
        if (!is_array($r) || !($r['speed-limit-up-enabled'] ?? false)) {
            return 0;
        }
        return (int) round(((float) ($r['speed-limit-up'] ?? 0)) * 1024);
    }

    public function setGlobalDownloadLimit(int $bytes): bool
    {
        return $this->ok('session-set', [
            'speed-limit-down'         => max(0, (int) self::bytesToKib($bytes)),
            'speed-limit-down-enabled' => $bytes > 0,
        ]);
    }

    public function setGlobalUploadLimit(int $bytes): bool
    {
        return $this->ok('session-set', [
            'speed-limit-up'         => max(0, (int) self::bytesToKib($bytes)),
            'speed-limit-up-enabled' => $bytes > 0,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Aggregated statistics
    // ══════════════════════════════════════════════════════════════════════════

    /** Same output keys as QBittorrentClient::getStats() so the template ports. */
    public function getStats(?array $torrents = null): array
    {
        $torrents = $torrents ?? $this->getTorrents();

        $sess = $this->result('session-stats');
        $sess = is_array($sess) ? $sess : [];
        $cumulative = is_array($sess['cumulative-stats'] ?? null) ? $sess['cumulative-stats'] : [];
        $current    = is_array($sess['current-stats'] ?? null) ? $sess['current-stats'] : [];

        $sessionInfo = $this->result('session-get', ['fields' => ['download-dir']]);
        $downloadDir = is_array($sessionInfo) ? (string) ($sessionInfo['download-dir'] ?? '') : '';
        $free = $downloadDir !== '' ? $this->result('free-space', ['path' => $downloadDir]) : null;
        $freeBytes = is_array($free) ? (int) ($free['size-bytes'] ?? 0) : 0;

        $count = fn(string $state) => count(array_filter($torrents, fn($t) => $t['state'] === $state));
        $completed = count(array_filter($torrents, fn($t) => ($t['progress'] ?? 0) >= 100));

        $upAll = (int) array_sum(array_column($torrents, 'uploaded'));
        $dlAll = (int) array_sum(array_column($torrents, 'downloaded'));

        return [
            'total'        => count($torrents),
            'downloading'  => $count('downloading'),
            'seeding'      => $count('seeding'),
            'paused'       => $count('paused'),
            'completed'    => $completed,
            'errored'      => $count('error'),
            'stalled'      => 0,
            'dl_speed'     => (int) ($sess['downloadSpeed'] ?? 0),
            'up_speed'     => (int) ($sess['uploadSpeed'] ?? 0),
            'connection'   => 'connected',
            'dht_nodes'    => 0,
            'dl_session'   => (int) ($current['downloadedBytes'] ?? 0),
            'up_session'   => (int) ($current['uploadedBytes'] ?? 0),
            'dl_alltime'   => (int) ($cumulative['downloadedBytes'] ?? $dlAll),
            'up_alltime'   => (int) ($cumulative['uploadedBytes'] ?? $upAll),
            'global_ratio' => $dlAll > 0 ? round($upAll / $dlAll, 2) : 0,
            'free_space'   => $freeBytes,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Normalization
    // ══════════════════════════════════════════════════════════════════════════

    private function normalizeTorrent(array $t): array
    {
        $eta = (int) ($t['eta'] ?? -1);
        $status = (int) ($t['status'] ?? 0);
        $errorNum = (int) ($t['error'] ?? 0);
        $totalSize = (int) ($t['totalSize'] ?? 0);
        $labels = is_array($t['labels'] ?? null) ? array_values(array_map('strval', $t['labels'])) : [];

        $trackerHost = '';
        $firstTracker = is_array($t['trackers'] ?? null) ? ($t['trackers'][0] ?? null) : null;
        if (is_array($firstTracker) && isset($firstTracker['announce'])) {
            $trackerHost = (string) (parse_url((string) $firstTracker['announce'], PHP_URL_HOST) ?? '');
        }

        return [
            'hash'           => (string) $t['hashString'],
            'name'           => (string) ($t['name'] ?? '—'),
            'size'           => (int) ($t['sizeWhenDone'] ?? $totalSize),
            'total_size'     => $totalSize,
            'downloaded'     => (int) ($t['downloadedEver'] ?? 0),
            'uploaded'       => (int) ($t['uploadedEver'] ?? 0),
            'progress'       => round(((float) ($t['percentDone'] ?? 0)) * 100, 1),
            'dlspeed'        => (int) ($t['rateDownload'] ?? 0),
            'upspeed'        => (int) ($t['rateUpload'] ?? 0),
            'eta'            => $eta > 0 ? $eta : self::ETA_NONE,
            'state'          => self::normalizeState($status, $errorNum),
            'raw_state'      => (string) $status,
            'category'       => $labels[0] ?? '',
            'tags'           => implode(',', $labels),
            'ratio'          => round((float) ($t['uploadRatio'] ?? 0), 2),
            'num_seeds'      => (int) ($t['peersSendingToUs'] ?? 0),
            'num_leechs'     => (int) ($t['peersGettingFromUs'] ?? 0),
            'num_complete'   => 0,
            'num_incomplete' => 0,
            'added_on'       => isset($t['addedDate']) ? (int) $t['addedDate'] : null,
            'completion_on'  => isset($t['doneDate']) && (int) $t['doneDate'] > 0 ? (int) $t['doneDate'] : null,
            'save_path'      => (string) ($t['downloadDir'] ?? ''),
            'content_path'   => '',
            'tracker'        => $trackerHost,
            'dl_limit'       => ($t['downloadLimited'] ?? false) ? self::kibToBytes((float) ($t['downloadLimit'] ?? 0)) : -1,
            'up_limit'       => ($t['uploadLimited'] ?? false) ? self::kibToBytes((float) ($t['uploadLimit'] ?? 0)) : -1,
            'seeding_time'   => (int) ($t['secondsSeeding'] ?? 0),
            'priority'       => (int) ($t['queuePosition'] ?? 0),
            'availability'   => 0.0,
        ];
    }

    /** error !== 0 always wins, same precedence Deluge uses. */
    private static function normalizeState(int $status, int $errorNum): string
    {
        if ($errorNum !== 0) {
            return 'error';
        }
        return match ($status) {
            0       => 'paused',
            1, 2    => 'checking',
            3, 5    => 'queued',
            4       => 'downloading',
            6       => 'seeding',
            default => 'unknown',
        };
    }

    /** Transmission speed limits are KiB/s, 0/disabled = unlimited. Prismarr speaks bytes/s. */
    private static function kibToBytes(float $kib): int
    {
        return $kib <= 0 ? -1 : (int) round($kib * 1024);
    }

    private static function bytesToKib(int $bytes): float
    {
        return $bytes <= 0 ? 0.0 : round($bytes / 1024, 1);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  JSON-RPC transport
    // ══════════════════════════════════════════════════════════════════════════

    /** Convenience: RPC arguments or null on failure (check getLastError()). */
    private function result(string $method, array $arguments = []): mixed
    {
        return $this->call($method, $arguments)['result'];
    }

    /** Convenience: true when the RPC succeeded. */
    private function ok(string $method, array $arguments = []): bool
    {
        return $this->call($method, $arguments)['ok'];
    }

    /**
     * Perform one Transmission RPC call, transparently handling the
     * session-id handshake.
     *
     * @return array{ok: bool, result: mixed}
     */
    private function call(string $method, array $arguments = [], bool $retried = false): array
    {
        $failure = ['ok' => false, 'result' => null];
        if ($this->health->isDown(self::SERVICE_KEY)) {
            $this->serviceUnavailable = true;
            return $failure;
        }
        if ($this->serviceUnavailable) {
            return $failure;
        }
        $this->lastError = null;
        $this->ensureConfig();

        $payload = json_encode(['method' => $method, 'arguments' => (object) $arguments, 'tag' => ++$this->tag]);
        $resp = $this->httpPost($payload);

        if ($resp['body'] === false) {
            $this->logger->warning('TransmissionClient RPC error', ['method' => $method, 'error' => $resp['err']]);
            $this->recordError($method, $resp['code'], $resp['err'] !== '' ? $resp['err'] : 'connection failed');
            $this->serviceUnavailable = true;
            $this->health->markDown(self::SERVICE_KEY);
            return $failure;
        }

        // Expected handshake: no/expired session id — store the fresh one and retry once.
        if ($resp['code'] === 409) {
            $sessionId = self::extractSessionId($resp['headers']);
            if ($sessionId !== null) {
                $this->sessionId = $sessionId;
            }
            if (!$retried) {
                return $this->call($method, $arguments, true);
            }
            $this->recordError($method, 409, 'session-id handshake failed');
            return $failure;
        }

        // Wrong RPC credentials — host is reachable, so do not trip the breaker.
        if ($resp['code'] === 401) {
            $this->recordError($method, 401, 'unauthorized');
            return $failure;
        }

        if ($resp['code'] < 200 || $resp['code'] >= 300) {
            $this->logger->warning('TransmissionClient RPC HTTP error', ['method' => $method, 'code' => $resp['code']]);
            $this->recordError($method, $resp['code'], 'HTTP ' . $resp['code']);
            if ($resp['code'] === 0) {
                $this->serviceUnavailable = true;
                $this->health->markDown(self::SERVICE_KEY);
            }
            return $failure;
        }

        $decoded = json_decode($resp['body'], true);
        if (!is_array($decoded)) {
            $this->recordError($method, $resp['code'], 'malformed RPC response');
            return $failure;
        }
        if (($decoded['result'] ?? null) !== 'success') {
            $this->recordError($method, $resp['code'], (string) ($decoded['result'] ?? 'RPC error'));
            return $failure;
        }

        $this->health->clear(self::SERVICE_KEY);
        return ['ok' => true, 'result' => $decoded['arguments'] ?? null];
    }

    /** Extract the fresh `X-Transmission-Session-Id` from raw response headers. */
    private static function extractSessionId(string $responseHeaders): ?string
    {
        if (preg_match('/X-Transmission-Session-Id:\s*([^\r\n]+)/i', $responseHeaders, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  HTTP
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * POST a JSON payload to /transmission/rpc. Always captures response
     * headers (the 409 handshake needs them). Timeouts + NOSIGNAL as in
     * QBittorrentClient — see the SIGALRM note there for why NOSIGNAL is
     * critical in worker mode.
     *
     * @return array{body: string|false, headers: string, code: int, err: string}
     */
    private function httpPost(string $json): array
    {
        $url = rtrim($this->baseUrl, '/') . '/transmission/rpc';

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($this->sessionId !== null) {
            $headers[] = 'X-Transmission-Session-Id: ' . $this->sessionId;
        }

        $opts = [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS, // SSRF guard
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT  => 4,
            // torrent-get on a large library is easily multiple MB uncompressed
            // (e.g. ~6.3MB for ~1900 torrents) — transmission-daemon happily
            // gzips it if asked, shrinking that to a few hundred KB. Empty
            // string tells curl to negotiate every encoding it supports and
            // auto-decompress; without it the transfer itself was what timed
            // out over a slower/remote (e.g. DDNS) link, not the RPC call.
            CURLOPT_ENCODING        => '',
            CURLOPT_TIMEOUT         => 15,
            CURLOPT_NOSIGNAL        => 1,
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $json,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_HEADER          => true,
        ];
        if ($this->user !== '') {
            $opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $opts[CURLOPT_USERPWD]  = $this->user . ':' . $this->password;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, $opts);

        $raw   = curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err   = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['body' => false, 'headers' => '', 'code' => $code, 'err' => $err];
        }
        return ['body' => substr($raw, $hsize), 'headers' => substr($raw, 0, $hsize), 'code' => $code, 'err' => $err];
    }
}
