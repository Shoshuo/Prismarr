<?php

namespace App\Tests\Service\Media;

use App\Entity\ServiceInstance;
use App\Service\Media\RadarrClient;
use App\Service\Media\ServiceHealthCache;
use App\Service\Media\SonarrClient;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Review 2026-11-06 (AC-7, AC-10, H2): pins the WIRE contract of the
 * Radarr/Sonarr write helpers through the `exec()` cURL seam (same pattern
 * as BazarrClient): the request each public method would send, and the
 * circuit-breaker decision each transport outcome drives.
 *
 *  - AC-7  a write that connected and then ran out of time is "slow", not
 *          "down": it must NOT open the cross-request breaker (get() already
 *          had this exemption since issue #41; the write helpers did not).
 *  - AC-10 path / root-folder edits must be able to ask the *arr to MOVE the
 *          files (`?moveFiles=true` on PUT /movie|series/{id}, `moveFiles`
 *          in the editor bodies), otherwise the DB is repointed and the
 *          files stay behind.
 *  - H2    PUT /api/v3/collection/{id} with a partial body wipes the
 *          collection (Radarr's ApplyChanges has no null guards), so the
 *          single-collection update must GET-merge-PUT.
 */
#[AllowMockObjectsWithoutExpectations]
class ArrClientWriteContractTest extends TestCase
{
    /** @var list<array<int, mixed>> options of every exec() call, in order */
    private array $calls = [];

    /**
     * @param list<array{0: string|false, 1: int, 2: string, 3: bool}> $responses one per exec() call
     */
    private function radarr(array $responses): RadarrClient&MockObject
    {
        return $this->client(RadarrClient::class, ServiceInstance::TYPE_RADARR, 'radarr-1', $responses);
    }

    /**
     * @param list<array{0: string|false, 1: int, 2: string, 3: bool}> $responses
     */
    private function sonarr(array $responses): SonarrClient&MockObject
    {
        return $this->client(SonarrClient::class, ServiceInstance::TYPE_SONARR, 'sonarr-1', $responses);
    }

    /**
     * @template T of RadarrClient|SonarrClient
     * @param class-string<T> $class
     * @param list<array{0: string|false, 1: int, 2: string, 3: bool}> $responses
     * @return T&MockObject
     */
    private function client(string $class, string $type, string $slug, array $responses, ?ServiceHealthCache $health = null): object
    {
        $instance = new ServiceInstance($type, $slug, ucfirst($type), 'http://' . $type . '.test:1234', 'secret-key');
        $instance->setEnabled(true);
        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('getDefault')->willReturn($instance);

        $client = $this->getMockBuilder($class)
            ->setConstructorArgs([$instances, new NullLogger(), $health ?? new ServiceHealthCache(new ArrayAdapter())])
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

    // ── AC-7: breaker exemption for connected-then-timed-out writes ─────────

    /**
     * @return iterable<string, array{0: string, 1: callable}>
     */
    public static function writeHelpers(): iterable
    {
        yield 'radarr request() via updateMovie'          => ['radarr', fn(RadarrClient $c) => $c->updateMovie(3, ['title' => 'x'])];
        yield 'radarr delete() via deleteMovie'           => ['radarr', fn(RadarrClient $c) => $c->deleteMovie(3, true)];
        yield 'radarr deleteWithBody() via bulkDelete'    => ['radarr', fn(RadarrClient $c) => $c->bulkDeleteMovies([3], true)];
        yield 'radarr requestWithError() via grabQueue'   => ['radarr', fn(RadarrClient $c) => $c->grabQueueItem(3)];
        yield 'sonarr request() via updateSeries'         => ['sonarr', fn(SonarrClient $c) => $c->updateSeries(3, ['title' => 'x'])];
        yield 'sonarr delete() via deleteSeries'          => ['sonarr', fn(SonarrClient $c) => $c->deleteSeries(3, true)];
        yield 'sonarr deleteWithBody() via bulkDelete'    => ['sonarr', fn(SonarrClient $c) => $c->bulkDeleteSeries([3], true)];
        yield 'sonarr requestWithError() via bulkEdit'    => ['sonarr', fn(SonarrClient $c) => $c->bulkEditSeries(['seriesIds' => [3]])];
    }

    #[DataProvider('writeHelpers')]
    public function testAWriteThatConnectedThenTimedOutDoesNotOpenTheBreaker(string $which, callable $call): void
    {
        $health = new ServiceHealthCache(new ArrayAdapter());
        $client = $which === 'radarr'
            ? $this->client(RadarrClient::class, ServiceInstance::TYPE_RADARR, 'radarr-1', [[false, 0, 'Operation timed out after 8001 milliseconds', true]], $health)
            : $this->client(SonarrClient::class, ServiceInstance::TYPE_SONARR, 'sonarr-1', [[false, 0, 'Operation timed out after 8001 milliseconds', true]], $health);

        $call($client);

        $this->assertNotNull($client->getLastError(), 'the failed call is still reported to the caller');
        $this->assertFalse(
            $health->isDown($which, $which . '-1'),
            'a read timeout after a successful connect means "busy", not "dead": the instance must stay up for the next request'
        );
    }

    #[DataProvider('writeHelpers')]
    public function testAWriteThatCouldNotConnectOpensTheBreaker(string $which, callable $call): void
    {
        $health = new ServiceHealthCache(new ArrayAdapter());
        $client = $which === 'radarr'
            ? $this->client(RadarrClient::class, ServiceInstance::TYPE_RADARR, 'radarr-1', [[false, 0, 'Failed to connect to radarr.test port 1234', false]], $health)
            : $this->client(SonarrClient::class, ServiceInstance::TYPE_SONARR, 'sonarr-1', [[false, 0, 'Failed to connect to sonarr.test port 1234', false]], $health);

        $call($client);

        $this->assertTrue($health->isDown($which, $which . '-1'), 'a connect-level failure still trips the breaker');
    }

    // ── AC-10: moveFiles ─────────────────────────────────────────────────────

    public function testUpdateMovieCanAskRadarrToMoveTheFiles(): void
    {
        $client = $this->radarr([[json_encode(['id' => 7, 'title' => 'x', 'images' => []]), 202, '', false]]);

        $client->updateMovie(7, ['title' => 'x', 'path' => '/movies/x'], true);

        $this->assertCount(1, $this->calls);
        $this->assertSame('PUT', $this->calls[0][CURLOPT_CUSTOMREQUEST]);
        $this->assertStringEndsWith('/api/v3/movie/7?moveFiles=true', $this->calls[0][CURLOPT_URL]);
        $this->assertSame(['title' => 'x', 'path' => '/movies/x'], json_decode($this->calls[0][CURLOPT_POSTFIELDS], true));
    }

    public function testUpdateMovieWithoutMoveFilesKeepsTheLegacyUrl(): void
    {
        $client = $this->radarr([[json_encode(['id' => 7, 'title' => 'x', 'images' => []]), 202, '', false]]);

        $client->updateMovie(7, ['title' => 'x']);

        $this->assertStringEndsWith('/api/v3/movie/7', $this->calls[0][CURLOPT_URL]);
    }

    public function testUpdateSeriesCanAskSonarrToMoveTheFiles(): void
    {
        $client = $this->sonarr([[json_encode(['id' => 9, 'title' => 'x', 'images' => [], 'seasons' => []]), 202, '', false]]);

        $client->updateSeries(9, ['title' => 'x', 'path' => '/tv/x'], true);

        $this->assertSame('PUT', $this->calls[0][CURLOPT_CUSTOMREQUEST]);
        $this->assertStringEndsWith('/api/v3/series/9?moveFiles=true', $this->calls[0][CURLOPT_URL]);
    }

    public function testBulkUpdateMoviesForwardsMoveFilesInTheEditorBody(): void
    {
        $client = $this->radarr([['[]', 202, '', false]]);

        $client->bulkUpdateMovies([1, 2], ['rootFolderPath' => '/new', 'moveFiles' => true]);

        $this->assertStringEndsWith('/api/v3/movie/editor', $this->calls[0][CURLOPT_URL]);
        $body = json_decode($this->calls[0][CURLOPT_POSTFIELDS], true);
        $this->assertSame([1, 2], $body['movieIds']);
        $this->assertSame('/new', $body['rootFolderPath']);
        $this->assertTrue($body['moveFiles']);
    }

    // ── H2: single-collection update must not wipe the resource ─────────────

    public function testUpdateCollectionMergesTheToggleIntoTheExistingResource(): void
    {
        $existing = [
            'id' => 5, 'title' => 'Alien Collection', 'tmdbId' => 8091, 'qualityProfileId' => 4,
            'rootFolderPath' => '/movies', 'monitored' => true, 'minimumAvailability' => 'released',
            'searchOnAdd' => true, 'tags' => [2], 'movies' => [['tmdbId' => 348]],
        ];
        $client = $this->radarr([
            [json_encode($existing), 200, '', false],                               // GET /collection/5
            [json_encode(['monitored' => false] + $existing), 202, '', false],      // PUT /collection/5
        ]);

        $this->assertTrue($client->updateCollection(5, false));

        $this->assertCount(2, $this->calls, 'GET then PUT');
        $this->assertStringEndsWith('/api/v3/collection/5', $this->calls[0][CURLOPT_URL]);
        $this->assertArrayNotHasKey(CURLOPT_CUSTOMREQUEST, $this->calls[0], 'first call is the GET');
        $this->assertSame('PUT', $this->calls[1][CURLOPT_CUSTOMREQUEST]);
        $body = json_decode($this->calls[1][CURLOPT_POSTFIELDS], true);
        $this->assertFalse($body['monitored']);
        foreach (['tmdbId', 'qualityProfileId', 'rootFolderPath', 'minimumAvailability', 'searchOnAdd', 'tags'] as $kept) {
            $this->assertSame($existing[$kept], $body[$kept], "PUT body keeps {$kept} — a partial body resets it in Radarr");
        }
    }

    public function testUpdateCollectionFailsClosedWhenTheResourceCannotBeRead(): void
    {
        $client = $this->radarr([[false, 0, 'Failed to connect', false]]);

        $this->assertFalse($client->updateCollection(5, false));
        $this->assertCount(1, $this->calls, 'no PUT without the merged resource');
    }

    // ── Final review, minor #9: the single-file PUT kept the isset([]) bug ──

    public function testSonarrUpdateEpisodeFileIgnoresAnEmptyLanguageList(): void
    {
        // H5 fixed /series/files/bulk-update; SonarrClient::updateEpisodeFile()
        // (behind /series/file/{id}/update) still replaced the stored
        // languages with [] because isset([]) is true.
        $sonarr = $this->sonarr([
            [json_encode(['id' => 3, 'languages' => [['id' => 8, 'name' => 'Japanese']], 'releaseGroup' => 'OLD']), 200, '', false],
            [json_encode(['id' => 3]), 202, '', false],
        ]);

        $result = $sonarr->updateEpisodeFile(3, ['languages' => [], 'releaseGroup' => 'GRP']);

        $this->assertTrue($result['ok']);
        $this->assertCount(2, $this->calls, 'GET the file, then PUT it back');
        $put = $this->calls[1];
        $this->assertSame('PUT', $put[CURLOPT_CUSTOMREQUEST]);
        $this->assertStringEndsWith('/api/v3/episodefile/3', $put[CURLOPT_URL]);
        $body = json_decode((string) $put[CURLOPT_POSTFIELDS], true);
        $this->assertSame([['id' => 8, 'name' => 'Japanese']], $body['languages'], 'an empty list must not wipe the stored languages');
        $this->assertSame('GRP', $body['releaseGroup']);
    }

    public function testSonarrUpdateEpisodeFileReplacesTheLanguagesWhenSomeAreGiven(): void
    {
        $sonarr = $this->sonarr([
            [json_encode(['id' => 3, 'languages' => [['id' => 8, 'name' => 'Japanese']]]), 200, '', false],
            [json_encode(['id' => 3]), 202, '', false],
        ]);

        $sonarr->updateEpisodeFile(3, ['languages' => [['id' => 1, 'name' => 'English']]]);

        $body = json_decode((string) $this->calls[1][CURLOPT_POSTFIELDS], true);
        $this->assertSame([['id' => 1, 'name' => 'English']], $body['languages']);
    }
}
