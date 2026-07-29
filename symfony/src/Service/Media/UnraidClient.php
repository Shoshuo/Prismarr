<?php

namespace App\Service\Media;

use App\Service\ConfigService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Client for the Unraid 7 GraphQL API (POST <url>/graphql, `x-api-key` header).
 * Optional service — if `unraid_url` isn't configured (or the kill switch is
 * off), calls return null without exception. Read-only: only queries, never
 * mutations; the settings page tells the admin to create a viewer-scoped key.
 *
 * overview() fires one small query PER GROUP (array / info / metrics / docker
 * / ups / parity status / parity history) instead of one combined document: a
 * GraphQL validation error (schema drift between Unraid versions, missing
 * UPS) fails the whole document, so per-group queries let the widget render
 * whatever the server does support.
 *
 * overview() shape (every leaf nullable — parse is fully defensive):
 *  [
 *    'array'  => ['state' => ?string,
 *                 'capacity' => ['free' => ?float, 'used' => ?float, 'total' => ?float], // kilobytes
 *                 'disks'    => list<['name' => ?string, 'temp' => ?int, 'status' => ?string,
 *                                     'size' => ?float, 'free' => ?float, 'used' => ?float]>,
 *                 'parities' => list<['name' => ?string, 'temp' => ?int, 'status' => ?string, 'size' => ?float]>,
 *                 'caches'   => list<['name' => ?string, 'temp' => ?int,
 *                                     'size' => ?float, 'free' => ?float, 'used' => ?float]>],
 *    'system' => ['uptime' => ?string (raw ISO), 'uptimeEpoch' => ?int, 'cpuBrand' => ?string,
 *                 'cores' => ?int, 'threads' => ?int,
 *                 'cpuPercent' => ?float, 'memPercent' => ?float, 'memTotal' => ?float, 'memUsed' => ?float],
 *    'docker' => ['running' => int, 'total' => int, 'stopped' => list<string>, 'containers' => list<['name' => string, 'running' => bool]>],
 *    'ups'    => ?['name' => ?string, 'battery' => ?int, 'runtime' => ?int (minutes), 'load' => ?float],
 *    'parity' => ?['running' => bool, 'progress' => ?float (0–100, 1dp), 'elapsed' => ?int (sec),
 *                  'etaSeconds' => ?int, 'errors' => ?int,
 *                  'last' => ?['dateEpoch' => ?int, 'duration' => ?int (sec), 'errors' => ?int, 'status' => ?string]],
 *  ]
 *
 * Int-overflow workaround: on arrays > 2^31 md-units, the API's 32-bit Int
 * type nulls mdResync/mdResyncSize (live-verified), so `parity.running` keys
 * off mdResyncPos alone and the progress denominator falls back to the
 * parities[].size from the array group (same units, big-safe). etaSeconds
 * prefers the live throughput (mdResyncDb/mdResyncDt) over the elapsed
 * average — sbSynced may mark a resume, not the check's true start.
 */
class UnraidClient implements ResetInterface
{
    /** Trivial read used by HealthService::probeFor() and ping(). */
    public const QUERY_PING = 'query { info { os { uptime } } }';

    private const QUERY_ARRAY   = 'query { array { state capacity { kilobytes { free used total } } disks { name temp status fsSize fsFree fsUsed } parities { name temp status size } caches { name temp fsSize fsFree fsUsed } } }';
    private const QUERY_INFO    = 'query { info { os { uptime } cpu { brand cores threads } } }';
    private const QUERY_METRICS = 'query { metrics { cpu { percentTotal } memory { percentTotal total used } } }';
    private const QUERY_DOCKER  = 'query { docker { containers { names state } } }';
    private const QUERY_UPS     = 'query { upsDevices { name battery { chargeLevel estimatedRuntime } power { loadPercentage } } }';
    private const QUERY_PARITY_STATUS  = 'query { vars { mdResyncPos mdResyncSize mdResyncDt mdResyncDb sbSynced sbSynced2 sbSyncErrs sbSyncExit } }';
    private const QUERY_PARITY_HISTORY = 'query { parityHistory { date duration errors status } }';

