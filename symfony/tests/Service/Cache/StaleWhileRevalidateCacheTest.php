<?php

namespace App\Tests\Service\Cache;

use App\Message\RefreshCacheKey;
use App\Service\Cache\StaleWhileRevalidateCache;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Cache\CacheInterface;

class StaleWhileRevalidateCacheTest extends TestCase
{
    /** @var list<object> */
    private array $dispatched = [];

    private function bus(bool $throw = false): MessageBusInterface
    {
        return new class($this->dispatched, $throw) implements MessageBusInterface {
            /** @param list<object> $sink */
            public function __construct(private array &$sink, private bool $throw) {}
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                if ($this->throw) {
                    throw new \RuntimeException('transport down');
                }
                $this->sink[] = $message;
                return new Envelope($message);
            }
        };
    }

    private function swr(ArrayAdapter $pool, bool $throwingBus = false): StaleWhileRevalidateCache
    {
        return new StaleWhileRevalidateCache($pool, $pool, $this->bus($throwingBus), new NullLogger());
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

    /** A CacheInterface that always hands back a malformed (non-envelope) result, regardless of $callback. */
    private function malformedCache(): CacheInterface
    {
        return new class implements CacheInterface {
            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                return ['not' => 'the expected shape'];
            }
            public function delete(string $key): bool { return true; }
        };
    }

    public function testReadReturnsNullOnHardMiss(): void
    {
        $this->assertNull($this->swr(new ArrayAdapter())->read('k', 60));
    }

    public function testWriteThenReadIsFresh(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);
        $swr->write('k', ['a' => 1], 600);

        $this->assertSame(['value' => ['a' => 1], 'state' => 'fresh'], $swr->read('k', 60));
    }

    public function testEntryOlderThanSoftTtlReadsStale(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);
        $swr->write('k', ['a' => 1], 600, time() - 120);

        $hit = $swr->read('k', 60);
        $this->assertNotNull($hit);
        $this->assertSame('stale', $hit['state']);
        $this->assertSame(['a' => 1], $hit['value']);
    }

    public function testReadTreatsACorruptEnvelopeAsAMissAndDropsIt(): void
    {
        $pool = new ArrayAdapter();
        $item = $pool->getItem('k');
        $item->set(['unexpected' => 'shape']); // no fetchedAt/value keys
        $item->expiresAfter(600);
        $pool->save($item);

        $swr = $this->swr($pool);

        $this->assertNull($swr->read('k', 60));
        $this->assertFalse($pool->getItem('k')->isHit(), 'a corrupt entry must be dropped, not just skipped');
    }

    public function testGetOrComputeFetchesOnceThenServesFromCache(): void
    {
        $pool  = new ArrayAdapter();
        $swr   = $this->swr($pool);
        $calls = 0;
        $fetch = function () use (&$calls) { $calls++; return ['row']; };

        $first  = $swr->getOrCompute('k', 60, 600, $fetch);
        $second = $swr->getOrCompute('k', 60, 600, $fetch);

        $this->assertSame(['row'], $first['value']);
        $this->assertSame('fresh', $first['state']);
        $this->assertSame(['row'], $second['value']);
        $this->assertSame(1, $calls, 'second call must hit the cache, not re-fetch');
    }

    public function testGetOrComputeDoesNotStoreAnEmptyResult(): void
    {
        $pool  = new ArrayAdapter();
        $swr   = $this->swr($pool);
        $calls = 0;
        $fetch = function () use (&$calls) { $calls++; return []; };

        $swr->getOrCompute('k', 60, 600, $fetch);
        $swr->getOrCompute('k', 60, 600, $fetch);

        $this->assertSame(2, $calls, 'an empty result must expire immediately so the next load retries');
    }

    public function testGetOrComputeTreatsACorruptEnvelopeAsAMissAndRecomputes(): void
    {
        $pool = new ArrayAdapter();
        $item = $pool->getItem('k');
        $item->set(['unexpected' => 'shape']);
        $item->expiresAfter(600);
        $pool->save($item);

        $swr   = $this->swr($pool);
        $calls = 0;
        $fetch = function () use (&$calls) { $calls++; return ['row']; };

        $result = $swr->getOrCompute('k', 60, 600, $fetch);

        $this->assertSame(['row'], $result['value']);
        $this->assertSame('fresh', $result['state']);
        $this->assertSame(1, $calls);
    }

    public function testGetOrComputeDoesNotTrustAMalformedContractsCacheResult(): void
    {
        // Empty pool: read() reports a genuine hard miss, so getOrCompute()
        // proceeds to the (here, deliberately broken) contracts cache.
        $pool  = new ArrayAdapter();
        $swr   = new StaleWhileRevalidateCache($this->malformedCache(), $pool, $this->bus(), new NullLogger());
        $calls = 0;

        $result = $swr->getOrCompute('k', 60, 600, function () use (&$calls) { $calls++; return ['fallback']; });

        $this->assertSame(['fallback'], $result['value']);
        $this->assertSame('fresh', $result['state']);
        $this->assertSame(1, $calls, 'a malformed contracts-cache result must never be trusted — recompute directly instead');
    }

    public function testGetOrComputePropagatesAThrowingFetchAndLeavesNoEntry(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);

        $this->expectException(\RuntimeException::class);
        try {
            $swr->getOrCompute('k', 60, 600, function (): array {
                throw new \RuntimeException('upstream down');
            });
        } finally {
            $this->assertFalse($pool->getItem('k')->isHit(), 'a throwing fetch must not leave a partial/cached entry behind');
        }
    }

    public function testGetOrComputeCanReturnStaleWhenSoftTtlIsAlreadyZero(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);

        $result = $swr->getOrCompute('k', 0, 600, fn () => ['row']);

        $this->assertSame(['row'], $result['value']);
        $this->assertSame('stale', $result['state'], 'state must be derived from fetchedAt like read() does, never hardcoded fresh');
    }

    public function testRequestRefreshDispatchesOnceInsideTheMarkerWindow(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);

        $swr->requestRefresh('k');
        $swr->requestRefresh('k');
        $swr->requestRefresh('k');

        $this->assertCount(1, $this->dispatched);
        $this->assertInstanceOf(RefreshCacheKey::class, $this->dispatched[0]);
        $this->assertSame('k', $this->dispatched[0]->key);
    }

    public function testDispatchFailureDropsTheMarkerSoTheNextRequestRetries(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool, throwingBus: true);

        $swr->requestRefresh('k'); // throws internally, must be swallowed

        $this->assertFalse(
            $pool->getItem('k.refreshing')->isHit(),
            'a failed dispatch must not leave the coalescing marker behind for 30 s',
        );
    }

    public function testDispatchFailureIsLoggedAsAnError(): void
    {
        $pool    = new ArrayAdapter();
        $records = [];
        $swr     = new StaleWhileRevalidateCache($pool, $pool, $this->bus(throw: true), $this->recordingLogger($records));

        $swr->requestRefresh('k');

        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]['level']);
        $this->assertSame('k', $records[0]['context']['key']);
        $this->assertSame(\RuntimeException::class, $records[0]['context']['exception']);
    }

    public function testRequestRefreshSwallowsAThrowingPoolAndLogsAnError(): void
    {
        $pool    = new ArrayAdapter();
        $records = [];
        $swr     = new StaleWhileRevalidateCache($pool, $pool, $this->bus(), $this->recordingLogger($records));

        // ArrayAdapter (like every PSR-6 adapter) throws on a reserved
        // character in the key: {}()/\@: — this must never propagate out of
        // requestRefresh(), which runs on the main request path.
        $swr->requestRefresh('bad{key}');

        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]['level']);
        $this->assertSame('bad{key}', $records[0]['context']['key']);
    }

    public function testWriteRejectsANonPositiveHardTtl(): void
    {
        $swr = $this->swr(new ArrayAdapter());

        $this->expectException(\InvalidArgumentException::class);
        $swr->write('k', ['a' => 1], 0);
    }

    public function testWriteSkipsWhenTheBackDatedFetchedAtHasAlreadyExpired(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);

        $swr->write('k', ['a' => 1], 600, time() - 700); // 700 s old vs a 600 s hard TTL

        $this->assertNull($swr->read('k', 60), 'a write whose effective TTL has already elapsed must not create a live entry');
    }

    /**
     * A write that bails out because its back-dated
     * $fetchedAt has already expired must also drop the .requested_at stamp
     * — the demand that stamp represents is moot (this write's data is
     * already too old to serve), so leaving the stamp behind would only
     * trip refreshIsOverdue() for a request nothing will ever answer.
     */
    public function testWriteSkipsAlsoClearsTheRequestedAtStampWhenAlreadyExpired(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);

        $swr->requestRefresh('k');
        $this->assertTrue($pool->getItem('k.requested_at')->isHit());

        $swr->write('k', ['a' => 1], 600, time() - 700); // 700 s old vs a 600 s hard TTL

        $this->assertFalse(
            $pool->getItem('k.requested_at')->isHit(),
            'an expired back-dated write must clear the now-moot requested-at stamp',
        );
    }

    public function testWriteWithABackDatedFetchedAtStillWritesWhenHardLifeRemains(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);

        $swr->write('k', ['a' => 1], 600, time() - 400); // 200 s of hard life left

        $hit = $swr->read('k', 1_000_000); // huge soft TTL so it reads back regardless of state
        $this->assertNotNull($hit);
        $this->assertSame(['a' => 1], $hit['value']);
    }

    public function testWriteDoesNotClearAnExistingRefreshingMarker(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);
        $swr->requestRefresh('k'); // sets .refreshing + .requested_at

        $swr->write('k', ['a' => 1], 600);

        $this->assertTrue(
            $pool->getItem('k.refreshing')->isHit(),
            'write() only clears the request stamp — the coalescing marker is left to expire on its own',
        );
    }

    public function testWriteClearsTheOverdueRequestStamp(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);

        $swr->requestRefresh('k');
        $this->assertTrue($pool->getItem('k.requested_at')->isHit());

        $swr->write('k', ['a' => 1], 600);
        $this->assertFalse($pool->getItem('k.requested_at')->isHit());
    }

    public function testRefreshIsOverdueWhenTheStampIsOlderThanTheThreshold(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);

        $item = $pool->getItem('k.requested_at');
        $item->set(time() - 300)->expiresAfter(900);
        $pool->save($item);

        $this->assertTrue($swr->refreshIsOverdue('k', 180));
        $this->assertFalse($swr->refreshIsOverdue('k', 600));
    }

    public function testDeleteDropsValueMarkerAndStamp(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);
        $swr->write('k', ['a' => 1], 600);
        $swr->requestRefresh('k');

        $swr->delete('k');

        $this->assertNull($swr->read('k', 60));
        $this->assertFalse($pool->getItem('k.refreshing')->isHit());
        $this->assertFalse($pool->getItem('k.requested_at')->isHit());
    }
}
