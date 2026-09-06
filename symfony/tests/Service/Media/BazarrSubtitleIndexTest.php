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

#[AllowMockObjectsWithoutExpectations]
class BazarrSubtitleIndexTest extends TestCase
{
    /** @var list<object> */
    private array $dispatched = [];

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

    private function index(
        BazarrClient $client,
        ?ArrayAdapter $pool = null,
        ?ServiceInstanceProvider $instances = null,
    ): BazarrSubtitleIndex {
        $pool ??= new ArrayAdapter();

        return new BazarrSubtitleIndex($client, $pool, $instances ?? $this->instances(), $this->swr($pool), new NullLogger());
    }

    public function testComputeMovieMissingWithCount(): void
    {
        $s = BazarrSubtitleIndex::computeMovieStatus([
            'profileId' => 1, 'subtitles' => [['code2' => 'en']],
            'missing_subtitles' => [['code2' => 'fr'], ['code2' => 'es']],
        ]);
        $this->assertSame('missing', $s['state']);
        $this->assertSame(2, $s['count']);
    }

    public function testComputeMovieComplete(): void
    {
        $s = BazarrSubtitleIndex::computeMovieStatus([
            'profileId' => 1, 'subtitles' => [['code2' => 'en']], 'missing_subtitles' => [],
        ]);
        $this->assertSame('complete', $s['state']);
    }

    public function testComputeMovieHiddenWhenNoProfile(): void
    {
        $s = BazarrSubtitleIndex::computeMovieStatus(['profileId' => null, 'missing_subtitles' => [['code2' => 'fr']]]);
        $this->assertSame('hidden', $s['state']);
    }

    public function testStatusShapeIsStateAndCountOnly(): void
    {
        // `hasProfile` was never read by any template/JS — the shape is now
        // exactly the two keys consumers use.
        $this->assertSame(
            ['state', 'count'],
            array_keys(BazarrSubtitleIndex::computeMovieStatus(['profileId' => 1, 'missing_subtitles' => []])),
        );
        $this->assertSame(
            ['state', 'count'],
            array_keys(BazarrSubtitleIndex::computeMovieStatus(['profileId' => null])),
        );
    }

    public function testComputeSeriesHiddenWhenNoFiles(): void
    {
        $s = BazarrSubtitleIndex::computeSeriesStatus(['profileId' => 1, 'episodeFileCount' => 0, 'episodeMissingCount' => 0]);
        $this->assertSame('hidden', $s['state']);
    }

    public function testComputeSeriesMissing(): void
    {
        $s = BazarrSubtitleIndex::computeSeriesStatus(['profileId' => 1, 'episodeFileCount' => 10, 'episodeMissingCount' => 3]);
        $this->assertSame('missing', $s['state']);
        $this->assertSame(3, $s['count']);
    }

