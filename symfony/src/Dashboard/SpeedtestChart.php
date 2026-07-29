<?php
namespace App\Dashboard;

/**
 * Pure geometry helper for the Network tab's inline-SVG speedtest history
 * chart. Turns UnifiHistoryReader::read()['speedtests'] into ready-to-render
 * coordinate strings so the Twig fragment stays dumb. No I/O, no state.
 *
 * Dual scale: download and upload share a throughput scale (peak of both),
 * latency has its own — a 900 Mbps line and a 4 ms line cannot share an axis
 * and still show variation in either. Both map into the same viewBox, so the
 * template draws one SVG with a left (Mbps) and right (ms) legend.
 *
 * null when fewer than two runs carry a usable throughput value — a single
 * dot is not a trend, and an all-null series would render an empty frame.
 */
final class SpeedtestChart
{
    public const WIDTH  = 600.0;
    public const HEIGHT = 120.0;
    private const PAD_TOP = 8.0;

    /**
     * @param ?list<array{ts: int, downMbps: ?float, upMbps: ?float, latencyMs: ?int}> $runs
     * @return ?array{downLine: string, upLine: string, latencyLine: string,
     *                points: list<array{x: float, w: float, label: string}>,
     *                latestDown: ?string, latestUp: ?string, latestLatency: ?string,
     *                latencyMin: ?int, latencyAvg: ?int, latencyMax: ?int,
     *                peakMbps: string, startLabel: string, endLabel: string}
     */
    public static function build(?array $runs): ?array
    {
        $rows = [];
        foreach ((array) ($runs ?? []) as $r) {
            if (!is_array($r) || !is_numeric($r['ts'] ?? null)) continue;
            $down = is_numeric($r['downMbps'] ?? null) ? max(0.0, (float) $r['downMbps']) : null;
            $up   = is_numeric($r['upMbps'] ?? null)   ? max(0.0, (float) $r['upMbps'])   : null;
            // A run with no throughput at all carries no trend information.
            if ($down === null && $up === null) continue;
            $rows[] = [
                'ts'      => (int) $r['ts'],
                'down'    => $down,
                'up'      => $up,
                'latency' => is_numeric($r['latencyMs'] ?? null) ? max(0, (int) $r['latencyMs']) : null,
            ];
        }
        $n = count($rows);
        if ($n < 2) return null;
        usort($rows, static fn(array $a, array $b): int => $a['ts'] <=> $b['ts']);

        $peak = 1.0; // floor of 1 avoids division by zero
        foreach ($rows as $r) {
            $peak = max($peak, $r['down'] ?? 0.0, $r['up'] ?? 0.0);
        }
        $latencies = array_values(array_filter(array_column($rows, 'latency'), static fn($v) => $v !== null));
        $latencyPeak = $latencies === [] ? 1 : max(1, max($latencies));

        $stepX = self::WIDTH / ($n - 1);
        $span  = self::HEIGHT - self::PAD_TOP;
        $y = static fn(float $v, float $max): float => round(self::HEIGHT - ($v / $max) * $span, 1);

        $downPts = $upPts = $latPts = $points = [];
        foreach ($rows as $i => $r) {
            $x = round($i * $stepX, 1);
            // A gap in one series must not shift the others — skip the point
            // in that polyline rather than substituting a zero, which would
            // draw a fake drop to the floor.
            if ($r['down'] !== null)    $downPts[] = $x . ',' . $y($r['down'], $peak);
            if ($r['up'] !== null)      $upPts[]   = $x . ',' . $y($r['up'], $peak);
            if ($r['latency'] !== null) $latPts[]  = $x . ',' . $y((float) $r['latency'], (float) $latencyPeak);
            $points[] = [
                'x'     => round(max(0.0, $x - $stepX / 2), 1),
                'w'     => round($stepX, 1),
                'label' => date('M j, H:i', $r['ts'])
                    . ' — ↓ ' . self::mbps($r['down'])
                    . ' · ↑ ' . self::mbps($r['up'])
                    . ($r['latency'] !== null ? ' · ' . $r['latency'] . ' ms' : ''),
            ];
        }

        $latest = $rows[$n - 1];

        return [
            'downLine'      => implode(' ', $downPts),
            'upLine'        => implode(' ', $upPts),
            'latencyLine'   => implode(' ', $latPts),
            'points'        => $points,
            'latestDown'    => $latest['down'] !== null ? self::mbps($latest['down']) : null,
            'latestUp'      => $latest['up'] !== null ? self::mbps($latest['up']) : null,
            'latestLatency' => $latest['latency'] !== null ? $latest['latency'] . ' ms' : null,
            'latencyMin'    => $latencies === [] ? null : min($latencies),
            'latencyAvg'    => $latencies === [] ? null : (int) round(array_sum($latencies) / count($latencies)),
            'latencyMax'    => $latencies === [] ? null : max($latencies),
            'peakMbps'      => self::mbps($peak),
            'startLabel'    => date('M j', $rows[0]['ts']),
            'endLabel'      => date('M j', $rows[$n - 1]['ts']),
        ];
    }

    /** 900.0 → '900 Mbps', 12.5 → '12.5 Mbps', null → '—'. Trailing .0 dropped. */
    public static function mbps(?float $v): string
    {
        if ($v === null) return '—';
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.') . ' Mbps';
    }
}
