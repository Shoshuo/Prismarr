<?php

namespace App\Tests\Service\Unifi;

use App\Service\Unifi\UnifiLiveReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class UnifiLiveReaderTest extends TestCase
{
    private const HEALTH = [
        // gw_system-stats carries cpu, mem and uptime ONLY — no temp, no
        // loadavg_1. Verified in Task 0; the fixture must not invent them.
        ['subsystem' => 'wan',  'status' => 'ok', 'wan_ip' => '203.0.113.7',
         'isp_name' => 'AT&T Internet', 'isp_organization' => 'AT&T Enterprises, LLC',
         'gw_system-stats' => ['cpu' => '11.0', 'mem' => '78.0', 'uptime' => '27420']],
        ['subsystem' => 'www',  'status' => 'ok', 'tx_bytes-r' => '69125', 'rx_bytes-r' => '96875',
         'latency' => '3', 'uptime' => '27360', 'drops' => 4],
        ['subsystem' => 'lan',  'status' => 'ok', 'num_user' => 17],
        ['subsystem' => 'wlan', 'status' => 'ok', 'num_user' => 29, 'num_guest' => 2],
    ];
    private const STA = [
        ['mac' => 'aa:bb:cc:00:00:01', 'name' => 'Amazon Echo', 'ip' => '192.168.1.50',
         'is_wired' => false, 'essid' => 'Hades', 'ap_mac' => 'ff:ff:00:00:00:01', 'radio' => 'ng',
         'signal' => -40, 'tx_bytes-r' => 1000, 'rx_bytes-r' => 5237, 'tx_bytes' => 1.0e9, 'rx_bytes' => 7.0e8],
        ['mac' => 'aa:bb:cc:00:00:02', 'hostname' => 'kitchen-echo', 'ip' => '192.168.1.51',
         'is_wired' => false, 'essid' => 'Hades', 'ap_mac' => 'ff:ff:00:00:00:01', 'radio' => 'ng',
         'signal' => -42, 'tx_bytes-r' => 500, 'rx_bytes-r' => 1337, 'tx_bytes' => 5.0e8, 'rx_bytes' => 5.0e8],
        ['mac' => 'aa:bb:cc:00:00:03', 'name' => 'HP Printer', 'ip' => '192.168.1.60',
         'is_wired' => true, 'tx_bytes-r' => 50, 'rx_bytes-r' => 6, 'tx_bytes' => 1.0e6, 'rx_bytes' => 2.0e6],
    ];
    private const USERS = [
        // Matching reservation.
        ['mac' => 'aa:bb:cc:00:00:01', 'name' => 'Amazon Echo', 'use_fixedip' => true, 'fixed_ip' => '192.168.1.50'],
        // Mismatched: reserved .99 but live on .51.
        ['mac' => 'aa:bb:cc:00:00:02', 'name' => 'Kitchen Echo', 'use_fixedip' => true, 'fixed_ip' => '192.168.1.99'],
        // Reserved but not currently online.
        ['mac' => 'aa:bb:cc:00:00:09', 'name' => 'Old Laptop', 'use_fixedip' => true, 'fixed_ip' => '192.168.1.77'],
        // Not a reservation at all — must be excluded from the total.
        ['mac' => 'aa:bb:cc:00:00:03', 'name' => 'HP Printer', 'use_fixedip' => false],
    ];
    private const DEVICE_BASIC = [
        ['mac' => 'ff:ff:00:00:00:01', 'name' => 'Upstairs U7 Lite', 'type' => 'uap'],
    ];

    /** @return array{0: UnifiLiveReader, 1: StubUnifiFetcher} */
    private function reader(array $responses, bool $fail = false): array
    {
        $stub = new StubUnifiFetcher($responses, $fail);
        return [new UnifiLiveReader($stub, new NullLogger()), $stub];
    }

    private function all(): array
    {
        return ['stat/health' => self::HEALTH, 'stat/sta' => self::STA,
                'rest/user' => self::USERS, 'stat/device-basic' => self::DEVICE_BASIC];
    }

    public function testWanAndClientsMatchTheWidgetShape(): void
    {
        [$reader] = $this->reader($this->all());
        $r = $reader->read();

        $this->assertSame('ok', $r['wan']['status']);
        $this->assertSame('203.0.113.7', $r['wan']['ip']);
        $this->assertSame(27360, $r['wan']['uptimeSeconds']);
        $this->assertSame(96875.0, $r['wan']['downBps']); // rx = download, BYTES/s
        $this->assertSame(69125.0, $r['wan']['upBps']);
        $this->assertSame(3, $r['wan']['latencyMs']);
        $this->assertSame('AT&T Internet', $r['wan']['ispName']); // WAN tile subtitle
        $this->assertSame(4, $r['wan']['drops']);

        $this->assertSame(17, $r['clients']['wired']);
        $this->assertSame(31, $r['clients']['wireless']); // 29 + 2 guest
        $this->assertSame(48, $r['clients']['total']);
        $this->assertSame(2, $r['clients']['guest']);
    }

    /**
     * CPU and memory are the only gateway figures stat/health exposes. Asserting
     * the absence of temp/load keys stops a future edit from re-adding a block
     * that would render as a permanent dash (or worse, "0 °C").
     */
    public function testGatewayCarriesCpuAndMemoryOnly(): void
    {
        [$reader] = $this->reader($this->all());
        $g = $reader->read()['gateway'];

        $this->assertSame(11.0, $g['cpuPercent']);
        $this->assertSame(78.0, $g['memPercent']);
        $this->assertSame(['cpuPercent', 'memPercent'], array_keys($g));
    }

    public function testTalkersSortedByCurrentRateDescending(): void
    {
        [$reader] = $this->reader($this->all());
        $t = $reader->read()['talkers'];

        // tx+rx rate: Echo 6237, kitchen 1837, printer 56.
        $this->assertSame(['Amazon Echo', 'kitchen-echo', 'HP Printer'], array_column($t, 'name'));
        $this->assertSame(6237.0, $t[0]['bps']);
    }

    public function testTopClientsSortedByTotalBytesDescending(): void
    {
        [$reader] = $this->reader($this->all());
        $t = $reader->read()['topClients'];

        // tx+rx total: Echo 1.7e9, kitchen 1.0e9, printer 3.0e6.
        $this->assertSame(['Amazon Echo', 'kitchen-echo', 'HP Printer'], array_column($t, 'name'));
        $this->assertSame(1.7e9, $t[0]['bytes']);
    }

    public function testNamePrecedenceIsNameThenHostnameThenMac(): void
    {
        [$reader] = $this->reader(['stat/sta' => [
            ['mac' => 'aa:bb:cc:00:00:0a', 'tx_bytes-r' => 5, 'rx_bytes-r' => 0, 'tx_bytes' => 5, 'rx_bytes' => 0],
        ]]);
        $this->assertSame('aa:bb:cc:00:00:0a', $reader->read()['talkers'][0]['name']);
    }

    public function testWirelessGroupsBySsidAndApWithAveragedSignal(): void
    {
        [$reader] = $this->reader($this->all());
        $w = $reader->read()['wireless'];

        $this->assertCount(1, $w); // both wireless clients share SSID + AP
        $this->assertSame('Hades', $w[0]['ssid']);
        $this->assertSame('Upstairs U7 Lite', $w[0]['ap']);
        $this->assertSame('2.4 GHz', $w[0]['band']);
        $this->assertSame(2, $w[0]['clients']);
        $this->assertSame(-41, $w[0]['avgSignalDbm']); // mean of -40 and -42
    }

    public function testWirelessExcludesWiredClients(): void
    {
        [$reader] = $this->reader(['stat/sta' => [self::STA[2]]]); // the wired printer only
        $this->assertNull($reader->read()['wireless']);
    }

    public function testUnresolvableApNameLeavesApNull(): void
    {
        [$reader] = $this->reader(['stat/sta' => self::STA, 'stat/device-basic' => null]);
        $this->assertNull($reader->read()['wireless'][0]['ap']);
    }

    public function testReservationStatusesAndMismatchCount(): void
    {
        [$reader] = $this->reader($this->all());
        $res = $reader->read()['reservations'];

        $this->assertSame(3, $res['total']);      // three reservations; the printer isn't one
        $this->assertSame(1, $res['mismatched']); // only the kitchen echo

        $byName = [];
        foreach ($res['rows'] as $row) { $byName[$row['name']] = $row; }

        $this->assertSame('ok', $byName['Amazon Echo']['status']);
        $this->assertSame('192.168.1.50', $byName['Amazon Echo']['liveIp']);

        $this->assertSame('mismatch', $byName['Kitchen Echo']['status']);
        $this->assertSame('192.168.1.99', $byName['Kitchen Echo']['reservedIp']);
        $this->assertSame('192.168.1.51', $byName['Kitchen Echo']['liveIp']);

        $this->assertSame('offline', $byName['Old Laptop']['status']);
        $this->assertNull($byName['Old Laptop']['liveIp']);
    }

    public function testMismatchesSortFirstSoTheyAreVisibleWithoutScrolling(): void
    {
        [$reader] = $this->reader($this->all());
        $this->assertSame('mismatch', $reader->read()['reservations']['rows'][0]['status']);
    }

    public function testReservationsNullWhenUserEndpointMissing(): void
    {
        [$reader] = $this->reader(['stat/health' => self::HEALTH, 'stat/sta' => self::STA]);
        $r = $reader->read();

        $this->assertNull($r['reservations']);
        $this->assertNotNull($r['talkers']); // sibling panels unaffected
    }

    public function testHealthMissingLeavesClientPanelsIntact(): void
    {
        [$reader] = $this->reader(['stat/sta' => self::STA, 'rest/user' => self::USERS]);
        $r = $reader->read();

        $this->assertNull($r['wan']);
        $this->assertNull($r['gateway']);
        $this->assertNull($r['clients']);
        $this->assertNotNull($r['talkers']);
        $this->assertNotNull($r['reservations']);
    }

    public function testTransportFailureShortCircuits(): void
    {
        [$reader, $stub] = $this->reader([], fail: true);

        $this->assertNull($reader->read());
        $this->assertCount(1, $stub->paths); // health only
    }

    public function testEverythingEmptyReturnsNullAndDoesNotCache(): void
    {
        [$reader, $stub] = $this->reader(['stat/health' => null, 'stat/sta' => null,
                                          'rest/user' => null, 'stat/device-basic' => null]);
        $this->assertNull($reader->read());
        $reader->read();
        $this->assertGreaterThan(4, count($stub->paths)); // retried
    }

    public function testLiveEndpointsRefreshButConfigEndpointsAreCachedLonger(): void
    {
        [$reader, $stub] = $this->reader($this->all());

        $reader->read();
        $reader->nowOffset = 15.0; // past the 10s live TTL, inside the 300s config TTL
        $reader->read();

        $count = static fn(string $needle): int => count(array_filter(
            $stub->paths, static fn(string $p): bool => str_contains($p, $needle)));

        $this->assertSame(2, $count('stat/health'));       // refreshed
        $this->assertSame(2, $count('stat/sta'));          // refreshed
        $this->assertSame(1, $count('rest/user'));         // config — still cached
        $this->assertSame(1, $count('stat/device-basic')); // config — still cached
    }

    public function testTalkersAndTopClientsAreCapped(): void
    {
        $many = [];
        for ($i = 0; $i < 40; $i++) {
            $many[] = ['mac' => sprintf('aa:bb:cc:00:01:%02x', $i), 'name' => "c$i",
                       'tx_bytes-r' => $i, 'rx_bytes-r' => 0, 'tx_bytes' => $i, 'rx_bytes' => 0];
        }
        [$reader] = $this->reader(['stat/sta' => $many]);
        $r = $reader->read();

        $this->assertCount(8, $r['talkers']);
        $this->assertCount(10, $r['topClients']);
        $this->assertSame('c39', $r['talkers'][0]['name']); // busiest first
    }

    public function testIdleClientsAreExcludedFromTalkers(): void
    {
        [$reader] = $this->reader(['stat/sta' => [
            ['mac' => 'aa:bb:cc:00:00:0b', 'name' => 'idle', 'tx_bytes-r' => 0, 'rx_bytes-r' => 0,
             'tx_bytes' => 1.0e6, 'rx_bytes' => 0],
        ]]);
        $r = $reader->read();

        $this->assertNull($r['talkers']);        // nothing is talking
        $this->assertNotNull($r['topClients']);  // but it has moved data historically
    }

    public function testGarbageRowsSkippedNotFatal(): void
    {
        [$reader] = $this->reader(['stat/sta' => [
            'not-an-array',
            // Half-garbage rate ('x' is not numeric) and no mac: the row must
            // still surface, named from `name`, with the unparseable half
            // contributing 0 instead of blowing up.
            ['name' => 'no-mac', 'tx_bytes-r' => 'x', 'rx_bytes-r' => 20],
            ['mac' => 'aa:bb:cc:00:00:0c', 'name' => 'ok', 'is_wired' => 'maybe',
             'essid' => 'S', 'signal' => 'loud', 'tx_bytes-r' => 0, 'rx_bytes-r' => 0,
             'tx_bytes' => 10, 'rx_bytes' => 0],
        ], 'rest/user' => ['not-an-array', ['use_fixedip' => true]]]);
        $r = $reader->read();

        $this->assertCount(1, $r['talkers']);              // the no-mac row still names itself
        $this->assertSame('no-mac', $r['talkers'][0]['name']);
        $this->assertNull($r['wireless'][0]['avgSignalDbm']); // non-numeric signal → null, no crash
        $this->assertNull($r['reservations']);                // a reservation with no mac/ip is unusable
    }
}
