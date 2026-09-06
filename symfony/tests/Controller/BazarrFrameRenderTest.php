<?php

namespace App\Tests\Controller;

use App\Controller\SetupController;
use App\Entity\ServiceInstance;
use App\Entity\Setting;
use App\Entity\User;
use App\Service\Cache\StaleWhileRevalidateCache;
use App\Service\Media\BazarrClient;
use App\Service\Media\BazarrSubtitleIndex;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The only test in the suite that actually renders Bazarr markup. Without
 * seeded `bazarr_url`/`bazarr_api_key` settings, ServiceRouteGuardSubscriber
 * redirects before the controller body runs — the CI blind spot
 * BazarrControllerTest's docblock records.
 *
 * This does NOT extend AbstractWebTestCase, on purpose. BazarrClient is
 * eagerly built during that base class's OWN setUp(): its first Doctrine
 * flush (seedAdmin) fires the `onFlush` event, which the `turbo` bundle's
 * ContainerAwareEventManager resolves lazily — building its Doctrine-
 * broadcast Turbo listener, which builds the Twig environment, which
 * instantiates every registered Twig extension including
 * SubtitleBadgeExtension → BazarrSubtitleIndex → BazarrClient (verified
 * empirically; BazarrSubtitlesApiTest's docblock records the same finding
 * for the same reason). By the time any AbstractWebTestCase subclass's own
 * setUp() body runs, BazarrClient is already a real, container-built
 * singleton, and Symfony's TestContainer::set() refuses to replace an
 * already-initialized private service — so BazarrClient::ping() can never
 * be faked there, which is exactly what every view under test needs
 * (a real ping() would burn 8s against an address nothing listens on, and
 * ServiceRouteGuardSubscriber's OWN health check — independent of and
 * upstream from BazarrController — would 302 every non-index /bazarr/*
 * route to /bazarr before the controller ever ran).
 *
 * Rebuilding setUp() from scratch here means the BazarrClient override can
 * happen BEFORE the first flush, while the container still holds the real
 * (unbuilt) service definition — confirmed to survive into the request.
 */
class BazarrFrameRenderTest extends WebTestCase
{
    private KernelBrowser $client;

    /**
     * @param bool|list<bool> $reachable stubs BazarrClient::ping(). A plain
     *                        bool answers every call the same way.
     *                        ServiceRouteGuardSubscriber's OWN health check
     *                        calls ping() too (via HealthService, on the
     *                        SAME overridden instance) — BEFORE the
     *                        controller — so faking a permanent `false`
     *                        would 302 every non-index /bazarr/* route to
     *                        /bazarr before the controller's own
     *                        try/ping()/catch ever ran. To reach that
     *                        branch, pass consecutive values instead —
     *                        [true, false] answers the guard's call (1st)
     *                        reachable and the controller's own call (2nd)
     *                        unreachable, isolating the controller's error
     *                        path from the (separate, out-of-scope) guard
     *                        behaviour.
     */
    private function boot(bool|array $reachable): void
    {
        $this->client = static::createClient();

        // cache.app is a filesystem pool that outlives the SQLite schema
        // reset below — without this, an earlier test's primed dataset (or
        // a stale SWR write from an unrelated test class run in the same
        // process) leaks into this one and a "hard miss → warming" test
        // would silently see "ready" instead.
        static::getContainer()->get('cache.app')->clear();

        $em = static::getContainer()->get('doctrine')->getManager();
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $fakeBazarr = $this->createStub(BazarrClient::class);
        if (is_array($reachable)) {
            $fakeBazarr->method('ping')->willReturnOnConsecutiveCalls(...$reachable);
        } else {
            $fakeBazarr->method('ping')->willReturn($reachable);
        }
        static::getContainer()->set(BazarrClient::class, $fakeBazarr);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = new User();
        $admin->setEmail('admin@test.local');
        $admin->setDisplayName('Admin Test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'admin-password'));
        $em->persist($admin);
        $em->persist(new Setting(SetupController::SETUP_DONE_KEY, '1'));
        $em->persist(new Setting('bazarr_url', 'http://127.0.0.1:1'));
        $em->persist(new Setting('bazarr_api_key', 'k'));

        // BazarrSubtitleIndex's badge/single-item read
        // paths fail closed to gated ("multi-instance") unless EXACTLY one
        // Radarr instance is enabled (see BazarrSubtitleIndex::gate()) —
        // without this, every one of those paths silently takes the gated
        // branch instead of the real one this suite is meant to exercise.
        // Mirrors AbstractWebTestCase::seedDefaultInstances(); this class
        // does not extend that base (see the class docblock), so it is not
        // seeded automatically.
        $radarr = new ServiceInstance(ServiceInstance::TYPE_RADARR, 'radarr-1', 'Radarr', 'http://radarr.invalid:7878', 'k');
        $radarr->setIsDefault(true);
        $radarr->setEnabled(true);
        $em->persist($radarr);

        $em->flush();

        $this->client->loginUser($admin);

        // The functional test client reboots the kernel (a fresh container)
        // before every request by default, which would silently drop the
        // BazarrClient override above on any test making more than one
        // request.
        $this->client->disableReboot();
    }

    private function swr(): StaleWhileRevalidateCache
    {
        return static::getContainer()->get(StaleWhileRevalidateCache::class);
    }

    /** @param list<array<string, mixed>> $cards */
    private function primeMovieCards(array $cards, string $key = BazarrSubtitleIndex::KEY_MOVIE_CARDS): void
    {
        $this->swr()->write($key, ['cards' => $cards, 'languages' => []], BazarrSubtitleIndex::HARD_TTL);
    }

    /** @param list<array<string, mixed>> $movies, list<array<string, mixed>> $series */
    private function primeLanding(array $movies, array $series, array $counts): void
    {
        $swr = $this->swr();
        $swr->write(BazarrSubtitleIndex::KEY_MOST_MISSING_MOVIES, $movies, BazarrSubtitleIndex::HARD_TTL);
        $swr->write(BazarrSubtitleIndex::KEY_MOST_MISSING_SERIES, $series, BazarrSubtitleIndex::HARD_TTL);
        $swr->write(BazarrSubtitleIndex::KEY_BADGES, $counts, BazarrSubtitleIndex::HARD_TTL);
    }

    public function testAPlainRequestRendersTheFullPage(): void
    {
        $this->boot(true);
        $this->primeMovieCards([[
            'title' => 'ZzyxReadyMovie', 'year' => 2020, 'substate' => 'missing', 'count' => 1,
            'missingLangs' => ['en'], 'seriesId' => null, 'movieId' => 42,
        ]]);

        $this->client->request('GET', '/bazarr/movies');

        $html = (string) $this->client->getResponse()->getContent();
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('<html', $html, 'a normal navigation gets the whole document');
        $this->assertStringContainsString('navbar-vertical', $html, 'the full shape ships the sidebar/topbar chrome');
        $this->assertStringContainsString('id="bazarr-view"', $html, 'the shell always contains the frame');
        $this->assertStringContainsString('ZzyxReadyMovie', $html, 'the requested view\'s content is inline on a direct hit — no double fetch');
    }

    public function testAFrameRequestRendersOnlyTheFrame(): void
    {
        $this->boot(true);
        $this->primeMovieCards([[
            'title' => 'ZzyxReadyMovie', 'year' => 2020, 'substate' => 'missing', 'count' => 1,
            'missingLangs' => ['en'], 'seriesId' => null, 'movieId' => 42,
        ]]);

        $this->client->request('GET', '/bazarr/movies', [], [], ['HTTP_TURBO_FRAME' => 'bazarr-view']);

        $html = (string) $this->client->getResponse()->getContent();
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('<html', $html, 'a frame navigation must not ship the whole document');
        $this->assertStringNotContainsString('navbar-vertical', $html, 'a frame navigation must not ship the sidebar/topbar chrome');
        $this->assertStringContainsString(
            'id="bazarr-view"',
            $html,
            'Turbo matches the replacement by finding the frame element IN the response — a bare inner fragment leaves the frame empty',
        );
        $this->assertStringContainsString('ZzyxReadyMovie', $html);
    }

    public function testBothShapesVaryOnTheTurboFrameHeader(): void
    {
        $this->boot(true);

        $this->client->request('GET', '/bazarr/movies');
        $this->assertStringContainsString('Turbo-Frame', (string) $this->client->getResponse()->headers->get('Vary'), 'full-page shape');

        $this->client->request('GET', '/bazarr/movies', [], [], ['HTTP_TURBO_FRAME' => 'bazarr-view']);
        $this->assertStringContainsString('Turbo-Frame', (string) $this->client->getResponse()->headers->get('Vary'), 'frame shape');
    }

    public function testTheLandingAndSeriesViewsBranchTheSameWay(): void
    {
        $this->boot(true);

        foreach (['/bazarr', '/bazarr/series'] as $path) {
            $this->client->request('GET', $path, [], [], ['HTTP_TURBO_FRAME' => 'bazarr-view']);
            $this->assertStringNotContainsString('<html', (string) $this->client->getResponse()->getContent(), $path);
        }
    }

    public function testHistoryStaysAFullTurboDrivePage(): void
    {
        $this->boot(true);

        $this->client->request('GET', '/bazarr/history', [], [], ['HTTP_TURBO_FRAME' => 'bazarr-view']);

        $this->assertStringContainsString('<html', (string) $this->client->getResponse()->getContent());
    }

    public function testLandingRendersReadyTilesAndMostMissing(): void
    {
        $this->boot(true);
        $this->primeLanding(
            movies: [['kind' => 'movie', 'id' => 7, 'title' => 'ZzyxLandingMovie', 'year' => 2019, 'missingCount' => 2]],
            series: [],
            counts: ['movies' => 3, 'episodes' => 5, 'providers' => 2],
        );

        $this->client->request('GET', '/bazarr', [], [], ['HTTP_TURBO_FRAME' => 'bazarr-view']);

        $html = (string) $this->client->getResponse()->getContent();
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('bazarr-warming', $html);
        $this->assertStringNotContainsString('alert-danger', $html);
        $this->assertStringContainsString('ZzyxLandingMovie', $html, 'the ready most-missing row must render');
        // Both landing "View movies"/"View series" links stay inside the
        // frame's own view set.
        $this->assertSame(2, substr_count($html, 'data-turbo-frame="bazarr-view" data-turbo-action="advance"'));
    }

    public function testMoviesGridRendersReadyCards(): void
    {
        $this->boot(true);
        $this->primeMovieCards([[
            'title' => 'ZzyxReadyMovie', 'year' => 2021, 'substate' => 'complete', 'count' => 0,
            'missingLangs' => [], 'seriesId' => null, 'movieId' => 99,
        ]]);

        $this->client->request('GET', '/bazarr/movies', [], [], ['HTTP_TURBO_FRAME' => 'bazarr-view']);

        $html = (string) $this->client->getResponse()->getContent();
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('bazarr-warming', $html);
        $this->assertStringNotContainsString('alert-danger', $html);
        $this->assertStringContainsString('ZzyxReadyMovie', $html);
        $this->assertStringContainsString('id="bazarr-grid"', $html);
    }

    public function testSeriesGridRendersReadyCards(): void
    {
        $this->boot(true);
        $this->primeMovieCards(
            [[
                'title' => 'ZzyxReadySeries', 'year' => 2018, 'substate' => 'missing', 'count' => 3,
                'missingLangs' => ['fr'], 'seriesId' => 55, 'movieId' => null,
            ]],
            key: BazarrSubtitleIndex::KEY_SERIES_CARDS,
        );

        $this->client->request('GET', '/bazarr/series', [], [], ['HTTP_TURBO_FRAME' => 'bazarr-view']);

        $html = (string) $this->client->getResponse()->getContent();
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('bazarr-warming', $html);
        $this->assertStringNotContainsString('alert-danger', $html);
        $this->assertStringContainsString('ZzyxReadySeries', $html);
        // JSON_HEX_QUOT (see _grid.html.twig's json_encode() call) escapes
        // every " to ", so the embedded blob reads seriesId":55,
        // not "seriesId":55.
        $this->assertStringContainsString('seriesId":55', $html, 'the card JSON blob must carry the id the client script keys the drill-down link off');
        // the series-card <a> the client-side script
        // builds at runtime must be able to escape the frame — this response
        // only ships the JSON + the script's source, so the actual
        // data-turbo-frame="_top" attribute (set on click, in JS) is pinned
        // structurally instead, in BazarrTemplateGuardTest.
    }

    public function testWarmingStateRendersRetrySkeletonWithBoundedBackoffMarker(): void
    {
        $this->boot(true);
        // No cache primed at all: movieCards() is a hard miss → 'warming'.

        $this->client->request('GET', '/bazarr/movies', [], [], ['HTTP_TURBO_FRAME' => 'bazarr-view']);

        $html = (string) $this->client->getResponse()->getContent();
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('id="bazarr-warming"', $html);
        $this->assertStringContainsString('id="bazarr-warming-retry"', $html);
        $this->assertStringNotContainsString('alert-danger', $html);
        // the auto-retry marker must be out-of-band (window-keyed), never
        // server-rendered state — there is nothing for the server to read it
        // back from. It is a bounded-backoff attempt counter, not a one-shot
        // boolean.
        $this->assertStringContainsString('window.__bzWarmAttempts', $html);
        $this->assertStringContainsString('var SCHEDULE = [', $html);
        $this->assertStringNotContainsString('data-retried', $html, 'the dead attribute must be gone');
    }

    public function testErrorStateRendersServiceBannerEscapingTheFrame(): void
    {
        // [true, false]: the guard's own health check (1st ping() call) sees
        // "up" so it does not intercept the request; the controller's own
        // ping() (2nd call) sees "down" and takes the try/catch → error
        // banner branch under test. See boot()'s docblock.
        $this->boot([true, false]);

        $this->client->request('GET', '/bazarr/movies', [], [], ['HTTP_TURBO_FRAME' => 'bazarr-view']);

        $html = (string) $this->client->getResponse()->getContent();
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('alert-danger', $html);
        $this->assertStringNotContainsString('bazarr-warming', $html);
        // the banner's CTA must escape the frame.
        $this->assertStringContainsString('data-turbo-frame="_top"', $html);
    }
}
