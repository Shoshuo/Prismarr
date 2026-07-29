<?php

namespace App\Service\Media;

use App\Service\ConfigService;
use App\Service\Unifi\UnifiFetcher;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Client for the classic UniFi Network API on a UniFi OS console
 * (GET/POST <url>/proxy/network/api/s/<site>/…, `X-API-KEY` header —
 * official local API key, Network 9.0+). Optional service — if `unifi_url`
 * isn't configured (or the kill switch is off), calls return null without
 * exception. Read-only: stat reads and report queries only, never a command.
 *
 * overview() fires one call PER ENDPOINT (health / hourly report / devices)
 * so a missing or drifted endpoint degrades one block instead of blanking
 * the widget — same stance as UnraidClient's per-group GraphQL queries.
 *
 * overview() shape (every leaf nullable — parse is fully defensive):
 *  [
 *    'wan'     => ?['status' => ?string, 'ip' => ?string, 'uptimeSeconds' => ?int,
 *                   'downBps' => ?float, 'upBps' => ?float,   // BYTES/sec
 *                   'latencyMs' => ?int],
 *    'clients' => ?['total' => int, 'wired' => ?int, 'wireless' => ?int, 'guest' => ?int],
 *    'gateway' => ?['cpuPercent' => ?float, 'memPercent' => ?float],
 *    'usage24h'=> ?list<['ts' => int (epoch s), 'downBytes' => float, 'upBytes' => float]>,
 *    'devices' => ?list<['name' => ?string, 'kind' => 'gateway'|'switch'|'ap'|'other',
 *                        'model' => ?string, 'online' => bool, 'uptimeSeconds' => ?int]>,
 *  ]
 *
 * Direction convention: the site's wan-RX is data received FROM the internet,
 * i.e. download; wan-TX is upload. Report timestamps are epoch MILLISECONDS.
 */
class UnifiClient implements ResetInterface, UnifiFetcher
{
    /** Trivial read used by ping(), the widget and HealthService::probeFor(). */
    public const PATH_HEALTH = '/stat/health';
    private const PATH_REPORT = '/stat/report/hourly.site';
    private const PATH_DEVICE = '/stat/device';

    /** Widget polls every 30s; TTL keeps a paint + poll from double-querying. */
    private const OVERVIEW_TTL = 20.0;

    private ?array $overviewCache = null;
    private float  $overviewCacheAt = 0.0;

    /**
     * Set by request() when a call fails at the transport layer (connect
     * refused / timed out / DNS) as opposed to an HTTP/application error.
     * overview() reads it to fail fast on a dead console instead of waiting
     * out a connect timeout on every remaining endpoint. Protected so tests
     * can simulate a transport failure without real cURL.
     */
    protected bool $transportDown = false;

    private ?string $baseUrl = null;
    private string  $apiKey = '';
    private string  $site = 'default';
    private bool    $skipTlsVerify = false;
    private bool    $enabled = true;
    private bool    $configLoaded = false;

    public function __construct(
        private readonly ConfigService $config,
        private readonly LoggerInterface $logger,
    ) {}

    private function ensureConfig(): void
    {
        if ($this->configLoaded) return;
        $this->baseUrl       = $this->config->get('unifi_url');
        $this->apiKey        = $this->config->get('unifi_api_key') ?? '';
        $site                = trim((string) ($this->config->get('unifi_site') ?? ''));
        $this->site          = $site !== '' ? $site : 'default';
        $this->skipTlsVerify = $this->config->get('unifi_skip_tls_verify') === '1';
        $this->enabled       = $this->config->get('unifi_enabled') !== '0';
        $this->configLoaded  = true;
    }

    public function reset(): void
    {
        $this->configLoaded    = false;
        $this->baseUrl         = null;
        $this->apiKey          = '';
        $this->site            = 'default';
        $this->skipTlsVerify   = false;
        $this->enabled         = true;
        $this->overviewCache   = null;
        $this->overviewCacheAt = 0.0;
        $this->transportDown   = false;
    }

    public function ping(): bool
    {
        return $this->request(self::PATH_HEALTH) !== null;
    }

    /**
     * UnifiFetcher — raw single-call access for the App\Service\Unifi readers.
     * Deliberately thin: no caching and no mapping here, because each reader
     * owns a TTL matching its own poll cadence and its own normalized shape.
     */
    public function fetch(string $path, ?array $body = null): ?array
    {
        return $this->request($path, $body);
    }

    /** UnifiFetcher — see the interface: state of the LAST fetch() only. */
    public function transportFailed(): bool
    {
        return $this->transportDown;
    }

