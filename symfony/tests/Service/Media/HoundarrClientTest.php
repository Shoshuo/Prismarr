<?php

namespace App\Tests\Service\Media;

use App\Service\Media\HoundarrClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class HoundarrClientTest extends TestCase
{
    /** Raw payload exactly as documented (schema 1). */
    private function fixture(): array
    {
        return [
            'schema'       => 1,
            'generated_at' => '2026-05-22T18:00:00Z',
            'totals'       => [
                'tracked'     => 11,
                'eligible'    => 7,
                'gated'       => 2,
                'unreleased'  => 1,
                'searches_7d' => 1,
            ],
        ];
    }

    public function testNormalizesDocumentedPayload(): void
    {
        $out = HoundarrClient::normalizeWidget($this->fixture());

        self::assertSame(
            ['tracked' => 11, 'eligible' => 7, 'gated' => 2, 'unreleased' => 1, 'searches7d' => 1],
            $out['totals'],
        );
        self::assertSame(strtotime('2026-05-22T18:00:00Z'), $out['generatedAtEpoch']);
        self::assertNull($out['error']);
    }

    public function testClampsNegativesAndCastsStrings(): void
    {
        $out = HoundarrClient::normalizeWidget([
            'totals' => ['tracked' => '-3', 'eligible' => '5', 'gated' => -1, 'unreleased' => 2.9, 'searches_7d' => 'abc'],
        ]);

        self::assertSame(
            ['tracked' => 0, 'eligible' => 5, 'gated' => 0, 'unreleased' => 2, 'searches7d' => 0],
            $out['totals'],
        );
    }

    public function testMissingFieldsDefaultToZeroAndUnknownKeysAreDropped(): void
    {
        $out = HoundarrClient::normalizeWidget(['totals' => ['tracked' => 4, 'secret_path' => '/mnt/x'], 'api_key' => 'leak']);

        self::assertSame(
            ['tracked' => 4, 'eligible' => 0, 'gated' => 0, 'unreleased' => 0, 'searches7d' => 0],
            $out['totals'],
        );
        self::assertSame(['totals', 'generatedAtEpoch', 'error'], array_keys($out));
    }

    public function testBadOrMissingGeneratedAtIsNull(): void
    {
        self::assertNull(HoundarrClient::normalizeWidget(['totals' => [], 'generated_at' => 'not-a-date'])['generatedAtEpoch']);
        self::assertNull(HoundarrClient::normalizeWidget(['totals' => []])['generatedAtEpoch']);
    }

    public function testTotalsNotAnArrayIsTreatedAsEmpty(): void
    {
        $out = HoundarrClient::normalizeWidget(['totals' => 'nope']);
        self::assertSame(0, $out['totals']['tracked']);
    }

    /**
     * Test double: stubs the protected transport seam, counts calls, and lets
     * tests drive config via a real ConfigService mock.
     */
    private function makeClient(array $settings, ?array $response, int &$calls): HoundarrClient
    {
        $config = $this->createMock(\App\Service\ConfigService::class);
        $config->method('get')->willReturnCallback(fn(string $k) => $settings[$k] ?? null);

        return new class($config, new \Psr\Log\NullLogger(), $response, $calls) extends HoundarrClient {
            public function __construct($config, $logger, private readonly ?array $response, private int &$calls)
            {
                parent::__construct($config, $logger);
            }
            protected function request(): ?array
            {
                $this->calls++;
                return $this->response;
            }
        };
    }

    private const CONFIGURED = ['houndarr_url' => 'http://houndarr:8877', 'houndarr_api_key' => 'hndarr_k'];

    public function testWidgetReturnsNullAndNeverCallsUpstreamWhenUnconfigured(): void
    {
        $calls = 0;
        self::assertNull($this->makeClient([], ['http' => 200, 'body' => '{}'], $calls)->widget());
        self::assertSame(0, $calls);
    }

    public function testWidgetReturnsNullWhenKillSwitchedOff(): void
    {
        $calls = 0;
        $client = $this->makeClient(self::CONFIGURED + ['houndarr_enabled' => '0'], ['http' => 200, 'body' => '{}'], $calls);
        self::assertNull($client->widget());
        self::assertSame(0, $calls);
    }

    public function testWidgetNormalizesSuccessAndCachesWithinTtl(): void
    {
        $calls = 0;
        $body = json_encode(['schema' => 1, 'generated_at' => '2026-05-22T18:00:00Z',
            'totals' => ['tracked' => 11, 'eligible' => 7, 'gated' => 2, 'unreleased' => 1, 'searches_7d' => 1]]);
        $client = $this->makeClient(self::CONFIGURED, ['http' => 200, 'body' => $body], $calls);

        $out = $client->widget();
        self::assertSame(7, $out['totals']['eligible']);
        self::assertNull($out['error']);

        $client->widget();
        self::assertSame(1, $calls); // second read served from the 45 s cache
    }

    public function testWidgetMapsAuthAndCachesTheVerdict(): void
    {
        $calls = 0;
        $client = $this->makeClient(self::CONFIGURED, ['http' => 401, 'body' => ''], $calls);

        $out = $client->widget();
        self::assertSame('auth', $out['error']);
        self::assertNull($out['totals']);

        $client->widget();
        self::assertSame(1, $calls); // auth verdict cached too — no 429-tripping re-probes
    }

    public function testWidgetReturnsNullUncachedOnServerErrorAndTransportFailure(): void
    {
        $calls = 0;
        $client = $this->makeClient(self::CONFIGURED, ['http' => 500, 'body' => ''], $calls);
        self::assertNull($client->widget());
        self::assertNull($client->widget());
        self::assertSame(2, $calls); // not cached — retry next poll

        $calls = 0;
        self::assertNull($this->makeClient(self::CONFIGURED, null, $calls)->widget());
        self::assertSame(1, $calls);
    }

    public function testWidgetReturnsNullOnMalformedJson(): void
    {
        $calls = 0;
        self::assertNull($this->makeClient(self::CONFIGURED, ['http' => 200, 'body' => 'not-json'], $calls)->widget());
    }

    public function testPingTrueOnlyOnCleanFetch(): void
    {
        $calls = 0;
        $ok = json_encode(['totals' => ['tracked' => 0, 'eligible' => 0, 'gated' => 0, 'unreleased' => 0, 'searches_7d' => 0]]);
        self::assertTrue($this->makeClient(self::CONFIGURED, ['http' => 200, 'body' => $ok], $calls)->ping());
        self::assertFalse($this->makeClient(self::CONFIGURED, ['http' => 401, 'body' => ''], $calls)->ping());
        self::assertFalse($this->makeClient(self::CONFIGURED, null, $calls)->ping());
        self::assertFalse($this->makeClient([], ['http' => 200, 'body' => $ok], $calls)->ping());
    }
}
