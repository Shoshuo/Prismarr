<?php

namespace App\Tests\Service\Unifi;

use App\Service\Unifi\UnifiInfraReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class UnifiInfraReaderTest extends TestCase
{
    private const DEVICES = [
        ['name' => 'MAJ UCG Fiber', 'type' => 'ucg', 'model' => 'UCG-Fiber', 'state' => 1,
         'ip' => '192.168.1.1', 'uptime' => 27420, 'upgradable' => false, 'num_sta' => 47,
         // Gateways alone carry this array; APs and switches have no temperature
         // at all (has_temperature: false). Verified in Task 0.
         'temperatures' => [['name' => 'CPU', 'type' => 'cpu', 'value' => 52.0]],
         'system-stats' => ['cpu' => '10.3', 'mem' => '78.7']],
        ['name' => 'Upstairs U7 Lite', 'type' => 'uap', 'model' => 'U7-Lite', 'state' => 1,
         'ip' => '192.168.1.20', 'uptime' => 349260, 'upgradable' => true, 'num_sta' => 14,
         'system-stats' => ['cpu' => '2.0', 'mem' => '77.2'],
         'radio_table_stats' => [
             // Width is `bw`, not `ht`. Radio-level satisfaction is -1 in the
             // field; one radio here carries the real sentinel to lock that in.
             ['radio' => 'ng', 'channel' => 6,  'bw' => 20, 'tx_power' => 23,
              'cu_total' => 46, 'tx_retries_pct' => 13.8, 'satisfaction' => 94, 'num_sta' => 13],
             ['radio' => 'na', 'channel' => 60, 'bw' => 80, 'tx_power' => 24,
              'cu_total' => 3,  'tx_retries_pct' => 10.6, 'satisfaction' => -1, 'num_sta' => 1],
         ]],
        ['name' => 'Garage Switch', 'type' => 'usw', 'model' => 'USW-8', 'state' => 0],
    ];
    private const ROGUE = [
        ['essid' => 'ATTXEDuStX', 'channel' => 40,  'signal' => -33, 'oui' => 'Nokia'],
        ['essid' => '',           'channel' => 161, 'signal' => -38],
    ];
    private const NETWORKS = [
        ['name' => 'Default', 'vlan' => 1,  'ip_subnet' => '192.168.1.1/24',  'purpose' => 'corporate'],
        ['name' => 'IoT',     'vlan' => 20, 'ip_subnet' => '192.168.20.1/24', 'purpose' => 'corporate'],
        ['name' => 'WAN',     'purpose' => 'wan'], // excluded — not a LAN
    ];

    /** @return array{0: UnifiInfraReader, 1: StubUnifiFetcher} */
    private function reader(array $responses, bool $fail = false): array
    {
        $stub = new StubUnifiFetcher($responses, $fail);
        return [new UnifiInfraReader($stub, new NullLogger()), $stub];
    }

    private function all(): array
    {
        return ['stat/device' => self::DEVICES, 'stat/rogueap' => self::ROGUE,
                'rest/networkconf' => self::NETWORKS];
    }

    /**
     * A gateway reports its WAN address in `ip`, which is the wrong answer in a
     * LAN device inventory — the console showed a public address next to four
     * 192.168.x ones. Prefer `lan_ip` when present; everything without it (every
     * switch and AP) must keep using `ip`.
     */
    public function testGatewayPrefersItsLanAddressOverTheWanAddress(): void
    {
        [$reader] = $this->reader(['stat/device' => [
            ['name' => 'GW', 'type' => 'ucg', 'state' => 1,
             'ip' => '203.0.113.9', 'lan_ip' => '192.0.2.1'],
            ['name' => 'AP', 'type' => 'uap', 'state' => 1, 'ip' => '192.0.2.20'],
        ]]);
        $d = $reader->read()['devices'];

        $this->assertSame('192.0.2.1', $d[0]['ip']);  // gateway → LAN, not WAN
        $this->assertSame('192.0.2.20', $d[1]['ip']); // no lan_ip → unchanged
    }

    public function testDevicesMappedAndSortedOfflineFirst(): void
    {
        [$reader] = $this->reader($this->all());
        $d = $reader->read()['devices'];

        $this->assertSame('Garage Switch', $d[0]['name']); // offline first — it's the news
        $this->assertFalse($d[0]['online']);
        $this->assertSame('switch', $d[0]['kind']);
        $this->assertSame('gateway', $d[1]['kind']);
        $this->assertSame('192.168.1.1', $d[1]['ip']);
        $this->assertSame(27420, $d[1]['uptimeSeconds']);
        $this->assertSame(52.0, $d[1]['tempC']); // temperatures[0].value, gateway only
        $this->assertSame(10.3, $d[1]['cpuPercent']);
        $this->assertSame(78.7, $d[1]['memPercent']);
        $this->assertSame(47, $d[1]['clients']);
        $this->assertFalse($d[1]['upgradable']);
        $this->assertTrue($d[2]['upgradable']);
        // The AP has no `temperatures` array at all — null, not 0.0.
        $this->assertNull($d[2]['tempC']);
    }

    public function testCountsDriveTheSectionHeader(): void
    {
        [$reader] = $this->reader($this->all());
        $c = $reader->read()['counts'];

        $this->assertSame(3, $c['devices']);
        $this->assertSame(2, $c['online']);
        $this->assertSame(1, $c['upgradable']);
        $this->assertSame(2, $c['neighbors']);
    }

    public function testRadiosFlattenedWithBandLabels(): void
    {
        [$reader] = $this->reader($this->all());
        $r = $reader->read()['radios'];

        $this->assertCount(2, $r); // only the AP has radios
        $this->assertSame('Upstairs U7 Lite', $r[0]['device']);
        $this->assertSame('2.4 GHz', $r[0]['band']);
        $this->assertSame(6, $r[0]['channel']);
        $this->assertSame(20, $r[0]['widthMhz']);
        $this->assertSame(23, $r[0]['txPowerDbm']);
        $this->assertSame(46.0, $r[0]['utilizationPercent']);
        $this->assertSame(13.8, $r[0]['retryPercent']);
        $this->assertSame(94, $r[0]['satisfaction']);
        $this->assertSame(13, $r[0]['clients']);
        $this->assertSame('5 GHz', $r[1]['band']);
        $this->assertSame(80, $r[1]['widthMhz']); // from `bw`, not `ht`
    }

    /**
     * Every radio on the live console reports satisfaction: -1 (the AP-level
     * figure is the valid one). Rendering "-1%" would be a visible bug, so the
     * mapper turns any negative into null and the template shows a dash.
     */
    public function testNegativeRadioSatisfactionBecomesNull(): void
    {
        [$reader] = $this->reader($this->all());
        $r = $reader->read()['radios'];

        $this->assertNull($r[1]['satisfaction']);
        $this->assertSame(3.0, $r[1]['utilizationPercent']); // the rest still maps
    }

    public function testUnknownRadioBandDegradesToDash(): void
    {
        [$reader] = $this->reader(['stat/device' => [
            ['name' => 'AP', 'type' => 'uap', 'state' => 1,
             'radio_table_stats' => [['radio' => 'wat', 'channel' => 1]]],
        ]]);
        $this->assertSame('—', $reader->read()['radios'][0]['band']);
    }

    public function testNeighborsMappedHiddenSsidBecomesNullAndStrongestFirst(): void
    {
        [$reader, $stub] = $this->reader(['stat/rogueap' => [
            ['essid' => 'far',  'signal' => -80, 'channel' => 1],
            ['essid' => 'near', 'signal' => -30, 'channel' => 6, 'oui' => 'Roku'],
            ['essid' => '',     'signal' => -55, 'channel' => 11],
        ]]);
        $n = $reader->read()['neighbors'];

        $this->assertSame(['near', null, 'far'], array_column($n, 'ssid'));
        $this->assertSame(-30, $n[0]['signalDbm']);
        $this->assertSame('Roku', $n[0]['vendor']);
        $this->assertNull($n[2]['vendor']);

        // The classic API 400s a bodyless GET on this endpoint, so it must go
        // out as a POST carrying the time window. Locking that in here means a
        // refactor back to a GET fails a test instead of silently emptying the
        // panel in production.
        $i = array_search('/stat/rogueap', $stub->paths, true);
        $this->assertNotFalse($i);
        $this->assertSame(['within' => 24], $stub->bodies[$i]);
    }

    public function testNetworksExcludeNonLanPurposesAndSortByVlan(): void
    {
        [$reader] = $this->reader($this->all());
        $n = $reader->read()['networks'];

        $this->assertCount(2, $n);
        $this->assertSame(['Default', 'IoT'], array_column($n, 'name'));
        $this->assertSame(1, $n[0]['vlan']);
        $this->assertSame('192.168.1.1/24', $n[0]['subnet']);
    }

    public function testMissingEndpointDegradesOnePanelOnly(): void
    {
        [$reader] = $this->reader(['stat/device' => self::DEVICES,
                                   'stat/rogueap' => null, 'rest/networkconf' => null]);
        $r = $reader->read();

        $this->assertNotNull($r['devices']);
        $this->assertNotNull($r['radios']);
        $this->assertNull($r['neighbors']);
        $this->assertNull($r['networks']);
        $this->assertSame(0, $r['counts']['neighbors']);
    }

    public function testDeviceWithoutRadiosYieldsNoRadioRows(): void
    {
        [$reader] = $this->reader(['stat/device' => [self::DEVICES[0]]]);
        $this->assertNull($reader->read()['radios']);
    }

    public function testEveryEndpointEmptyReturnsNullAndDoesNotCache(): void
    {
        [$reader, $stub] = $this->reader(['stat/device' => null, 'stat/rogueap' => null,
                                          'rest/networkconf' => null]);

        $this->assertNull($reader->read());
        $reader->read();
        $this->assertCount(6, $stub->paths); // retried
    }

    public function testTransportFailureShortCircuits(): void
    {
        [$reader, $stub] = $this->reader([], fail: true);

        $this->assertNull($reader->read());
        $this->assertCount(1, $stub->paths); // device only
    }

    public function testTtlCachesWithinWindow(): void
    {
        [$reader, $stub] = $this->reader($this->all());

        $this->assertSame($reader->read(), $reader->read());
        $this->assertCount(3, $stub->paths); // not 6
    }

    public function testGarbageRowsSkippedNotFatal(): void
    {
        [$reader] = $this->reader(['stat/device' => [
            'not-an-array',
            ['type' => 'uap', 'state' => 'x', 'num_sta' => 'y', 'name' => '',
             'system-stats' => 'nope', 'radio_table_stats' => 'also-nope'],
        ]]);
        $r = $reader->read();

        $this->assertCount(1, $r['devices']);
        $this->assertNull($r['devices'][0]['name']);      // empty string → null
        $this->assertFalse($r['devices'][0]['online']);   // non-numeric state → offline
        $this->assertNull($r['devices'][0]['clients']);
        $this->assertNull($r['devices'][0]['cpuPercent']);
        $this->assertNull($r['radios']);                  // non-array radio table ignored
    }
}
