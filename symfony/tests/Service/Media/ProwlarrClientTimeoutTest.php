<?php

namespace App\Tests\Service\Media;

use App\Service\ConfigService;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\ServiceHealthCache;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * A manual Prowlarr search fans out to every indexer and routinely takes
 * longer than the client's default 8 s. That read timeout used to empty the
 * results AND open the cross-request circuit breaker, flagging Prowlarr down
 * everywhere for the breaker window.
 */
#[AllowMockObjectsWithoutExpectations]
class ProwlarrClientTimeoutTest extends TestCase
{
    private function client(string $baseUrl, ServiceHealthCache $health): ProwlarrClient
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturn(null);
        $config->method('require')->willReturnCallback(
            static fn(string $key) => $key === 'prowlarr_url' ? $baseUrl : 'test-key',
        );

        return new ProwlarrClient($config, new NullLogger(), $health);
    }

    private function get(ProwlarrClient $client, int $timeout): ?array
    {
        return (new \ReflectionMethod($client, 'get'))->invoke($client, '/api/v1/search', ['query' => 'x'], $timeout);
    }

    public function testSearchGetsABudgetWellAboveTheDefault(): void
    {
        self::assertGreaterThanOrEqual(60, ProwlarrClient::SEARCH_TIMEOUT);
        self::assertLessThan(120, ProwlarrClient::SEARCH_TIMEOUT, 'must stay under PHP max_execution_time');
    }

    public function testAReadTimeoutAfterConnectDoesNotMarkProwlarrDown(): void
    {
        // A listening socket nobody accept()s on: the TCP connect succeeds
        // (kernel backlog), the HTTP answer never comes — a slow indexer
        // fan-out as far as curl can tell.
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server, $errstr);
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

        $health = new ServiceHealthCache(new ArrayAdapter());
        $client = $this->client('http://127.0.0.1:' . $port, $health);

        self::assertNull($this->get($client, 1));
        self::assertFalse($health->isDown('prowlarr'), 'a slow answer is not a dead service');
        self::assertSame([], $client->search('again'), 'the rest of this request still short-circuits');

        fclose($server);
    }

    public function testAConnectionFailureStillMarksProwlarrDown(): void
    {
        // Grab a free port, then close it so the connect is refused.
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port  = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $health = new ServiceHealthCache(new ArrayAdapter());
        $client = $this->client('http://127.0.0.1:' . $port, $health);

        self::assertNull($this->get($client, 1));
        self::assertTrue($health->isDown('prowlarr'));
    }
}
