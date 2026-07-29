<?php

namespace App\Tests\Service\Media;

use App\Service\ConfigService;
use App\Service\Media\UnifiClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * UnifiClient — classic Network API client on a UniFi OS console.
 * Tests override request() so no HTTP happens; each canned payload mirrors
 * the `data` list of the corresponding endpoint.
 */
#[AllowMockObjectsWithoutExpectations]
class UnifiClientTest extends TestCase
{
    /** @param array<string, ?array> $responses  keyed by a substring of the path */
    private function makeClient(array $responses, array $settings = [], bool $failTransport = false): UnifiClient
    {
        $settings += [
            'unifi_url'             => 'https://192.168.1.1',
            'unifi_api_key'         => 'k3y',
            'unifi_site'            => null,
            'unifi_enabled'         => null,
            'unifi_skip_tls_verify' => null,
        ];
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturnCallback(fn(string $k) => $settings[$k] ?? null);

        return new class($config, $this->createMock(LoggerInterface::class), $responses, $failTransport) extends UnifiClient {
            public array $pathsRequested = [];
            public array $bodiesSent = [];
            public ?int $nowOverride = null;
            public function __construct($config, $logger, private array $responses, private bool $failTransport)
            {
                parent::__construct($config, $logger);
            }
            protected function request(string $path, ?array $jsonBody = null): ?array
            {
                $this->pathsRequested[] = $path;
                $this->bodiesSent[]     = $jsonBody;
                if ($this->failTransport) {
                    $this->transportDown = true;
                    return null;
                }
                foreach ($this->responses as $needle => $payload) {
                    if (str_contains($path, $needle)) {
                        return $payload;
                    }
                }
                return null;
            }
            protected function now(): int { return $this->nowOverride ?? parent::now(); }
        };
    }

    private const HEALTH_DATA = [
        ['subsystem' => 'wan',  'status' => 'ok', 'wan_ip' => '203.0.113.7',
         'gw_system-stats' => ['cpu' => '12.3', 'mem' => '38.1', 'uptime' => '123456']],
        ['subsystem' => 'www',  'status' => 'ok', 'tx_bytes-r' => '125000', 'rx_bytes-r' => '2500000',
         'latency' => '12', 'uptime' => '864000'],
        ['subsystem' => 'wlan', 'status' => 'ok', 'num_user' => 18, 'num_guest' => 2, 'num_iot' => 7],
        ['subsystem' => 'lan',  'status' => 'ok', 'num_user' => 9,  'num_guest' => 0, 'num_iot' => 3],
    ];
    private const REPORT_DATA = [
        // Deliberately unsorted: mapUsage must sort ascending. time is epoch MS.
        ['time' => 1751742000000, 'wan-tx_bytes' => 1.0e8, 'wan-rx_bytes' => 2.0e9],
        ['time' => 1751738400000, 'wan-tx_bytes' => 5.0e7, 'wan-rx_bytes' => 1.0e9],
    ];
    private const DEVICE_DATA = [
        ['name' => 'Dream Machine', 'type' => 'udm', 'model' => 'UDM-PRO', 'state' => 1, 'uptime' => 999],
        ['name' => 'Office AP',     'type' => 'uap', 'model' => 'U6-Lite', 'state' => 1, 'uptime' => 500],
        ['name' => 'Garage Switch', 'type' => 'usw', 'model' => 'USW-8',   'state' => 0],
    ];

    private function allEndpoints(): array
    {
        return [
            'stat/health'             => self::HEALTH_DATA,
            'stat/report/hourly.site' => self::REPORT_DATA,
            'stat/device'             => self::DEVICE_DATA,
        ];
    }

    public function testUnconfiguredReturnsNullWithoutRequest(): void
    {
        $client = $this->makeClient($this->allEndpoints(), ['unifi_url' => null]);
        $this->assertNull($client->overview());
        $this->assertSame([], $client->pathsRequested);

        $client = $this->makeClient($this->allEndpoints(), ['unifi_api_key' => null]);
        $this->assertNull($client->overview());
        $this->assertSame([], $client->pathsRequested);
    }

    public function testKillSwitchDisables(): void
    {
        $client = $this->makeClient($this->allEndpoints(), ['unifi_enabled' => '0']);
        $this->assertNull($client->overview());
        $this->assertSame([], $client->pathsRequested);
    }

    public function testOverviewMapsWanClientsGateway(): void
    {
        $o = $this->makeClient($this->allEndpoints())->overview();

        $this->assertSame('ok', $o['wan']['status']);
        $this->assertSame('203.0.113.7', $o['wan']['ip']);
        $this->assertSame(864000, $o['wan']['uptimeSeconds']);
        $this->assertSame(2500000.0, $o['wan']['downBps']); // rx = download
        $this->assertSame(125000.0, $o['wan']['upBps']);
        $this->assertSame(12, $o['wan']['latencyMs']);

        $this->assertSame(27, $o['clients']['wireless']); // 18 + 2 + 7
        $this->assertSame(12, $o['clients']['wired']);    // 9 + 0 + 3
        $this->assertSame(39, $o['clients']['total']);
        $this->assertSame(2,  $o['clients']['guest']);    // wlan 2 + lan 0

        $this->assertSame(12.3, $o['gateway']['cpuPercent']);
        $this->assertSame(38.1, $o['gateway']['memPercent']);
    }

