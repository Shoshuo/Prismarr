<?php

namespace App\Service\Unifi;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Network-tab `live` group: everything that is genuinely per-second. 10 s TTL.
 *
 * One stat/sta payload feeds FOUR panels (wireless, talkers, top clients,
 * reservations) — that sharing is why they are one group. Splitting them into
 * per-panel routes would re-fetch every client four times per cycle, because
 * with worker mode off the client's cache is per-request.
 *
 * Two of the four endpoints are configuration rather than telemetry
 * (rest/user = DHCP reservations, stat/device-basic = AP names), so they carry
 * their own longer TTL. The reservations panel still updates at live cadence
 * because the LIVE half of its comparison — each client's current IP — comes
 * from stat/sta.
 *
 * Shape (every leaf nullable — parse is fully defensive):
 *  [
 *    'wan'          => ?['status', 'ip', 'uptimeSeconds', 'downBps', 'upBps', 'latencyMs',
 *                        'ispName', 'drops'],
 *    'gateway'      => ?['cpuPercent', 'memPercent'],   // no temp/load in stat/health
 *    'clients'      => ?['total', 'wired', 'wireless', 'guest'],
 *    'wireless'     => ?list<['ssid', 'band', 'ap', 'clients', 'avgSignalDbm']>,
 *    'talkers'      => ?list<['name', 'bps']>,     // BYTES/sec, busiest first
 *    'topClients'   => ?list<['name', 'bytes']>,   // total, largest first
 *    'reservations' => ?['total', 'mismatched', 'rows' => list<['name', 'reservedIp',
 *                        'liveIp', 'status' => 'ok'|'mismatch'|'offline']>],
 *  ]
 *
 * `wan` and `clients` deliberately mirror UnifiClient::overview() so the tile
 * row can reuse the dashboard widget's rate()/dur() Twig macros unchanged.
 *
 * FIELD MAP verified against the live console in plan Task 0 — see
 * docs/superpowers/plans/2026-07-24-unifi-probe-output.md.
 */
final class UnifiLiveReader implements ResetInterface
{
    private const PATH_HEALTH       = '/stat/health';
    private const PATH_STA          = '/stat/sta';
    private const PATH_USER         = '/rest/user';
    private const PATH_DEVICE_BASIC = '/stat/device-basic';

    /** Matches the live region's data-dash-poll cadence (10 s). */
    private const TTL = 10.0;
    /** Reservations and AP names are config: they change monthly, not per-second. */
    private const CONFIG_TTL = 300.0;

    private const MAX_TALKERS = 8;
    private const MAX_TOP     = 10;

    /** Test seam — advances the reader's clock without sleeping. */
    public float $nowOffset = 0.0;

    private ?array $cache = null;
    private float $cacheAt = 0.0;

    /** @var ?array{users: ?array, apNames: array<string, string>} */
    private ?array $configCache = null;
    private float $configCacheAt = 0.0;

    public function __construct(
        private readonly UnifiFetcher $unifi,
        private readonly LoggerInterface $logger,
    ) {}

    public function reset(): void
    {
        $this->cache         = null;
        $this->cacheAt       = 0.0;
        $this->configCache   = null;
        $this->configCacheAt = 0.0;
    }

    public function read(): ?array
    {
        $now = microtime(true) + $this->nowOffset;
        if ($this->cache !== null && ($now - $this->cacheAt) < self::TTL) {
            return $this->cache;
        }

        $health = $this->mapHealth($this->unifi->fetch(self::PATH_HEALTH));
        // Fail fast on a dead console — the remaining calls would each burn
        // another connect timeout.
        if ($this->unifi->transportFailed()) {
            return null; // don't cache — retry next call
        }

        $sta = $this->unifi->fetch(self::PATH_STA);
        [$users, $apNames] = $this->config($now);

        $result = [
            'wan'          => $health['wan'] ?? null,
            'gateway'      => $health['gateway'] ?? null,
            'clients'      => $health['clients'] ?? null,
            'wireless'     => $this->mapWireless($sta, $apNames),
            'talkers'      => $this->mapTalkers($sta),
            'topClients'   => $this->mapTopClients($sta),
            'reservations' => $this->mapReservations($users, $sta),
        ];

        if (array_filter($result, static fn($v): bool => $v !== null) === []) {
            $this->logger->debug('UnifiLiveReader: every endpoint returned no data');
            return null; // don't cache — retry next call
        }

        $this->cache   = $result;
        $this->cacheAt = $now;
        return $this->cache;
    }

