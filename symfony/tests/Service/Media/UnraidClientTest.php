<?php

namespace App\Tests\Service\Media;

use App\Service\ConfigService;
use App\Service\Media\UnraidClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * UnraidClient — GraphQL client for the Unraid 7 API.
 * Tests override gql() so no HTTP happens; each canned payload mirrors the
 * `data` object of the corresponding group query.
 */
#[AllowMockObjectsWithoutExpectations]
class UnraidClientTest extends TestCase
{
    /** @param array<string, ?array> $responses  keyed by a substring of the query */
    private function makeClient(array $responses, array $settings = []): UnraidClient
    {
        $settings += [
            'unraid_url'             => 'https://tower.local',
            'unraid_api_key'         => 'k3y',
            'unraid_enabled'         => null,
            'unraid_skip_tls_verify' => null,
        ];
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturnCallback(fn(string $k) => $settings[$k] ?? null);

        return new class($config, $this->createMock(LoggerInterface::class), $responses) extends UnraidClient {
            public array $queriesSent = [];
            public ?int $nowOverride = null;
            public function __construct($config, $logger, private array $responses)
            {
                parent::__construct($config, $logger);
            }
            protected function gql(string $query): ?array
            {
                $this->queriesSent[] = $query;
                foreach ($this->responses as $needle => $payload) {
                    if (str_contains($query, $needle)) {
                        return $payload;
                    }
                }
                return null;
            }
            protected function now(): int { return $this->nowOverride ?? parent::now(); }
        };
    }

    private const ARRAY_DATA = ['array' => [
        'state'    => 'STARTED',
        'capacity' => ['kilobytes' => ['free' => '1000', 'used' => '3000', 'total' => '4000']],
        'disks'    => [
            ['name' => 'disk1', 'temp' => 38, 'status' => 'DISK_OK', 'fsSize' => '4000', 'fsFree' => '1000', 'fsUsed' => '3000'],
        ],
        'parities' => [['name' => 'parity', 'temp' => 41, 'status' => 'DISK_OK', 'size' => '10000']],
        'caches'   => [['name' => 'cache', 'temp' => 35, 'fsSize' => '500', 'fsFree' => '200', 'fsUsed' => '300']],
    ]];
    private const INFO_DATA = ['info' => [
        'os'  => ['uptime' => '2026-06-20T04:05:06Z'],
        'cpu' => ['brand' => 'AMD Ryzen 7', 'cores' => 8, 'threads' => 16],
    ]];
    private const METRICS_DATA = ['metrics' => [
        'cpu'    => ['percentTotal' => 12.5],
        'memory' => ['percentTotal' => 40.0, 'total' => '32000000000', 'used' => '12800000000'],
    ]];
    private const DOCKER_DATA = ['docker' => ['containers' => [
        ['names' => ['/plex'],     'state' => 'RUNNING'],
        ['names' => ['/radarr'],   'state' => 'RUNNING'],
        ['names' => ['/old-app'],  'state' => 'EXITED'],
    ]]];
    private const UPS_DATA = ['upsDevices' => [[
        'name'    => 'APC',
        'battery' => ['chargeLevel' => 100, 'estimatedRuntime' => 4302], // seconds (≈72 min)
        'power'   => ['loadPercentage' => 18.0],
    ]]];
    private const PARITY_STATUS_RUNNING = ['vars' => [
        'mdResyncPos' => '5000', 'mdResyncSize' => '10000',
        'sbSynced' => '1750000000', 'sbSyncErrs' => '3',
    ]];
    private const PARITY_STATUS_IDLE = ['vars' => [
        'mdResyncPos' => '0', 'mdResyncSize' => '10000',
        'sbSynced' => '1750000000', 'sbSyncErrs' => '0',
    ]];
    private const PARITY_STATUS_RUNNING_NULLSIZE = ['vars' => [
        'mdResyncPos' => '5000', 'mdResyncSize' => null,
        'sbSynced' => '1750000000', 'sbSyncErrs' => '0',
    ]];
    private const PARITY_HISTORY = ['parityHistory' => [
        // Deliberately oldest-first: mapParity must pick the newest by date.
        ['date' => '2026-05-01 06:00:00', 'duration' => 80000, 'errors' => 2, 'status' => 'OK'],
        ['date' => '2026-06-01 06:00:00', 'duration' => 76440, 'errors' => 0, 'status' => 'OK'],
    ]];