    public function testMissingSubsystemsTolerated(): void
    {
        $health = [['subsystem' => 'lan', 'status' => 'ok', 'num_user' => 5]];
        $o = $this->makeClient(['stat/health' => $health, 'stat/report' => null, 'stat/device' => null])->overview();

        $this->assertNull($o['wan']);      // no wan/www subsystem
        $this->assertNull($o['gateway']);
        $this->assertSame(5, $o['clients']['wired']);
        $this->assertNull($o['clients']['wireless']);
        $this->assertSame(5, $o['clients']['total']);
        $this->assertNull($o['usage24h']);
        $this->assertNull($o['devices']);
    }

    public function testUsageMappingSortsAndConvertsMsToSeconds(): void
    {
        $o = $this->makeClient($this->allEndpoints())->overview();

        $this->assertCount(2, $o['usage24h']);
        $this->assertSame(1751738400, $o['usage24h'][0]['ts']); // sorted ascending, ms → s
        $this->assertSame(1.0e9, $o['usage24h'][0]['downBytes']); // rx = download
        $this->assertSame(5.0e7, $o['usage24h'][0]['upBytes']);
        $this->assertSame(1751742000, $o['usage24h'][1]['ts']);
    }

    public function testReportRequestUses24hMsWindow(): void
    {
        $client = $this->makeClient($this->allEndpoints());
        $client->nowOverride = 1751800000;
        $client->overview();

        $reportBody = null;
        foreach ($client->pathsRequested as $i => $p) {
            if (str_contains($p, 'report')) { $reportBody = $client->bodiesSent[$i]; }
        }
        $this->assertSame(['time', 'wan-tx_bytes', 'wan-rx_bytes'], $reportBody['attrs']);
        $this->assertSame((1751800000 - 86400) * 1000, $reportBody['start']);
        $this->assertSame(1751800000 * 1000, $reportBody['end']);
    }

    public function testDevicesMappedAndSortedOfflineFirst(): void
    {
        $o = $this->makeClient($this->allEndpoints())->overview();

        $this->assertSame('Garage Switch', $o['devices'][0]['name']); // offline first
        $this->assertFalse($o['devices'][0]['online']);
        $this->assertSame('switch', $o['devices'][0]['kind']);
        $this->assertSame('Dream Machine', $o['devices'][1]['name']); // then gateway
        $this->assertSame('gateway', $o['devices'][1]['kind']);
        $this->assertSame('Office AP', $o['devices'][2]['name']);     // then AP
        $this->assertSame('ap', $o['devices'][2]['kind']);
        $this->assertSame(999, $o['devices'][1]['uptimeSeconds']);
    }

    public function testTransportDownShortCircuits(): void
    {
        $client = $this->makeClient([], failTransport: true);
        $this->assertNull($client->overview());
        $this->assertCount(1, $client->pathsRequested); // health only, no report/device
    }

    public function testOverviewTtlCachesWithinWindow(): void
    {
        $client = $this->makeClient($this->allEndpoints());
        $first = $client->overview();
        $second = $client->overview();
        $this->assertSame($first, $second);
        $this->assertCount(3, $client->pathsRequested); // not 6
    }

    public function testAllNullEndpointsMeansNullAndNoCache(): void
    {
        $client = $this->makeClient(['stat/health' => null, 'stat/report' => null, 'stat/device' => null]);
        $this->assertNull($client->overview());
        $client->overview();
        $this->assertCount(6, $client->pathsRequested); // second call retried
    }

    public function testPing(): void
    {
        $this->assertTrue($this->makeClient($this->allEndpoints())->ping());
        $this->assertFalse($this->makeClient(['stat/health' => null])->ping());
    }

    public function testFetchDelegatesToRequestAndReturnsData(): void
    {
        $client = $this->makeClient($this->allEndpoints());

        $this->assertSame(self::DEVICE_DATA, $client->fetch('/stat/device'));
        $this->assertContains('/stat/device', $client->pathsRequested);
        $this->assertFalse($client->transportFailed());
    }

    public function testFetchReportsTransportFailureButNotApplicationMiss(): void
    {
        $failing = $this->makeClient([], failTransport: true);
        $this->assertNull($failing->fetch('/stat/device'));
        $this->assertTrue($failing->transportFailed());

        // An application-level miss (no such payload) is NOT a transport
        // failure — readers must keep trying their remaining endpoints.
        $ok = $this->makeClient($this->allEndpoints());
        $this->assertNull($ok->fetch('/stat/nonexistent'));
        $this->assertFalse($ok->transportFailed());
    }

    public function testFetchPassesJsonBodyThrough(): void
    {
        $client = $this->makeClient($this->allEndpoints());
        $client->fetch('/stat/report/hourly.site', ['attrs' => ['time']]);

        $i = array_search('/stat/report/hourly.site', $client->pathsRequested, true);
        $this->assertSame(['attrs' => ['time']], $client->bodiesSent[$i]);
    }
}
