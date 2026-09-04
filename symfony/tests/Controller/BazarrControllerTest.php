<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Service\Media\BazarrClient;
use App\Service\Media\ServiceHealthCache;
use App\Tests\AbstractWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Smoke test for the Bazarr section's route guard.
 *
 * AbstractWebTestCase seeds a fresh SQLite DB with an admin user + completed
 * setup flag, but no bazarr_url / bazarr_api_key rows — so every test here
 * runs under the "unconfigured" path and ServiceRouteGuardSubscriber's
 * `app_bazarr_` rule (src/EventSubscriber/ServiceRouteGuardSubscriber.php)
 * fires before BazarrController::index() ever runs, redirecting to
 * admin_settings_index. That guard behavior — not the Wanted-tab markup —
 * is what this task's test proves; template rendering is exercised live on
 * :beta once Bazarr is actually configured (see the task's report).
 */
class BazarrControllerTest extends AbstractWebTestCase
{
    /**
     * apiRefresh()'s coalescing marker lives in the
     * real filesystem cache.app pool, which — unlike the SQLite schema
     * AbstractWebTestCase resets — is NOT reset between tests in this
     * process. A test that reaches apiRefresh() successfully (the truthful-
     * shape test, the breaker-open test, and the already_running test below)
     * leaves the marker set for 30 s, which would make whichever of those
     * tests happens to run next within that window answer already_running
     * instead of whatever it actually means to exercise. Clear it after
     * every test in this class so order/timing can never matter.
     */
    protected function tearDown(): void
    {
        static::getContainer()->get('cache.app')->deleteItem('bazarr_subtitle_index.inline_refresh');
        parent::tearDown();
    }

    public function testBazarrRedirectsToAdminSettingsWhenUnconfigured(): void
    {
        $this->client->request('GET', '/bazarr');

        self::assertResponseRedirects('/admin/settings');
    }

    public function testMoviesSeriesHistoryRedirectWhenUnconfigured(): void
    {
        foreach (['/bazarr/movies', '/bazarr/series', '/bazarr/history'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseRedirects('/admin/settings');
        }
    }

    public function testSeriesDetailRedirectsWhenUnconfigured(): void
    {
        $this->client->request('GET', '/bazarr/series/7');

        self::assertResponseRedirects('/admin/settings');
    }

    /**
     * Anonymous access to the JSON API routes must be denied, same as every
     * other admin-only section. AbstractWebTestCase::setUp() already logs
     * $this->client in as admin, and the test kernel/client may only be
     * booted once per test (a second static::createClient() call throws) —
     * so we drop the session cookie to make the next requests anonymous
     * instead of spinning up a second client.
     */
    public function testJsonApiRoutesDenyAnonymousAccess(): void
    {
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', '/bazarr/api/search/movie/1');
        self::assertTrue($this->client->getResponse()->isRedirect(), 'GET search/movie should redirect anonymous users');

        $this->client->request('GET', '/bazarr/api/search/episode/1');
        self::assertTrue($this->client->getResponse()->isRedirect(), 'GET search/episode should redirect anonymous users');

        $this->client->request('POST', '/bazarr/api/download/movie');
        self::assertTrue($this->client->getResponse()->isRedirect(), 'POST download/movie should redirect anonymous users');

        $this->client->request('POST', '/bazarr/api/download/episode');
        self::assertTrue($this->client->getResponse()->isRedirect(), 'POST download/episode should redirect anonymous users');

        $this->client->request('POST', '/bazarr/api/auto/movie/1');
        self::assertTrue($this->client->getResponse()->isRedirect(), 'POST auto/movie should redirect anonymous users');

        $this->client->request('POST', '/bazarr/api/auto/series/1');
        self::assertTrue($this->client->getResponse()->isRedirect(), 'POST auto/series should redirect anonymous users');
    }

    /**
     * ServiceRouteGuardSubscriber's `app_bazarr_` rule carries an
     * `exclude_prefix` of `app_bazarr_api_`, so the JSON endpoints are exempt
     * from the guard (src/EventSubscriber/ServiceRouteGuardSubscriber.php).
     * A 302-to-HTML on a background fetch is unparseable to the caller, drops
     * the POST body, and leaves a flash message queued for whatever page the
     * user loads next. Unconfigured Bazarr therefore falls through to the
     * controller, whose client fails closed → jsonClientError(): a JSON 500
     * carrying `ok: false`.
     */
    public function testDownloadMovieAnswersJsonErrorWhenUnconfigured(): void
    {
        $this->client->request('POST', '/bazarr/api/download/movie', [
            'radarrid' => 42, 'provider' => 'x', 'subtitle' => 'y', 'hi' => 'False', 'forced' => 'False', 'original_format' => 'False',
        ]);

        self::assertFalse($this->client->getResponse()->isRedirect(), 'API routes must never redirect');
        self::assertResponseStatusCodeSame(500);
        self::assertJson((string) $this->client->getResponse()->getContent());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($payload['ok']);
        self::assertSame('Bazarr', $payload['service']);
    }

    public function testAutoMovieAnswersJsonErrorWhenUnconfigured(): void
    {
        $this->client->request('POST', '/bazarr/api/auto/movie/42');

        self::assertFalse($this->client->getResponse()->isRedirect());
        self::assertResponseStatusCodeSame(500);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($payload['ok']);
    }

    public function testSearchMovieAnswersJsonErrorWhenUnconfigured(): void
    {
        $this->client->request('GET', '/bazarr/api/search/movie/42');

        self::assertFalse($this->client->getResponse()->isRedirect());
        self::assertResponseStatusCodeSame(500);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($payload['ok']);
    }

    public function testTheRefreshEndpointDeniesAnonymousAccess(): void
    {
        $this->client->getCookieJar()->clear();

        $this->client->request('POST', '/bazarr/api/refresh');

        self::assertTrue($this->client->getResponse()->isRedirect(), 'POST api/refresh must redirect anonymous users');
    }

    /**
     * a logged-in but non-admin user must be denied too
     * — #[IsGranted('ROLE_ADMIN')] on the class throws AccessDeniedException,
     * which the security layer turns into a 403 (not a redirect: the user IS
     * authenticated, just under-privileged).
     */
    public function testTheRefreshEndpointDeniesNonAdminAccess(): void
    {
        $user = new User();
        $user->setEmail('member@test.local');
        $user->setDisplayName('Member');
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'member-password'));

        $em = $this->em();
        $em->persist($user);
        $em->flush();

        $this->client->loginUser($user);

        $this->client->request('POST', '/bazarr/api/refresh');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * the response must be truthful, not an
     * unconditional {"ok": true}. Unconfigured Bazarr (this test's fixture)
     * is a clean-empty fetch — BazarrClient::ready() is false, so getMovies/
     * getSeries/getBadgeCounts never make an HTTP call and never record a
     * lastError — so the inline refresh genuinely succeeds by writing fresh
     * empty maps, and the endpoint must report exactly that.
     */
    public function testTheRefreshEndpointReturnsATruthfulShapeForAdmin(): void
    {
        $this->client->request('POST', '/bazarr/api/refresh');

        self::assertFalse($this->client->getResponse()->isRedirect(), 'API routes must never redirect');
        self::assertResponseStatusCodeSame(200);
        self::assertJson((string) $this->client->getResponse()->getContent());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('ok', $payload);
        self::assertArrayHasKey('movies', $payload);
        self::assertArrayHasKey('series', $payload);
        self::assertArrayHasKey('reason', $payload);
        self::assertTrue($payload['ok']);
        self::assertSame('fresh', $payload['movies']);
        self::assertSame('fresh', $payload['series']);
        self::assertNull($payload['reason']);
    }

    /**
     * an open breaker must skip the fetch
     * entirely (structurally guaranteed by apiRefresh() returning before it
     * ever calls BazarrIndexRefresher::refresh()) and answer ok:false with a
     * breaker_open reason, never a 500.
     */
    public function testTheRefreshEndpointAnswersBreakerOpenWithoutFetching(): void
    {
        $pool = static::getContainer()->get('cache.app');
        (new ServiceHealthCache($pool))->markDown(BazarrClient::SERVICE);

        $this->client->request('POST', '/bazarr/api/refresh');

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($payload['ok']);
        self::assertSame('breaker_open', $payload['reason']);
    }

    /**
     * a second call while the coalescing marker is
     * still set (a double-clicked Retry, or a second admin) must not stack
     * another inline ~3x8s rebuild — it answers `already_running`
     * immediately. Zero client calls is structurally guaranteed here the
     * same way the breaker_open test above guarantees it: the marker check
     * returns before BazarrIndexRefresher::refresh() is ever called, and the
     * unconfigured Bazarr fixture would 500/JSON-error the moment any actual
     * HTTP client work happened, which it never does.
     */
    public function testASecondRefreshWhileOneIsMarkedInFlightAnswersAlreadyRunningWithoutFetching(): void
    {
        $pool = static::getContainer()->get('cache.app');
        $marker = $pool->getItem('bazarr_subtitle_index.inline_refresh');
        $marker->set(true);
        $marker->expiresAfter(30);
        $pool->save($marker);

        $this->client->request('POST', '/bazarr/api/refresh');

        self::assertFalse($this->client->getResponse()->isRedirect());
        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('ok', $payload);
        self::assertArrayHasKey('movies', $payload);
        self::assertArrayHasKey('series', $payload);
        self::assertFalse($payload['ok']);
        self::assertSame('already_running', $payload['reason']);
    }
}
