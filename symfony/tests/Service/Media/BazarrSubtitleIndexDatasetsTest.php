<?php

namespace App\Tests\Service\Media;

use App\Entity\ServiceInstance;
use App\Service\Cache\StaleWhileRevalidateCache;
use App\Service\Media\BazarrClient;
use App\Service\Media\BazarrSubtitleIndex;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The Bazarr-tab datasets (movie/series grid cards, most-missing candidates,
 * badge counts) computed at refresh time from the SAME getMovies()/getSeries()
 * fetch the subtitle index already makes — see BazarrIndexRefresher. This
 * class reads only, through the same read()/requestRefresh() contract the
 * badge maps use (BazarrSubtitleIndexSwrTest).
 */
#[AllowMockObjectsWithoutExpectations]
class BazarrSubtitleIndexDatasetsTest extends TestCase
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

    private function instances(int $radarr = 1, int $sonarr = 1): ServiceInstanceProvider
    {
        $p = $this->createMock(ServiceInstanceProvider::class);
        $p->method('hasExactlyOneEnabled')->willReturnCallback(
            static fn (string $type) => ($type === ServiceInstance::TYPE_RADARR ? $radarr : $sonarr) === 1,
        );

        return $p;
    }

    private function index(BazarrClient $client, ArrayAdapter $pool, int $radarr = 1): BazarrSubtitleIndex
    {
        return new BazarrSubtitleIndex($client, $pool, $this->instances($radarr), $this->swr($pool), new NullLogger());
    }

    public function testMovieCardsWarmOnAHardMissAndRequestARefresh(): void
    {
        $out = $this->index($this->createMock(BazarrClient::class), new ArrayAdapter())->movieCards();

        $this->assertSame('warming', $out['state']);
        $this->assertSame([], $out['cards']);
        $this->assertSame([], $out['languages']);
        $this->assertCount(1, $this->dispatched);
    }

    public function testMovieCardsAreServedFromTheCacheWithoutAnyClientCall(): void
    {
        $pool = new ArrayAdapter();
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIE_CARDS, [
            'cards' => [[
                'title' => 'Amélie', 'year' => 2001, 'substate' => 'missing', 'count' => 2,
                'missingLangs' => ['fr', 'en'], 'seriesId' => null, 'movieId' => 7,
            ]],
            'languages' => ['en', 'fr'],
        ], BazarrSubtitleIndex::HARD_TTL);

        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('getMovies');

        $out = $this->index($client, $pool)->movieCards();

        $this->assertSame('ready', $out['state']);
        $this->assertSame('Amélie', $out['cards'][0]['title']);
        $this->assertSame(['en', 'fr'], $out['languages']);
        $this->assertArrayNotHasKey('poster', $out['cards'][0], 'posters are joined at render time, never cached here');
    }

    public function testMostMissingMergesBothKindsAndKeepsMissingCounts(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);
        $swr->write(BazarrSubtitleIndex::KEY_MOST_MISSING_MOVIES, [
            ['kind' => 'movie', 'id' => 7, 'title' => 'A', 'year' => 2001, 'missingCount' => 3],
        ], BazarrSubtitleIndex::HARD_TTL);
        $swr->write(BazarrSubtitleIndex::KEY_MOST_MISSING_SERIES, [
            ['kind' => 'series', 'id' => 9, 'title' => 'B', 'year' => null, 'missingCount' => 11],
        ], BazarrSubtitleIndex::HARD_TTL);

        $out = $this->index($this->createMock(BazarrClient::class), $pool)->mostMissing();

        $this->assertSame('ready', $out['state']);
        $this->assertCount(2, $out['items']);
    }

    public function testMostMissingWarmsWhenEitherHalfIsMissing(): void
    {
        $pool = new ArrayAdapter();
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOST_MISSING_MOVIES, [], BazarrSubtitleIndex::HARD_TTL);

        $this->assertSame('warming', $this->index($this->createMock(BazarrClient::class), $pool)->mostMissing()['state']);
    }

    public function testBadgeCountsWarmToZerosOnAHardMiss(): void
    {
        $out = $this->index($this->createMock(BazarrClient::class), new ArrayAdapter())->badgeCounts();

        $this->assertSame('warming', $out['state']);
        $this->assertSame(['movies' => 0, 'episodes' => 0, 'providers' => 0], $out['counts']);
    }

    public function testInvalidateDropsEveryDatasetKey(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);
        foreach ([
            BazarrSubtitleIndex::KEY_MOVIES, BazarrSubtitleIndex::KEY_MOVIE_LANGS, BazarrSubtitleIndex::KEY_SERIES,
            BazarrSubtitleIndex::KEY_MOVIE_CARDS, BazarrSubtitleIndex::KEY_SERIES_CARDS,
            BazarrSubtitleIndex::KEY_MOST_MISSING_MOVIES, BazarrSubtitleIndex::KEY_MOST_MISSING_SERIES,
            BazarrSubtitleIndex::KEY_BADGES,
        ] as $key) {
            $swr->write($key, ['x'], BazarrSubtitleIndex::HARD_TTL);
        }

        $this->index($this->createMock(BazarrClient::class), $pool)->invalidate();

        foreach ([
            BazarrSubtitleIndex::KEY_MOVIES, BazarrSubtitleIndex::KEY_MOVIE_LANGS, BazarrSubtitleIndex::KEY_SERIES,
            BazarrSubtitleIndex::KEY_MOVIE_CARDS, BazarrSubtitleIndex::KEY_SERIES_CARDS,
            BazarrSubtitleIndex::KEY_MOST_MISSING_MOVIES, BazarrSubtitleIndex::KEY_MOST_MISSING_SERIES,
            BazarrSubtitleIndex::KEY_BADGES,
        ] as $key) {
            $this->assertNull($swr->read($key, 60), $key . ' must be dropped by invalidate()');
        }
    }

    public function testMovieCardsServesStaleDataAndRequestsARefresh(): void
    {
        $pool = new ArrayAdapter();
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIE_CARDS, [
            'cards' => [['title' => 'A', 'year' => null, 'substate' => 'missing', 'count' => 1, 'missingLangs' => [], 'seriesId' => null, 'movieId' => 1]],
            'languages' => [],
        ], BazarrSubtitleIndex::HARD_TTL, time() - 300);

        $out = $this->index($this->createMock(BazarrClient::class), $pool)->movieCards();

        $this->assertSame('ready', $out['state'], 'stale data is still served');
        $this->assertCount(1, $out['cards']);
        $this->assertCount(1, $this->dispatched, 'exactly one refresh must be requested for the stale key');
    }

    public function testSeriesCardsServesStaleDataAndRequestsARefresh(): void
    {
        $pool = new ArrayAdapter();
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_SERIES_CARDS, [
            'cards' => [['title' => 'S', 'year' => null, 'substate' => 'missing', 'count' => 1, 'missingLangs' => [], 'seriesId' => 9, 'movieId' => null]],
            'languages' => [],
        ], BazarrSubtitleIndex::HARD_TTL, time() - 300);

        $out = $this->index($this->createMock(BazarrClient::class), $pool)->seriesCards();

        $this->assertSame('ready', $out['state'], 'stale data is still served');
        $this->assertCount(1, $out['cards']);
        $this->assertCount(1, $this->dispatched, 'exactly one refresh must be requested for the stale key');
    }

    public function testMostMissingServesStaleDataAndRequestsARefreshForTheStaleHalf(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);
        // Only the movies half is stale; the series half is fresh, so only
        // ONE refresh (KEY_MOVIES) should be requested.
        $swr->write(BazarrSubtitleIndex::KEY_MOST_MISSING_MOVIES, [
            ['kind' => 'movie', 'id' => 7, 'title' => 'A', 'year' => 2001, 'missingCount' => 3],
        ], BazarrSubtitleIndex::HARD_TTL, time() - 300);
        $swr->write(BazarrSubtitleIndex::KEY_MOST_MISSING_SERIES, [
            ['kind' => 'series', 'id' => 9, 'title' => 'B', 'year' => null, 'missingCount' => 11],
        ], BazarrSubtitleIndex::HARD_TTL);

        $out = $this->index($this->createMock(BazarrClient::class), $pool)->mostMissing();

        $this->assertSame('ready', $out['state'], 'stale data is still served');
        $this->assertCount(2, $out['items']);
        $this->assertCount(1, $this->dispatched, 'exactly one refresh must be requested, for the stale half only');
    }

    public function testBadgeCountsServesStaleDataAndRequestsARefresh(): void
    {
        $pool = new ArrayAdapter();
        $this->swr($pool)->write(
            BazarrSubtitleIndex::KEY_BADGES,
            ['movies' => 6219, 'episodes' => 13758, 'providers' => 2],
            BazarrSubtitleIndex::HARD_TTL,
            time() - 300,
        );

        $out = $this->index($this->createMock(BazarrClient::class), $pool)->badgeCounts();

        $this->assertSame('ready', $out['state'], 'stale data is still served');
        $this->assertSame(6219, $out['counts']['movies']);
        $this->assertCount(1, $this->dispatched, 'exactly one refresh must be requested for the stale key');
    }
}
