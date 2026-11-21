<?php

namespace App\Tests\Service\Media;

use App\Service\ConfigService;
use App\Service\Media\ProwlarrClient;
use App\Service\Media\ServiceHealthCache;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Review 2026-11-06 (TQ-3, MS-1, MS-7): Prowlarr wire contract through the
 * exec() cURL seam, and the connected-then-timed-out breaker exemption
 * (a multi-indexer search slower than 8 s used to turn Prowlarr red).
 */
#[AllowMockObjectsWithoutExpectations]
class ProwlarrClientWriteContractTest extends TestCase
{
    /** @var list<array<int, mixed>> */
    private array $calls = [];

    /** @param list<array{0: string|false, 1: int, 2: string, 3: bool}> $responses */
    private function client(array $responses, ?ServiceHealthCache $health = null): ProwlarrClient&MockObject
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturn(null);
        $config->method('require')->willReturnCallback(fn(string $key) => $key === 'prowlarr_url' ? 'http://prowlarr.test:9696' : 'secret');

        $client = $this->getMockBuilder(ProwlarrClient::class)
            ->setConstructorArgs([$config, new NullLogger(), $health ?? new ServiceHealthCache(new ArrayAdapter())])
            ->onlyMethods(['exec'])
            ->getMock();
        $client->method('exec')->willReturnCallback(function (\CurlHandle $ch, array $opts) use (&$responses): array {
            // ProwlarrClient hands the URL to curl_init(), not to the option array.
            $opts[CURLOPT_URL] = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
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

    public function testUpdateIndexerWithForceSaveSkipsProwlarrsInlineTest(): void
    {
        $client = $this->client([['{"id":5}', 202, '', false]]);

        $r = $client->updateIndexerWithError(5, ['id' => 5, 'enable' => true], true);

        $this->assertTrue($r['ok']);
        $this->assertSame('PUT', $this->calls[0][CURLOPT_CUSTOMREQUEST]);
        $this->assertStringEndsWith('/api/v1/indexer/5?forceSave=true', $this->calls[0][CURLOPT_URL]);
        $this->assertSame(['id' => 5, 'enable' => true], json_decode($this->calls[0][CURLOPT_POSTFIELDS], true));
    }

    public function testUpdateIndexerWithoutForceSaveKeepsTheLegacyUrl(): void
    {
        $client = $this->client([['{"id":5}', 202, '', false]]);

        $client->updateIndexerWithError(5, ['id' => 5]);

        $this->assertStringEndsWith('/api/v1/indexer/5', $this->calls[0][CURLOPT_URL]);
    }

    public function testASearchThatConnectedThenTimedOutDoesNotOpenTheBreaker(): void
    {
        $health = new ServiceHealthCache(new ArrayAdapter());
        $client = $this->client([[false, 0, 'Operation timed out after 8001 milliseconds', true]], $health);

        $client->getIndexers();

        $this->assertNotNull($client->getLastError());
        $this->assertFalse($health->isDown('prowlarr'), 'slow indexers are "busy", not "down"');
    }

    public function testAConnectFailureOpensTheBreaker(): void
    {
        $health = new ServiceHealthCache(new ArrayAdapter());
        $client = $this->client([[false, 0, 'Failed to connect', false]], $health);

        $client->getIndexers();

        $this->assertTrue($health->isDown('prowlarr'));
    }
}
