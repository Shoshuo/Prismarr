<?php

namespace App\Tests\Dashboard;

use App\Dashboard\NetworkUsageChart;
use PHPUnit\Framework\TestCase;

class NetworkUsageChartTest extends TestCase
{
    public function testNullOnEmptyOrSinglePoint(): void
    {
        $this->assertNull(NetworkUsageChart::build(null));
        $this->assertNull(NetworkUsageChart::build([]));
        $this->assertNull(NetworkUsageChart::build([['ts' => 1, 'downBytes' => 5.0, 'upBytes' => 1.0]]));
    }

    public function testGeometrySpansViewboxAndScalesToPeak(): void
    {
        $c = NetworkUsageChart::build([
            ['ts' => 1000, 'downBytes' => 0.0,   'upBytes' => 0.0],
            ['ts' => 2000, 'downBytes' => 100.0, 'upBytes' => 50.0], // peak = 100
            ['ts' => 3000, 'downBytes' => 50.0,  'upBytes' => 25.0],
        ]);

        // x spans 0 → WIDTH; y: value 0 → HEIGHT (baseline), peak → PAD_TOP (8).
        $this->assertSame('0,120 300,8 600,64', $c['downLine']);
        $this->assertSame('0,120 300,64 600,92', $c['upLine']);
        // Area = baseline-closed polygon around the down line.
        $this->assertSame('0,120 0,120 300,8 600,64 600,120', $c['downArea']);
        $this->assertCount(3, $c['points']);
    }

    public function testUnsortedInputIsSorted(): void
    {
        $c = NetworkUsageChart::build([
            ['ts' => 3000, 'downBytes' => 50.0,  'upBytes' => 0.0],
            ['ts' => 1000, 'downBytes' => 100.0, 'upBytes' => 0.0],
        ]);
        // First x=0 must belong to ts=1000 (the peak) → y = 8.
        $this->assertStringStartsWith('0,8 ', $c['downLine']);
    }

    public function testTotalsAndLabels(): void
    {
        $c = NetworkUsageChart::build([
            ['ts' => 1751738400, 'downBytes' => 1.0e9, 'upBytes' => 5.0e7],
            ['ts' => 1751742000, 'downBytes' => 2.0e9, 'upBytes' => 5.0e7],
        ]);
        $this->assertSame('3 GB', $c['downTotal']);
        $this->assertSame('100 MB', $c['upTotal']);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $c['startLabel']);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $c['endLabel']);
        $this->assertStringContainsString('↓ 1 GB', $c['points'][0]['label']);
        $this->assertStringContainsString('↑ 50 MB', $c['points'][0]['label']);
    }

    public function testMalformedRowsSkipped(): void
    {
        $this->assertNull(NetworkUsageChart::build([
            ['ts' => 'nope', 'downBytes' => 1.0, 'upBytes' => 1.0],
            'garbage',
            ['ts' => 1000, 'downBytes' => 1.0, 'upBytes' => 1.0],
        ])); // only 1 usable point survives → null
    }

    public function testBytesFormatter(): void
    {
        $this->assertSame('0 B', NetworkUsageChart::bytes(0.0));
        $this->assertSame('999 B', NetworkUsageChart::bytes(999.0));
        $this->assertSame('2.5 MB', NetworkUsageChart::bytes(2500000.0));
        $this->assertSame('1.9 GB', NetworkUsageChart::bytes(1.85e9));
    }

    /** Two hourly buckets one hour apart. */
    private function series(): array
    {
        return [
            ['ts' => 1751738400, 'downBytes' => 1.0e9, 'upBytes' => 5.0e7],
            ['ts' => 1751742000, 'downBytes' => 2.0e9, 'upBytes' => 1.0e8],
        ];
    }

    public function testDefaultFormatOutputIsUnchanged(): void
    {
        $c = NetworkUsageChart::build($this->series());

        // Geometry: 2 points across WIDTH=600, peak = 2e9 → the peak sits at
        // PAD_TOP=8 and an idle value sits at HEIGHT=120.
        $this->assertSame('0,64 600,8', $c['downLine']);
        $this->assertSame('0,120 0,64 600,8 600,120', $c['downArea']);
        $this->assertSame('0,117.2 600,114.4', $c['upLine']);
        $this->assertSame('3 GB', $c['downTotal']);
        $this->assertSame('150 MB', $c['upTotal']);
        // Default labels are hour-of-day in the server timezone.
        $this->assertSame(date('H:i', 1751738400), $c['startLabel']);
        $this->assertSame(date('H:i', 1751742000), $c['endLabel']);
        $this->assertStringContainsString(date('H:i', 1751738400), $c['points'][0]['label']);
    }

    public function testLabelFormatAppliesToPointsAndAxis(): void
    {
        $c = NetworkUsageChart::build($this->series(), 'D');

        $this->assertSame(date('D', 1751738400), $c['startLabel']);
        $this->assertSame(date('D', 1751742000), $c['endLabel']);
        $this->assertStringStartsWith(date('D', 1751738400) . ' — ', $c['points'][0]['label']);
    }

    public function testGeometryDoesNotDependOnLabelFormat(): void
    {
        $a = NetworkUsageChart::build($this->series());
        $b = NetworkUsageChart::build($this->series(), 'D');

        foreach (['downArea', 'downLine', 'upLine', 'downTotal', 'upTotal'] as $k) {
            $this->assertSame($a[$k], $b[$k], "$k must not depend on the label format");
        }
    }
}
