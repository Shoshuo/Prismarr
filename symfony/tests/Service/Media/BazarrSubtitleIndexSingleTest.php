<?php

namespace App\Tests\Service\Media;

use App\Entity\ServiceInstance;
use App\Service\Cache\StaleWhileRevalidateCache;
use App\Service\Media\BazarrClient;
use App\Service\Media\BazarrSubtitleIndex;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * the per-id single-item fallback (movieStatusSingle() /
 * movieLanguagesSingle()) for surfaces that render exactly ONE item — the
 * quick-look badge and the modal-chips endpoint. The map is read first
 * (fresh OR stale); only a genuine hard miss makes ONE `radarrid[]`-filtered
 * Bazarr call. Kept strictly off the grid path ; see
 * BazarrTemplateGuardTest for the template-side enforcement.
 */
#[AllowMockObjectsWithoutExpectations]
class BazarrSubtitleIndexSingleTest extends TestCase
{
    private function swr(ArrayAdapter $pool): StaleWhileRevalidateCache
    {
        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
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

    public function testAHardMissFallsBackToExactlyOnePerIdCall(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->once())->method('getMovies')->with([7])->willReturn([
            ['radarrId' => 7, 'profileId' => 1, 'subtitles' => [['code2' => 'en']], 'missing_subtitles' => [['code2' => 'fr']]],
        ]);
        $client->method('getLastError')->willReturn(null);

        $status = $this->index($client, new ArrayAdapter())->movieStatusSingle(7);

        $this->assertSame('missing', $status['state']);
        $this->assertSame(1, $status['count']);
    }

    public function testAWarmMapCostsNoBazarrCallAtAll(): void
    {
        $pool = new ArrayAdapter();
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIES, [7 => ['state' => 'complete', 'count' => 0]], BazarrSubtitleIndex::HARD_TTL);
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIE_LANGS, [7 => ['present' => [], 'missing' => [], 'tracked' => true]], BazarrSubtitleIndex::HARD_TTL);

        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('getMovies');

        $this->assertSame('complete', $this->index($client, $pool)->movieStatusSingle(7)['state']);
    }

    public function testTheFallbackResultIsMemoizedForTheRestOfTheRequest(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->once())->method('getMovies')->with([7])->willReturn([
            ['radarrId' => 7, 'profileId' => 1, 'subtitles' => [['code2' => 'en']], 'missing_subtitles' => []],
        ]);
        $client->method('getLastError')->willReturn(null);

        $index = $this->index($client, new ArrayAdapter());
        $index->movieStatusSingle(7);
        $langs = $index->movieLanguagesSingle(7); // badge + chips = one call

        $this->assertTrue($langs['tracked']);
    }

    public function testAFailedFallbackAnswersHiddenNotPending(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([]);
        $client->method('getLastError')->willReturn(['code' => 0, 'method' => 'GET', 'path' => '/movies', 'message' => 'connection failed']);

        $this->assertSame('hidden', $this->index($client, new ArrayAdapter())->movieStatusSingle(7)['state']);
    }

    public function testTheMultiInstanceGateBlocksTheFallbackToo(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('getMovies');

        $this->assertSame('hidden', $this->index($client, new ArrayAdapter(), radarr: 2)->movieStatusSingle(7)['state']);
    }

    /**
     * a filter param Bazarr ignored (or a
     * misbehaving stub) can hand back a row for a DIFFERENT movie than the one
     * requested. Trusting $rows[0] blindly would compute and memoize the
     * WRONG movie's status/langs under this id — loadSingle() must verify the
     * row's own radarrId via findRowById() and treat a mismatch exactly like
     * an empty/error response.
     */
    public function testARowForADifferentIdIsTreatedAsAMissNotTrusted(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->once())->method('getMovies')->with([7])->willReturn([
            ['radarrId' => 999, 'profileId' => 1, 'subtitles' => [], 'missing_subtitles' => [['code2' => 'fr']]],
        ]);
        $client->method('getLastError')->willReturn(null);

        $index = $this->index($client, new ArrayAdapter());

        $this->assertSame('hidden', $index->movieStatusSingle(7)['state']);
        $this->assertFalse($index->movieLanguagesSingle(7)['tracked']);
    }

    /**
     * a broken pool must not turn a quick-look open
     * into a 500. StaleWhileRevalidateCache's own read()/requestRefresh() are
     * already exception-safe (mirrors
     * BazarrSubtitleIndexPatchTest::testABrokenPoolDuringThePatchNeverThrows),
     * so this proves that safety extends through the *Single() read path too.
     */
    public function testABrokenPoolDuringTheSingleLookupNeverThrows(): void
    {
        $throwing = new class implements CacheItemPoolInterface, CacheInterface {
            public function getItem(string $key): CacheItemInterface { throw new \RuntimeException('pool down'); }
            /** @return iterable<string, CacheItemInterface> */
            public function getItems(array $keys = []): iterable { throw new \RuntimeException('pool down'); }
            public function hasItem(string $key): bool { throw new \RuntimeException('pool down'); }
            public function clear(): bool { throw new \RuntimeException('pool down'); }
            public function deleteItem(string $key): bool { throw new \RuntimeException('pool down'); }
            public function deleteItems(array $keys): bool { throw new \RuntimeException('pool down'); }
            public function save(CacheItemInterface $item): bool { throw new \RuntimeException('pool down'); }
            public function saveDeferred(CacheItemInterface $item): bool { throw new \RuntimeException('pool down'); }
            public function commit(): bool { throw new \RuntimeException('pool down'); }
            /** @param callable $callback */
            public function get(string $key, $callback, ?float $beta = null, ?array &$metadata = null): mixed { throw new \RuntimeException('pool down'); }
            public function delete(string $key): bool { throw new \RuntimeException('pool down'); }
        };

        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return new Envelope($message);
            }
        };
        $swr = new StaleWhileRevalidateCache($throwing, $throwing, $bus, new NullLogger());

        $client = $this->createMock(BazarrClient::class);
        $client->method('getMovies')->willReturn([
            ['radarrId' => 7, 'profileId' => 1, 'subtitles' => [['code2' => 'en']], 'missing_subtitles' => []],
        ]);
        $client->method('getLastError')->willReturn(null);

        $index = new BazarrSubtitleIndex($client, $throwing, $this->instances(), $swr, new NullLogger());

        $status = $index->movieStatusSingle(7); // must not throw
        $langs  = $index->movieLanguagesSingle(7); // must not throw

        $this->assertSame('complete', $status['state']);
        $this->assertTrue($langs['tracked']);
    }

    /**
     * reset() must clear the $singles per-request memo
     * — a second lookup after reset() is a
     * fresh request as far as the memo is concerned and must re-read the pool
     * (and re-fetch on another hard miss), not silently reuse a PREVIOUS
     * request's fallback answer.
     */
    public function testResetClearsTheSinglesMemo(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->exactly(2))->method('getMovies')->with([7])->willReturn([
            ['radarrId' => 7, 'profileId' => 1, 'subtitles' => [['code2' => 'en']], 'missing_subtitles' => []],
        ]);
        $client->method('getLastError')->willReturn(null);

        $index = $this->index($client, new ArrayAdapter());
        $index->movieStatusSingle(7);
        $index->reset();
        $index->movieStatusSingle(7);
    }
}