    /**
     * The two configuration endpoints, on their own longer TTL.
     *
     * @return array{0: ?array, 1: array<string, string>} raw user rows, mac => AP name
     */
    private function config(float $now): array
    {
        if ($this->configCache !== null && ($now - $this->configCacheAt) < self::CONFIG_TTL) {
            return [$this->configCache['users'], $this->configCache['apNames']];
        }

        $users = $this->unifi->fetch(self::PATH_USER);

        // AP names: device names live in the 60s infra group, but the wireless
        // panel is here in the 10s group. device-basic is a name-only payload,
        // so resolving MAC => name costs far less than a full stat/device.
        $apNames = [];
        foreach ((array) ($this->unifi->fetch(self::PATH_DEVICE_BASIC) ?? []) as $d) {
            if (!is_array($d)) continue;
            $mac  = self::str($d['mac'] ?? null);
            $name = self::str($d['name'] ?? null);
            if ($mac !== null && $name !== null) {
                $apNames[strtolower($mac)] = $name;
            }
        }

        $this->configCache   = ['users' => $users, 'apNames' => $apNames];
        $this->configCacheAt = $now;
        return [$users, $apNames];
    }

    private static function num(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }

    /** name → hostname → mac. Never empty: the panels are lists of things, not blanks. */
    private static function clientName(array $c): string
    {
        return self::str($c['name'] ?? null)
            ?? self::str($c['hostname'] ?? null)
            ?? self::str($c['mac'] ?? null)
            ?? '—';
    }

    /** Current combined throughput in BYTES/sec. */
    private static function rateOf(array $c): float
    {
        return (self::num($c['tx_bytes-r'] ?? null) ?? 0.0)
             + (self::num($c['rx_bytes-r'] ?? null) ?? 0.0);
    }

    /** Session total in bytes. */
    private static function totalOf(array $c): float
    {
        return (self::num($c['tx_bytes'] ?? null) ?? 0.0)
             + (self::num($c['rx_bytes'] ?? null) ?? 0.0);
    }

