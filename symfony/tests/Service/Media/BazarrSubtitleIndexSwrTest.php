<?php

namespace App\Tests\Service\Media;

use App\Entity\ServiceInstance;
use App\Service\Cache\StaleWhileRevalidateCache;
use App\Service\Media\BazarrClient;
use App\Service\Media\BazarrSubtitleIndex;
use App\Service\Media\ServiceHealthCache;
use App\Service\ServiceInstanceProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[AllowMockObjectsWithoutExpectations]
class BazarrSubtitleIndexSwrTest extends TestCase
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

    /** @param list<array{level: mixed, message: string, context: array<string, mixed>}> $records */
    private function recordingLogger(array &$records): LoggerInterface
    {
        return new class($records) extends AbstractLogger {
            /** @param list<array{level: mixed, message: string, context: array<string, mixed>}> $records */
            public function __construct(private array &$records) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    /** A CacheItemPoolInterface that throws on every method — simulates a broken pool adapter. */
    private function throwingCacheApp(): CacheItemPoolInterface
    {
        return new class implements CacheItemPoolInterface {
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
        };
    }

    /** Marks KEY_MOVIES's refresh request as asked for well past the 180 s overdue threshold. */
    private function primeOverdueMovieRefresh(ArrayAdapter $pool): void
    {
        $this->swr($pool)->requestRefresh(BazarrSubtitleIndex::KEY_MOVIES);
        $stamp = $pool->getItem(BazarrSubtitleIndex::KEY_MOVIES . '.requested_at');
        $stamp->set(time() - 200);
        $stamp->expiresAfter(900);
        $pool->save($stamp);
    }

    public function testAHardMissNeverCallsBazarrAndAnswersPending(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('getMovies');

        $status = $this->index($client, new ArrayAdapter())->movieStatus(42);

        $this->assertSame('pending', $status['state']);
        $this->assertSame(0, $status['count']);
    }

    public function testAHardMissRequestsExactlyOneRefreshPerKey(): void
    {
        $pool  = new ArrayAdapter();
        $index = $this->index($this->createMock(BazarrClient::class), $pool);

        $index->movieStatus(1);
        $index->movieStatus(2);
        $index->movieStatus(3);

        $this->assertCount(1, $this->dispatched, 'the coalescing marker must collapse a whole grid to one request');
    }

    public function testAFreshEntryIsServedWithoutAnyRefreshRequest(): void
    {
        $pool = new ArrayAdapter();
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIES, [42 => ['state' => 'missing', 'count' => 3]], BazarrSubtitleIndex::HARD_TTL);
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIE_LANGS, [], BazarrSubtitleIndex::HARD_TTL);

        $status = $this->index($this->createMock(BazarrClient::class), $pool)->movieStatus(42);

        $this->assertSame(['state' => 'missing', 'count' => 3], $status);
        $this->assertSame([], $this->dispatched);
    }

    public function testAStaleEntryIsStillServedAndRequestsARefresh(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);
        $swr->write(BazarrSubtitleIndex::KEY_MOVIES, [42 => ['state' => 'missing', 'count' => 3]], BazarrSubtitleIndex::HARD_TTL, time() - 300);
        $swr->write(BazarrSubtitleIndex::KEY_MOVIE_LANGS, [], BazarrSubtitleIndex::HARD_TTL, time() - 300);
        $this->dispatched = [];

        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('getMovies');

        $status = $this->index($client, $pool)->movieStatus(42);

        $this->assertSame('missing', $status['state'], 'stale data is still shown');
        $this->assertCount(1, $this->dispatched);
    }

    public function testTheMultiInstanceGateAnswersHiddenAndRequestsNothing(): void
    {
        $index = $this->index($this->createMock(BazarrClient::class), new ArrayAdapter(), radarr: 2);

        $this->assertSame('hidden', $index->movieStatus(42)['state']);
        $this->assertSame([], $this->dispatched, 'a gated install must not spend a Bazarr fetch');
    }

    public function testAnIdAbsentFromAFreshMapIsHiddenNotPending(): void
    {
        $pool = new ArrayAdapter();
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIES, [1 => ['state' => 'complete', 'count' => 0]], BazarrSubtitleIndex::HARD_TTL);
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIE_LANGS, [], BazarrSubtitleIndex::HARD_TTL);

        $this->assertSame('hidden', $this->index($this->createMock(BazarrClient::class), $pool)->movieStatus(999)['state']);
    }

    public function testSeriesStatusFollowsTheSameContract(): void
    {
        $pool   = new ArrayAdapter();
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('getSeries');

        $this->assertSame('pending', $this->index($client, $pool)->seriesStatus(7)['state']);
    }

    public function testResetClearsEveryPerRequestField(): void
    {
        $index = $this->index($this->createMock(BazarrClient::class), new ArrayAdapter());
        $index->movieStatus(1);
        $index->reset();

        $r = new \ReflectionObject($index);
        foreach (['movies', 'movieLangs', 'series', 'radarrGate', 'sonarrGate'] as $field) {
            $this->assertNull($r->getProperty($field)->getValue($index), $field . ' must be null after reset()');
        }
    }

    public function testAnEmptyButFreshMovieMapAnswersHiddenNotPending(): void
    {
        // Regression: a clean-but-empty Bazarr fetch
        // (unconfigured/disabled Bazarr, or a genuinely empty library) writes
        // EMPTY maps — see BazarrIndexRefresherTest — so the read path here
        // must answer 'hidden' for any id, not 'pending', and must not ask
        // for yet another refresh.
        $pool = new ArrayAdapter();
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIES, [], BazarrSubtitleIndex::HARD_TTL);
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIE_LANGS, [], BazarrSubtitleIndex::HARD_TTL);

        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('getMovies');

        $status = $this->index($client, $pool)->movieStatus(42);

        $this->assertSame('hidden', $status['state']);
        $this->assertSame([], $this->dispatched, 'a warm-but-empty index must not request another refresh');
    }

    public function testAnEmptyButFreshSeriesMapAnswersHiddenNotPending(): void
    {
        $pool = new ArrayAdapter();
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_SERIES, [], BazarrSubtitleIndex::HARD_TTL);

        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('getSeries');

        $status = $this->index($client, $pool)->seriesStatus(7);

        $this->assertSame('hidden', $status['state']);
        $this->assertSame([], $this->dispatched);
    }

    public function testAnOverdueCheckOnABrokenPoolNeverThrows(): void
    {
        // Minor fix (round 1): refreshIsOverdue() and the overdue-log
        // marker/breaker check are the only unguarded pool calls left on the
        // render path (the hard-miss branch itself never touches the pool
        // via $this->cacheApp except here) — a broken pool adapter here must
        // degrade to "skip the log", never a 500.
        $pool = new ArrayAdapter();
        $this->primeOverdueMovieRefresh($pool);

        $index = new BazarrSubtitleIndex(
            $this->createMock(BazarrClient::class),
            $this->throwingCacheApp(),
            $this->instances(),
            $this->swr($pool),
            new NullLogger(),
        );

        $status = $index->movieStatus(42);

        $this->assertSame('pending', $status['state']);
    }

    public function testOverdueRefreshLogsWarningWhenBazarrIsDown(): void
    {
        $pool = new ArrayAdapter();
        $this->primeOverdueMovieRefresh($pool);
        (new ServiceHealthCache($pool))->markDown(BazarrClient::SERVICE);

        $records = [];
        $index   = new BazarrSubtitleIndex(
            $this->createMock(BazarrClient::class),
            $pool,
            $this->instances(),
            $this->swr($pool),
            $this->recordingLogger($records),
        );
        $index->movieStatus(42);

        $this->assertCount(1, $records);
        $this->assertSame('warning', $records[0]['level']);
        $this->assertStringContainsString('circuit breaker', $records[0]['message']);
    }

    public function testOverdueRefreshLogsErrorWhenBazarrIsNotDown(): void
    {
        $pool = new ArrayAdapter();
        $this->primeOverdueMovieRefresh($pool);

        $records = [];
        $index   = new BazarrSubtitleIndex(
            $this->createMock(BazarrClient::class),
            $pool,
            $this->instances(),
            $this->swr($pool),
            $this->recordingLogger($records),
        );
        $index->movieStatus(42);

        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]['level']);
        $this->assertStringContainsString('messenger-worker', $records[0]['message']);
    }

    public function testOverdueRefreshLogLineIsRateLimitedAcrossRequests(): void
    {
        $pool = new ArrayAdapter();
        $this->primeOverdueMovieRefresh($pool);

        $records = [];
        $logger  = $this->recordingLogger($records);

        // Two separate BazarrSubtitleIndex instances over the same pool,
        // simulating two different worker-mode requests both hitting the
        // same cold, overdue key — reset() clears in-process state between
        // requests but never the pool, so only the pool-backed marker can
        // throttle this across them.
        (new BazarrSubtitleIndex($this->createMock(BazarrClient::class), $pool, $this->instances(), $this->swr($pool), $logger))->movieStatus(1);
        (new BazarrSubtitleIndex($this->createMock(BazarrClient::class), $pool, $this->instances(), $this->swr($pool), $logger))->movieStatus(2);

        $this->assertCount(1, $records, 'the overdue log line must be throttled across requests, not just within one');
    }

    /**
     * requestRefresh() now checks REQUESTABLE_KEYS
     * (exactly what BazarrIndexRefresher::supports() claims) rather than the
     * broader ALL_KEYS — a derived-dataset key like KEY_MOVIE_CARDS has no
     * refresher of its own to route a request to, so queuing one would
     * silently do nothing forever instead of throwing at the mistake.
     */
    public function testRequestRefreshAcceptsOnlyTheThreeRefresherSupportedKeys(): void
    {
        $pool  = new ArrayAdapter();
        $index = $this->index($this->createMock(BazarrClient::class), $pool);

        foreach ([BazarrSubtitleIndex::KEY_MOVIES, BazarrSubtitleIndex::KEY_SERIES, BazarrSubtitleIndex::KEY_BADGES] as $key) {
            $index->requestRefresh($key);
        }
        $this->assertCount(3, $this->dispatched, 'each of the three refresher-supported keys must dispatch its own request');
    }

    /** @return iterable<string, array{0: string}> */
    public static function unsupportedRefreshKeys(): iterable
    {
        yield 'a derived cards key'        => [BazarrSubtitleIndex::KEY_MOVIE_CARDS];
        yield 'a derived most-missing key' => [BazarrSubtitleIndex::KEY_MOST_MISSING_SERIES];
        yield 'the movie langs key'        => [BazarrSubtitleIndex::KEY_MOVIE_LANGS];
        yield 'the patch journal key'      => [BazarrSubtitleIndex::KEY_PATCHES];
        yield 'a mistyped key'             => ['bazarr_subtitle_index.movies_typo'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedRefreshKeys')]
    public function testRequestRefreshRejectsEveryOtherKey(string $key): void
    {
        $index = $this->index($this->createMock(BazarrClient::class), new ArrayAdapter());

        $this->expectException(\InvalidArgumentException::class);
        $index->requestRefresh($key);
    }
}
