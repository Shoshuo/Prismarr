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
 * Per-movie subtitle-language detail: BazarrSubtitleIndex::movieLanguages(),
 * built from the SAME getMovies() pass that feeds the badge status tuples.
 * Consumed by the film-detail modal  via the JSON endpoint .
 */
#[AllowMockObjectsWithoutExpectations]
class BazarrSubtitleIndexLanguagesTest extends TestCase
{
    /** ServiceInstanceProvider reporting $radarr enabled Radarr / $sonarr enabled Sonarr instances. */
    private function instances(int $radarr = 1, int $sonarr = 1): ServiceInstanceProvider
    {
        $make = static fn (string $type, int $n) => $n > 0
            ? array_map(
                static fn (int $i) => new ServiceInstance($type, $type . '-' . $i, ucfirst($type) . ' ' . $i, 'http://x', 'k'),
                range(1, $n),
            )
            : [];

        $provider = $this->createMock(ServiceInstanceProvider::class);
        $provider->method('getEnabled')->willReturnCallback(
            static fn (string $type) => $type === ServiceInstance::TYPE_RADARR
                ? $make(ServiceInstance::TYPE_RADARR, $radarr)
                : $make(ServiceInstance::TYPE_SONARR, $sonarr),
        );
        // BazarrSubtitleIndex's gate() now delegates to the shared predicate
        // (ServiceInstanceProvider::hasExactlyOneEnabled) instead of counting
        // getEnabled() itself — stub it from the same $radarr/$sonarr counts
        // so this mock still behaves like the real provider.
        $provider->method('hasExactlyOneEnabled')->willReturnCallback(
            static fn (string $type) => ($type === ServiceInstance::TYPE_RADARR ? $radarr : $sonarr) === 1,
        );

        return $provider;
    }

    private function swr(ArrayAdapter $pool): StaleWhileRevalidateCache
    {
        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope { return new Envelope($message); }
        };

