<?php

namespace App\Tests\Service\Media;

use App\Controller\QBittorrentController;
use App\Service\ConfigService;
use App\Service\Media\GluetunClient;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\ServiceHealthCache;
use App\Service\Media\TorrentResolverService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Final review, minor #11 (closes TQ-3 for the READ side): TorrentJsonOutageTest
 * pins the controller against a mocked client; nothing pinned what the client
 * itself answers when the transport fails. Driven through the exec() cURL
 * seam in reverse-proxy mode (no user → login() returns its sentinel without
 * a round trip), then the real client is handed to the controller so the C1
 * envelope is exercised end to end.
 */
#[AllowMockObjectsWithoutExpectations]
class QBittorrentGetTorrentsSeamTest extends TestCase
{
    /** @var list<array<int, mixed>> */
    private array $calls = [];

    private ServiceHealthCache $health;

    /** @param list<array{0: string|false, 1: int, 2: string, 3: bool}> $responses */
    private function client(array $responses): QBittorrentClient
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('require')->willReturn('http://qbit.test:8080');
        $config->method('get')->willReturn(null); // no user / password → proxy mode

        $this->health = new ServiceHealthCache(new ArrayAdapter());
        $client = $this->getMockBuilder(QBittorrentClient::class)
            ->setConstructorArgs([$config, new NullLogger(), $this->health])
            ->onlyMethods(['exec'])
            ->getMock();
        $client->method('exec')->willReturnCallback(function (\CurlHandle $ch, array $opts) use (&$responses): array {
            $this->calls[] = $opts;
            curl_close($ch);
            $next = array_shift($responses);
            if ($next === null) {
                $this->fail('exec() called more times than responses were scripted');
            }
            return $next;
        });
        return $client;
    }

    private function controller(QBittorrentClient $client): QBittorrentController
    {
        $t = $this->createMock(TranslatorInterface::class);
        $t->method('trans')->willReturnArgument(0);
        $c = new QBittorrentController($client, $this->createMock(GluetunClient::class), $this->createMock(TorrentResolverService::class), $this->createMock(ConfigService::class), new NullLogger(), $t);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $c->setContainer($container);
        return $c;
    }

    public function testAConnectFailureIsAnEmptyListWithTheErrorRecorded(): void
    {
        $client = $this->client([[false, 0, 'Failed to connect to qbit.test port 8080: Connection refused', false]]);

        $this->assertSame([], $client->getTorrents());
        $this->assertNotNull($client->getLastError(), 'the controller tells an outage from an empty list by this');
        $this->assertStringContainsString('Failed to connect', $client->getLastError()['message']);
        $this->assertSame(0, $client->getLastError()['code']);
        $this->assertStringEndsWith('/api/v2/torrents/info?filter=all&sort=added_on&reverse=true', $this->calls[0][CURLOPT_URL]);
        $this->assertSame([], $this->calls[0][CURLOPT_HTTPHEADER], 'proxy mode sends no Cookie header');
    }

    public function testAnOutageOpensTheBreakerAndTheControllerAnswers502(): void
    {
        $client = $this->client([[false, 0, 'Failed to connect to qbit.test port 8080: Connection refused', false]]);

        $response = $this->controller($client)->apiTorrents(Request::create('/x'));

        $this->assertSame(502, $response->getStatusCode(), 'C1: the page gets an error envelope, not an empty list');
        $this->assertTrue($this->health->isDown('qbittorrent'), 'a connect failure opens the cross-request breaker');
        $this->assertCount(1, $this->calls, 'the rest of the request short-circuits on the breaker');
    }

    public function testATorrentListIsNormalisedAndClearsTheError(): void
    {
        $client = $this->client([[json_encode([
            ['hash' => 'abc', 'name' => 'Ubuntu', 'progress' => 0.5, 'state' => 'downloading', 'size' => 1024, 'dlspeed' => 10],
        ]), 200, '', false]]);

        $torrents = $client->getTorrents();

        $this->assertCount(1, $torrents);
        $this->assertSame('abc', $torrents[0]['hash']);
        $this->assertSame(50.0, $torrents[0]['progress']);
        $this->assertSame('downloading', $torrents[0]['raw_state']);
        $this->assertNull($client->getLastError());
        $this->assertFalse($this->health->isDown('qbittorrent'));
    }
}
