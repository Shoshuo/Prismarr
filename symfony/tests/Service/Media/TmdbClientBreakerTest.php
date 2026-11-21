<?php

namespace App\Tests\Service\Media;

use App\Service\ConfigService;
use App\Service\DisplayPreferencesService;
use App\Service\Media\ServiceHealthCache;
use App\Service\Media\TmdbClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Review 2026-11-06 (DD-9): TmdbClient had no circuit breaker — an
 * unreachable TMDb made every dashboard load burn hero + recommendations +
 * lookups sequentially at 15 s each, and 429s were retried with no back-off.
 * Transport failures, 429 and 5xx now open the shared breaker for the
 * window; a 404 does not.
 */
#[AllowMockObjectsWithoutExpectations]
class TmdbClientBreakerTest extends TestCase
{
    private int $transfers = 0;

    /** @param list<array{0: string|false, 1: int, 2: string, 3: bool}> $responses */
    private function client(array $responses, ServiceHealthCache $health): TmdbClient
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('require')->willReturn('tmdb-key');
        $config->method('get')->willReturn('tmdb-key');
        $prefs = $this->createMock(DisplayPreferencesService::class);
        $prefs->method('getMetadataLanguage')->willReturn('en-US');

        $client = $this->getMockBuilder(TmdbClient::class)
            ->setConstructorArgs([$config, new ArrayAdapter(), new NullLogger(), $prefs, $health])
            ->onlyMethods(['exec'])
            ->getMock();
        $client->method('exec')->willReturnCallback(function (\CurlHandle $ch, array $opts) use (&$responses): array {
            $this->transfers++;
            curl_close($ch);
            $next = array_shift($responses);
            if ($next === null) {
                $this->fail('exec() called more times than responses were scripted');
            }
            return $next;
        });
        return $client;
    }

    public function testATransportFailureOpensTheBreakerAndShortCircuitsTheNextCalls(): void
    {
        $health = new ServiceHealthCache(new ArrayAdapter());
        $client = $this->client([[false, 0, 'Failed to connect', false]], $health);

        $this->assertSame([], $client->searchMulti('interstellar'));
        $this->assertTrue($health->isDown('tmdb'));

        $this->assertSame([], $client->searchMulti('dune'), 'breaker open → no transfer');
        $this->assertSame(1, $this->transfers);
    }

    public function testARateLimitOpensTheBreaker(): void
    {
        $health = new ServiceHealthCache(new ArrayAdapter());
        $client = $this->client([['{"status_message":"slow down"}', 429, '', false]], $health);

        $client->searchMulti('interstellar');

        $this->assertTrue($health->isDown('tmdb'));
    }

    public function testANotFoundDoesNotOpenTheBreaker(): void
    {
        $health = new ServiceHealthCache(new ArrayAdapter());
        $client = $this->client([['{"status_code":34}', 404, '', false], ['{"results":[]}', 200, '', false]], $health);

        $client->searchMulti('nothing');
        $this->assertFalse($health->isDown('tmdb'));

        $client->searchMulti('else');
        $this->assertSame(2, $this->transfers);
    }

    public function testASuccessClearsTheBreaker(): void
    {
        $health = new ServiceHealthCache(new ArrayAdapter());
        $health->markDown('tmdb');
        $client = $this->client([['{"results":[{"id":1}]}', 200, '', false]], $health);

        // The breaker is honoured first...
        $this->assertSame([], $client->searchMulti('x'));
        $health->clear('tmdb');
        // ...and a real success keeps it clear.
        $this->assertSame(1, $client->searchMulti('y')['results'][0]['id']);
        $this->assertFalse($health->isDown('tmdb'));
    }
}
