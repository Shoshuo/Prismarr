<?php

namespace App\Tests\Dashboard;

use App\Dashboard\SpeedtestChart;
use PHPUnit\Framework\TestCase;

class SpeedtestChartTest extends TestCase
{
    /** Three runs, deliberately unsorted — build() must sort ascending. */
    private function runs(): array
    {
        return [
            ['ts' => 1751900000, 'downMbps' => 900.0, 'upMbps' => 800.0, 'latencyMs' => 4],
            ['ts' => 1751700000, 'downMbps' => 450.0, 'upMbps' => 400.0, 'latencyMs' => 8],
            ['ts' => 1751800000, 'downMbps' => 300.0, 'upMbps' => 200.0, 'latencyMs' => 12],
        ];
    }

    public function testNullWhenFewerThanTwoUsableRuns(): void
    {
        $this->assertNull(SpeedtestChart::build(null));
        $this->assertNull(SpeedtestChart::build([]));
        $this->assertNull(SpeedtestChart::build([['ts' => 1, 'downMbps' => 100.0, 'upMbps' => 90.0, 'latencyMs' => 5]]));
        // Rows with no usable throughput at all do not count toward the two.
        $this->assertNull(SpeedtestChart::build([
            ['ts' => 1, 'downMbps' => null, 'upMbps' => null, 'latencyMs' => 5],
            ['ts' => 2, 'downMbps' => null, 'upMbps' => null, 'latencyMs' => 6],
        ]));
    }

    public function testSortsAscendingAndScalesThroughputToPeak(): void
    {
        $c = SpeedtestChart::build($this->runs());

        // Sorted: 450 (x=0), 300 (x=300), 900 (x=600). Peak = 900 → y=PAD_TOP=8.
        // Idle baseline is HEIGHT=120; y = 120 - (v/900) * (120-8).
        $this->assertSame('0,64 300,82.7 600,8', $c['downLine']);
        $this->assertSame('900 Mbps', $c['peakMbps']);
        $this->assertSame(date('M j', 1751700000), $c['startLabel']);
        $this->assertSame(date('M j', 1751900000), $c['endLabel']);
    }

    public function testLatencyUsesItsOwnScale(): void
    {
        $c = SpeedtestChart::build($this->runs());

        // Latency peak = 12 ms → that run sits at PAD_TOP; 4 ms is near baseline.
        // Sorted order is 8, 12, 4.
        $this->assertSame('0,45.3 300,8 600,82.7', $c['latencyLine']);
        $this->assertSame(4,  $c['latencyMin']);
        $this->assertSame(8,  $c['latencyAvg']);
        $this->assertSame(12, $c['latencyMax']);
    }

    public function testLatestRunIsTheNewestNotTheLast(): void
    {
        $c = SpeedtestChart::build($this->runs());

        $this->assertSame('900 Mbps', $c['latestDown']);
        $this->assertSame('800 Mbps', $c['latestUp']);
        $this->assertSame('4 ms', $c['latestLatency']);
    }

    public function testRunMissingLatencyStillPlotsThroughput(): void
    {
        $c = SpeedtestChart::build([
            ['ts' => 100, 'downMbps' => 100.0, 'upMbps' => 50.0, 'latencyMs' => null],
            ['ts' => 200, 'downMbps' => 200.0, 'upMbps' => 100.0, 'latencyMs' => null],
        ]);

        $this->assertSame('0,64 600,8', $c['downLine']);
        $this->assertSame('', $c['latencyLine']);   // nothing to draw
        $this->assertNull($c['latencyMin']);
        $this->assertNull($c['latencyAvg']);
        $this->assertNull($c['latencyMax']);
        $this->assertNull($c['latestLatency']);
    }

    public function testGarbageRowsAreSkippedNotFatal(): void
    {
        $c = SpeedtestChart::build([
            ['ts' => 'not-a-number', 'downMbps' => 100.0, 'upMbps' => 1.0, 'latencyMs' => 1],
            ['ts' => 100, 'downMbps' => 'x', 'upMbps' => null, 'latencyMs' => 'y'],
            ['ts' => 200, 'downMbps' => 100.0, 'upMbps' => 50.0, 'latencyMs' => 5],
            ['ts' => 300, 'downMbps' => 200.0, 'upMbps' => 100.0, 'latencyMs' => 7],
            'not-an-array',
        ]);

        $this->assertNotNull($c);
        $this->assertCount(2, $c['points']); // only the two fully usable runs
    }

    public function testHoverLabelCarriesDateAndBothDirections(): void
    {
        $c = SpeedtestChart::build($this->runs());

        $this->assertStringContainsString(date('M j, H:i', 1751700000), $c['points'][0]['label']);
        $this->assertStringContainsString('450 Mbps', $c['points'][0]['label']);
        $this->assertStringContainsString('400 Mbps', $c['points'][0]['label']);
        $this->assertStringContainsString('8 ms', $c['points'][0]['label']);
    }
}
