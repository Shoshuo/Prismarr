<?php

namespace App\Tests\MessageHandler;

use App\Message\RefreshCacheKey;
use App\MessageHandler\RefreshCacheKeyHandler;
use App\Service\Cache\CacheRefresherInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class RefreshCacheKeyHandlerTest extends TestCase
{
    private function refresher(string $prefix, ?string &$seen): CacheRefresherInterface
    {
        return new class($prefix, $seen) implements CacheRefresherInterface {
            public function __construct(private string $prefix, private ?string &$seen) {}
            public function supports(string $key): bool { return str_starts_with($key, $this->prefix); }
            public function refresh(string $key): void { $this->seen = $key; }
        };
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

    public function testRoutesTheKeyToTheOwningRefresher(): void
    {
        $a = null; $b = null;
        $handler = new RefreshCacheKeyHandler([$this->refresher('media.', $a), $this->refresher('queue.', $b)], new NullLogger());

        $handler(new RefreshCacheKey('queue.counts.qbittorrent'));

        $this->assertNull($a);
        $this->assertSame('queue.counts.qbittorrent', $b);
    }

    public function testMultipleMatchingRefreshersAreAllCalledAndLogAWarning(): void
    {
        $a = null; $b = null;
        $records = [];
        $handler = new RefreshCacheKeyHandler(
            [$this->refresher('media.', $a), $this->refresher('media.movies.', $b)],
            $this->recordingLogger($records),
        );

        $handler(new RefreshCacheKey('media.movies.radarr-1'));

        // supports() domains are supposed to be mutually exclusive; an
        // overlap must not silently pick a "winner" by iteration order —
        // BOTH refreshers run (they are required to be idempotent) and the
        // collision is logged so it gets fixed.
        $this->assertSame('media.movies.radarr-1', $a);
        $this->assertSame('media.movies.radarr-1', $b);
        $this->assertCount(1, $records);
        $this->assertSame('warning', $records[0]['level']);
        $this->assertSame('media.movies.radarr-1', $records[0]['context']['key']);
        $this->assertSame(2, $records[0]['context']['count']);
    }

    public function testUnknownKeyIsAckedNotThrown(): void
    {
        $a = null;
        $records = [];
        $handler = new RefreshCacheKeyHandler([$this->refresher('media.', $a)], $this->recordingLogger($records));

        $handler(new RefreshCacheKey('nope.whatever')); // must not throw

        $this->assertNull($a);
        $this->assertCount(1, $records);
        $this->assertSame('warning', $records[0]['level']);
        $this->assertSame('nope.whatever', $records[0]['context']['key']);
    }
}
