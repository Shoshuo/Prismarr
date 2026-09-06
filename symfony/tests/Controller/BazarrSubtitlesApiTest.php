<?php

namespace App\Tests\Controller;

use App\Controller\BazarrController;
use App\Entity\ServiceInstance;
use App\Entity\Setting;
use App\Service\Cache\StaleWhileRevalidateCache;
use App\Service\ConfigService;
use App\Service\Media\BazarrClient;
use App\Service\Media\BazarrPosterResolver;
use App\Service\Media\BazarrSubtitleIndex;
use App\Service\ServiceInstanceProvider;
use App\Tests\AbstractWebTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The subtitle-language JSON endpoints the film-detail and series-detail
 * modals fetch on open (one movie, one per-episode-series).
 *
 * Like the other app_bazarr_api_* routes they are carved out of
 * ServiceRouteGuardSubscriber's `app_bazarr_` rule (exclude_prefix
 * `app_bazarr_api_`), so a background fetch gets JSON, never a 302 to HTML.
 * Unlike the mutation endpoints they are fail-closed to a 200 `tracked:false`
 * (matching the badge contract), not a 500 — gated / untracked / absent /
 * unreachable is data, not an error.
 *
 * BazarrClient AND BazarrSubtitleIndex are both eagerly built during
 * AbstractWebTestCase::setUp() — the first Doctrine flush (seedAdmin) warms
 * the sidebar's SubtitleBadgeExtension Twig extension via the ContainerAware
 * event manager, which resolves BazarrSubtitleIndex and therefore BazarrClient
 * — so by the time a test method runs, `getContainer()->set(BazarrClient::class,
 * ...)` throws "service already initialized" (verified empirically). Three
 * different workarounds are used below depending on what each test needs:
 *   - a MOVIE test that only needs the fail-closed/untracked shape steers the
 *     result through BazarrSubtitleIndex's own documented 60 s cache layer:
 *     seeding both pool keys makes the index serve a fixed payload without a
 *     live Bazarr;
 *   - the MOVIE "tracked" happy path  and the SERIES "tracked" happy
 *     path both instead construct BazarrController directly (bypassing the
 *     container entirely) with a mocked BazarrClient and, for the movie case,
 *     a real BazarrSubtitleIndex built over an empty in-memory pool — a
 *     genuine hard miss, so apiSubtitlesMovie()'s movieLanguagesSingle() call
 *     falls back to exactly ONE per-id getMovies([$id]) on the mock;
 *   - the gate/anonymous/real-chain tests (both kinds) don't need fake data at
 *     all, so those DO run through the real HTTP+security stack.
 * The mapping logic itself is unit-tested in BazarrSubtitleIndexLanguagesTest /
 * BazarrSubtitleIndexSingleTest (movie) and BazarrLangsTest (the shared
 * per-entry extractor).
 */