    /**
     * Same subsystem walk as UnifiClient::mapHealth(), plus the ISP name and
     * the WAN drop count — the tab has room for them where the widget's tile
     * did not.
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

        $field = static fn(?array $s, string $k): ?float =>
            is_array($s) ? self::num($s[$k] ?? null) : null;

        // num_user excludes guests/IoT on recent Network versions, so a count
        // is the SUM of the three buckets — same rule as UnifiClient.
        $count = static function (?array $s) use ($field): ?int {
            $total = null;
            foreach (['num_user', 'num_guest', 'num_iot'] as $k) {
                $v = $field($s, $k);
                if ($v !== null) $total = ($total ?? 0) + (int) $v;
            }
            return $total;
        };

        $wired    = $count($lan);
        $wireless = $count($wlan);
        $guest    = null;
        foreach ([$lan, $wlan] as $s) {
            $g = $field($s, 'num_guest');
            if ($g !== null) $guest = ($guest ?? 0) + (int) $g;
        }

        $wanBlock = null;
        if (is_array($wan) || is_array($www)) {
            $uptime  = $field($www, 'uptime');
            $latency = $field($www, 'latency');
            $drops   = $field($www, 'drops');
            $wanBlock = [
                'status'        => isset($wan['status']) ? (string) $wan['status']
                                   : (isset($www['status']) ? (string) $www['status'] : null),
                'ip'            => self::str($wan['wan_ip'] ?? null),
                'uptimeSeconds' => $uptime === null ? null : (int) $uptime,
                'downBps'       => $field($www, 'rx_bytes-r') ?? $field($wan, 'rx_bytes-r'),
                'upBps'         => $field($www, 'tx_bytes-r') ?? $field($wan, 'tx_bytes-r'),
                'latencyMs'     => $latency === null ? null : (int) $latency,
                // The ISP name is the WAN tile's subtitle ("AT&T Internet · 3 ms"),
                // and drops is a real WAN health signal. Both come free out of a
                // payload already being parsed.
                'ispName'       => self::str($wan['isp_name'] ?? null),
                'drops'         => $drops === null ? null : (int) $drops,
            ];
        }

        // CPU and memory only: stat/health's gw_system-stats has no temperature
        // and no load average (verified in Task 0). Gateway temperature comes
        // from UnifiInfraReader's devices[].tempC on the infrastructure card.
        $gw = is_array($wan['gw_system-stats'] ?? null) ? $wan['gw_system-stats'] : null;

        return [
            'wan'     => $wanBlock,
            'clients' => ($wired === null && $wireless === null) ? null : [
                'total'    => (int) (($wired ?? 0) + ($wireless ?? 0)),
                'wired'    => $wired,
                'wireless' => $wireless,
                'guest'    => $guest,
            ],
            'gateway' => $gw === null ? null : [
                'cpuPercent' => self::num($gw['cpu'] ?? null),
                'memPercent' => self::num($gw['mem'] ?? null),
            ],
        ];
    }

    /**
     * Wireless clients grouped by SSID x AP, with the mean signal per group.
     * Wired clients are excluded — this panel answers "how is each SSID doing
     * on each radio", which is meaningless for a cable.
     *
     * @param array<string, string> $apNames mac => AP name
     */
    private function mapWireless(?array $sta, array $apNames): ?array
    {
        if (!is_array($sta)) return null;
        $groups = [];
        foreach ($sta as $c) {
            if (!is_array($c) || ($c['is_wired'] ?? false) === true) continue;
            $ssid = self::str($c['essid'] ?? null);
            if ($ssid === null) continue; // not usably wireless
            $apMac = strtolower((string) ($c['ap_mac'] ?? ''));
            $band  = match (strtolower((string) ($c['radio'] ?? ''))) {
                'ng'    => '2.4 GHz',
                'na'    => '5 GHz',
                '6e'    => '6 GHz',
                default => null,
            };
            $key = $ssid . '|' . $apMac . '|' . ($band ?? '');
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'ssid'    => $ssid,
                    'band'    => $band,
                    'ap'      => $apNames[$apMac] ?? null,
                    'clients' => 0,
                    'signals' => [],
                ];
            }
            $groups[$key]['clients']++;
            $signal = self::num($c['signal'] ?? null);
            if ($signal !== null) $groups[$key]['signals'][] = $signal;
        }
        if ($groups === []) return null;

        $out = [];
        foreach ($groups as $g) {
            $out[] = [
                'ssid'         => $g['ssid'],
                'band'         => $g['band'],
                'ap'           => $g['ap'],
                'clients'      => $g['clients'],
                'avgSignalDbm' => $g['signals'] === [] ? null
                    : (int) round(array_sum($g['signals']) / count($g['signals'])),
            ];
        }
        // Busiest SSID first, then by name for a stable order across polls.
        usort($out, static fn(array $a, array $b): int =>
            [$b['clients'], $a['ssid']] <=> [$a['clients'], $b['ssid']]);
        return $out;
    }

    /** Busiest clients right now. Idle clients are omitted — a bar of 0 is noise. */
    private function mapTalkers(?array $sta): ?array
    {
        if (!is_array($sta)) return null;
        $out = [];
        foreach ($sta as $c) {
            if (!is_array($c)) continue;
            $bps = self::rateOf($c);
            if ($bps <= 0.0) continue;
            $out[] = ['name' => self::clientName($c), 'bps' => $bps];
        }
        if ($out === []) return null;
        usort($out, static fn(array $a, array $b): int => $b['bps'] <=> $a['bps']);
        return array_slice($out, 0, self::MAX_TALKERS);
    }

    /** Largest data movers this session. */
    private function mapTopClients(?array $sta): ?array
    {
        if (!is_array($sta)) return null;
        $out = [];
        foreach ($sta as $c) {
            if (!is_array($c)) continue;
            $bytes = self::totalOf($c);
            if ($bytes <= 0.0) continue;
            $out[] = ['name' => self::clientName($c), 'bytes' => $bytes];
        }
        if ($out === []) return null;
        usort($out, static fn(array $a, array $b): int => $b['bytes'] <=> $a['bytes']);
        return array_slice($out, 0, self::MAX_TOP);
    }

    /**
     * DHCP reservations vs the address each client actually holds.
     *
     * `mismatched` counts only status 'mismatch' — a reserved device that is
     * merely offline has not violated its reservation, and counting it would
     * cry wolf every time a laptop sleeps.
     */
    private function mapReservations(?array $users, ?array $sta): ?array
    {
        if (!is_array($users)) return null;

        $liveByMac = [];
        foreach ((array) ($sta ?? []) as $c) {
            if (!is_array($c)) continue;
            $mac = self::str($c['mac'] ?? null);
            if ($mac !== null) $liveByMac[strtolower($mac)] = self::str($c['ip'] ?? null);
        }

        $rows = [];
        foreach ($users as $u) {
            if (!is_array($u)) continue;
            if (($u['use_fixedip'] ?? false) !== true) continue; // not a reservation
            $mac      = self::str($u['mac'] ?? null);
            $reserved = self::str($u['fixed_ip'] ?? null);
            if ($mac === null || $reserved === null) continue;   // unusable row

            $isOnline = array_key_exists(strtolower($mac), $liveByMac);
            $liveIp   = $isOnline ? $liveByMac[strtolower($mac)] : null;
            $status   = !$isOnline || $liveIp === null ? 'offline'
                : ($liveIp === $reserved ? 'ok' : 'mismatch');

            $rows[] = [
                'name'       => self::clientName($u),
                'reservedIp' => $reserved,
                'liveIp'     => $liveIp,
                'status'     => $status,
            ];
        }
        if ($rows === []) return null;

        // Mismatches first — they're the reason the panel exists and must be
        // visible without scrolling a 38-row table.
        $rank = ['mismatch' => 0, 'offline' => 1, 'ok' => 2];
        usort($rows, static fn(array $a, array $b): int =>
            [$rank[$a['status']], strtolower($a['name'])]
            <=> [$rank[$b['status']], strtolower($b['name'])]);

        return [
            'total'      => count($rows),
            'mismatched' => count(array_filter($rows, static fn(array $r): bool => $r['status'] === 'mismatch')),
            'rows'       => $rows,
        ];
    }
}
