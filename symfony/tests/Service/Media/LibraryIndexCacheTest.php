<?php

namespace App\Tests\Service\Media;

use App\Entity\ServiceInstance;
use App\Message\RefreshCacheKey;
use App\Service\Cache\MediaLibraryRefresher;
use App\Service\Cache\StaleWhileRevalidateCache;
use App\Service\Media\LibraryIndexCache;
use App\Service\Media\MediaLibraryCache;
use App\Service\Media\RadarrClient;
use App\Service\Media\SonarrClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[AllowMockObjectsWithoutExpectations]
class LibraryIndexCacheTest extends TestCase
{
    /** @var list<object> */
    private array $dispatched = [];

    private function swr(ArrayAdapter $pool): StaleWhileRevalidateCache
    {
        $bus = new class($this->dispatched) implements MessageBusInterface {
            /** @param list<object> $sink */
            public function __construct(private array &$sink) {}
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->sink[] = $message;
                return new Envelope($message);
            }
        };

        return new StaleWhileRevalidateCache($pool, $pool, $bus, new NullLogger());
    }

    private function radarrInstance(string $slug = 'radarr-1'): ServiceInstance
    {
        return new ServiceInstance(ServiceInstance::TYPE_RADARR, $slug, 'Radarr', 'http://x', 'k');
    }

    /** @return list<string> keys of the RefreshCacheKey messages dispatched so far */
    private function refreshedKeys(): array
    {
        return array_map(fn(RefreshCacheKey $m) => $m->key, array_values(array_filter(
            $this->dispatched,
            fn(object $m) => $m instanceof RefreshCacheKey,
        )));
    }

    public function testRadarrRowProjectionKeepsOnlyTheIndexedFields(): void
    {
        $row = RadarrClient::toLibraryIndexRow([
            'id' => '7', 'tmdbId' => 603, 'imdbId' => 'tt0133093', 'title' => 'The Matrix',
            'originalTitle' => 'The Matrix', 'sortTitle' => 'Matrix', 'year' => 1999,
            'status' => 'released', 'hasFile' => 1, 'monitored' => true,
            'added' => '2024-01-02T03:04:05Z', 'path' => '/movies/The Matrix (1999)',
            'overview' => str_repeat('x', 1000),
            'images' => [
                ['coverType' => 'poster', 'remoteUrl' => 'https://img/p.jpg'],
                ['coverType' => 'fanart', 'remoteUrl' => 'https://img/f.jpg'],
            ],
            'alternateTitles' => [['title' => 'Matrix'], ['title' => ''], 'garbage', ['sourceType' => 'tmdb']],
            'movieFile' => ['size' => 123],
        ]);

        self::assertSame(7, $row['id']);
        self::assertSame(603, $row['tmdbId']);
        self::assertSame('matrix', $row['sortTitle']);
        self::assertTrue($row['hasFile']);
        self::assertSame('https://img/p.jpg', $row['poster']);
        self::assertSame('https://img/f.jpg', $row['fanart']);
        self::assertSame([['title' => 'Matrix']], $row['alternateTitles']);
        self::assertSame('2024-01-02T03:04:05Z', $row['added']);
        self::assertArrayNotHasKey('overview', $row);
        self::assertArrayNotHasKey('movieFile', $row);
    }

    public function testRadarrRowProjectionToleratesAnEmptyPayload(): void
    {
        $row = RadarrClient::toLibraryIndexRow([]);

        self::assertNull($row['id']);
        self::assertNull($row['tmdbId']);
        self::assertSame('—', $row['title']);
        self::assertFalse($row['hasFile']);
        self::assertNull($row['poster']);
        self::assertSame([], $row['alternateTitles']);
    }

    public function testSonarrRowProjectionFlattensEpisodeStatistics(): void
    {
        $row = SonarrClient::toLibraryIndexRow([
            'id' => 3, 'tvdbId' => 81189, 'tmdbId' => 0, 'title' => 'Breaking Bad',
            'monitored' => true, 'status' => 'ended', 'network' => 'AMC',
            'statistics' => ['episodeCount' => 62, 'episodeFileCount' => 60],
            'images' => [['coverType' => 'poster', 'remoteUrl' => 'https://img/bb.jpg']],
        ]);

        self::assertSame(81189, $row['tvdbId']);
        self::assertNull($row['tmdbId'], 'tmdbId 0 means "unknown", not a real id');
        self::assertSame(62, $row['episodeCount']);
        self::assertSame(60, $row['episodeFileCount']);
        self::assertSame('https://img/bb.jpg', $row['poster']);
        self::assertNull($row['fanart']);
        self::assertSame('AMC', $row['network']);
    }

    public function testAHardMissFetchesOnceWithTheLibraryTimeoutThenServesFromCache(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('withInstance')->willReturnSelf();
        $radarr->expects($this->once())->method('getRawMovies')
            ->with(RadarrClient::LIBRARY_TIMEOUT)
            ->willReturn([['id' => 1, 'tmdbId' => 10, 'title' => 'A']]);
        $index = new LibraryIndexCache($this->swr(new ArrayAdapter()), $radarr, $this->createMock(SonarrClient::class));

        $first  = $index->movies($this->radarrInstance());
        $second = $index->movies($this->radarrInstance());

        self::assertSame(10, $first[0]['tmdbId']);
        self::assertSame($first, $second);
        self::assertSame([], $this->refreshedKeys(), 'a fresh entry needs no background refresh');
    }

    public function testAStaleIndexIsServedAndRefreshedInTheBackground(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);
        $swr->write('media.index.movies.radarr-1', [['id' => 1, 'title' => 'Old']], LibraryIndexCache::HARD_TTL, time() - LibraryIndexCache::SOFT_TTL - 5);

        $radarr = $this->createMock(RadarrClient::class);
        $radarr->expects($this->never())->method('getRawMovies');
        $index = new LibraryIndexCache($swr, $radarr, $this->createMock(SonarrClient::class));

        $rows = $index->movies($this->radarrInstance());

        self::assertSame('Old', $rows[0]['title']);
        self::assertSame(['media.index.movies.radarr-1'], $this->refreshedKeys());
    }

    public function testAnEmptyFetchIsNotCached(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('withInstance')->willReturnSelf();
        $radarr->expects($this->exactly(2))->method('getRawMovies')->willReturn([]);
        $index = new LibraryIndexCache($this->swr(new ArrayAdapter()), $radarr, $this->createMock(SonarrClient::class));

        self::assertSame([], $index->movies($this->radarrInstance()));
        self::assertSame([], $index->movies($this->radarrInstance()), 'second read must retry, not pin the failure');
    }

    public function testInstancesAndKindsGetTheirOwnEntries(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);
        $swr->write('media.index.movies.radarr-1', [['id' => 1]], LibraryIndexCache::HARD_TTL);
        $swr->write('media.index.movies.radarr-4k', [['id' => 2]], LibraryIndexCache::HARD_TTL);
        $index = new LibraryIndexCache($swr, $this->createMock(RadarrClient::class), $this->createMock(SonarrClient::class));

        self::assertSame(1, $index->movies($this->radarrInstance('radarr-1'))[0]['id']);
        self::assertSame(2, $index->movies($this->radarrInstance('radarr-4k'))[0]['id']);
    }

    public function testKeysAreDisjointFromTheFullLibraryRefresherDomain(): void
    {
        self::assertSame('media.index.series.sonarr-1', LibraryIndexCache::key('series', 'sonarr-1'));
        self::assertSame(['movies', 'radarr-4k'], LibraryIndexCache::parseKey('media.index.movies.radarr-4k'));
        self::assertNull(LibraryIndexCache::parseKey('media.movies.radarr-1'));
        self::assertNull(LibraryIndexCache::parseKey('media.index.movies.'));

        $fullRefresher = (new \ReflectionClass(MediaLibraryRefresher::class))->newInstanceWithoutConstructor();
        self::assertFalse($fullRefresher->supports('media.index.movies.radarr-1'));
    }

    public function testInvalidatingTheLibraryKeepsTheIndexReadableButStale(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);
        $swr->write('media.movies.radarr-1', [['id' => 1]], MediaLibraryCache::HARD_TTL);
        $swr->write('media.index.movies.radarr-1', [['id' => 1, 'tmdbId' => 10]], LibraryIndexCache::HARD_TTL);

        (new MediaLibraryCache($swr))->invalidate('radarr', 'radarr-1');

        self::assertNull($swr->read('media.movies.radarr-1', MediaLibraryCache::TTL), 'full list is hard-deleted as before');
        $hit = $swr->read('media.index.movies.radarr-1', LibraryIndexCache::SOFT_TTL);
        self::assertNotNull($hit, 'index must survive a mutation');
        self::assertSame('stale', $hit['state']);
        self::assertSame(['media.index.movies.radarr-1'], $this->refreshedKeys());
    }
}
