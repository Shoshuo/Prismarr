<?php

namespace App\Service\Media;

use App\Exception\ServiceNotConfiguredException;
use App\Service\ConfigService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Deluge Web UI (deluge-web) JSON-RPC client.
 *
 * Every call is `POST {deluge_url}/json` and deluge-web answers HTTP 200
 * even on failure — the outcome lives in the body's `error` field, so
 * success is judged there, never on the HTTP status.
 *
 * Mirrors QBittorrentClient's conventions: circuit breaker (in-process +
 * cross-request via ServiceHealthCache), SSRF-guarded curl, lastError for
 * ApiClientErrorTrait, ResetInterface for FrankenPHP worker mode, and a
 * reverse-proxy mode (empty password) where auth is injected upstream.
 */
class DelugeClient implements ResetInterface
{
    private const SERVICE = 'Deluge';
    private const SERVICE_KEY = 'deluge';

    /**
     * Sentinel cookie in reverse-proxy mode (empty password in config —
     * the proxy injects auth on every request). login() returns it without
     * an auth.login round-trip; httpPost() skips the Cookie header for it.
     * Mirrors QBittorrentClient::NO_AUTH_SID (issue #10).
     */
    private const NO_AUTH_COOKIE = '__noauth__';

    /** qBit's "no ETA" sentinel — shared so the template formats both alike. */
    private const ETA_NONE = 8640000;

    /** deluge-web session as a ready-to-send "name=value" Cookie pair. */
    private ?string $cookie = null;

    /** web.connected verified once per request cycle. */
    private bool $daemonChecked = false;

