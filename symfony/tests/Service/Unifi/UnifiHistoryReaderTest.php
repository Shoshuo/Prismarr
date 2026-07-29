<?php

namespace App\Tests\Service\Unifi;

use App\Service\Unifi\UnifiHistoryReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class UnifiHistoryReaderTest extends TestCase
{
    private const REPORT = [
        ['time' => 1751742000000, 'wan-tx_bytes' => 1.0e8, 'wan-rx_bytes' => 2.0e9],
        ['time' => 1751738400000, 'wan-tx_bytes' => 5.0e7, 'wan-rx_bytes' => 1.0e9],
    ];
    private const SPEEDTEST = [
        ['time' => 1751742000000, 'xput_download' => 914.2, 'xput_upload' => 918.0, 'latency' => 3],
        ['time' => 1751655600000, 'xput_download' => 812.5, 'xput_upload' => 900.1, 'latency' => 4],
    ];

    /** @return array{0: UnifiHistoryReader, 1: StubUnifiFetcher} */
    private function reader(array $responses, bool $fail = false): array
    {
        $stub = new StubUnifiFetcher($responses, $fail);
        return [new UnifiHistoryReader($stub, new NullLogger()), $stub];
    }

    private function all(): array
    {
        return ['hourly.site' => self::REPORT, 'archive.speedtest' => self::SPEEDTEST];
    }

    public function testMapsBothSeriesSortedAscending(): void
    {
        [$reader] = $this->reader($this->all());
        $r = $reader->read();

        // ms → s, rx = download, unsorted input sorted ascending.
        $this->assertSame(1751738400, $r['usage7d'][0]['ts']);
        $this->assertSame(1.0e9, $r['usage7d'][0]['downBytes']);
        $this->assertSame(5.0e7, $r['usage7d'][0]['upBytes']);

        $this->assertSame(1751655600, $r['speedtests'][0]['ts']);
        $this->assertSame(812.5, $r['speedtests'][0]['downMbps']);
        $this->assertSame(900.1, $r['speedtests'][0]['upMbps']);
        $this->assertSame(4, $r['speedtests'][0]['latencyMs']);
    }

    public function testRequestWindowsAre7dAnd30dInMilliseconds(): void
    {
        [$reader, $stub] = $this->reader($this->all());
        $reader->nowOverride = 1751800000;
        $reader->read();

        $report = $speedtest = null;
        foreach ($stub->paths as $i => $p) {
            if (str_contains($p, 'hourly.site'))       $report    = $stub->bodies[$i];
            if (str_contains($p, 'archive.speedtest')) $speedtest = $stub->bodies[$i];
        }
        $this->assertSame((1751800000 - 7 * 86400) * 1000, $report['start']);
        $this->assertSame(1751800000 * 1000, $report['end']);
        $this->assertSame((1751800000 - 30 * 86400) * 1000, $speedtest['start']);
    }

    public function testOneEndpointMissingLeavesTheOther(): void
    {
        [$reader] = $this->reader(['hourly.site' => self::REPORT, 'archive.speedtest' => null]);
        $r = $reader->read();

        $this->assertNotNull($r['usage7d']);
        $this->assertNull($r['speedtests']);
    }

    public function testAllEndpointsFailingReturnsNullAndDoesNotCache(): void
    {
        [$reader, $stub] = $this->reader(['hourly.site' => null, 'archive.speedtest' => null]);

        $this->assertNull($reader->read());
        $reader->read();
        $this->assertCount(4, $stub->paths); // retried, not served from cache
    }

    public function testTransportFailureShortCircuitsRemainingCalls(): void
    {
        [$reader, $stub] = $this->reader([], fail: true);

        $this->assertNull($reader->read());
        $this->assertCount(1, $stub->paths); // report only, speedtest skipped
    }

    public function testTtlCachesWithinWindow(): void
    {
        [$reader, $stub] = $this->reader($this->all());

        $this->assertSame($reader->read(), $reader->read());
        $this->assertCount(2, $stub->paths); // not 4
    }

    public function testGarbageRowsSkippedNotFatal(): void
    {
        [$reader] = $this->reader([
            'hourly.site' => [
                'not-an-array',
                ['time' => 'nope', 'wan-rx_bytes' => 1.0],
                ['time' => 1751738400000, 'wan-rx_bytes' => 'x', 'wan-tx_bytes' => null],
                ['time' => 1751742000000, 'wan-rx_bytes' => 5.0e8, 'wan-tx_bytes' => 1.0e8],
            ],
            'archive.speedtest' => [
                ['time' => 1751742000000, 'xput_download' => null, 'xput_upload' => 'x', 'latency' => null],
            ],
        ]);
        $r = $reader->read();

        $this->assertCount(2, $r['usage7d']);                   // only rows with a usable ts
        $this->assertSame(0.0, $r['usage7d'][0]['downBytes']);  // non-numeric byte count → 0.0
        $this->assertNull($r['speedtests'][0]['downMbps']);     // but null throughput STAYS null
        $this->assertNull($r['speedtests'][0]['upMbps']);
        $this->assertNull($r['speedtests'][0]['latencyMs']);
    }
}