    private function allGroups(): array
    {
        return [
            'array {'         => self::ARRAY_DATA,
            'info {'          => self::INFO_DATA,
            'metrics {'       => self::METRICS_DATA,
            'docker {'        => self::DOCKER_DATA,
            'upsDevices'      => self::UPS_DATA,
            'vars {'          => self::PARITY_STATUS_IDLE,
            'parityHistory {' => self::PARITY_HISTORY,
        ];
    }

    public function testOverviewHappyPathMapsAllGroups(): void
    {
        $o = $this->makeClient($this->allGroups())->overview();

        $this->assertNotNull($o);
        $this->assertSame('STARTED', $o['array']['state']);
        $this->assertSame(3000.0, $o['array']['capacity']['used']);
        $this->assertCount(1, $o['array']['disks']);
        $this->assertSame('disk1', $o['array']['disks'][0]['name']);
        $this->assertSame(38, $o['array']['disks'][0]['temp']);
        $this->assertCount(1, $o['array']['parities']);
        $this->assertCount(1, $o['array']['caches']);

        $this->assertSame('AMD Ryzen 7', $o['system']['cpuBrand']);
        $this->assertSame(12.5, $o['system']['cpuPercent']);
        $this->assertSame(40.0, $o['system']['memPercent']);
        $this->assertSame('2026-06-20T04:05:06Z', $o['system']['uptime']);
        $this->assertSame(strtotime('2026-06-20T04:05:06Z'), $o['system']['uptimeEpoch']);

        $this->assertSame(2, $o['docker']['running']);
        $this->assertSame(3, $o['docker']['total']);
        $this->assertSame(['old-app'], $o['docker']['stopped']);

        $this->assertSame(100, $o['ups']['battery']);
        $this->assertSame(72, $o['ups']['runtime'], 'estimatedRuntime seconds must convert to whole minutes');
        $this->assertSame(18.0, $o['ups']['load']);
    }

    public function testMissingGroupsDegradeToNullWithoutKillingOthers(): void
    {
        // UPS query fails (no UPS / scope missing) — the other groups survive.
        $responses = $this->allGroups();
        unset($responses['upsDevices']);
        $o = $this->makeClient($responses)->overview();

        $this->assertNotNull($o);
        $this->assertNull($o['ups']);
        $this->assertNotNull($o['array']);
        $this->assertNotNull($o['docker']);
    }

    public function testOverviewIsNullWhenEveryGroupFails(): void
    {
        $this->assertNull($this->makeClient([])->overview());
    }

    public function testDeadHostShortCircuitsAfterFirstQuery(): void
    {
        // Simulate a transport-level failure (connect refused/timeout) on the
        // first (array) query: gql() sets transportDown and returns null.
        // overview() must bail immediately without issuing the other 4 queries.
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturnCallback(fn(string $k) => [
            'unraid_url'     => 'https://tower.local',
            'unraid_api_key' => 'k3y',
        ][$k] ?? null);

        $client = new class($config, $this->createMock(LoggerInterface::class)) extends UnraidClient {
            public int $calls = 0;
            protected function gql(string $query): ?array
            {
                $this->calls++;
                $this->transportDown = true; // first (and only) call: host down
                return null;
            }
        };

        $this->assertNull($client->overview());
        $this->assertSame(1, $client->calls, 'must stop after the first transport-failed query');
    }

