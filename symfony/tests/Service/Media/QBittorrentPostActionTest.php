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
 * Review 2026-11-06 (DC-4): qBittorrent ≤ 5.1 answers `200 Fails.` to a
 * rejected action (duplicate / invalid URL on /torrents/add, unknown hash);
 * postAction() only looked at the status and reported success. Exercised
 * through the exec() seam in reverse-proxy mode (no user → login() returns
 * its sentinel without a round trip).
 */
#[AllowMockObjectsWithoutExpectations]
class QBittorrentPostActionTest extends TestCase
{
    /** @var list<array<int, mixed>> */
    private array $calls = [];

    /** @param list<array{0: string|false, 1: int, 2: string, 3: bool}> $responses */
    private function client(array $responses): QBittorrentClient
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('require')->willReturn('http://qbit.test:8080');
        $config->method('get')->willReturn(null); // no user / password → proxy mode

        $client = $this->getMockBuilder(QBittorrentClient::class)
            ->setConstructorArgs([$config, new NullLogger(), new ServiceHealthCache(new ArrayAdapter())])
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

    public function testAFailsBodyWithStatus200IsAFailure(): void
    {
        $client = $this->client([['Fails.', 200, '', false]]);

        $this->assertFalse($client->pauseTorrents(['abc']));
        $this->assertNotNull($client->getLastError());
        $this->assertStringContainsString('Fails', $client->getLastError()['message']);
    }

    public function testAnOkBodyIsASuccessAndSendsTheHashes(): void
    {
        $client = $this->client([['Ok.', 200, '', false]]);

        $this->assertTrue($client->pauseTorrents(['abc', 'def']));
        $this->assertNull($client->getLastError());
        $this->assertStringEndsWith('/api/v2/torrents/stop', $this->calls[0][CURLOPT_URL]);
        $this->assertSame('hashes=abc%7Cdef', $this->calls[0][CURLOPT_POSTFIELDS]);
    }

    public function testAnEmptyBodyWithStatus200IsStillASuccess(): void
    {
        $client = $this->client([['', 200, '', false]]);

        $this->assertTrue($client->pauseTorrents(['abc']));
    }
}