    public function overview(): ?array
    {
        $this->ensureConfig();
        if (!$this->enabled || $this->baseUrl === null || $this->baseUrl === ''
            || $this->apiKey === '') {
            return null;
        }

        $now = microtime(true);
        if ($this->overviewCache !== null && ($now - $this->overviewCacheAt) < self::OVERVIEW_TTL) {
            return $this->overviewCache;
        }

        $this->transportDown = false;
        $health = $this->mapHealth($this->request(self::PATH_HEALTH));
        // Fail fast on a dead console: the remaining two calls would each burn
        // another connect timeout. An HTTP/application error does NOT set
        // this, so a partial API surface still tries every endpoint.
        if ($this->transportDown) {
            return null; // don't cache — retry next call
        }
        $ts = $this->now();
        $usage = $this->mapUsage($this->request(self::PATH_REPORT, [
            'attrs' => ['time', 'wan-tx_bytes', 'wan-rx_bytes'],
            'start' => ($ts - 86400) * 1000,
            'end'   => $ts * 1000,
        ]));
        $devices = $this->mapDevices($this->request(self::PATH_DEVICE));

        if ($health === null && $usage === null && $devices === null) {
            return null; // fully unreachable — don't cache, retry next call
        }

        $this->overviewCache = [
            'wan'      => $health['wan'] ?? null,
            'clients'  => $health['clients'] ?? null,
            'gateway'  => $health['gateway'] ?? null,
            'usage24h' => $usage,
            'devices'  => $devices,
        ];
        $this->overviewCacheAt = $now;
        return $this->overviewCache;
    }

    /**
     * stat/health returns one row per subsystem (wan, www, lan, wlan, vpn).
     * Live throughput + latency + WAN uptime live on `www` (internet check),
     * WAN IP / status / gateway system stats on `wan`, client counts on
     * `lan` (wired) and `wlan` (wireless). Any subsystem may be absent.
     */
    private function mapHealth(?array $data): ?array
    {
        if (!is_array($data)) return null;
        $sub = [];
        foreach ($data as $row) {
            if (is_array($row) && isset($row['subsystem'])) {
                $sub[(string) $row['subsystem']] = $row;
            }
        }
        if ($sub === []) return null;

        $wan  = $sub['wan']  ?? null;
        $www  = $sub['www']  ?? null;
        $lan  = $sub['lan']  ?? null;
        $wlan = $sub['wlan'] ?? null;

        $num = static fn(?array $s, string $k): ?float =>
            is_array($s) && isset($s[$k]) && is_numeric($s[$k]) ? (float) $s[$k] : null;

        // num_user excludes guests/IoT on recent Network versions, so a
        // count is the SUM of the three buckets; null only when the whole
        // subsystem (or all three fields) is absent.
        $count = static function (?array $s) use ($num): ?int {
            $total = null;
            foreach (['num_user', 'num_guest', 'num_iot'] as $k) {
                $v = $num($s, $k);
                if ($v !== null) $total = ($total ?? 0) + (int) $v;
            }
            return $total;
        };

        $wired    = $count($lan);
        $wireless = $count($wlan);
        $guest    = null;
        foreach ([$lan, $wlan] as $s) {
            $g = $num($s, 'num_guest');
            if ($g !== null) $guest = ($guest ?? 0) + (int) $g;
        }

        $wanBlock = null;
        if (is_array($wan) || is_array($www)) {
            $uptime  = $num($www, 'uptime');
            $latency = $num($www, 'latency');
            $wanBlock = [
                'status'        => isset($wan['status']) ? (string) $wan['status']
                                   : (isset($www['status']) ? (string) $www['status'] : null),
                'ip'            => isset($wan['wan_ip']) ? (string) $wan['wan_ip'] : null,
                'uptimeSeconds' => $uptime !== null ? (int) $uptime : null,
                'downBps'       => $num($www, 'rx_bytes-r') ?? $num($wan, 'rx_bytes-r'),
                'upBps'         => $num($www, 'tx_bytes-r') ?? $num($wan, 'tx_bytes-r'),
                'latencyMs'     => $latency !== null ? (int) $latency : null,
            ];
        }

        $gwStats = is_array($wan['gw_system-stats'] ?? null) ? $wan['gw_system-stats'] : null;

        return [
            'wan'     => $wanBlock,
            'clients' => ($wired === null && $wireless === null) ? null : [
                'total'    => (int) (($wired ?? 0) + ($wireless ?? 0)),
                'wired'    => $wired,
                'wireless' => $wireless,
                'guest'    => $guest,
            ],
            'gateway' => $gwStats === null ? null : [
                'cpuPercent' => is_numeric($gwStats['cpu'] ?? null) ? (float) $gwStats['cpu'] : null,
                'memPercent' => is_numeric($gwStats['mem'] ?? null) ? (float) $gwStats['mem'] : null,
            ],
        ];
    }