#[AllowMockObjectsWithoutExpectations]
class BazarrSubtitlesApiTest extends AbstractWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // cache.app is on the filesystem and survives kernel reboots between
        // test methods — start each test from an empty subtitle index so a
        // seeded movie from one test can't leak into another.
        static::getContainer()->get('cache.app')->clear();
    }

    /**
     * Seed the index's cross-request cache so the real index serves radarrId 5
     * as tracked (English present / French missing) from a genuinely WARM
     * map. loadMovies() only skips its live fetch when BOTH pool keys are
     * warm, so seed both — and via
     * StaleWhileRevalidateCache::write(), which wraps each value in the
     * `{fetchedAt, value}` envelope StaleWhileRevalidateCache::read() actually
     * expects. A bare `$pool->getItem($key)->set($rawArray)` (the previous
     * version of this helper) fails that shape check and is silently treated
     * as a hard miss, so every test using it was actually exercising the
     * cold-index / hard-miss fallback path rather than a warm map, without
     * anyone noticing because both paths can produce the same JSON for the
     * absent-id case.
     */
    private function seedMovie5(): void
    {
        $pool = static::getContainer()->get('cache.app');
        $swr  = $this->swr($pool);

        $swr->write(BazarrSubtitleIndex::KEY_MOVIES, [5 => ['state' => 'missing', 'count' => 1]], BazarrSubtitleIndex::HARD_TTL);
        $swr->write(BazarrSubtitleIndex::KEY_MOVIE_LANGS, [5 => [
            'present' => [['lang' => 'en', 'hi' => false, 'forced' => false]],
            'missing' => [['lang' => 'fr', 'hi' => false, 'forced' => false]],
            'tracked' => true,
        ]], BazarrSubtitleIndex::HARD_TTL);
    }

    /**
     * Same shape as the Service-layer BazarrSubtitleIndex tests. Accepts
     * either the in-memory ArrayAdapter (the movie "tracked" happy-path test)
     * or the real `cache.app` pool from the container (seedMovie5(), which
     * needs to write through the SAME pool the real BazarrSubtitleIndex
     * service reads from) — both implement CacheItemPoolInterface AND
     * CacheInterface, which is all StaleWhileRevalidateCache's constructor
     * actually needs.
     */
    private function swr(CacheItemPoolInterface&CacheInterface $pool): StaleWhileRevalidateCache
    {
        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return new Envelope($message);
            }
        };

        return new StaleWhileRevalidateCache($pool, $pool, $bus, new NullLogger());
    }

    /**
     * apiSubtitlesMovie() now reads via movieLanguagesSingle(), so on a
     * genuine hard miss (an empty in-memory pool — nothing seeded) it must fall
     * back to exactly ONE per-id getMovies([5]) call, and the resulting
     * present/missing/tracked shape must come from that call's data — the
     * "comes from the client" behaviour this test lost when made
     * movieLanguages() non-blocking. Constructs BazarrController directly
     * (bypassing the container — see the class doc block for why) with a
     * mocked BazarrClient and a real BazarrSubtitleIndex over an empty pool.
     */
    public function testMovieLanguagesReturnsPresentMissingTracked(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->once())->method('getMovies')->with([5])->willReturn([
            ['radarrId' => 5, 'profileId' => 1, 'subtitles' => [['code2' => 'en']], 'missing_subtitles' => [['code2' => 'fr']]],
        ]);
        $client->method('getLastError')->willReturn(null);

        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->method('hasExactlyOneEnabled')->willReturn(true);

        $pool  = new ArrayAdapter();
        $index = new BazarrSubtitleIndex($client, $pool, $instances, $this->swr($pool), new NullLogger());

        $controller = new BazarrController(
            $client,
            $this->createMock(ConfigService::class),
            new NullLogger(),
            $index,
            $instances,
            $this->createMock(BazarrPosterResolver::class),
        );
        $controller->setContainer(new Container());

        $response = $controller->apiSubtitlesMovie(5);
        $data = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['ok']);
        self::assertTrue($data['tracked']);
        self::assertSame([['lang' => 'en', 'hi' => false, 'forced' => false]], $data['present']);
        self::assertSame([['lang' => 'fr', 'hi' => false, 'forced' => false]], $data['missing']);
    }

    /**
     * Movie 5 is in a genuinely WARM map (seedMovie5() now writes proper SWR
     * envelopes), but 999 is not → movieStatus(999)
     * resolves straight to the map's own fail-closed `hidden` entry (never
     * `pending`), so movieLanguagesSingle() takes the "anything but pending"
     * branch and returns movieLanguages(999) directly — zero per-id Bazarr
     * calls, proven at the unit level by
     * BazarrSubtitleIndexSingleTest::testAWarmMapCostsNoBazarrCallAtAll()
     * (this HTTP-level test only has the real, unmockable container client to
     * assert against, so it checks the resulting shape, not the call count).
     */
    public function testAbsentMovieIsUntracked(): void
    {
        $this->seedMovie5();

        $this->client->request('GET', '/bazarr/api/subtitles/movie/999');

        self::assertFalse($this->client->getResponse()->isRedirect());
        self::assertResponseStatusCodeSame(200);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);
        self::assertFalse($data['tracked']);
        self::assertSame([], $data['present']);
        self::assertSame([], $data['missing']);
    }

    public function testAnonymousAccessIsDenied(): void
    {
        // AbstractWebTestCase::setUp() logs the client in as admin; drop the
        // session cookie to make the request anonymous (a second createClient()
        // would throw).
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', '/bazarr/api/subtitles/movie/5');

        self::assertTrue(
            $this->client->getResponse()->isRedirect(),
            'ROLE_ADMIN endpoint must redirect anonymous users to login',
        );
    }

    /**
     * No seed: the real index + client with Bazarr unconfigured in the test
     * env. The endpoint must still answer a clean 200 `tracked:false`, never a
     * 302 (guard exemption) and never a 500 — the end-to-end proof of the
     * fail-closed contract the film modal depends on.
     */
    public function testUnconfiguredRealChainFailsClosed(): void
    {
        $this->client->request('GET', '/bazarr/api/subtitles/movie/5');

        self::assertFalse($this->client->getResponse()->isRedirect(), 'guard must leave app_bazarr_api_ routes alone');
        self::assertResponseStatusCodeSame(200);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);
        self::assertFalse($data['tracked']);
        self::assertSame([], $data['present']);
        self::assertSame([], $data['missing']);
    }

    /**
     * Regression for controller ruling #2: a Bazarr that's genuinely
     * configured but unreachable used to trip
     * `BazarrClient::getLastError() !== null` and this endpoint answered a
     * JSON 500 (jsonClientError). `bazarr.invalid` fails DNS resolution fast
     * (same idiom as UsenetControllerTest's `sabnzbd.invalid`), so the real
     * client attempts and fails a live request, leaving a structured error on
     * the client — this must now fold into the SAME 200 `tracked:false`
     * fail-closed contract as gated/untracked/absent, never a JSON error.
     */
    public function testMovieLanguagesFailsClosedOnUnreachableBazarrNotJsonError(): void
    {
        $em = $this->em();
        $em->persist(new Setting('bazarr_url', 'http://bazarr.invalid:6767'));
        $em->persist(new Setting('bazarr_api_key', 'k'));
        $em->flush();

        $this->client->request('GET', '/bazarr/api/subtitles/movie/5');

        self::assertFalse($this->client->getResponse()->isRedirect());
        self::assertResponseStatusCodeSame(200);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);
        self::assertFalse($data['tracked']);
        self::assertSame([], $data['present']);
        self::assertSame([], $data['missing']);
    }

    /**
     * Happy path for the series per-episode endpoint: constructs
     * BazarrController directly (see the class doc block for why) with a
     * mocked BazarrClient::getEpisodes(7) answering one episode with English
     * present / French missing, plus a second episode carrying no
     * `sonarrEpisodeId` — which must be skipped, not crash or key on null.
     */
    public function testSeriesLanguagesReturnsPresentMissingKeyedByEpisodeId(): void
    {
        $client = $this->createMock(BazarrClient::class);
        $client->expects($this->once())->method('getEpisodes')->with(7)->willReturn([
            ['sonarrEpisodeId' => 70, 'subtitles' => [['code2' => 'en']], 'missing_subtitles' => [['code2' => 'fr']]],
            ['subtitles' => [['code2' => 'de']]], // no sonarrEpisodeId -> must be skipped
        ]);

        $instances = $this->createMock(ServiceInstanceProvider::class);
        $instances->expects($this->once())->method('hasExactlyOneEnabled')->with(ServiceInstance::TYPE_SONARR)->willReturn(true);

        $controller = new BazarrController(
            $client,
            $this->createMock(ConfigService::class),
            new NullLogger(),
            $this->createMock(BazarrSubtitleIndex::class),
            $instances,
            $this->createMock(BazarrPosterResolver::class),
        );
        $controller->setContainer(new Container());

        $response = $controller->apiSubtitlesSeries(7);
        $data = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['ok']);
        self::assertTrue($data['tracked']);
        self::assertSame([
            '70' => [
                'present' => [['lang' => 'en', 'hi' => false, 'forced' => false]],
                'missing' => [['lang' => 'fr', 'hi' => false, 'forced' => false]],
            ],
        ], $data['episodes']);
    }

    /**
     * Multi-instance gate: AbstractWebTestCase seeds one enabled Sonarr
     * instance by default; adding a second must trip the gate to
     * `tracked:false, episodes:{}` — a bare sonarrEpisodeId is ambiguous
     * across two enabled instances, same rule as the badge.
     */
    public function testTwoEnabledSonarrInstancesGateSeriesEndpoint(): void
    {
        $em = $this->em();
        $second = new ServiceInstance(ServiceInstance::TYPE_SONARR, 'sonarr-2', 'Sonarr 2', 'http://sonarr2.invalid:8989', 'k');
        $second->setEnabled(true);
        $em->persist($second);
        $em->flush();

        $this->client->request('GET', '/bazarr/api/subtitles/series/7');

        self::assertFalse($this->client->getResponse()->isRedirect());
        self::assertResponseStatusCodeSame(200);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);
        self::assertFalse($data['tracked']);
        self::assertSame([], $data['episodes']);
    }

    public function testSeriesAnonymousAccessIsDenied(): void
    {
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', '/bazarr/api/subtitles/series/7');

        self::assertTrue(
            $this->client->getResponse()->isRedirect(),
            'ROLE_ADMIN endpoint must redirect anonymous users to login',
        );
    }

    /**
     * No seed: the real client with Bazarr unconfigured in the test env and
     * the default single enabled Sonarr instance. The gate passes (exactly
     * one enabled instance), getEpisodes() fails closed to `[]` on the
     * unconfigured client, so the endpoint answers a clean 200 `tracked:true,
     * episodes:{}` — never a 302 (guard exemption) and never a 500.
     */
    public function testUnconfiguredRealChainSeriesFailsClosed(): void
    {
        $this->client->request('GET', '/bazarr/api/subtitles/series/7');

        self::assertFalse($this->client->getResponse()->isRedirect(), 'guard must leave app_bazarr_api_ routes alone');
        self::assertResponseStatusCodeSame(200);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);
        self::assertTrue($data['tracked']);
        self::assertSame([], $data['episodes']);
    }
}
