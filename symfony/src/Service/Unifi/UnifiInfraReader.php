<?php

namespace App\Service\Unifi;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Network-tab `infra` group: physical topology and RF — the things that change
 * on the order of minutes. 60 s TTL.
 *
 * One stat/device fetch feeds BOTH the infrastructure cards and the flattened
 * per-radio RF table; that sharing is the reason these panels are grouped.
 *
 * Shape (every leaf nullable — parse is fully defensive):
 *  [
 *    'devices'   => ?list<['name' => ?string, 'model' => ?string, 'kind' => string,
 *                          'ip' => ?string, 'online' => bool, 'uptimeSeconds' => ?int,
 *                          'cpuPercent' => ?float, 'memPercent' => ?float,
 *                          'tempC' => ?float (gateway only), 'clients' => ?int,
 *                          'upgradable' => bool]>,
 *    'radios'    => ?list<['device' => ?string, 'band' => string, 'channel' => ?int,
 *                          'widthMhz' => ?int, 'txPowerDbm' => ?int,
 *                          'utilizationPercent' => ?float, 'retryPercent' => ?float,
 *                          'satisfaction' => ?int, 'clients' => ?int]>,
 *    'neighbors' => ?list<['ssid' => ?string, 'channel' => ?int,
 *                          'signalDbm' => ?int, 'vendor' => ?string]>,
 *    'networks'  => ?list<['name' => ?string, 'vlan' => ?int, 'subnet' => ?string]>,
 *    'counts'    => ['devices' => int, 'online' => int, 'upgradable' => int, 'neighbors' => int],
 *  ]
 *
 * `counts` exists because the reference dashboard's composite "Watch items"
 * tile spanned two poll cadences; those figures live in the section headers
 * that already own the data instead (see the spec's deviations section).
 *
 * FIELD MAP verified against the live console in plan Task 0 — see
 * docs/superpowers/plans/2026-07-24-unifi-probe-output.md.
 */
final class UnifiInfraReader implements ResetInterface
{
    private const PATH_DEVICE   = '/stat/device';
    /**
     * POST, not GET: `list/rogueap` 400s on a bodyless GET (Task 0). The classic
     * API wants stat/rogueap with a lookback window.
     */
    private const PATH_ROGUEAP  = '/stat/rogueap';
    private const ROGUEAP_HOURS = 24;
    private const PATH_NETWORKS = '/rest/networkconf';

    /** Matches the infra region's data-dash-poll cadence (60 s). */
    private const TTL = 60.0;

    /**
     * Gateway-only array of {name, type, value}; `general_temperature` does not
     * exist on this firmware and `has_temperature` is false on every device.
     */
    private const FIELD_TEMP    = 'temperatures';
    private const FIELD_CLIENTS = 'num_sta';
    private const FIELD_UTIL    = 'cu_total';
    private const FIELD_RETRY   = 'tx_retries_pct';
    /** Multiplier to reach 0-100. Set to 100.0 if the console reports 0-1. */
    private const SCALE_UTIL  = 1.0;
    private const SCALE_RETRY = 1.0;

    /** networkconf carries WAN and VPN objects too; the VLAN panel wants LANs. */
    private const LAN_PURPOSES = ['corporate', 'guest', 'vlan-only'];

    private ?array $cache = null;
    private float $cacheAt = 0.0;

    public function __construct(
        private readonly UnifiFetcher $unifi,
        private readonly LoggerInterface $logger,
    ) {}

    public function reset(): void
    {
        $this->cache   = null;
        $this->cacheAt = 0.0;
    }

    public function read(): ?array
    {
        $now = microtime(true);
        if ($this->cache !== null && ($now - $this->cacheAt) < self::TTL) {
            return $this->cache;
        }

        $raw = $this->unifi->fetch(self::PATH_DEVICE);
        // Fail fast on a dead console — the remaining two calls would each
        // burn another connect timeout. An HTTP/application error does NOT
        // trigger this, so a partial API surface still tries every endpoint.
        if ($this->unifi->transportFailed()) {
            return null; // don't cache — retry next call
        }

        $devices   = $this->mapDevices($raw);
        $radios    = $this->mapRadios($raw);
        $neighbors = $this->mapNeighbors(
            $this->unifi->fetch(self::PATH_ROGUEAP, ['within' => self::ROGUEAP_HOURS]),
        );
        $networks  = $this->mapNetworks($this->unifi->fetch(self::PATH_NETWORKS));

        if ($devices === null && $neighbors === null && $networks === null) {
            $this->logger->debug('UnifiInfraReader: every endpoint returned no data');
            return null; // don't cache — retry next call
        }

        $this->cache = [
            'devices'   => $devices,
            'radios'    => $radios,
            'neighbors' => $neighbors,
            'networks'  => $networks,
            'counts'    => [
                'devices'    => $devices === null ? 0 : count($devices),
                'online'     => $devices === null ? 0
                    : count(array_filter($devices, static fn(array $d): bool => $d['online'])),
                'upgradable' => $devices === null ? 0
                    : count(array_filter($devices, static fn(array $d): bool => $d['upgradable'])),
                'neighbors'  => $neighbors === null ? 0 : count($neighbors),
            ],
        ];
        $this->cacheAt = $now;
        return $this->cache;
    }

    /** Same `kind` vocabulary as UnifiClient::mapDevices() — keep the two in sync. */
    private static function kindOf(string $type): string
    {
        return match ($type) {
            'ugw', 'udm', 'uxg', 'ucg' => 'gateway',
            'usw'                      => 'switch',
            'uap'                      => 'ap',
            default                    => 'other',
        };
    }

    private static function num(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    /** Empty strings become null so templates can use a single `?? '—'` fallback. */
    private static function str(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * Device temperature in °C, or null. Only the gateway reports one, as a
     * `temperatures: [{name, type, value}]` array — there is no scalar
     * `general_temperature` field on this firmware. First entry wins: the
     * gateway lists CPU first and the card shows a single figure.
     */
    private static function tempOf(array $d): ?float
    {
        $temps = $d[self::FIELD_TEMP] ?? null;
        if (!is_array($temps) || !is_array($temps[0] ?? null)) return null;
        return self::num($temps[0]['value'] ?? null);
    }

    /** Offline first (they're the news), then gateway → switch → AP → other, then name. */
    private function mapDevices(?array $data): ?array
    {
        if (!is_array($data)) return null;
        $out = [];
        foreach ($data as $d) {
            if (!is_array($d)) continue;
            $stats   = is_array($d['system-stats'] ?? null) ? $d['system-stats'] : null;
            $clients = self::num($d[self::FIELD_CLIENTS] ?? null);
            $uptime  = self::num($d['uptime'] ?? null);
            $out[] = [
                'name'          => self::str($d['name'] ?? null),
                'model'         => self::str($d['model'] ?? null),
                'kind'          => self::kindOf(strtolower((string) ($d['type'] ?? ''))),
                // Gateways report their WAN address in `ip`, which is the wrong
                // answer in a LAN inventory (and needless exposure in a
                // screenshot), so prefer a LAN address when the payload carries
                // one. Switches and APs have no `lan_ip` and fall through to
                // `ip` unchanged.
                'ip'            => self::str($d['lan_ip'] ?? null) ?? self::str($d['ip'] ?? null),
                'online'        => is_numeric($d['state'] ?? null) && (int) $d['state'] === 1,
                'uptimeSeconds' => $uptime === null ? null : (int) $uptime,
                'cpuPercent'    => $stats === null ? null : self::num($stats['cpu'] ?? null),
                'memPercent'    => $stats === null ? null : self::num($stats['mem'] ?? null),
                'tempC'         => self::tempOf($d),
                'clients'       => $clients === null ? null : (int) $clients,
                // Strict true: the field is absent on some firmwares, and a
                // truthy non-bool must not light up an "update available" chip.
                'upgradable'    => ($d['upgradable'] ?? false) === true,
            ];
        }
        if ($out === []) return null;
        $rank = ['gateway' => 0, 'switch' => 1, 'ap' => 2, 'other' => 3];
        usort($out, static fn(array $a, array $b): int =>
            [$a['online'] ? 1 : 0, $rank[$a['kind']], strtolower($a['name'] ?? '')]
            <=> [$b['online'] ? 1 : 0, $rank[$b['kind']], strtolower($b['name'] ?? '')]);
        return $out;
    }

    /**
     * Flatten every AP's radio_table_stats into one row per radio so the RF
     * table is a flat list rather than a nested loop in Twig. Devices with no
     * radios (gateways, switches) contribute nothing.
     */
    private function mapRadios(?array $data): ?array
    {
        if (!is_array($data)) return null;
        $out = [];
        foreach ($data as $d) {
            if (!is_array($d) || !is_array($d['radio_table_stats'] ?? null)) continue;
            foreach ($d['radio_table_stats'] as $r) {
                if (!is_array($r)) continue;
                $ch    = self::num($r['channel'] ?? null);
                // `bw`, not `ht` — `ht` lives on radio_table, a different array.
                $bw    = self::num($r['bw'] ?? null);
                $power = self::num($r['tx_power'] ?? null);
                // Radio-level satisfaction is -1 in the field (the AP-level one
                // is the valid figure). Negative means "not reported", so null.
                $sat   = self::num($r['satisfaction'] ?? null);
                if ($sat !== null && $sat < 0) $sat = null;
                $sta   = self::num($r[self::FIELD_CLIENTS] ?? null);
                $util  = self::num($r[self::FIELD_UTIL] ?? null);
                $retry = self::num($r[self::FIELD_RETRY] ?? null);
                $out[] = [
                    'device'             => self::str($d['name'] ?? null),
                    'band'               => match (strtolower((string) ($r['radio'] ?? ''))) {
                        'ng'    => '2.4 GHz',
                        'na'    => '5 GHz',
                        '6e'    => '6 GHz',
                        default => '—',
                    },
                    'channel'            => $ch === null ? null : (int) $ch,
                    'widthMhz'           => $bw === null ? null : (int) $bw,
                    'txPowerDbm'         => $power === null ? null : (int) $power,
                    'utilizationPercent' => $util === null ? null : $util * self::SCALE_UTIL,
                    'retryPercent'       => $retry === null ? null : $retry * self::SCALE_RETRY,
                    'satisfaction'       => $sat === null ? null : (int) $sat,
                    'clients'            => $sta === null ? null : (int) $sta,
                ];
            }
        }
        return $out === [] ? null : $out;
    }

    /**
     * Neighbor BSSIDs heard by our APs, strongest signal first. Fed by a POST to
     * stat/rogueap; if that endpoint is unavailable this is permanently null and
     * Task 9's table renders its empty state — a known scope reduction, not a bug.
     */
    private function mapNeighbors(?array $data): ?array
    {
        if (!is_array($data)) return null;
        $out = [];
        foreach ($data as $r) {
            if (!is_array($r)) continue;
            $ch  = self::num($r['channel'] ?? null);
            $sig = self::num($r['signal'] ?? null);
            $out[] = [
                'ssid'      => self::str($r['essid'] ?? null), // empty = hidden network
                'channel'   => $ch === null ? null : (int) $ch,
                'signalDbm' => $sig === null ? null : (int) $sig,
                'vendor'    => self::str($r['oui'] ?? null),
            ];
        }
        if ($out === []) return null;
        // Strongest (least negative) first; unknown signal sorts last.
        usort($out, static fn(array $a, array $b): int =>
            ($b['signalDbm'] ?? PHP_INT_MIN) <=> ($a['signalDbm'] ?? PHP_INT_MIN));
        return $out;
    }

    /**
     * LAN/VLAN definitions. WAN and VPN entries share this endpoint and are
     * excluded — the panel is a VLAN inventory, not a dump of every network
     * object the console knows about. A row with no `purpose` at all is kept:
     * older firmwares omit it on plain LANs.
     */
    private function mapNetworks(?array $data): ?array
    {
        if (!is_array($data)) return null;
        $out = [];
        foreach ($data as $n) {
            if (!is_array($n)) continue;
            $purpose = strtolower((string) ($n['purpose'] ?? ''));
            if ($purpose !== '' && !in_array($purpose, self::LAN_PURPOSES, true)) continue;
            $vlan = self::num($n['vlan'] ?? null);
            $out[] = [
                'name'   => self::str($n['name'] ?? null),
                'vlan'   => $vlan === null ? null : (int) $vlan,
                'subnet' => self::str($n['ip_subnet'] ?? null),
            ];
        }
        if ($out === []) return null;
        usort($out, static fn(array $a, array $b): int =>
            ($a['vlan'] ?? PHP_INT_MAX) <=> ($b['vlan'] ?? PHP_INT_MAX));
        return $out;
    }
}