    /** Widget polls every 30s; TTL keeps a paint + poll from double-querying. */
    private const OVERVIEW_TTL = 20.0;

    private ?array $overviewCache = null;
    private float  $overviewCacheAt = 0.0;

    /**
     * Set by gql() when a request fails at the transport layer (connect
     * refused / timed out / DNS) as opposed to an application-level GraphQL
     * error. overview() reads it to fail fast on a dead host instead of
     * waiting out a connect timeout on every remaining group query. Protected
     * so tests can simulate a transport failure without real cURL.
     */
    protected bool $transportDown = false;

    private ?string $baseUrl = null;
    private string  $apiKey = '';
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
        $this->baseUrl       = $this->config->get('unraid_url');
        $this->apiKey        = $this->config->get('unraid_api_key') ?? '';
        $this->skipTlsVerify = $this->config->get('unraid_skip_tls_verify') === '1';
        $this->enabled       = $this->config->get('unraid_enabled') !== '0';
        $this->configLoaded  = true;
    }

    public function reset(): void
    {
        $this->configLoaded    = false;
        $this->baseUrl         = null;
        $this->apiKey          = '';
        $this->skipTlsVerify   = false;
        $this->enabled         = true;
        $this->overviewCache   = null;
        $this->overviewCacheAt = 0.0;
        $this->transportDown   = false;
    }

    public function ping(): bool
    {
        $data = $this->gql(self::QUERY_PING);
        return isset($data['info']);
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
        $array = $this->mapArray($this->gql(self::QUERY_ARRAY));
        // Fail fast on a dead host: if the first query couldn't even reach the
        // box (connect refused / timed out / DNS), the remaining four group
        // queries would each burn another connect timeout (~12s total). A
        // GraphQL app-level error (HTTP 200 with {errors}) does NOT set this,
        // so a partial-scope key still queries every group.
        if ($this->transportDown) {
            return null; // don't cache — retry next call
        }
        $system = $this->mapSystem($this->gql(self::QUERY_INFO), $this->gql(self::QUERY_METRICS));
        $docker = $this->mapDocker($this->gql(self::QUERY_DOCKER));
        $ups    = $this->mapUps($this->gql(self::QUERY_UPS));
        $paritySizes = array_filter(array_column($array['parities'] ?? [], 'size'));
        $parity = $this->mapParity(
            $this->gql(self::QUERY_PARITY_STATUS),
            $this->gql(self::QUERY_PARITY_HISTORY),
            $paritySizes !== [] ? (float) max($paritySizes) : null,
        );

        if ($array === null && $system === null && $docker === null && $ups === null && $parity === null) {
            return null; // fully unreachable — don't cache, retry next call
        }

        $this->overviewCache   = ['array' => $array, 'system' => $system, 'docker' => $docker, 'ups' => $ups, 'parity' => $parity];
        $this->overviewCacheAt = $now;
        return $this->overviewCache;
    }

    private function mapArray(?array $data): ?array
    {
        $a = $data['array'] ?? null;
        if (!is_array($a)) return null;

        $kb = $a['capacity']['kilobytes'] ?? [];
        $mapDisk = static fn(array $d): array => [
            'name'   => isset($d['name']) ? (string) $d['name'] : null,
            'temp'   => isset($d['temp']) ? (int) $d['temp'] : null,
            'status' => isset($d['status']) ? (string) $d['status'] : null,
            'size'   => isset($d['fsSize']) ? (float) $d['fsSize'] : null,
            'free'   => isset($d['fsFree']) ? (float) $d['fsFree'] : null,
            'used'   => isset($d['fsUsed']) ? (float) $d['fsUsed'] : null,
        ];

        return [
            'state'    => isset($a['state']) ? (string) $a['state'] : null,
            'capacity' => [
                'free'  => isset($kb['free'])  ? (float) $kb['free']  : null,
                'used'  => isset($kb['used'])  ? (float) $kb['used']  : null,
                'total' => isset($kb['total']) ? (float) $kb['total'] : null,
            ],
            'disks'    => array_map($mapDisk, array_values(array_filter((array) ($a['disks'] ?? []), 'is_array'))),
            'parities' => array_map(static fn(array $p): array => [
                'name'   => isset($p['name']) ? (string) $p['name'] : null,
                'temp'   => isset($p['temp']) ? (int) $p['temp'] : null,
                'status' => isset($p['status']) ? (string) $p['status'] : null,
                'size'   => isset($p['size']) ? (float) $p['size'] : null,
            ], array_values(array_filter((array) ($a['parities'] ?? []), 'is_array'))),
            'caches'   => array_map($mapDisk, array_values(array_filter((array) ($a['caches'] ?? []), 'is_array'))),
        ];
    }

    private function mapSystem(?array $info, ?array $metrics): ?array
    {
        $i = $info['info']    ?? null;
        $m = $metrics['metrics'] ?? null;
        if (!is_array($i) && !is_array($m)) return null;

        // os.uptime is an ISO-8601 boot timestamp (live-verified:
        // "2026-06-14T03:31:03.786Z"). Parse it to an epoch so the template
        // can render it via the locale-aware relative_date filter; keep the
        // raw string as a fallback for unparseable values.
        $uptimeRaw   = isset($i['os']['uptime']) ? (string) $i['os']['uptime'] : null;
        $uptimeEpoch = $uptimeRaw !== null ? strtotime($uptimeRaw) : false;

        return [
            'uptime'      => $uptimeRaw,
            'uptimeEpoch' => $uptimeEpoch !== false ? $uptimeEpoch : null,
            'cpuBrand'   => isset($i['cpu']['brand'])   ? (string) $i['cpu']['brand']   : null,
            'cores'      => isset($i['cpu']['cores'])   ? (int) $i['cpu']['cores']      : null,
            'threads'    => isset($i['cpu']['threads']) ? (int) $i['cpu']['threads']    : null,
            'cpuPercent' => isset($m['cpu']['percentTotal'])    ? (float) $m['cpu']['percentTotal']    : null,
            'memPercent' => isset($m['memory']['percentTotal']) ? (float) $m['memory']['percentTotal'] : null,
            'memTotal'   => isset($m['memory']['total'])        ? (float) $m['memory']['total']        : null,
            'memUsed'    => isset($m['memory']['used'])         ? (float) $m['memory']['used']         : null,
        ];
    }

    private function mapDocker(?array $data): ?array
    {
        $raw = $data['docker']['containers'] ?? null;
        if (!is_array($raw)) return null;

        $running    = 0;
        $stopped    = [];
        $containers = [];
        foreach ($raw as $c) {
            if (!is_array($c)) continue;
            $name = trim((string) (($c['names'][0] ?? '')), '/');
            $isRunning = strtoupper((string) ($c['state'] ?? '')) === 'RUNNING';
            if ($isRunning) {
                $running++;
            } elseif ($name !== '') {
                $stopped[] = $name;
            }
            if ($name !== '') {
                $containers[] = ['name' => $name, 'running' => $isRunning];
            }
        }
        usort($containers, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return ['running' => $running, 'total' => count($raw), 'stopped' => $stopped, 'containers' => $containers];
    }

    private function mapUps(?array $data): ?array
    {
        $ups = $data['upsDevices'][0] ?? null;
        if (!is_array($ups)) return null;

        return [
            'name'    => isset($ups['name']) ? (string) $ups['name'] : null,
            'battery' => isset($ups['battery']['chargeLevel'])      ? (int) $ups['battery']['chargeLevel']        : null,
            // estimatedRuntime is SECONDS (live-verified: 4302 ≈ 72 min on a
            // CP1500 at 15% load) — convert to whole minutes for the widget.
            'runtime' => isset($ups['battery']['estimatedRuntime']) ? (int) round(((float) $ups['battery']['estimatedRuntime']) / 60) : null,
            'load'    => isset($ups['power']['loadPercentage'])     ? (float) $ups['power']['loadPercentage']     : null,
        ];
    }

    /**
     * Parity health from two independent queries: `vars` (mdcmd resync state —
     * live progress) and `parityHistory` (completed checks). Either side may be
     * missing (old API / partial key scope); null only when both are.
     */
    private function mapParity(?array $status, ?array $history, ?float $sizeFallback = null): ?array
    {
        $vars = is_array($status['vars'] ?? null) ? $status['vars'] : null;
        $runs = is_array($history['parityHistory'] ?? null)
            ? array_values(array_filter($history['parityHistory'], 'is_array')) : null;
        if ($vars === null && $runs === null) return null;

        $pos  = isset($vars['mdResyncPos'])  ? (float) $vars['mdResyncPos']  : 0.0;
        $size = isset($vars['mdResyncSize']) ? (float) $vars['mdResyncSize'] : 0.0;
        // mdResyncPos > 0 ⇔ a check/rebuild is in flight. Deliberately NOT gated on
        // mdResyncSize: on arrays > 2^31 md-units the API's 32-bit Int type nulls
        // that field (live-verified), so requiring it would read "idle" mid-check.
        $running = $pos > 0;
        // Denominator preference: mdResyncSize (exact md units) when the API managed
        // to return it, else the parity-disk size from the array group — live-verified
        // to be the SAME value/units (27344764876 on a dual-parity box). No denominator
        // → progress null; the tile still shows the badge/elapsed/errors.
        $denom = $size > 0 ? $size : (float) ($sizeFallback ?? 0.0);
        $progress = ($running && $denom > 0) ? round($pos / $denom * 100, 1) : null;

        $elapsed = null;
        if ($running && (int) ($vars['sbSynced'] ?? 0) > 0) {
            $elapsed = max(0, $this->now() - (int) $vars['sbSynced']);
        }
        // ETA: prefer the live md throughput (mdResyncDb blocks per mdResyncDt
        // seconds — the same current-rate estimate Unraid's own footer shows).
        // The elapsed-average formula is only a fallback: sbSynced can reflect
        // a pause/resume rather than the check's true start (live-verified ~8h
        // drift), which skews any average computed from it.
        $dt = isset($vars['mdResyncDt']) ? (float) $vars['mdResyncDt'] : 0.0;
        $db = isset($vars['mdResyncDb']) ? (float) $vars['mdResyncDb'] : 0.0;
        if ($running && $denom > 0 && $dt > 0 && $db > 0) {
            $eta = (int) round(($denom - $pos) / ($db / $dt));
        } elseif ($elapsed !== null && $progress !== null && $progress > 0) {
            $eta = (int) round($elapsed * (100 - $progress) / $progress);
        } else {
            $eta = null;
        }

        $last = null;
        foreach ($runs ?? [] as $run) {
            $epoch = isset($run['date']) ? strtotime((string) $run['date']) : false;
            $cand = [
                'dateEpoch' => $epoch !== false ? $epoch : null,
                'duration'  => isset($run['duration']) ? (int) $run['duration'] : null,
                'errors'    => isset($run['errors'])   ? (int) $run['errors']   : null,
                'status'    => isset($run['status'])   ? (string) $run['status'] : null,
            ];
            if ($last === null || ($cand['dateEpoch'] ?? PHP_INT_MIN) > ($last['dateEpoch'] ?? PHP_INT_MIN)) {
                $last = $cand;
            }
        }

        // parityHistory reads /boot/config/parity-checks.log, which Unraid only
        // appends while its webGui Main page is open in a browser (the writer is
        // an nchan daemon that exits without listeners) — so history can lag a
        // finished check by days. vars.sbSynced2 is the kernel's completion
        // stamp and always current: synthesize the last check from it when it
        // is newer. Duration is omitted — sbSynced may mark a resume, not the
        // start, so sbSynced2−sbSynced lies on paused checks.
        $synced2 = (int) ($vars['sbSynced2'] ?? 0);
        if (!$running && $synced2 > 0 && $synced2 > ($last['dateEpoch'] ?? PHP_INT_MIN)) {
            $exit = isset($vars['sbSyncExit']) ? (int) $vars['sbSyncExit'] : null;
            $last = [
                'dateEpoch' => $synced2,
                'duration'  => null,
                'errors'    => isset($vars['sbSyncErrs']) ? (int) $vars['sbSyncErrs'] : null,
                'status'    => $exit === null ? null : ($exit === 0 ? 'COMPLETED' : ($exit === -4 ? 'CANCELLED' : 'FAILED')),
            ];
        }

        return [
            'running'    => $running,
            'progress'   => $progress,
            'elapsed'    => $elapsed,
            'etaSeconds' => $eta,
            'errors'     => $running && isset($vars['sbSyncErrs']) ? (int) $vars['sbSyncErrs'] : null,
            'last'       => $last,
        ];
    }

    /** Clock seam — parity elapsed math is testable with a fixed now. */
    protected function now(): int
    {
        return time();
    }

    /**
     * Execute one GraphQL query document. Returns the decoded `data` object,
     * or null on transport error / non-200 / GraphQL errors without data.
     * Protected so tests can substitute canned payloads.
     */
    protected function gql(string $query): ?array
    {
        $this->ensureConfig();
        if (!$this->enabled || $this->baseUrl === null || $this->baseUrl === '' || $this->apiKey === '') {
            return null;
        }
        $url = rtrim($this->baseUrl, '/') . '/graphql';

        $ch = curl_init($url);
        if ($ch === false) return null;
        $opts = $this->curlOptions();
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = (string) json_encode(['query' => $query]);
        $opts[CURLOPT_HTTPHEADER] = $this->authHeaders();
        curl_setopt_array($ch, $opts);
        $body  = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($body === false || $code !== 200) {
            // Distinguish "host unreachable" (connect/DNS/timeout) from an
            // HTTP/GraphQL error so overview() can short-circuit a dead box.
            if (in_array($errno, [CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEOUTED, CURLE_COULDNT_RESOLVE_HOST], true)) {
                $this->transportDown = true;
            }
            $this->logger->debug('UnraidClient gql failed', ['code' => $code, 'errno' => $errno]);
            return null;
        }
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded) || !is_array($decoded['data'] ?? null)) {
            // GraphQL errors (bad field, missing scope) come back as
            // {errors:[...]} with data null/absent — treat as group-missing.
            $this->logger->debug('UnraidClient gql returned no data', ['body' => substr((string) $body, 0, 300)]);
            return null;
        }
        return $decoded['data'];
    }

    /** @return array<int, string> */
    private function authHeaders(): array
    {
        $this->ensureConfig();
        return [
            'x-api-key: ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
    }

    /** Base cURL options, TLS verification driven by unraid_skip_tls_verify. */
    private function curlOptions(): array
    {
        $this->ensureConfig();
        return [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => 8,
            CURLOPT_CONNECTTIMEOUT  => 3,
            // HTTP(S) only — same SSRF stance as GluetunClient.
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            // Self-signed / myunraid.net certs are the norm on LAN Unraid GUIs;
            // the admin opts out of verification explicitly in settings.
            CURLOPT_SSL_VERIFYPEER  => !$this->skipTlsVerify,
            CURLOPT_SSL_VERIFYHOST  => $this->skipTlsVerify ? 0 : 2,
        ];
    }
}