    public function testAppLevelGraphqlErrorDoesNotShortCircuit(): void
    {
        // A missing array group WITHOUT a transport failure (e.g. scope error,
        // HTTP 200 {errors}) must NOT stop the remaining group queries.
        $responses = $this->allGroups();
        unset($responses['array {']); // array group returns null, transportDown stays false
        $client = $this->makeClient($responses);
        $o = $client->overview();

        $this->assertNotNull($o);
        $this->assertNull($o['array']);
        $this->assertNotNull($o['docker']);
        $this->assertGreaterThan(1, count($client->queriesSent), 'app-level error must not short-circuit');
    }

    public function testOverviewIsNullAndSendsNothingWhenUnconfigured(): void
    {
        $client = $this->makeClient($this->allGroups(), ['unraid_url' => null]);
        $this->assertNull($client->overview());
        $this->assertSame([], $client->queriesSent);
    }

    public function testOverviewIsNullAndSendsNothingWhenKillSwitchedOff(): void
    {
        $client = $this->makeClient($this->allGroups(), ['unraid_enabled' => '0']);
        $this->assertNull($client->overview());
        $this->assertSame([], $client->queriesSent);
    }

    public function testOverviewIsCachedWithinTtl(): void
    {
        $client = $this->makeClient($this->allGroups());
        $client->overview();
        $sent = count($client->queriesSent);
        $client->overview();
        $this->assertSame($sent, count($client->queriesSent), 'second overview() within TTL must not re-query');
    }

    public function testSystemSurvivesMetricsBeingUnavailable(): void
    {
        // Older API without `metrics` — uptime/brand still come from `info`.
        $responses = $this->allGroups();
        unset($responses['metrics {']);
        $o = $this->makeClient($responses)->overview();

        $this->assertNotNull($o['system']);
        $this->assertNull($o['system']['cpuPercent']);
        $this->assertSame('2026-06-20T04:05:06Z', $o['system']['uptime']);
    }

    public function testAuthHeaderUsesXApiKey(): void
    {
        $client  = $this->makeClient([]);
        $headers = (new \ReflectionMethod($client, 'authHeaders'))->invoke($client);
        $this->assertContains('x-api-key: k3y', $headers);
    }

