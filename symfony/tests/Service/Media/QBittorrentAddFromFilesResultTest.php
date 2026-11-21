<?php

namespace App\Tests\Service\Media;

use App\Service\ConfigService;
use App\Service\Media\QBittorrentClient;
use App\Service\Media\ServiceHealthCache;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Since WebAPI 2.14 (qBittorrent 5.2) /torrents/add answers a JSON summary
 * — {"added_torrent_ids":[…],"failure_count":0,…} — instead of "Ok.".
 * addTorrentFromFiles() flagged any body containing "fail" as a refusal, so
 * every successful .torrent upload on 5.2+ was reported as an error (found
 * live against 5.3.0). It now classifies like postAction(): only a body
 * starting with "Fails." (≤ 5.1) is a refusal; an all-failed add is a 409.
 */
#[AllowMockObjectsWithoutExpectations]
class QBittorrentAddFromFilesResultTest extends TestCase
{
    /** @param array{0: string|false, 1: int, 2: string, 3: bool} $response */
    private function client(array $response): QBittorrentClient
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('require')->willReturn('http://qbit.test:8080');
        $config->method('get')->willReturn(null); // no user / password → proxy mode

        $client = $this->getMockBuilder(QBittorrentClient::class)
            ->setConstructorArgs([$config, new NullLogger(), new ServiceHealthCache(new ArrayAdapter())])
            ->onlyMethods(['exec'])
            ->getMock();
        $client->method('exec')->willReturnCallback(function (\CurlHandle $ch) use ($response): array {
            curl_close($ch);
            return $response;
        });
        return $client;
    }

    private const FILE = [['content' => 'd4:infoe', 'name' => 'a.torrent']];

    public function testTheWebApi214JsonSummaryIsASuccess(): void
    {
        $body = '{"added_torrent_ids":["1f66cb557f6ed1d945fc7e624fbe81fbd3bb5ac3"],"failure_count":0,"pending_count":0,"success_count":1}';
        $client = $this->client([$body, 200, '', false]);

        $this->assertTrue($client->addTorrentFromFiles(self::FILE));
        $this->assertNull($client->getLastError());
    }

    public function testALegacyFailsBodyIsStillARefusal(): void
    {
        $client = $this->client(['Fails.', 200, '', false]);

        $this->assertFalse($client->addTorrentFromFiles(self::FILE));
        $this->assertStringContainsString('Fails', $client->getLastError()['message']);
    }

    public function testAnAllFailedAddAnsweredWith409IsARefusal(): void
    {
        $client = $this->client(['{"failure_count":1,"success_count":0}', 409, '', false]);

        $this->assertFalse($client->addTorrentFromFiles(self::FILE));
        $this->assertSame(409, $client->getLastError()['code']);
    }
}