    private string $baseUrl = '';
    private string $password = '';
    private int $rpcId = 0;

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
            $this->baseUrl  = $this->config->require('deluge_url', self::SERVICE);
            $this->password = (string) ($this->config->get('deluge_password') ?? '');
        }
    }

    public function reset(): void
    {
        $this->baseUrl = '';
        $this->password = '';
        $this->cookie = null;
        $this->daemonChecked = false;
        $this->rpcId = 0;
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
        $this->lastError = ['code' => $code, 'method' => $method, 'path' => '/json', 'message' => $message];
    }

    /** Light ping — true if deluge-web answers and accepts the password. */
    public function ping(): bool
    {
        try {
            return $this->getVersion() !== null;
        } catch (\Throwable $e) {
            $this->logger->warning('Deluge ping failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            return false;
        }
    }

    public function getVersion(): ?string
    {
        $v = $this->result('daemon.get_version');
        return is_string($v) ? $v : null;
    }

    /** Status keys requested for the torrent table. */
    private const TORRENT_KEYS = [
        'name', 'state', 'progress', 'total_size', 'total_wanted', 'total_done',
        'total_uploaded', 'all_time_download', 'download_payload_rate', 'upload_payload_rate',
        'eta', 'ratio', 'num_seeds', 'total_seeds', 'num_peers', 'total_peers',
        'time_added', 'completed_time', 'save_path', 'label', 'tracker_host',
        'seeding_time', 'is_finished', 'max_download_speed', 'max_upload_speed',
        'distributed_copies',
    ];

    /** Extra keys for the detail panel. */
    private const DETAIL_KEYS = [
        'name', 'state', 'progress', 'save_path', 'total_size', 'piece_length', 'num_pieces',
        'comment', 'total_uploaded', 'all_time_download', 'ratio', 'time_added',
        'completed_time', 'active_time', 'seeding_time', 'next_announce', 'label',
        'tracker_status', 'trackers', 'files', 'file_progress', 'file_priorities', 'peers',
        'is_finished', 'eta', 'num_seeds', 'total_seeds', 'num_peers', 'total_peers',
        'download_payload_rate', 'upload_payload_rate',
    ];

    // ══════════════════════════════════════════════════════════════════════════
    //  Torrents — Read
    // ══════════════════════════════════════════════════════════════════════════

    /** All torrents, normalized to the qBit-compatible shape, newest first. */
    public function getTorrents(): array
    {
        // First param is a FILTER DICT — must encode as {} not [], hence stdClass.
        $data = $this->result('core.get_torrents_status', [new \stdClass(), self::TORRENT_KEYS]);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $hash => $t) {
            if (is_array($t)) {
                $out[] = $this->normalizeTorrent((string) $hash, $t);
            }
        }
        usort($out, fn($a, $b) => ($b['added_on'] ?? 0) <=> ($a['added_on'] ?? 0));
        return $out;
    }

    /** Detail panel payload: {properties, files, trackers, peers} — qBit-detail-compatible field names. */
    public function getTorrentDetail(string $hash): ?array
    {
        $t = $this->result('core.get_torrent_status', [$hash, self::DETAIL_KEYS]);
        if (!is_array($t) || $t === []) {
            return null;
        }

        $files = [];
        $progressList = $t['file_progress'] ?? [];
        $prioList     = $t['file_priorities'] ?? [];
        foreach (($t['files'] ?? []) as $i => $f) {
            $files[] = [
                'name'     => $f['path'] ?? ($f['name'] ?? '—'),
                'size'     => (int) ($f['size'] ?? 0),
                'progress' => round((float) ($progressList[$i] ?? 0), 3),
                'priority' => (int) ($prioList[$i] ?? 1),
            ];
        }

        $trackers = [];
        foreach (($t['trackers'] ?? []) as $tr) {
            $trackers[] = [
                'url'    => $tr['url'] ?? '',
                'tier'   => (int) ($tr['tier'] ?? 0),
                'status' => (string) ($t['tracker_status'] ?? ''),
                'msg'    => '',
            ];
        }

        $peers = [];
        foreach (($t['peers'] ?? []) as $p) {
            $peers[] = [
                'ip'         => $p['ip'] ?? '',
                'client'     => $p['client'] ?? '',
                'country'    => $p['country'] ?? '',
                'progress'   => round((float) ($p['progress'] ?? 0) * 100, 1),
                'dl_speed'   => (int) ($p['down_speed'] ?? 0),
                'up_speed'   => (int) ($p['up_speed'] ?? 0),
                'flags'      => ($p['seed'] ?? 0) ? 'seed' : '',
                'connection' => '',
            ];
        }

        return [
            'properties' => [
                'name'             => (string) ($t['name'] ?? ''),
                'save_path'        => (string) ($t['save_path'] ?? ''),
                'total_size'       => (int) ($t['total_size'] ?? 0),
                'piece_size'       => (int) ($t['piece_length'] ?? 0),
                'pieces_num'       => (int) ($t['num_pieces'] ?? 0),
                'comment'          => (string) ($t['comment'] ?? ''),
                'total_uploaded'   => (int) ($t['total_uploaded'] ?? 0),
                'total_downloaded' => (int) ($t['all_time_download'] ?? 0),
                'share_ratio'      => round((float) ($t['ratio'] ?? 0), 2),
                'addition_date'    => (int) ($t['time_added'] ?? 0),
                'completion_date'  => (int) ($t['completed_time'] ?? 0),
                'time_elapsed'     => (int) ($t['active_time'] ?? 0),
                'seeding_time'     => (int) ($t['seeding_time'] ?? 0),
                'next_announce'    => (int) ($t['next_announce'] ?? 0),
                'eta'              => (int) ($t['eta'] ?? 0),
                'seeds'            => (int) ($t['num_seeds'] ?? 0),
                'seeds_total'      => (int) ($t['total_seeds'] ?? 0),
                'peers'            => (int) ($t['num_peers'] ?? 0),
                'peers_total'      => (int) ($t['total_peers'] ?? 0),
                'dl_speed'         => (int) ($t['download_payload_rate'] ?? 0),
                'up_speed'         => (int) ($t['upload_payload_rate'] ?? 0),
            ],
            'files'    => $files,
            'trackers' => $trackers,
            'peers'    => $peers,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Labels (Label plugin — read-only in Prismarr)
    // ══════════════════════════════════════════════════════════════════════════

    /** @return list<string> Empty when the Label plugin is disabled. */
    public function getLabels(): array
    {
        $r = $this->result('label.get_labels');
        return is_array($r) ? array_values(array_map('strval', $r)) : [];
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Torrents — Actions
    // ══════════════════════════════════════════════════════════════════════════

    public function pauseTorrents(array $hashes): bool
    {
        return $this->ok('core.pause_torrent', [$hashes]);
    }

    public function resumeTorrents(array $hashes): bool
    {
        return $this->ok('core.resume_torrent', [$hashes]);
    }

    /** Pause/resume the whole session — Deluge's native "all torrents" RPCs. */
    public function pauseAll(): bool
    {
        return $this->ok('core.pause_session');
    }

    public function resumeAll(): bool
    {
        return $this->ok('core.resume_session');
    }

    /** core.remove_torrent takes ONE hash — loop for bulk. */
    public function deleteTorrents(array $hashes, bool $deleteFiles = false): bool
    {
        $allOk = true;
        foreach ($hashes as $hash) {
            $allOk = $this->ok('core.remove_torrent', [$hash, $deleteFiles]) && $allOk;
        }
        return $allOk;
    }

    public function recheckTorrents(array $hashes): bool
    {
        return $this->ok('core.force_recheck', [$hashes]);
    }

    public function reannounceTorrents(array $hashes): bool
    {
        return $this->ok('core.force_reannounce', [$hashes]);
    }

    public function setTorrentLocation(string $hash, string $location): bool
    {
        return $this->ok('core.move_storage', [[$hash], $location]);
    }

    public function setTorrentDownloadLimit(array $hashes, int $bytes): bool
    {
        return $this->ok('core.set_torrent_options', [$hashes, (object) ['max_download_speed' => self::bytesToKib($bytes)]]);
    }

    public function setTorrentUploadLimit(array $hashes, int $bytes): bool
    {
        return $this->ok('core.set_torrent_options', [$hashes, (object) ['max_upload_speed' => self::bytesToKib($bytes)]]);
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

    /** @return object Options dict for core.add_torrent_* ((object) so empty encodes as {}). */
    private static function addOptions(?string $savepath, bool $paused): object
    {
        $options = [];
        if ($savepath !== null && $savepath !== '') {
            $options['download_location'] = $savepath;
        }
        if ($paused) {
            $options['add_paused'] = true;
        }
        return (object) $options;
    }

    public function addTorrentFromUrl(string $urls, ?string $savepath = null, bool $paused = false): bool
    {
        $split = self::splitAddUrls($urls);
        $options = self::addOptions($savepath, $paused);
        $any = false;
        $allOk = true;
        foreach ($split['magnets'] as $magnet) {
            $any = true;
            $allOk = $this->ok('core.add_torrent_magnet', [$magnet, $options]) && $allOk;
        }
        foreach ($split['urls'] as $url) {
            $any = true;
            $allOk = $this->ok('core.add_torrent_url', [$url, $options]) && $allOk;
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
        $options = self::addOptions($savepath, $paused);
        $allOk = true;
        foreach ($files as $file) {
            $allOk = $this->ok('core.add_torrent_file', [$file['name'], base64_encode($file['content']), $options]) && $allOk;
        }
        return $allOk;
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Global transfer limits
    // ══════════════════════════════════════════════════════════════════════════

    /** qBit convention at the boundary: 0 = unlimited for GLOBAL limits. */
    public function getGlobalDownloadLimit(): int
    {
        $c = $this->result('core.get_config_values', [['max_download_speed']]);
        $kib = is_array($c) ? (float) ($c['max_download_speed'] ?? -1) : -1.0;
        return $kib <= 0 ? 0 : (int) round($kib * 1024);
    }

    public function getGlobalUploadLimit(): int
    {
        $c = $this->result('core.get_config_values', [['max_upload_speed']]);
        $kib = is_array($c) ? (float) ($c['max_upload_speed'] ?? -1) : -1.0;
        return $kib <= 0 ? 0 : (int) round($kib * 1024);
    }

    public function setGlobalDownloadLimit(int $bytes): bool
    {
        return $this->ok('core.set_config', [(object) ['max_download_speed' => self::bytesToKib($bytes)]]);
    }

    public function setGlobalUploadLimit(int $bytes): bool
    {
        return $this->ok('core.set_config', [(object) ['max_upload_speed' => self::bytesToKib($bytes)]]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Aggregated statistics
    // ══════════════════════════════════════════════════════════════════════════

    /** Same output keys as QBittorrentClient::getStats() so the template ports. */
    public function getStats(?array $torrents = null): array
    {
        $torrents = $torrents ?? $this->getTorrents();

        $sess = $this->result('core.get_session_status', [[
            'payload_download_rate', 'payload_upload_rate',
            'total_payload_download', 'total_payload_upload',
        ]]);
        $sess = is_array($sess) ? $sess : [];
        $free = $this->result('core.get_free_space');

        $count = fn(string $state) => count(array_filter($torrents, fn($t) => $t['state'] === $state));
        // Deluge has no completed/stalled states — completed = 100% progress.
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
            'dl_speed'     => (int) ($sess['payload_download_rate'] ?? 0),
            'up_speed'     => (int) ($sess['payload_upload_rate'] ?? 0),
            'connection'   => 'connected',
            'dht_nodes'    => 0,
            'dl_session'   => (int) ($sess['total_payload_download'] ?? 0),
            'up_session'   => (int) ($sess['total_payload_upload'] ?? 0),
            'dl_alltime'   => $dlAll,
            'up_alltime'   => $upAll,
            'global_ratio' => $dlAll > 0 ? round($upAll / $dlAll, 2) : 0,
            'free_space'   => is_numeric($free) ? (int) $free : 0,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Normalization
    // ══════════════════════════════════════════════════════════════════════════

    private function normalizeTorrent(string $hash, array $t): array
    {
        $eta = (int) ($t['eta'] ?? 0);
        return [
            'hash'           => $hash,
            'name'           => $t['name'] ?? '—',
            'size'           => (int) ($t['total_wanted'] ?? $t['total_size'] ?? 0),
            'total_size'     => (int) ($t['total_size'] ?? 0),
            'downloaded'     => (int) ($t['all_time_download'] ?? $t['total_done'] ?? 0),
            'uploaded'       => (int) ($t['total_uploaded'] ?? 0),
            'progress'       => round((float) ($t['progress'] ?? 0), 1),
            'dlspeed'        => (int) ($t['download_payload_rate'] ?? 0),
            'upspeed'        => (int) ($t['upload_payload_rate'] ?? 0),
            'eta'            => $eta > 0 ? $eta : self::ETA_NONE,
            'state'          => self::normalizeState((string) ($t['state'] ?? ''), (bool) ($t['is_finished'] ?? false)),
            'raw_state'      => (string) ($t['state'] ?? ''),
            'category'       => (string) ($t['label'] ?? ''),
            'tags'           => '',
            'ratio'          => round((float) ($t['ratio'] ?? 0), 2),
            'num_seeds'      => (int) ($t['num_seeds'] ?? 0),
            'num_leechs'     => (int) ($t['num_peers'] ?? 0),
            'num_complete'   => (int) ($t['total_seeds'] ?? 0),
            'num_incomplete' => (int) ($t['total_peers'] ?? 0),
            'added_on'       => isset($t['time_added']) ? (int) $t['time_added'] : null,
            'completion_on'  => isset($t['completed_time']) && (int) $t['completed_time'] > 0 ? (int) $t['completed_time'] : null,
            'save_path'      => (string) ($t['save_path'] ?? ''),
            'content_path'   => '',
            'tracker'        => (string) ($t['tracker_host'] ?? ''),
            'dl_limit'       => self::kibToBytes((float) ($t['max_download_speed'] ?? -1)),
            'up_limit'       => self::kibToBytes((float) ($t['max_upload_speed'] ?? -1)),
            'seeding_time'   => (int) ($t['seeding_time'] ?? 0),
            'priority'       => 0,
            'availability'   => round((float) ($t['distributed_copies'] ?? 0), 3),
        ];
    }

    private static function normalizeState(string $state, bool $finished): string
    {
        return match ($state) {
            'Downloading' => 'downloading',
            'Seeding'     => 'seeding',
            'Paused'      => 'paused',
            'Queued'      => 'queued',
            'Checking'    => 'checking',
            'Error'       => 'error',
            'Moving'      => 'moving',
            'Allocating'  => 'downloading',
            default       => $finished ? 'seeding' : 'unknown',
        };
    }

    /** Deluge speed limits are KiB/s, -1 = unlimited. Prismarr speaks bytes/s. */
    private static function kibToBytes(float $kib): int
    {
        return $kib < 0 ? -1 : (int) round($kib * 1024);
    }

    private static function bytesToKib(int $bytes): float
    {
        return $bytes <= 0 ? -1.0 : round($bytes / 1024, 1);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  JSON-RPC transport
    // ══════════════════════════════════════════════════════════════════════════

    /** Convenience: RPC result or null on failure (check getLastError()). */
    private function result(string $method, array $params = []): mixed
    {
        return $this->call($method, $params)['result'];
    }

    /** Convenience: true when the RPC succeeded (result may be null). */
    private function ok(string $method, array $params = []): bool
    {
        return $this->call($method, $params)['ok'];
    }

    /**
     * Perform one JSON-RPC call. Success means "no error in the envelope";
     * many Deluge mutations legitimately return result null, hence the
     * separate ok flag.
     *
     * @return array{ok: bool, result: mixed}
     */
    private function call(string $method, array $params = [], bool $retried = false): array
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

        $cookie = $this->login();
        if ($cookie === null) {
            return $failure;
        }

        // deluge-web can be up while disconnected from the deluged daemon —
        // core.* calls then fail with an unhelpful error. Verify once per
        // request cycle and auto-connect to the first known host.
        if (!$this->daemonChecked && !str_starts_with($method, 'web.')) {
            $this->daemonChecked = true; // set first — ensureDaemonConnected() re-enters call()
            if (!$this->ensureDaemonConnected()) {
                return $failure;
            }
        }

        $payload = json_encode(['method' => $method, 'params' => $params, 'id' => ++$this->rpcId]);
        $resp = $this->httpPost($payload, $cookie);

        if ($resp['body'] === false) {
            $this->logger->warning('DelugeClient RPC error', ['method' => $method, 'error' => $resp['err']]);
            $this->recordError($method, $resp['code'], $resp['err'] !== '' ? $resp['err'] : 'connection failed');
            $this->serviceUnavailable = true;
            $this->health->markDown(self::SERVICE_KEY);
            return $failure;
        }
        if ($resp['code'] < 200 || $resp['code'] >= 300) {
            $this->logger->warning('DelugeClient RPC HTTP error', ['method' => $method, 'code' => $resp['code']]);
            $this->recordError($method, $resp['code'], 'HTTP ' . $resp['code']);
            if ($resp['code'] === 0) {
                $this->serviceUnavailable = true;
                $this->health->markDown(self::SERVICE_KEY);
            }
            return $failure;
        }

        $parsed = self::parseRpcBody($resp['body']);
        if ($parsed['error'] !== null) {
            // code 1 = "Not authenticated" → expired session → one re-login retry
            if (($parsed['error']['code'] ?? null) === 1 && !$retried && $cookie !== self::NO_AUTH_COOKIE) {
                $this->cookie = null;
                return $this->call($method, $params, true);
            }
            $this->recordError($method, $resp['code'], $parsed['error']['message']);
            return $failure;
        }

        $this->health->clear(self::SERVICE_KEY);
        return ['ok' => true, 'result' => $parsed['result']];
    }

    /**
     * Decode a JSON-RPC envelope. deluge-web always answers HTTP 200, so
     * this is the single place success/failure is decided.
     *
     * @return array{result: mixed, error: ?array{code: ?int, message: string}}
     */
    private static function parseRpcBody(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['result' => null, 'error' => ['code' => null, 'message' => 'malformed JSON-RPC response']];
        }
        $err = $decoded['error'] ?? null;
        if ($err !== null) {
            return ['result' => null, 'error' => [
                'code'    => is_array($err) && isset($err['code']) ? (int) $err['code'] : null,
                'message' => is_array($err) ? (string) ($err['message'] ?? 'RPC error') : (string) $err,
            ]];
        }
        return ['result' => $decoded['result'] ?? null, 'error' => null];
    }

    private function ensureDaemonConnected(): bool
    {
        $connected = $this->call('web.connected');
        if ($connected['ok'] && $connected['result'] === true) {
            return true;
        }
        $hosts = $this->call('web.get_hosts');
        $hostId = is_array($hosts['result'] ?? null) && isset($hosts['result'][0][0])
            ? (string) $hosts['result'][0][0]
            : null;
        if ($hostId !== null) {
            $this->call('web.connect', [$hostId]);
            $again = $this->call('web.connected');
            if ($again['ok'] && $again['result'] === true) {
                return true;
            }
        }
        $this->logger->warning('DelugeClient: web UI is not connected to the daemon');
        $this->recordError('web.connected', 0, 'Deluge web UI is not connected to the daemon');
        $this->serviceUnavailable = true;
        return false;
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Authentication
    // ══════════════════════════════════════════════════════════════════════════

    private function login(): ?string
    {
        if ($this->cookie !== null) {
            return $this->cookie;
        }
        $this->ensureConfig();

        // Reverse-proxy mode: empty password means the proxy in front of
        // deluge-web injects the session — auth.login with '' would fail.
        if ($this->password === '') {
            $this->cookie = self::NO_AUTH_COOKIE;
            $this->health->clear(self::SERVICE_KEY);
            return $this->cookie;
        }

        $payload = json_encode(['method' => 'auth.login', 'params' => [$this->password], 'id' => ++$this->rpcId]);
        $resp = $this->httpPost($payload, null);

        if ($resp['body'] === false || $resp['code'] === 0) {
            $this->logger->warning('DelugeClient login error', ['error' => $resp['err']]);
            $this->recordError('auth.login', $resp['code'], $resp['err'] !== '' ? $resp['err'] : 'connection failed');
            $this->serviceUnavailable = true;
            $this->health->markDown(self::SERVICE_KEY);
            return null;
        }

        $parsed = self::parseRpcBody($resp['body']);
        if ($parsed['result'] !== true) {
            // Wrong password: HTTP 200 with result false — host is reachable,
            // so do NOT trip the circuit breaker.
            $this->recordError('auth.login', $resp['code'], $parsed['error']['message'] ?? 'wrong password');
            return null;
        }

        $cookie = self::extractSessionCookie($resp['headers']);
        if ($cookie === null) {
            $this->recordError('auth.login', $resp['code'], 'no session cookie in login response');
            return null;
        }
        $this->cookie = $cookie;
        $this->health->clear(self::SERVICE_KEY);
        return $this->cookie;
    }

    /** Extract `_session_id` from raw response headers as a "name=value" pair. */
    private static function extractSessionCookie(string $responseHeaders): ?string
    {
        if (preg_match('/Set-Cookie:\s*(_session_id)=([^;\s]+)/i', $responseHeaders, $m)) {
            return $m[1] . '=' . $m[2];
        }
        return null;
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  HTTP
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * POST a JSON payload to /json. Always captures response headers (login
     * needs Set-Cookie). Timeouts + NOSIGNAL as in QBittorrentClient — see
     * the SIGALRM note there for why NOSIGNAL is critical in worker mode.
     *
     * @return array{body: string|false, headers: string, code: int, err: string}
     */
    private function httpPost(string $json, ?string $cookie): array
    {
        $this->ensureConfig();
        $url = rtrim($this->baseUrl, '/') . '/json';

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($cookie !== null && $cookie !== self::NO_AUTH_COOKIE) {
            $headers[] = 'Cookie: ' . $cookie;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS, // SSRF guard
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT  => 4,
            CURLOPT_TIMEOUT         => 8,
            CURLOPT_NOSIGNAL        => 1,
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $json,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_HEADER          => true,
        ]);

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
