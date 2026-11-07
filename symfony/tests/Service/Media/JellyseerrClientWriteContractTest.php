<?php

namespace App\Tests\Service\Media;

use App\Service\ConfigService;
use App\Service\Media\JellyseerrClient;
use App\Service\Media\ServiceHealthCache;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Review 2026-11-06 (MS-2, C1, TQ-3): Jellyseerr write contract through the
 * exec() cURL seam, plus the breaker branch recording an error so callers
 * can tell "outage" from "empty".
 */
#[AllowMockObjectsWithoutExpectations]
class JellyseerrClientWriteContractTest extends TestCase
{
    /** @var list<array<int, mixed>> */
    private array $calls = [];

    /** @param list<array{0: string|false, 1: int, 2: string, 3: bool}> $responses */
    private function client(array $responses, ?ServiceHealthCache $health = null): JellyseerrClient&MockObject
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('get')->willReturn(null);
        $config->method('require')->willReturnCallback(fn(string $key) => $key === 'jellyseerr_url' ? 'http://seerr.test:5055' : 'secret');

        $client = $this->getMockBuilder(JellyseerrClient::class)
            ->setConstructorArgs([$config, new NullLogger(), $health ?? new ServiceHealthCache(new ArrayAdapter())])
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

    public function testCreateLocalUserWithoutAPasswordLetsSeerrGenerateOne(): void
    {
        // MS-2: Seerr's POST /user treats a missing password as "generate
        // and e-mail one"; sending an empty string made the option always fail.
        $client = $this->client([[json_encode(['id' => 9]), 201, '', false]]);

        $client->createLocalUser('alice@example.test');

        $body = json_decode($this->calls[0][CURLOPT_POSTFIELDS], true);
        $this->assertStringEndsWith('/api/v1/user', $this->calls[0][CURLOPT_URL]);
        $this->assertSame('alice@example.test', $body['email']);
        $this->assertArrayNotHasKey('password', $body);
    }

    public function testCreateLocalUserForwardsPasswordAndUsername(): void
    {
        $client = $this->client([[json_encode(['id' => 9]), 201, '', false]]);

        $client->createLocalUser('alice@example.test', 'hunter22', 'alice');

        $body = json_decode($this->calls[0][CURLOPT_POSTFIELDS], true);
        $this->assertSame('hunter22', $body['password']);
        $this->assertSame('alice', $body['username']);
    }

    public function testAnOpenBreakerRecordsAnErrorInsteadOfSilentlyAnsweringEmpty(): void
    {
        // C1: isDown() returned null before recordError(), so every caller
        // rendered "0 pending" / "no users" during the breaker window.
        $health = new ServiceHealthCache(new ArrayAdapter());
        $health->markDown('jellyseerr');
        $client = $this->client([], $health);

        $this->assertSame([], $client->getJellyfinUsers());
        $this->assertNotNull($client->getLastError());
        $this->assertSame(0, $client->getLastError()['code']);
        $this->assertCount(0, $this->calls, 'no transfer while the breaker is open');
    }

    public function testAConnectFailureOpensTheBreakerAndAReadTimeoutDoesNot(): void
    {
        $health = new ServiceHealthCache(new ArrayAdapter());
        $client = $this->client([[false, 0, 'Operation timed out after 8001 milliseconds', true]], $health);
        $client->getJellyfinUsers();
        $this->assertFalse($health->isDown('jellyseerr'), 'busy, not dead');

        $health = new ServiceHealthCache(new ArrayAdapter());
        $client = $this->client([[false, 0, 'Failed to connect', false]], $health);
        $client->getJellyfinUsers();
        $this->assertTrue($health->isDown('jellyseerr'));
    }
}