    public function testTlsVerificationTogglesWithSetting(): void
    {
        $on  = $this->makeClient([]);
        $opts = (new \ReflectionMethod($on, 'curlOptions'))->invoke($on);
        $this->assertTrue($opts[CURLOPT_SSL_VERIFYPEER]);

        $off = $this->makeClient([], ['unraid_skip_tls_verify' => '1']);
        $opts = (new \ReflectionMethod($off, 'curlOptions'))->invoke($off);
        $this->assertFalse($opts[CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(0, $opts[CURLOPT_SSL_VERIFYHOST]);
    }

    public function testDockerContainersListIsCompleteAndAlphabetical(): void
    {
        $client = $this->makeClient(['docker {' => self::DOCKER_DATA]);
        $docker = $client->overview()['docker'];

        // Full list, case-insensitive alphabetical, running flag per container.
        self::assertSame([
            ['name' => 'old-app', 'running' => false],
            ['name' => 'plex',    'running' => true],
            ['name' => 'radarr',  'running' => true],
        ], $docker['containers']);
        // Legacy keys untouched.
        self::assertSame(2, $docker['running']);
        self::assertSame(3, $docker['total']);
        self::assertSame(['old-app'], $docker['stopped']);
    }

    public function testParityRunningCheckComputesProgressElapsedAndEta(): void
    {
        $client = $this->makeClient([
            'vars {'          => self::PARITY_STATUS_RUNNING,
            'parityHistory {' => self::PARITY_HISTORY,
        ]);
        $client->nowOverride = 1750050000; // 50 000 s after sbSynced

        $parity = $client->overview()['parity'];
        self::assertTrue($parity['running']);
        self::assertSame(50.0, $parity['progress']);
        self::assertSame(50000, $parity['elapsed']);
        self::assertSame(50000, $parity['etaSeconds']); // 50% done → same again
        self::assertSame(3, $parity['errors']);
        self::assertSame(strtotime('2026-06-01 06:00:00'), $parity['last']['dateEpoch']);
        self::assertSame(76440, $parity['last']['duration']);
        self::assertSame(0, $parity['last']['errors']);
    }

    public function testParityIdleShowsLastCheckOnly(): void
    {
        $client = $this->makeClient([
            'vars {'          => self::PARITY_STATUS_IDLE,
            'parityHistory {' => self::PARITY_HISTORY,
        ]);
        $parity = $client->overview()['parity'];
        self::assertFalse($parity['running']);
        self::assertNull($parity['progress']);
        self::assertNull($parity['elapsed']);
        self::assertNull($parity['errors']); // sbSyncErrs only meaningful while running
        self::assertSame(0, $parity['last']['errors']);
    }

    public function testParityRunningWithNullSizeFallsBackToParityDiskSize(): void
    {
        // Live-verified: mdResyncSize nulls (32-bit Int overflow) on big arrays,
        // while array.parities[].size returns the same value big-safe.
        $client = $this->makeClient([
            'array {'         => self::ARRAY_DATA,          // parity size 10000
            'vars {'          => self::PARITY_STATUS_RUNNING_NULLSIZE,
            'parityHistory {' => self::PARITY_HISTORY,
        ]);
        $client->nowOverride = 1750050000;

        $parity = $client->overview()['parity'];
        self::assertTrue($parity['running']);
        self::assertSame(50.0, $parity['progress']); // 5000 / 10000 via fallback
        self::assertSame(50000, $parity['elapsed']);
        self::assertSame(50000, $parity['etaSeconds']);
    }

    public function testParityRunningWithNoDenominatorStillReportsRunning(): void
    {
        $client = $this->makeClient([
            'vars {'          => self::PARITY_STATUS_RUNNING_NULLSIZE, // no array group
            'parityHistory {' => self::PARITY_HISTORY,
        ]);
        $client->nowOverride = 1750050000;

        $parity = $client->overview()['parity'];
        self::assertTrue($parity['running']);   // no more idle-lie
        self::assertNull($parity['progress']);
        self::assertNull($parity['etaSeconds']);
        self::assertSame(50000, $parity['elapsed']);
        self::assertSame(0, $parity['errors']);
    }

    public function testParityEtaPrefersCurrentThroughput(): void
    {
        // mdResyncDt/mdResyncDb = live sampling window; ETA = (denom − pos) ÷
        // (db/dt), independent of sbSynced — which drifts to the resume time
        // on paused checks (live-verified ~8h off the true start).
        $client = $this->makeClient([
            'vars {' => ['vars' => [
                'mdResyncPos' => '5000', 'mdResyncSize' => '10000',
                'mdResyncDt' => '10', 'mdResyncDb' => '100',
                'sbSynced' => '1750000000', 'sbSyncErrs' => '0',
            ]],
            'parityHistory {' => self::PARITY_HISTORY,
        ]);
        $client->nowOverride = 1750050000;

        $parity = $client->overview()['parity'];
        self::assertSame(50.0, $parity['progress']);
        self::assertSame(50000, $parity['elapsed']);   // still sbSynced-based
        self::assertSame(500, $parity['etaSeconds']);  // (10000-5000)/(100/10)
    }

    public function testParityGroupAbsentWhenBothQueriesFail(): void
    {
        $client = $this->makeClient(['docker {' => self::DOCKER_DATA]); // no parity payloads
        self::assertNull($client->overview()['parity']);
        self::assertNotNull($client->overview()['docker']); // rest of widget unaffected
    }

    public function testParityHistoryAloneStillMapsLastCheck(): void
    {
        $client = $this->makeClient(['parityHistory {' => self::PARITY_HISTORY]);
        $parity = $client->overview()['parity'];
        self::assertFalse($parity['running']);
        self::assertSame(76440, $parity['last']['duration']);
    }

    public function testParityIdleSynthesizesLastCheckFromVarsWhenNewerThanHistory(): void
    {
        // Live-verified gap: parity-checks.log (the parityHistory source) is
        // appended by a webGui nchan daemon that only runs while the Unraid
        // Main page is open in a browser — history can lag a finished check
        // by days. vars.sbSynced2 is the kernel's completion stamp and is
        // always current, so it must win when newer.
        $client = $this->makeClient([
            'vars {' => ['vars' => [
                'mdResyncPos' => '0', 'mdResyncSize' => '10000',
                'sbSynced' => '1783088534', 'sbSynced2' => '1783132190',
                'sbSyncErrs' => '5', 'sbSyncExit' => '0',
            ]],
            'parityHistory {' => self::PARITY_HISTORY, // newest entry 2026-06-01
        ]);
        $parity = $client->overview()['parity'];
        self::assertFalse($parity['running']);
        self::assertSame(1783132190, $parity['last']['dateEpoch']);
        self::assertNull($parity['last']['duration']); // true duration unknowable from vars on paused/resumed checks
        self::assertSame(5, $parity['last']['errors']);
        self::assertSame('COMPLETED', $parity['last']['status']);
    }

    public function testParityIdleKeepsHistoryLastWhenHistoryIsNewer(): void
    {
        $client = $this->makeClient([
            'vars {' => ['vars' => [
                'mdResyncPos' => '0',
                'sbSynced' => '1700000000', 'sbSynced2' => '1700003600', // 2023 — older than history
                'sbSyncErrs' => '0', 'sbSyncExit' => '0',
            ]],
            'parityHistory {' => self::PARITY_HISTORY,
        ]);
        $last = $client->overview()['parity']['last'];
        self::assertSame(strtotime('2026-06-01 06:00:00'), $last['dateEpoch']);
        self::assertSame(76440, $last['duration']);
    }

    public function testParityVarsAloneSynthesizesLastCheckWhenIdle(): void
    {
        // No parityHistory scope at all — sbSynced2 still yields a last check.
        $client = $this->makeClient(['vars {' => ['vars' => [
            'mdResyncPos' => '0',
            'sbSynced' => '1783088534', 'sbSynced2' => '1783132190',
            'sbSyncErrs' => '0', 'sbSyncExit' => '-4',
        ]]]);
        $parity = $client->overview()['parity'];
        self::assertFalse($parity['running']);
        self::assertSame(1783132190, $parity['last']['dateEpoch']);
        self::assertSame(0, $parity['last']['errors']);
        self::assertSame('CANCELLED', $parity['last']['status']); // -4 = cancelled, mirroring Unraid's own mapping
    }

    public function testParityRunningDoesNotSynthesizeLastFromVars(): void
    {
        // While a check runs, vars reflect the in-flight check — the history
        // entry stays the richer record for the previous one.
        $client = $this->makeClient([
            'vars {' => ['vars' => [
                'mdResyncPos' => '5000', 'mdResyncSize' => '10000',
                'sbSynced' => '1750000000', 'sbSynced2' => '1783132190',
                'sbSyncErrs' => '3',
            ]],
            'parityHistory {' => self::PARITY_HISTORY,
        ]);
        $client->nowOverride = 1750050000;
        $last = $client->overview()['parity']['last'];
        self::assertSame(strtotime('2026-06-01 06:00:00'), $last['dateEpoch']);
        self::assertSame(76440, $last['duration']);
    }

    public function testParityStatusQueryRequestsCompletionFields(): void
    {
        $client = $this->makeClient($this->allGroups());
        $client->overview();
        $varsQuery = implode("\n", array_filter($client->queriesSent, fn($q) => str_contains($q, 'vars {')));
        self::assertStringContainsString('sbSynced2', $varsQuery);
        self::assertStringContainsString('sbSyncExit', $varsQuery);
    }
}