        return new StaleWhileRevalidateCache($pool, $pool, $bus, new NullLogger());
    }

    private function index(
        BazarrClient $client,
        ?ArrayAdapter $pool = null,
        ?ServiceInstanceProvider $instances = null,
    ): BazarrSubtitleIndex {
        $pool ??= new ArrayAdapter();

        return new BazarrSubtitleIndex($client, $pool, $instances ?? $this->instances(), $this->swr($pool), new NullLogger());
    }

    public function testMovieLanguagesReturnsPresentMissingTracked(): void
    {
        // Badge/language reads never fetch : warm the cache directly
        // via the SWR primitive, the way BazarrIndexRefresher would from one
        // getMovies() pass.
        $movie = [
            'radarrId'          => 5,
            'profileId'         => 1,
            'subtitles'         => [['name' => 'English', 'code2' => 'en', 'hi' => false, 'forced' => false]],
            'missing_subtitles' => [['name' => 'French', 'code2' => 'fr', 'hi' => false, 'forced' => false]],
        ];

        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);
        $swr->write(BazarrSubtitleIndex::KEY_MOVIES, [5 => BazarrSubtitleIndex::computeMovieStatus($movie)], BazarrSubtitleIndex::HARD_TTL);
        $swr->write(BazarrSubtitleIndex::KEY_MOVIE_LANGS, [5 => BazarrSubtitleIndex::extractMovieLangs($movie)], BazarrSubtitleIndex::HARD_TTL);

        $this->assertSame([
            'present' => [['lang' => 'en', 'hi' => false, 'forced' => false]],
            'missing' => [['lang' => 'fr', 'hi' => false, 'forced' => false]],
            'tracked' => true,
        ], $this->index($this->createMock(BazarrClient::class), $pool)->movieLanguages(5));
    }

    public function testAbsentMovieIsUntracked(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);
        $swr->write(BazarrSubtitleIndex::KEY_MOVIES, [5 => ['state' => 'complete', 'count' => 0]], BazarrSubtitleIndex::HARD_TTL);
        $swr->write(BazarrSubtitleIndex::KEY_MOVIE_LANGS, [5 => ['present' => [], 'missing' => [], 'tracked' => true]], BazarrSubtitleIndex::HARD_TTL);

        $this->assertSame(
            ['present' => [], 'missing' => [], 'tracked' => false],
            $this->index($this->createMock(BazarrClient::class), $pool)->movieLanguages(999),
        );
    }

    public function testMovieWithoutProfileIsUntracked(): void
    {
        // Untracked in Bazarr (no subtitle profile) → tracked:false, empty
        // lists, even though the raw dict carries a subtitles array.
        $movie = [
            'radarrId'          => 5,
            'profileId'         => null,
            'subtitles'         => [['code2' => 'en']],
            'missing_subtitles' => [],
        ];

        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);
        $swr->write(BazarrSubtitleIndex::KEY_MOVIES, [5 => BazarrSubtitleIndex::computeMovieStatus($movie)], BazarrSubtitleIndex::HARD_TTL);
        $swr->write(BazarrSubtitleIndex::KEY_MOVIE_LANGS, [5 => BazarrSubtitleIndex::extractMovieLangs($movie)], BazarrSubtitleIndex::HARD_TTL);

        $this->assertSame(
            ['present' => [], 'missing' => [], 'tracked' => false],
            $this->index($this->createMock(BazarrClient::class), $pool)->movieLanguages(5),
        );
    }

    public function testMultiInstanceGateHidesLanguages(): void
    {
        // Two enabled Radarr instances: ids collide, so fail closed exactly
        // like the badge — no fetch, tracked:false.
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('getMovies');

        $index = $this->index($client, new ArrayAdapter(), $this->instances(radarr: 2));

        $this->assertSame(
            ['present' => [], 'missing' => [], 'tracked' => false],
            $index->movieLanguages(5),
        );
    }

    public function testAHardMissForBothMapsRequestsExactlyOneRefresh(): void
    {
        // movieStatus() and movieLanguages() share one loadMovies() pass per
        // request (moviesLoaded guard) — a cold cache for both maps must
        // still only ask the messenger-worker for ONE rebuild, not two.
        $dispatched = [];
        $pool       = new ArrayAdapter();
        $bus        = new class($dispatched) implements MessageBusInterface {
            /** @param list<object> $sink */
            public function __construct(private array &$sink) {}
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->sink[] = $message;
                return new Envelope($message);
            }
        };
        $swr = new StaleWhileRevalidateCache($pool, $pool, $bus, new NullLogger());

        $index = new BazarrSubtitleIndex($this->createMock(BazarrClient::class), $pool, $this->instances(), $swr, new NullLogger());

        $this->assertSame('pending', $index->movieStatus(5)['state']);
        $this->assertFalse($index->movieLanguages(5)['tracked']);
        $this->assertCount(1, $dispatched);
    }

    public function testLanguageMapCachedAcrossRequests(): void
    {
        $movie = [
            'radarrId'          => 5,
            'profileId'         => 1,
            'subtitles'         => [['code2' => 'en']],
            'missing_subtitles' => [],
        ];

        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);
        $swr->write(BazarrSubtitleIndex::KEY_MOVIES, [5 => BazarrSubtitleIndex::computeMovieStatus($movie)], BazarrSubtitleIndex::HARD_TTL);
        $swr->write(BazarrSubtitleIndex::KEY_MOVIE_LANGS, [5 => BazarrSubtitleIndex::extractMovieLangs($movie)], BazarrSubtitleIndex::HARD_TTL);

        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('getMovies');

        $this->assertTrue($this->index($client, $pool)->movieLanguages(5)['tracked']);
        // Fresh service, same cache.app pool → served from cache, no fetch.
        $this->assertTrue($this->index($client, $pool)->movieLanguages(5)['tracked']);
    }

    public function testInvalidateDropsLanguageCacheKey(): void
    {
        $pool = new ArrayAdapter();
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIE_LANGS, [5 => ['present' => [], 'missing' => [], 'tracked' => true]], BazarrSubtitleIndex::HARD_TTL);

        $index = $this->index($this->createMock(BazarrClient::class), $pool);
        $this->assertTrue($pool->getItem(BazarrSubtitleIndex::KEY_MOVIE_LANGS)->isHit());

        $index->invalidate();
        $this->assertFalse($pool->getItem(BazarrSubtitleIndex::KEY_MOVIE_LANGS)->isHit());
    }
}
