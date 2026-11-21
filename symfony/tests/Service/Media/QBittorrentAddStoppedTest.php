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
 * qBittorrent 5.0 renamed the /torrents/add `paused` field to `stopped`, and
 * 5.x only reads `stopped` — so "add paused" was silently ignored and the
 * torrent started downloading. Both add paths send `stopped`, plus the legacy
 * `paused` for 4.x. Exercised through the exec() seam in reverse-proxy mode
 * (no user → login() returns its sentinel without a round trip).
 */
#[AllowMockObjectsWithoutExpectations]
class QBittorrentAddStoppedTest extends TestCase
{
    /** @var list<array<int, mixed>> */
    private array $calls = [];

    private function client(): QBittorrentClient
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('require')->willReturn('http://qbit.test:8080');
        $config->method('get')->willReturn(null); // no user / password → proxy mode

        $client = $this->getMockBuilder(QBittorrentClient::class)
            ->setConstructorArgs([$config, new NullLogger(), new ServiceHealthCache(new ArrayAdapter())])
            ->onlyMethods(['exec'])
            ->getMock();
        $client->method('exec')->willReturnCallback(function (\CurlHandle $ch, array $opts): array {
            $this->calls[] = $opts;
            curl_close($ch);
            return ['Ok.', 200, '', false];
        });
        return $client;
    }

    public function testAddFromUrlPausedSendsStopped(): void
    {
        $this->assertTrue($this->client()->addTorrentFromUrl('magnet:?xt=urn:btih:abc', null, null, true));

        $this->assertStringEndsWith('/api/v2/torrents/add', $this->calls[0][CURLOPT_URL]);
        parse_str($this->calls[0][CURLOPT_POSTFIELDS], $fields);
        $this->assertSame('true', $fields['stopped'] ?? null, 'qBit 5.x reads only `stopped`');
        $this->assertSame('true', $fields['paused'] ?? null, 'qBit 4.x reads `paused`');
    }

    public function testAddFromUrlNotPausedSendsNeitherFlag(): void
    {
        $this->assertTrue($this->client()->addTorrentFromUrl('magnet:?xt=urn:btih:abc'));

        parse_str($this->calls[0][CURLOPT_POSTFIELDS], $fields);
        $this->assertArrayNotHasKey('stopped', $fields);
        $this->assertArrayNotHasKey('paused', $fields);
    }

    public function testAddFromFilesPausedSendsStopped(): void
    {
        $ok = $this->client()->addTorrentFromFiles([['content' => 'd4:infoe', 'name' => 'a.torrent']], null, null, true);

        $this->assertTrue($ok);
        $this->assertCount(1, $this->calls, 'the upload goes through the exec() seam');
        $fields = $this->calls[0][CURLOPT_POSTFIELDS];
        $this->assertSame('true', $fields['stopped'] ?? null, 'qBit 5.x reads only `stopped`');
        $this->assertSame('true', $fields['paused'] ?? null, 'qBit 4.x reads `paused`');
        $this->assertInstanceOf(\CURLFile::class, $fields['torrents']);
    }
}
