<?php

namespace App\Tests\Service\Cache;

use App\Entity\ServiceInstance;
use App\Service\Cache\LibraryIndexRefresher;
use App\Service\Cache\StaleWhileRevalidateCache;
use App\Service\Media\LibraryIndexCache;
use App\Service\Media\MediaLibraryCache;
use App\Service\Media\RadarrClient;
use App\Service\Media\ServiceHealthCache;
use App\Service\Media\SonarrClient;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[AllowMockObjectsWithoutExpectations]
class LibraryIndexRefresherTest extends TestCase
{
    private function swr(ArrayAdapter $pool): StaleWhileRevalidateCache
    {
        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope { return new Envelope($message); }
        };

        return new StaleWhileRevalidateCache($pool, $pool, $bus, new NullLogger());
    }

    private function instances(bool $enabled = true): ServiceInstanceProvider
    {
        $inst = new ServiceInstance(ServiceInstance::TYPE_RADARR, 'radarr-1', 'Radarr', 'http://x', 'k');
        $inst->setEnabled($enabled);
        $p = $this->createMock(ServiceInstanceProvider::class);
        $p->method('getBySlug')->willReturnCallback(
            static fn(string $type, string $slug) => ($type === ServiceInstance::TYPE_RADARR && $slug === 'radarr-1') ? $inst : null,
        );

        return $p;
    }

    private function refresher(ArrayAdapter $pool, RadarrClient $radarr, bool $enabled = true): LibraryIndexRefresher
    {
        $swr = $this->swr($pool);

        return new LibraryIndexRefresher(
            $this->instances($enabled),
            new LibraryIndexCache($swr, $radarr, $this->createMock(SonarrClient::class)),
            $swr,
            new ServiceHealthCache($pool),
            new NullLogger(),
        );
    }

    private function radarrReturning(array $raw): RadarrClient
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('withInstance')->willReturnSelf();
        $radarr->method('getRawMovies')->willReturn($raw);

        return $radarr;
    }

    public function testSupportsOnlyIndexKeys(): void
    {
        $r = $this->refresher(new ArrayAdapter(), $this->createMock(RadarrClient::class));

        self::assertTrue($r->supports('media.index.movies.radarr-1'));
        self::assertTrue($r->supports('media.index.series.sonarr-1'));
        self::assertFalse($r->supports('media.movies.radarr-1'));
        self::assertFalse($r->supports('media.index.movies.'));
    }

    public function testRefreshWritesTheProjectedIndex(): void
    {
        $pool = new ArrayAdapter();
        $this->refresher($pool, $this->radarrReturning([['id' => 5, 'tmdbId' => 55, 'title' => 'Five', 'overview' => 'long']]))
            ->refresh('media.index.movies.radarr-1');

        $hit = $this->swr($pool)->read('media.index.movies.radarr-1', LibraryIndexCache::SOFT_TTL);
        self::assertSame('fresh', $hit['state']);
        self::assertSame(55, $hit['value'][0]['tmdbId']);
        self::assertArrayNotHasKey('overview', $hit['value'][0]);
    }

    public function testRefreshIsSkippedWhenTheIndexIsAlreadyFresh(): void
    {
        $pool = new ArrayAdapter();
        $this->swr($pool)->write('media.index.movies.radarr-1', [['id' => 1]], LibraryIndexCache::HARD_TTL);
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->expects($this->never())->method('getRawMovies');

        $this->refresher($pool, $radarr)->refresh('media.index.movies.radarr-1');
    }

    public function testRefreshIsSkippedWhenTheCircuitBreakerIsOpen(): void
    {
        $pool = new ArrayAdapter();
        (new ServiceHealthCache($pool))->markDown('radarr', 'radarr-1');
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->expects($this->never())->method('getRawMovies');

        $this->refresher($pool, $radarr)->refresh('media.index.movies.radarr-1');
    }

    public function testRefreshIsSkippedForADisabledInstance(): void
    {
        $radarr = $this->createMock(RadarrClient::class);
        $radarr->expects($this->never())->method('getRawMovies');

        $this->refresher(new ArrayAdapter(), $radarr, enabled: false)->refresh('media.index.movies.radarr-1');
    }

    public function testAnEmptyFetchNeverOverwritesAGoodValue(): void
    {
        $pool = new ArrayAdapter();
        $this->swr($pool)->write('media.index.movies.radarr-1', [['id' => 1]], LibraryIndexCache::HARD_TTL, time() - LibraryIndexCache::SOFT_TTL - 1);

        $this->refresher($pool, $this->radarrReturning([]))->refresh('media.index.movies.radarr-1');

        self::assertSame([['id' => 1]], $this->swr($pool)->read('media.index.movies.radarr-1', LibraryIndexCache::SOFT_TTL)['value']);
    }

    public function testARefreshOverlappingAMutationDoesNotLandAsFresh(): void
    {
        // The worker fetch starts, the user adds a movie (MediaLibraryCache::
        // invalidate → markStale on the index), the fetch returns the
        // pre-add list — it must not overwrite the stale marker as fresh.
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);
        $swr->write('media.index.movies.radarr-1', [['id' => 1]], LibraryIndexCache::HARD_TTL, time() - LibraryIndexCache::SOFT_TTL - 1);
        $library = new MediaLibraryCache($swr);

        $radarr = $this->createMock(RadarrClient::class);
        $radarr->method('withInstance')->willReturnSelf();
        $radarr->method('getRawMovies')->willReturnCallback(static function () use ($library): array {
            $library->invalidate('radarr', 'radarr-1');

            return [['id' => 1], ['id' => 2]];
        });

        $this->refresher($pool, $radarr)->refresh('media.index.movies.radarr-1');

        $hit = $swr->read('media.index.movies.radarr-1', LibraryIndexCache::SOFT_TTL);
        self::assertSame('stale', $hit['state']);
        self::assertSame([['id' => 1]], $hit['value']);
    }
}