    public function testUnknownMovieIsHidden(): void
    {
        // Badge reads never fetch : warm the cache directly via the
        // SWR primitive, the way BazarrIndexRefresher would.
        $pool = new ArrayAdapter();
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIES, [1 => ['state' => 'complete', 'count' => 0]], BazarrSubtitleIndex::HARD_TTL);
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIE_LANGS, [], BazarrSubtitleIndex::HARD_TTL);

        $index = $this->index($this->createMock(BazarrClient::class), $pool);
        $this->assertSame('hidden', $index->movieStatus(999)['state']);
        $this->assertSame('complete', $index->movieStatus(1)['state']);
    }

    public function testSuccessfulFetchIsCachedAcrossRequests(): void
    {
        $pool = new ArrayAdapter();
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIES, [1 => ['state' => 'missing', 'count' => 1]], BazarrSubtitleIndex::HARD_TTL);
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIE_LANGS, [], BazarrSubtitleIndex::HARD_TTL);

        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('getMovies');

        $first = $this->index($client, $pool);
        $this->assertSame('missing', $first->movieStatus(1)['state']);

        // A second request (fresh service instance, same cache.app pool) must
        // be served from the pool too — no client call from either.
        $second = $this->index($client, $pool);
        $this->assertSame('missing', $second->movieStatus(1)['state']);
        $this->assertSame(1, $second->movieStatus(1)['count']);
    }

    public function testInvalidateDropsBothPoolItems(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);
        $swr->write(BazarrSubtitleIndex::KEY_MOVIES, [1 => ['state' => 'complete', 'count' => 0]], BazarrSubtitleIndex::HARD_TTL);
        $swr->write(BazarrSubtitleIndex::KEY_MOVIE_LANGS, [], BazarrSubtitleIndex::HARD_TTL);
        $swr->write(BazarrSubtitleIndex::KEY_SERIES, [5 => ['state' => 'complete', 'count' => 0]], BazarrSubtitleIndex::HARD_TTL);

        $index = $this->index($this->createMock(BazarrClient::class), $pool);
        $this->assertTrue($pool->getItem(BazarrSubtitleIndex::KEY_MOVIES)->isHit());
        $this->assertTrue($pool->getItem(BazarrSubtitleIndex::KEY_SERIES)->isHit());

        $index->invalidate();

        $this->assertFalse($pool->getItem(BazarrSubtitleIndex::KEY_MOVIES)->isHit());
        $this->assertFalse($pool->getItem(BazarrSubtitleIndex::KEY_SERIES)->isHit());
    }

    public function testResetClearsOnlyTheRequestMemo(): void
    {
        $pool = new ArrayAdapter();
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIES, [1 => ['state' => 'complete', 'count' => 0]], BazarrSubtitleIndex::HARD_TTL);
        $this->swr($pool)->write(BazarrSubtitleIndex::KEY_MOVIE_LANGS, [], BazarrSubtitleIndex::HARD_TTL);

        $index = $this->index($this->createMock(BazarrClient::class), $pool);
        $index->movieStatus(1);
        $index->reset();

        $this->assertTrue(
            $pool->getItem(BazarrSubtitleIndex::KEY_MOVIES)->isHit(),
            'reset() is the worker-mode per-request hook — it must not wipe the shared pool',
        );
    }

    public function testMultipleEnabledRadarrInstancesHideEveryMovieBadge(): void
    {
        // Ids collide across instances, so a badge would show another film's
        // subtitle state (and download for the wrong film). Fail closed.
        $pool   = new ArrayAdapter();
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('getMovies');

        $index = $this->index($client, $pool, $this->instances(radarr: 2));

        $this->assertSame('hidden', $index->movieStatus(1)['state']);
        $this->assertSame(
            [],
            $this->dispatched,
            'the gate must run before any pool read/refresh-request — a gated install must not spend a Bazarr fetch',
        );
    }

    public function testMultipleEnabledSonarrInstancesHideEverySeriesBadge(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('getSeries');

        $index = $this->index($client, new ArrayAdapter(), $this->instances(sonarr: 3));

        $this->assertSame('hidden', $index->seriesStatus(5)['state']);
    }

    public function testZeroEnabledInstancesAlsoHideBadges(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->never())->method('getMovies');

        $index = $this->index($client, new ArrayAdapter(), $this->instances(radarr: 0));

        $this->assertSame('hidden', $index->movieStatus(1)['state']);
    }

    public function testSingleEnabledInstanceBehavesNormally(): void
    {
        $pool = new ArrayAdapter();
        $swr  = $this->swr($pool);
        $swr->write(BazarrSubtitleIndex::KEY_MOVIES, [1 => ['state' => 'missing', 'count' => 1]], BazarrSubtitleIndex::HARD_TTL);
        $swr->write(BazarrSubtitleIndex::KEY_MOVIE_LANGS, [], BazarrSubtitleIndex::HARD_TTL);
        $swr->write(BazarrSubtitleIndex::KEY_SERIES, [5 => ['state' => 'missing', 'count' => 2]], BazarrSubtitleIndex::HARD_TTL);

        $index = $this->index($this->createMock(BazarrClient::class), $pool, $this->instances(radarr: 1, sonarr: 1));

        $this->assertSame('missing', $index->movieStatus(1)['state']);
        $this->assertSame('missing', $index->seriesStatus(5)['state']);
        $this->assertSame(2, $index->seriesStatus(5)['count']);
    }

    public function testTheBadgeReadPathContainsNoClientCall(): void
    {
        $src = file_get_contents(__DIR__ . '/../../../src/Service/Media/BazarrSubtitleIndex.php');
        $this->assertNotFalse($src);

        // movieStatus/movieLanguages/seriesStatus (plus everything from
        // seriesStatus() through gate() — movieCards/seriesCards/mostMissing/
        // badgeCounts/readDataset, all called on ordinary page renders) are
        // never allowed to call the client. Checked as two separate spans
        // (not one movieStatus..gate() span) ONLY because the single-item
        // movieStatusSingle()/movieLanguagesSingle()/loadSingle() sit between
        // movieLanguages() and seriesStatus() and DELIBERATELY do call the
        // client — that's the single-item fallback, gated off the grid path
        // by BazarrTemplateGuardTest instead of this test. The third span's
        // end marker is 'private function gate(' (not 'public function
        // movieCards(') so
        // movieCards()/seriesCards()/mostMissing()/badgeCounts()/
        // readDataset() are scanned again — they sit AFTER seriesStatus() and
        // never contained the *Single()/loadSingle() methods to begin with,
        // so no sub-span exclusion is needed for this span.
        $this->assertMethodBodyHasNoClientCall($src, 'public function movieStatus(', 'public function movieLanguages(');
        $this->assertMethodBodyHasNoClientCall($src, 'public function movieLanguages(', 'public function movieStatusSingle(');
        $this->assertMethodBodyHasNoClientCall($src, 'public function seriesStatus(', 'private function gate(');
    }

    private function assertMethodBodyHasNoClientCall(string $src, string $startMarker, string $endMarker): void
    {
        $start = strpos($src, $startMarker);
        $end   = strpos($src, $endMarker);
        $this->assertIsInt($start, $startMarker . ' not found');
        $this->assertIsInt($end, $endMarker . ' not found');
        $this->assertStringNotContainsString('$this->client', substr($src, $start, $end - $start), $startMarker . ' must not call the client');
    }
}