    /** Hourly wan byte buckets; `time` is epoch MILLISECONDS (live-verify). */
    private function mapUsage(?array $data): ?array
    {
        if (!is_array($data)) return null;
        $out = [];
        foreach ($data as $row) {
            if (!is_array($row) || !is_numeric($row['time'] ?? null)) continue;
            $out[] = [
                'ts'        => (int) round(((float) $row['time']) / 1000),
                'downBytes' => is_numeric($row['wan-rx_bytes'] ?? null) ? (float) $row['wan-rx_bytes'] : 0.0,
                'upBytes'   => is_numeric($row['wan-tx_bytes'] ?? null) ? (float) $row['wan-tx_bytes'] : 0.0,
            ];
        }
        if ($out === []) return null;
        usort($out, static fn(array $a, array $b): int => $a['ts'] <=> $b['ts']);
        return $out;
    }

    /**
     * stat/device is a heavy payload; only the handful of rendered fields is
     * parsed. Sort: offline first (they're the news), then gateway → switch
     * → AP → other, then name.
     */
    private function mapDevices(?array $data): ?array
    {
        if (!is_array($data)) return null;
        $kindOf = static fn(string $t): string => match ($t) {
            'ugw', 'udm', 'uxg', 'ucg' => 'gateway',
            'usw'                      => 'switch',
            'uap'                      => 'ap',
            default                    => 'other',
        };
        $out = [];
        foreach ($data as $d) {
            if (!is_array($d)) continue;
            $out[] = [
                'name'          => isset($d['name']) && $d['name'] !== '' ? (string) $d['name'] : null,
                'kind'          => $kindOf(strtolower((string) ($d['type'] ?? ''))),
                'model'         => isset($d['model']) ? (string) $d['model'] : null,
                'online'        => (int) ($d['state'] ?? 0) === 1,
                'uptimeSeconds' => isset($d['uptime']) && is_numeric($d['uptime']) ? (int) $d['uptime'] : null,
            ];
        }
        if ($out === []) return null;
        $rank = ['gateway' => 0, 'switch' => 1, 'ap' => 2, 'other' => 3];
        usort($out, static fn(array $a, array $b): int =>
            [$a['online'] ? 1 : 0, $rank[$a['kind']], strtolower($a['name'] ?? '')]
            <=> [$b['online'] ? 1 : 0, $rank[$b['kind']], strtolower($b['name'] ?? '')]);
        return $out;
    }

    /** Clock seam — the report window is testable with a fixed now. */
    protected function now(): int
    {
        return time();
    }

    /**
     * Execute one classic-API call. GET by default; passing $jsonBody makes
     * it a POST (the report endpoint). Returns the envelope's `data` list on
     * success ({meta:{rc:'ok'}, data:[…]}), or null on transport error /
     * non-200 / error envelope. Protected so tests can substitute payloads.
     */
    protected function request(string $path, ?array $jsonBody = null): ?array
    {
        $this->ensureConfig();
        if (!$this->enabled || $this->baseUrl === null || $this->baseUrl === '' || $this->apiKey === '') {
            return null;
        }
        // Per-call semantics for transportFailed(). overview() is unaffected:
        // it clears the flag before its first call and checks it after, and
        // this reset runs at the start of that same call.
        $this->transportDown = false;
        $url = rtrim($this->baseUrl, '/') . '/proxy/network/api/s/' . rawurlencode($this->site) . $path;

        $ch = curl_init($url);
        if ($ch === false) return null;
        $opts = $this->curlOptions();
        if ($jsonBody !== null) {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = (string) json_encode($jsonBody);
        }
        $opts[CURLOPT_HTTPHEADER] = [
            'X-API-KEY: ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        curl_setopt_array($ch, $opts);
        $body  = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($body === false || $code !== 200) {
            // Distinguish "host unreachable" (connect/DNS/timeout) from an
            // HTTP/API error so overview() can short-circuit a dead console.
            if (in_array($errno, [CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEOUTED, CURLE_COULDNT_RESOLVE_HOST], true)) {
                $this->transportDown = true;
            }
            $this->logger->debug('UnifiClient request failed', ['path' => $path, 'code' => $code, 'errno' => $errno]);
            return null;
        }
        $decoded = json_decode((string) $body, true);
        // Anything but the ok-envelope (error rc, HTML login page after a
        // firmware change, …) → treat as endpoint-missing, never throw.
        if (!is_array($decoded) || ($decoded['meta']['rc'] ?? null) !== 'ok' || !is_array($decoded['data'] ?? null)) {
            $this->logger->debug('UnifiClient request returned no data', ['path' => $path, 'body' => substr((string) $body, 0, 300)]);
            return null;
        }
        return $decoded['data'];
    }

    /** Base cURL options, TLS verification driven by unifi_skip_tls_verify. */
    private function curlOptions(): array
    {
        $this->ensureConfig();
        return [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => 8,
            CURLOPT_CONNECTTIMEOUT  => 3,
            // HTTP(S) only — same SSRF stance as UnraidClient/GluetunClient.
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            // UniFi OS consoles ship a self-signed cert by default; the
            // admin opts out of verification explicitly in settings.
            CURLOPT_SSL_VERIFYPEER  => !$this->skipTlsVerify,
            CURLOPT_SSL_VERIFYHOST  => $this->skipTlsVerify ? 0 : 2,
        ];
    }
}
